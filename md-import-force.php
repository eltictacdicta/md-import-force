<?php
/**
 * Plugin Name: MD Import Force
 * Description: Plugin para importar contenido de WordPress forzando IDs específicos
 * Version: 1.0.0
 * Author: MD Import Export
 * Text Domain: md-import-force
 */

// Si este archivo es llamado directamente, abortar.
if (!defined('WPINC')) {
    die;
}

// Incluir clases necesarias
require_once plugin_dir_path(__FILE__) . 'includes/class-md-import-force-logger.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-md-import-force-file-processor.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-md-import-force-post-importer.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-md-import-force-taxonomy-importer.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-md-import-force-media-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-md-import-force-comment-importer.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-md-import-force-progress-tracker.php';

// Definir constantes
define('MD_IMPORT_FORCE_VERSION', '1.0.0');
define('MD_IMPORT_FORCE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MD_IMPORT_FORCE_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Clase principal del plugin
 */
class MD_Import_Force {
    /**
     * Instancia única de la clase
     */
    private static $instance = null;

    /**
     * Constructor
     */
    private function __construct() {
        // Inicializar el plugin
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_md_import_force_upload', array($this, 'handle_upload'));
        add_action('wp_ajax_md_import_force_import', array($this, 'handle_import'));
        add_action('wp_ajax_md_import_force_preview', array($this, 'handle_preview'));
        add_action('wp_ajax_md_import_force_read_log', array($this, 'handle_read_log')); // AJAX action to read log
        add_action('wp_ajax_md_import_force_clear_log', array($this, 'handle_clear_log')); // AJAX action to clear log
        add_action('wp_ajax_md_import_force_check_progress', array($this, 'handle_check_progress')); // AJAX action to check import progress
    }

    /**
     * Obtener la instancia única de la clase
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Agregar menú de administración
     */
    public function add_admin_menu() {
        add_menu_page(
            __('MD Import Force', 'md-import-force'),
            __('MD Import Force', 'md-import-force'),
            'manage_options',
            'md-import-force',
            array($this, 'display_admin_page'),
            'dashicons-upload',
            30
        );
    }

    /**
     * Cargar scripts y estilos de administración
     */
    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_md-import-force' !== $hook) {
            return;
        }

        wp_enqueue_style('md-import-force-admin', MD_IMPORT_FORCE_PLUGIN_URL . 'assets/css/admin.css', array(), MD_IMPORT_FORCE_VERSION);
        wp_enqueue_script('md-import-force-admin', MD_IMPORT_FORCE_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), MD_IMPORT_FORCE_VERSION, true);

        wp_localize_script('md-import-force-admin', 'md_import_force', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('md_import_force_nonce'),
            'i18n' => array(
                'uploading' => __('Subiendo archivo...', 'md-import-force'),
                'importing' => __('Importando contenido...', 'md-import-force'),
                'success' => __('La importación se ha realizado con éxito', 'md-import-force'),
                'error' => __('Error en la importación', 'md-import-force'),
            )
        ));
    }

    /**
     * Mostrar página de administración
     */
    public function display_admin_page() {
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'templates/admin-page.php';
    }

    /**
     * Manejar la lectura del log de errores.
     */
    public function handle_read_log() {
        check_ajax_referer('md_import_force_nonce', 'nonce');

        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-handler.php';
        $handler = new MD_Import_Force_Handler();
        $result = $handler->read_error_log();

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
        check_ajax_referer('md_import_force_nonce', 'nonce');

        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-handler.php';
        $handler = new MD_Import_Force_Handler();
        $result = $handler->clear_error_log();

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
        // Verificar nonce
        check_ajax_referer('md_import_force_nonce', 'nonce');

        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('No tienes permisos para realizar esta acción.', 'md-import-force')));
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
        // Verificar nonce
        check_ajax_referer('md_import_force_nonce', 'nonce');

        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('No tienes permisos para realizar esta acción.', 'md-import-force')));
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
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['data'] ?? array('message' => __('Error desconocido durante la previsualización.', 'md-import-force')));
        }
    }

    /**
     * Manejar la importación
     */
    public function handle_import() {
        // Verificar nonce
        check_ajax_referer('md_import_force_nonce', 'nonce');

        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('No tienes permisos para realizar esta acción.', 'md-import-force')));
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
            'timestamp' => microtime(true)
        ]) . "</progress-update>\n";

        // Vaciar el buffer para enviar los datos inmediatamente
        ob_flush();
        flush();

        // Cargar el manejador de importación
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-handler.php'; // Ruta corregida
        $importer = new MD_Import_Force_Handler(); // Clase corregida

        // Realizar la importación (siempre fuerza IDs)
        $result = $importer->start_import($file_path); // Método y parámetros corregidos

        // Asegurarse de que todo el buffer se ha enviado
        ob_flush();
        flush();

        // Devolver el resultado vía JSON
        if (isset($result['success']) && $result['success']) {
            wp_send_json_success(array(
                'message' => $result['message'] ?? __('La importación se ha realizado con éxito', 'md-import-force'), // Usar mensaje del resultado
                'stats' => $result // Devolver todas las estadísticas
            ));
        } else {
            wp_send_json_error(array(
                'message' => $result['message'] ?? __('Error desconocido durante la importación.', 'md-import-force'), // Usar mensaje del resultado
            ));
        }
    }

    /**
     * Manejar la consulta de progreso de importación
     */
    public function handle_check_progress() {
        // Verificar nonce
        check_ajax_referer('md_import_force_nonce', 'nonce');

        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('No tienes permisos para realizar esta acción.', 'md-import-force')));
        }

        // Obtener el ID de sesión actual
        $session_id = get_option('md_import_force_current_session', '');

        if (empty($session_id)) {
            wp_send_json_error(array('message' => __('No hay una importación en progreso.', 'md-import-force')));
        }

        // Obtener el archivo de progreso
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/md-import-force-temp';
        $progress_file = $temp_dir . '/' . $session_id . '_progress.json';

        if (!file_exists($progress_file)) {
            wp_send_json_error(array('message' => __('No se encontró información de progreso.', 'md-import-force')));
        }

        // Leer el archivo de progreso
        $progress_data = json_decode(file_get_contents($progress_file), true);

        if (!$progress_data) {
            wp_send_json_error(array('message' => __('Error al leer la información de progreso.', 'md-import-force')));
        }

        // Devolver los datos de progreso
        wp_send_json_success($progress_data);
    }
}

// Inicializar el plugin
function md_import_force_init() {
    MD_Import_Force::get_instance();
}
add_action('plugins_loaded', 'md_import_force_init');

// Activación del plugin
register_activation_hook(__FILE__, 'md_import_force_activate');
function md_import_force_activate() {
    // Crear directorio para archivos de importación
    $upload_dir = wp_upload_dir();
    $target_dir = $upload_dir['basedir'] . '/md-import-force/';
    if (!file_exists($target_dir)) {
        wp_mkdir_p($target_dir);
    }
}

// Desactivación del plugin
register_deactivation_hook(__FILE__, 'md_import_force_deactivate');
function md_import_force_deactivate() {
    // Limpiar opciones si es necesario
}
