<?php
/**
 * Clase para manejar todas las solicitudes AJAX del plugin
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

class MD_Import_Force_Ajax_Handler {

    /**
     * Constructor
     */
    public function __construct() {
        // Registrar todos los manejadores AJAX
        add_action('wp_ajax_md_import_force_upload', array($this, 'handle_upload'));
        add_action('wp_ajax_md_import_force_import', array($this, 'handle_import'));
        add_action('wp_ajax_md_import_force_preview', array($this, 'handle_preview'));
        add_action('wp_ajax_md_import_force_read_log', array($this, 'handle_read_log'));
        add_action('wp_ajax_md_import_force_clear_log', array($this, 'handle_clear_log'));
        add_action('wp_ajax_md_import_force_check_progress', array($this, 'handle_check_progress'));
        add_action('wp_ajax_md_import_force_cleanup_all', array($this, 'handle_cleanup_all'));
    }

    /**
     * Verifica el nonce y los permisos para todas las solicitudes AJAX
     * 
     * @param string $nonce_name Nombre del nonce a verificar
     * @return bool True si las verificaciones pasan, false en caso contrario
     */
    private function verify_ajax_request($nonce_name = 'md_import_force_nonce') {
        // Verificar nonce
        if (!check_ajax_referer($nonce_name, 'nonce', false)) {
            wp_send_json_error(array('message' => __('Error de seguridad. Por favor, recarga la página.', 'md-import-force')));
            return false;
        }

        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('No tienes permisos para realizar esta acción.', 'md-import-force')));
            return false;
        }

        return true;
    }

    /**
     * Manejar la lectura del log de errores.
     */
    public function handle_read_log() {
        if (!$this->verify_ajax_request()) {
            return;
        }

        $result = MD_Import_Force_Logger::read_error_log();

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * Manejar la limpieza del log de errores.
     */
    public function handle_clear_log() {
        if (!$this->verify_ajax_request()) {
            return;
        }

        $result = MD_Import_Force_Logger::clear_error_log();

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success($result);
        }
    }

    /**
     * Manejar la subida de archivos
     */
    public function handle_upload() {
        if (!$this->verify_ajax_request()) {
            return;
        }

        // Verificar que se haya subido un archivo
        if (!isset($_FILES['import_file']) || empty($_FILES['import_file']['tmp_name'])) {
            wp_send_json_error(array('message' => __('No se ha subido ningún archivo.', 'md-import-force')));
        }

        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/md-import-force/';

        // Crear directorio si no existe
        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
        }

        // Generar nombre de archivo único
        $file_name = 'import-' . time() . '.json';
        $target_file = $target_dir . $file_name;

        // Mover archivo subido
        if (move_uploaded_file($_FILES['import_file']['tmp_name'], $target_file)) {
            wp_send_json_success(array(
                'message' => __('Archivo subido correctamente.', 'md-import-force'),
                'file_path' => $target_file,
                'file_name' => $file_name
            ));
        } else {
            wp_send_json_error(array('message' => __('Error al subir el archivo.', 'md-import-force')));
        }
    }

    /**
     * Manejar la previsualización
     */
    public function handle_preview() {
        if (!$this->verify_ajax_request()) {
            return;
        }

        // Verificar que se haya subido un archivo
        if (!isset($_FILES['import_file']) || empty($_FILES['import_file']['tmp_name'])) {
            wp_send_json_error(array('message' => __('No se ha subido ningún archivo para previsualizar.', 'md-import-force')));
        }

        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/md-import-force/';

        // Crear directorio si no existe
        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
        }

        // Generar nombre de archivo único para la previsualización
        $file_name = 'preview-' . time() . '-' . sanitize_file_name($_FILES['import_file']['name']);
        $target_file = $target_dir . $file_name;

        // Mover archivo subido
        if (!move_uploaded_file($_FILES['import_file']['tmp_name'], $target_file)) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR PREVIEW]: Error al mover archivo temporal a {$target_file}");
            wp_send_json_error(array('message' => __('Error al subir el archivo para previsualizar.', 'md-import-force')));
            return;
        }

        // Cargar el manejador de importación
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-handler.php';
        $handler = new MD_Import_Force_Handler();

        // Realizar la previsualización
        $result = $handler->preview_import_file($target_file);

        // Devolver el resultado vía JSON
        if (isset($result['success']) && $result['success']) {
            // No eliminamos el archivo de previsualización aún porque se usará para la importación
            wp_send_json_success($result);
        } else {
            // Si hay error, limpiamos el archivo de previsualización ya que no se usará
            $handler->cleanup_import_file($target_file);
            MD_Import_Force_Logger::log_message("MD Import Force: Limpieza de archivo de previsualización fallido: {$target_file}");
            wp_send_json_error($result['data'] ?? array('message' => __('Error desconocido durante la previsualización.', 'md-import-force')));
        }
    }

    /**
     * Manejar la importación
     */
    public function handle_import() {
        if (!$this->verify_ajax_request()) {
            return;
        }

        // Verificar que se haya enviado un archivo
        if (!isset($_POST['file_path']) || empty($_POST['file_path'])) {
            wp_send_json_error(array('message' => __('No se ha especificado un archivo para importar.', 'md-import-force')));
        }

        $file_path = sanitize_text_field($_POST['file_path']);

        // Verificar que el archivo existe
        if (!file_exists($file_path)) {
            wp_send_json_error(array('message' => __('El archivo de importación no existe.', 'md-import-force')));
        }

        // Configurar cabeceras para evitar el almacenamiento en búfer
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        // Iniciar buffer de salida para permitir actualizaciones de progreso
        if (ob_get_level()) ob_end_clean();
        ob_start();

        // Desactivar el límite de tiempo de ejecución si es posible
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        // Enviar un mensaje inicial para establecer la conexión
        echo "\n<progress-update data-timestamp=\"" . time() . "\">" . json_encode([
            'current' => 0,
            'total' => 0,
            'percent' => 0,
            'current_item' => 'Iniciando importación...',
            'timestamp' => microtime(true),
            'status' => 'starting'
        ]) . "</progress-update>\n";

        // Vaciar el buffer para enviar los datos inmediatamente
        ob_flush();
        flush();

        // Cargar el manejador de importación
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-handler.php';
        $importer = new MD_Import_Force_Handler();

        // Realizar la importación (siempre fuerza IDs)
        $result = $importer->start_import($file_path);

        // Registrar el resultado de la importación en el log para depuración
        MD_Import_Force_Logger::log_message("MD Import Force: Resultado de importación: " . json_encode($result));

        // Asegurarse de que el progreso final se envíe al navegador
        echo "\n<progress-update data-timestamp=\"" . time() . "\">" . json_encode([
            'current' => 100,
            'total' => 100,
            'percent' => 100,
            'current_item' => 'Importación completada',
            'timestamp' => microtime(true),
            'status' => 'completed'
        ]) . "</progress-update>\n";

        // Vaciar el buffer para enviar los datos inmediatamente
        if (function_exists('ob_flush')) ob_flush();
        if (function_exists('flush')) flush();

        // Esperar un momento para asegurar que los datos se envíen
        usleep(500000); // 0.5 segundos

        // Limpiar completamente el buffer de salida para evitar contenido no JSON
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Limpiar el archivo de importación después de procesarlo
        $cleanup_result = false;
        if (isset($result['success']) && $result['success']) {
            // Solo intentamos limpiar si la importación fue exitosa
            $cleanup_result = $importer->cleanup_import_file($file_path);
            MD_Import_Force_Logger::log_message("MD Import Force: Limpieza de archivo después de importación: " . ($cleanup_result ? 'Exitosa' : 'Fallida'));
        }

        // Registrar en el log para depuración
        MD_Import_Force_Logger::log_message("MD Import Force [DEBUG]: Resultado completo: " . json_encode($result));

        // Devolver el resultado vía JSON de forma manual para evitar problemas
        if (isset($result['success']) && $result['success']) {
            // Asegurarse de que los elementos omitidos estén disponibles en la respuesta
            $response = array(
                'success' => true,
                'data' => array(
                    'message' => $result['message'] ?? __('La importación se ha realizado con éxito', 'md-import-force'),
                    'stats' => array(
                        'new_count' => $result['new_count'] ?? 0,
                        'updated_count' => $result['updated_count'] ?? 0,
                        'skipped_count' => $result['skipped_count'] ?? 0,
                        'skipped_items' => $result['skipped_items'] ?? array(),
                    ),
                    'cleanup' => $cleanup_result
                )
            );

            // Registrar en el log para depuración
            MD_Import_Force_Logger::log_message("MD Import Force [DEBUG]: Respuesta JSON: " . json_encode($response));
        } else {
            $response = array(
                'success' => false,
                'data' => array(
                    'message' => $result['message'] ?? __('Error desconocido durante la importación.', 'md-import-force')
                )
            );
        }

        // Enviar cabeceras JSON
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response);
        exit;
    }

    /**
     * Manejar la limpieza de todos los archivos de importación
     */
    public function handle_cleanup_all() {
        if (!$this->verify_ajax_request()) {
            return;
        }

        // Obtener el parámetro de horas (opcional)
        $hours = isset($_POST['hours']) ? intval($_POST['hours']) : 24;

        // Cargar el manejador de importación
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-handler.php';
        $handler = new MD_Import_Force_Handler();

        // Realizar la limpieza
        $result = $handler->cleanup_all_import_files($hours);

        // Devolver el resultado
        wp_send_json_success(array(
            'message' => sprintf(__('Limpieza completada. %d archivos eliminados, %d fallidos, %d omitidos.', 'md-import-force'),
                        $result['deleted'], $result['failed'], $result['skipped']),
            'stats' => $result
        ));
    }

    /**
     * Manejar la consulta de progreso de importación
     */
    public function handle_check_progress() {
        if (!$this->verify_ajax_request()) {
            return;
        }

        // Intentar obtener datos de progreso de la opción de WordPress primero
        $progress_data = get_option('md_import_force_progress_data', null);
        $session_id = get_option('md_import_force_current_session', '');

        // Verificar que los datos de progreso sean válidos y correspondan a la sesión actual
        if ($progress_data && !empty($session_id)) {
            // Verificar que los datos no sean de una importación anterior completada
            // Si es una importación nueva, el status no debe ser 'completed'
            if ($progress_data['status'] !== 'completed' ||
                (isset($progress_data['timestamp']) && (microtime(true) - $progress_data['timestamp']) < 60)) {
                wp_send_json_success($progress_data);
                return;
            }
        }

        // Si no hay datos en la opción, intentar leer del archivo
        // Obtener el ID de sesión actual
        $session_id = get_option('md_import_force_current_session', '');

        if (empty($session_id)) {
            // Intentar leer el ID de sesión del archivo
            $upload_dir = wp_upload_dir();
            $temp_dir = $upload_dir['basedir'] . '/md-import-force-temp';
            $session_file = $temp_dir . '/current_session.txt';

            if (file_exists($session_file)) {
                $session_id = trim(file_get_contents($session_file));
            }

            if (empty($session_id)) {
                wp_send_json_error(array(
                    'message' => __('No hay una importación en progreso.', 'md-import-force'),
                    'percent' => 0,
                    'current' => 0,
                    'total' => 0,
                    'current_item' => 'Esperando inicio de importación...'
                ));
                return;
            }
        }

        // Obtener el archivo de progreso
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/md-import-force-temp';
        $progress_file = $temp_dir . '/' . $session_id . '_progress.json';

        if (!file_exists($progress_file)) {
            // Si no hay archivo de progreso, devolver datos por defecto
            wp_send_json_success(array(
                'percent' => 0,
                'current' => 0,
                'total' => 0,
                'current_item' => 'Iniciando importación...',
                'status' => 'starting'
            ));
            return;
        }

        // Leer el archivo de progreso
        $progress_data = json_decode(file_get_contents($progress_file), true);

        if (!$progress_data) {
            // Si no se puede leer el archivo, devolver datos por defecto
            wp_send_json_success(array(
                'percent' => 0,
                'current' => 0,
                'total' => 0,
                'current_item' => 'Preparando importación...',
                'status' => 'preparing'
            ));
            return;
        }

        // Devolver los datos de progreso
        wp_send_json_success($progress_data);
    }
}
