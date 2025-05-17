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
require_once plugin_dir_path(__FILE__) . 'includes/class-md-import-force-skipped-items-tracker.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-md-import-force-post-importer.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-md-import-force-taxonomy-importer.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-md-import-force-media-handler.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-md-import-force-comment-importer.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-md-import-force-progress-tracker.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-md-import-force-job-manager.php';

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
        
        // Registrar endpoints de REST API
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Acción para el cron de importación
        add_action('md_import_force_run_background_import', array('MD_Import_Force', 'execute_background_import'), 10, 2);

        // Mantener las acciones AJAX tradicionales para compatibilidad por ahora
        add_action('wp_ajax_md_import_force_upload', array($this, 'handle_upload'));
        add_action('wp_ajax_md_import_force_import', array($this, 'handle_import'));
        add_action('wp_ajax_md_import_force_preview', array($this, 'handle_preview'));
        add_action('wp_ajax_md_import_force_read_log', array($this, 'handle_read_log')); 
        add_action('wp_ajax_md_import_force_clear_log', array($this, 'handle_clear_log'));
        add_action('wp_ajax_md_import_force_check_progress', array($this, 'handle_check_progress'));
        add_action('wp_ajax_md_import_force_cleanup_all', array($this, 'handle_cleanup_all'));
    }

    /**
     * Registrar endpoints de la REST API
     */
    public function register_rest_routes() {
        // >>> DEBUGGING: Log when this function is called
        error_log('MD Import Force: register_rest_routes called');

        register_rest_route('md-import-force/v1', '/upload', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_rest_upload'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        register_rest_route('md-import-force/v1', '/preview', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_rest_preview'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        register_rest_route('md-import-force/v1', '/import', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_rest_import'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        register_rest_route('md-import-force/v1', '/log', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_rest_read_log'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        register_rest_route('md-import-force/v1', '/log', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'handle_rest_clear_log'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        register_rest_route('md-import-force/v1', '/progress', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_rest_check_progress'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        register_rest_route('md-import-force/v1', '/cleanup', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_rest_cleanup_all'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));

        // >>> INICIO: Registrar endpoint para detener importaciones <<<
        // Modified to use standard format for consistency and clearer debug
        register_rest_route('md-import-force/v1', '/stop-imports', array(
            'methods' => 'POST',  // Changed from WP_REST_Server::CREATABLE for consistency with other endpoints
            'callback' => array($this, 'handle_stop_imports_request'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));
        error_log('MD Import Force: Registered /stop-imports endpoint');
        // >>> FIN: Registrar endpoint para detener importaciones <<<

        // >>> DEBUGGING: Log all registered routes
        if (function_exists('get_option') && get_option('permalink_structure')) { // Only log if pretty permalinks likely enabled
            $server = rest_get_server();
            $all_routes = array_keys($server->get_routes());
            error_log('MD Import Force - Registered REST Routes: ' . print_r($all_routes, true));
        }
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
            'rest_url' => rest_url('md-import-force/v1/'),
            'nonce' => wp_create_nonce('md_import_force_nonce'),
            'rest_nonce' => wp_create_nonce('wp_rest'),
            'i18n' => array(
                'uploading' => __('Subiendo archivo...', 'md-import-force'),
                'importing' => __('Importando contenido...', 'md-import-force'),
                'success' => __('La importación se ha realizado con éxito', 'md-import-force'),
                'error' => __('Error en la importación', 'md-import-force'),
                'completed' => __('Importación completada', 'md-import-force'),
                'processing' => __('Procesando elemento', 'md-import-force'),
                'empty_log_message' => __('El log está vacío.', 'md-import-force')
            )
        ));

        // >>> INICIO: Encolar script para el botón de detener importaciones <<<
        wp_enqueue_script(
            'md-import-force-stop-script', // Usar un handle diferente para este script específico
            MD_IMPORT_FORCE_PLUGIN_URL . 'assets/js/admin-script.js',
            array( 'wp-api-fetch' ), // Dependencia para la Fetch API y utilidades de nonces REST
            MD_IMPORT_FORCE_VERSION, // Usar la misma versión del plugin
            true // Cargar en el footer
        );

        wp_localize_script(
            'md-import-force-stop-script', // Usar el mismo handle que el wp_enqueue_script de arriba
            'mdImportForceSettings', // Nombre del objeto JavaScript para las configuraciones
            array(
                'rest_url_stop_imports' => 'md-import-force/v1/stop-imports', // Simplified to path only for wp-api-fetch
                'nonce'                 => wp_create_nonce( 'wp_rest' ), // Nonce estándar para la API REST
                'i18n'                  => array( 
                    'stopping'         => esc_html__( 'Procesando solicitud de detención...', 'md-import-force' ),
                    'success'          => esc_html__( 'Solicitud de detención enviada. Las importaciones se detendrán.', 'md-import-force' ),
                    'error'            => esc_html__( 'Error al solicitar la detención de las importaciones.', 'md-import-force' ),
                    'confirm_stop'     => esc_html__( '¿Estás seguro de que quieres detener todas las importaciones en curso? Esta acción no se puede deshacer inmediatamente.', 'md-import-force' ),
                    'stop_button_text' => esc_html__( 'Parar Todas las Importaciones', 'md-import-force' ),
                ),
            )
        );
        // >>> FIN: Encolar script para el botón de detener importaciones <<<
    }

    /**
     * Mostrar página de administración
     */
    public function display_admin_page() {
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'templates/admin-page.php';
    }

    /**
     * Manejador de REST API: Subir archivo
     */
    public function handle_rest_upload(WP_REST_Request $request) {
        // Verificar permisos (ya se verificó en permission_callback, pero por seguridad adicional)
        if (!current_user_can('manage_options')) {
            return new WP_Error('rest_forbidden', __('No tienes permisos para realizar esta acción.', 'md-import-force'), array('status' => 403));
        }

        // Obtener archivos subidos
        $files = $request->get_file_params();

        // Verificar que se haya subido un archivo
        if (empty($files) || !isset($files['import_file']) || empty($files['import_file']['tmp_name'])) {
            return new WP_Error('no_file', __('No se ha subido ningún archivo.', 'md-import-force'), array('status' => 400));
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
        if (move_uploaded_file($files['import_file']['tmp_name'], $target_file)) {
            return rest_ensure_response(array(
                'success' => true,
                'message' => __('Archivo subido correctamente.', 'md-import-force'),
                'file_path' => $target_file,
                'file_name' => $file_name
            ));
        } else {
            return new WP_Error('upload_error', __('Error al subir el archivo.', 'md-import-force'), array('status' => 500));
        }
    }

    /**
     * Manejador de REST API: Previsualizar archivo
     */
    public function handle_rest_preview(WP_REST_Request $request) {
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            return new WP_Error('rest_forbidden', __('No tienes permisos para realizar esta acción.', 'md-import-force'), array('status' => 403));
        }

        // Obtener archivos subidos
        $files = $request->get_file_params();

        // Verificar que se haya subido un archivo
        if (empty($files) || !isset($files['import_file']) || empty($files['import_file']['tmp_name'])) {
            return new WP_Error('no_file', __('No se ha subido ningún archivo para previsualizar.', 'md-import-force'), array('status' => 400));
        }

        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/md-import-force/';

        // Crear directorio si no existe
        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
        }

        // Generar nombre de archivo único para la previsualización
        $file_name = 'preview-' . time() . '-' . sanitize_file_name($files['import_file']['name']);
        $target_file = $target_dir . $file_name;

        // Path Traversal Hardening: Verificar que el target_file esté dentro del directorio permitido
        $allowed_base_path = trailingslashit($upload_dir['basedir'] . '/md-import-force');
        
        // Como el archivo aún no existe, no podemos usar realpath. En su lugar, verificamos que:
        // 1. El directorio de destino sea el esperado (ya está construido de manera segura)
        // 2. El nombre del archivo se haya sanitizado correctamente (ya estamos usando sanitize_file_name)
        
        // Verificar que el path comienza con el directorio permitido
        if (strpos($target_file, $allowed_base_path) !== 0) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR REST PREVIEW]: Ruta de destino inválida: {$target_file}");
            return new WP_Error('invalid_target_path', __('Error de seguridad: ruta de destino inválida.', 'md-import-force'), array('status' => 400));
        }

        // Mover archivo subido
        if (!move_uploaded_file($files['import_file']['tmp_name'], $target_file)) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR PREVIEW]: Error al mover archivo temporal a {$target_file}");
            return new WP_Error('upload_error', __('Error al subir el archivo para previsualizar.', 'md-import-force'), array('status' => 500));
        }

        // Cargar el manejador de importación
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-handler.php';
        $handler = new MD_Import_Force_Handler();

        // Realizar la previsualización
        $result = $handler->preview_import_file($target_file);

        // Devolver el resultado
        if (isset($result['success']) && $result['success']) {
            return rest_ensure_response($result);
        } else {
            // Si hay error, limpiamos el archivo de previsualización ya que no se usará
            $handler->cleanup_import_file($target_file);
            MD_Import_Force_Logger::log_message("MD Import Force: Limpieza de archivo de previsualización fallido: {$target_file}");
            return new WP_Error(
                'preview_error', 
                ($result['data']['message'] ?? __('Error desconocido durante la previsualización.', 'md-import-force')),
                array('status' => 500)
            );
        }
    }

    /**
     * Manejador de REST API: Importar archivo
     */
    public function handle_rest_import(WP_REST_Request $request) {
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR REST IMPORT]: Permiso denegado.");
            return new WP_Error('rest_forbidden', __('No tienes permisos para realizar esta acción.', 'md-import-force'), array('status' => 403));
        }

        // Obtener parámetros
        $params = $request->get_params();
        $file_path = isset($params['file_path']) ? sanitize_text_field($params['file_path']) : '';
        $import_id = $file_path; // Usar file_path como import_id

        // >>> INICIO: Limpiar las banderas de detención al iniciar una nueva importación <<<<
        delete_option('md_import_force_stop_all_imports_requested');
        // También limpiar cualquier transient específico para este import_id, si se proporciona
        if (!empty($import_id)) {
            delete_transient('md_import_force_stop_request_' . $import_id);
            MD_Import_Force_Logger::log_message("MD Import Force [INFO REST IMPORT]: Cleared stop flags (global and specific for {$import_id}) before scheduling new import.");
        } else {
            MD_Import_Force_Logger::log_message("MD Import Force [INFO REST IMPORT]: Cleared global stop flag. No specific import_id provided yet for specific transient cleanup.");
        }
        // >>> FIN: Limpiar las banderas de detención al iniciar una nueva importación <<<<

        // Verificar que se haya enviado la ruta del archivo
        if (empty($import_id)) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR REST IMPORT]: No se especificó file_path (import_id).");
            return new WP_Error('no_file_path', __('No se ha especificado la ruta del archivo a importar.', 'md-import-force'), array('status' => 400));
        }

        // Path Traversal Hardening: Verificar que el archivo esté dentro del directorio permitido
        $upload_dir_info = wp_upload_dir();
        $allowed_base_path = trailingslashit($upload_dir_info['basedir'] . '/md-import-force');
        
        // Normalizar rutas para prevenir manipulación
        $normalized_file_path = realpath($file_path);
        $normalized_allowed_base_path = realpath($allowed_base_path);

        if (!$normalized_file_path || !$normalized_allowed_base_path || strpos($normalized_file_path, $normalized_allowed_base_path) !== 0) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR REST IMPORT]: Ruta de archivo inválida (¿intento de path traversal?): {$file_path}");
            return new WP_Error('invalid_file_path', __('La ruta del archivo no es válida o no está permitida.', 'md-import-force'), array('status' => 400));
        }

        // Verificar que el archivo exista (verificación básica, el manejador hará una más profunda)
        if (!file_exists($normalized_file_path)) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR REST IMPORT]: Archivo no encontrado en {$file_path}.");
            return new WP_Error('file_not_found', __('El archivo especificado no existe.', 'md-import-force'), array('status' => 404));
        }

        // Extraer opciones
        $options = array();
        $available_options = array('force_ids', 'force_author', 'handle_attachments', 'generate_thumbnails');
        foreach ($available_options as $option_key) {
            $options[$option_key] = isset($params[$option_key]) ? filter_var($params[$option_key], FILTER_VALIDATE_BOOLEAN) : false;
        }
        
        MD_Import_Force_Logger::log_message("MD Import Force [INFO REST IMPORT]: Programando importación para import_id: {$import_id} con opciones: " . json_encode($options));

        // Intentar utilizar el gestor de trabajos basado en Action Scheduler si está disponible
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-job-manager.php';
        $job_manager = MD_Import_Force_Job_Manager::get_instance();
        
        // El gestor de trabajos usará Action Scheduler si está disponible, o caerá de nuevo a WP Cron
        $job_manager->schedule_import($import_id, $options);

        // Inicializar el tracker de progreso
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-progress-tracker.php';
        // Total items se actualizará después por el handler, iniciamos con 0 o 1 para indicar "un trabajo grande"
        MD_Import_Force_Progress_Tracker::initialize_progress($import_id, 1, __('Importación en cola...', 'md-import-force'));
        // initialize_progress ya setea el estado a 'queued'

        $response_data = array(
            'success' => true,
            'message' => __('La importación ha sido programada y se ejecutará en segundo plano.', 'md-import-force'),
            'import_id' => $import_id, // Devolver el import_id al cliente
            'file_path' => $import_id, // Mantener file_path por si el JS lo usa directamente
            'status' => 'queued'
        );
        
        $response = new WP_REST_Response($response_data, 202); // 202 Accepted
        MD_Import_Force_Logger::log_message("MD Import Force [INFO REST IMPORT]: Importación programada para {$import_id}. Respuesta 202 enviada.");
        return $response;
    }

    /**
     * Ejecuta la importación en segundo plano (llamado por WP Cron).
     * El primer argumento $args[0] será 'import_id', el segundo $args[1] será 'options'
     * Corregido para aceptar un array de argumentos o dos argumentos separados.
     * WordPress pasa los argumentos del array de wp_schedule_single_event como argumentos individuales a la función de callback.
     */
    public static function execute_background_import($import_id, $options) {
        MD_Import_Force_Logger::log_message("MD Import Force [CRON START]: Iniciando importación para import_id: {$import_id}");
        
        // Verificar si se ha solicitado detener todas las importaciones (global o específica)
        if (get_option('md_import_force_stop_all_imports_requested', false) || get_transient('md_import_force_stop_request_' . $import_id)) {
            $stop_reason = get_transient('md_import_force_stop_request_' . $import_id)
                ? "solicitud específica para import_id {$import_id}"
                : "solicitud global";
                
            MD_Import_Force_Logger::log_message("MD Import Force [STOP REQUESTED]: Importación detenida por {$stop_reason} antes de iniciar la importación en segundo plano.");
            MD_Import_Force_Progress_Tracker::update_status(
                $import_id, 
                'stopped', 
                __('Importación detenida por solicitud del usuario antes de comenzar.', 'md-import-force')
            );
            return;
        }
        
        // Verificar si el archivo (identificado por import_id) todavía existe
        if (!file_exists($import_id)) {
            MD_Import_Force_Logger::log_message("MD Import Force [CRON ERROR]: Archivo para import_id {$import_id} no encontrado al iniciar la tarea programada.");
            MD_Import_Force_Progress_Tracker::update_status($import_id, 'failed', __('Error: Archivo de importación no encontrado.', 'md-import-force'));
            return;
        }

        // Cargar el manejador de importación
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-handler.php';
        // El handler ahora necesitará el import_id para el seguimiento del progreso
        $handler = new MD_Import_Force_Handler(); 

        // Actualizar estado a 'processing'
        MD_Import_Force_Progress_Tracker::update_status($import_id, 'processing', __('Importación en progreso...', 'md-import-force'));

        try {
            // Iniciar la importación, pasando el import_id y las opciones
            // El método start_import dentro del handler debería actualizar el progreso más detalladamente
            $result = $handler->start_import($import_id, $options); // $import_id es el file_path

            if ($result['success']) {
                MD_Import_Force_Logger::log_message("MD Import Force [CRON SUCCESS]: Importación completada para {$import_id}. Resultado: " . json_encode($result));
                // $result debería contener las estadísticas finales.
                // Estas estadísticas deben incluir al menos new_count, updated_count, skipped_count.
                // Y idealmente processed_count (total importados) y total_count (total en el archivo).
                MD_Import_Force_Progress_Tracker::mark_complete($import_id, $result); // $result ya contiene las stats
            } else {
                MD_Import_Force_Logger::log_message("MD Import Force [CRON ERROR]: Fallo en la importación para {$import_id}. Mensaje: " . ($result['message'] ?? 'Error desconocido') . " Detalles: " . json_encode($result));
                // Pasar todo el $result como detalles si hay más información útil
                MD_Import_Force_Progress_Tracker::update_status($import_id, 'failed', ($result['message'] ?? __('Error desconocido durante la importación.', 'md-import-force')), $result);
            }
        } catch (Exception $e) {
            MD_Import_Force_Logger::log_message("MD Import Force [CRON FATAL ERROR]: Excepción durante la importación para {$import_id}. Mensaje: " . $e->getMessage());
            MD_Import_Force_Progress_Tracker::update_status($import_id, 'failed', $e->getMessage(), ['exception_trace' => $e->getTraceAsString()]);
        }
        MD_Import_Force_Logger::log_message("MD Import Force [CRON END]: Finalizada tarea de importación para: {$import_id}");
    }

    /**
     * Manejador de REST API: Leer log
     */
    public function handle_rest_read_log(WP_REST_Request $request) {
        $logger_response = MD_Import_Force_Logger::read_error_log();

        if (is_wp_error($logger_response)) {
            return $logger_response; // WP_Error se convierte automáticamente en respuesta de error
        }
        
        // $logger_response es array('success' => true, 'log_content' => 'actual log string')
        // o un WP_Error, o podría ser solo el string del log si la función Logger cambiara.
        if (isset($logger_response['success']) && $logger_response['success'] && isset($logger_response['log_content'])) {
            return rest_ensure_response(array(
                'success' => true,
                'log_content' => $logger_response['log_content'] // Pasar el string directamente
            ));
        } elseif (is_string($logger_response)) { // En caso de que read_error_log devuelva directamente el string
            return rest_ensure_response(array(
                'success' => true,
                'log_content' => $logger_response
            ));
        } elseif (is_array($logger_response) && isset($logger_response['success']) && !$logger_response['success']) {
            // Caso donde el logger devuelve un array de error, por ejemplo: array('success' => false, 'message' => 'error msg')
             return rest_ensure_response(array(
                'success' => false,
                'log_content' => isset($logger_response['message']) ? $logger_response['message'] : __('No se pudo obtener el contenido del log.', 'md-import-force'),
                'data' => $logger_response // pasar data original para depuración si es necesario
            ));
        }
         else {
            // Fallback genérico si la respuesta del logger no es la esperada
            // o si el log está simplemente vacío (file_get_contents devolvió string vacío)
            $log_output = '';
            $success_status = true; // Asumimos éxito si no hay error explícito

            if (is_array($logger_response) && isset($logger_response['log_content'])) {
                $log_output = $logger_response['log_content']; // Podría ser un string vacío
            } elseif (is_string($logger_response)) {
                $log_output = $logger_response; // String vacío
            } else {
                $log_output = __('No se pudo determinar el contenido del log o estructura inesperada.', 'md-import-force');
                $success_status = false; // Marcamos como no exitoso si la estructura es rara
            }
            
            if(empty(trim($log_output)) && $success_status) {
                 $log_output = __('El log está vacío.', 'md-import-force');
            }

            return rest_ensure_response(array(
                'success' => $success_status,
                'log_content' => $log_output
            ));
        }
    }

    /**
     * Manejador de REST API: Limpiar log
     */
    public function handle_rest_clear_log(WP_REST_Request $request) {
        $result = MD_Import_Force_Logger::clear_error_log();

        if (is_wp_error($result)) {
            return $result;
        } else {
            // MD_Import_Force_Logger::clear_error_log() devuelve:
            // array('success' => true, 'message' => 'Log de errores limpiado con éxito.')
            // o un WP_Error
            if (isset($result['success']) && $result['success']) {
                 return rest_ensure_response(array(
                    'success' => true,
                    'message' => isset($result['message']) ? $result['message'] : __('Log limpiado correctamente.', 'md-import-force')
                ));
            } else {
                return rest_ensure_response(array(
                    'success' => false,
                    'message' => isset($result['message']) ? $result['message'] : __('Error al limpiar el log.', 'md-import-force'),
                    'data' => $result // Para depuración
                ));
            }
        }
    }

    /**
     * Manejador de REST API: Verificar progreso
     */
    public function handle_rest_check_progress(WP_REST_Request $request) {
        // El cliente debe enviar 'import_id' como parámetro GET
        $import_id = $request->get_param('import_id');
        
        if (empty($import_id)) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR REST PROGRESS]: No se proporcionó import_id.");
            return new WP_Error('no_import_id', __('No se proporcionó ID de importación para verificar el progreso.', 'md-import-force'), array('status' => 400));
        }

        // Sanitizar el import_id (que es un file_path)
        $import_id = sanitize_text_field(urldecode($import_id));

        // Path Traversal Hardening: Verificar que el import_id (file_path) esté dentro del directorio permitido
        $upload_dir_info = wp_upload_dir();
        $allowed_base_path = trailingslashit($upload_dir_info['basedir'] . '/md-import-force');
        
        // Normalizar rutas para prevenir manipulación
        $normalized_import_id = realpath($import_id); 
        $normalized_allowed_base_path = realpath($allowed_base_path);

        // En este caso, si el archivo ya no existe (por ejemplo, porque se eliminó después de la importación),
        // realpath devolverá false. Podemos permitir que continúe ya que solo estamos verificando progreso.
        if ($normalized_import_id && $normalized_allowed_base_path && strpos($normalized_import_id, $normalized_allowed_base_path) !== 0) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR REST PROGRESS]: Ruta de archivo inválida (¿intento de path traversal?): {$import_id}");
            return new WP_Error('invalid_import_id', __('El ID de importación no es válido o no está permitido.', 'md-import-force'), array('status' => 400));
        }

        MD_Import_Force_Logger::log_message("MD Import Force [REST PROGRESS]: Verificando progreso para import_id: {$import_id}");
        
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-progress-tracker.php';
        $progress_data = MD_Import_Force_Progress_Tracker::get_progress($import_id);

        if ($progress_data === null || (isset($progress_data['status']) && $progress_data['status'] === 'not_found')) {
             MD_Import_Force_Logger::log_message("MD Import Force [REST PROGRESS]: No se encontró progreso para import_id: {$import_id}.");
            // Podríamos devolver un 404 aquí si el import_id no existe,
            // o simplemente el estado 'not_found' que get_progress ya devuelve.
            // Por consistencia con el JS, devolvemos el objeto de progreso con estado 'not_found'.
        }
         MD_Import_Force_Logger::log_message("MD Import Force [REST PROGRESS]: Datos de progreso para {$import_id}: " . json_encode($progress_data));

        return rest_ensure_response($progress_data);
    }

    /**
     * Manejador de REST API: Limpiar todos los archivos
     */
    public function handle_rest_cleanup_all(WP_REST_Request $request) {
        try {
            // Verificar permisos
            if (!current_user_can('manage_options')) {
                return new WP_Error('rest_forbidden', __('No tienes permisos para realizar esta acción.', 'md-import-force'), array('status' => 403));
            }

            $params = $request->get_params();
            $hours = isset($params['hours']) ? intval($params['hours']) : 24;

            // Validar el número de horas
            if ($hours < 1) {
                $hours = 1;
            } elseif ($hours > 720) { // 30 días máximo
                $hours = 720;
            }

            // Cargar el manejador de importación
            require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-handler.php';
            $handler = new MD_Import_Force_Handler();

            // Realizar la limpieza
            $result = $handler->cleanup_all_import_files($hours);

            $response_data = array(
                'success' => $result['success'],
                'deleted' => $result['deleted'],
                'failed' => $result['failed'],
                'skipped' => $result['skipped'],
                'message' => sprintf(
                    __('Limpieza completada. Se eliminaron %d archivos, fallaron %d y se omitieron %d.', 'md-import-force'),
                    $result['deleted'],
                    $result['failed'],
                    $result['skipped']
                )
            );

            return rest_ensure_response($response_data);
        } catch (Exception $e) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR REST CLEANUP]: " . $e->getMessage());
            return new WP_Error('rest_error', $e->getMessage(), array('status' => 500));
        }
    }

    /**
     * Manejar la lectura del log de errores.
     */
    public function handle_read_log() {
        check_ajax_referer('md_import_force_nonce', 'nonce');

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
        check_ajax_referer('md_import_force_nonce', 'nonce');

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

        // Path Traversal Hardening: Verificar que el target_file esté dentro del directorio permitido
        $allowed_base_path = trailingslashit($upload_dir['basedir'] . '/md-import-force');
        
        // Verificar que el path comienza con el directorio permitido
        if (strpos($target_file, $allowed_base_path) !== 0) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR AJAX PREVIEW]: Ruta de destino inválida: {$target_file}");
            wp_send_json_error(array('message' => __('Error de seguridad: ruta de destino inválida.', 'md-import-force')));
            return;
        }

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

        // Path Traversal Hardening: Verificar que el archivo esté dentro del directorio permitido
        $upload_dir_info = wp_upload_dir();
        $allowed_base_path = trailingslashit($upload_dir_info['basedir'] . '/md-import-force');
        
        // Normalizar rutas para prevenir manipulación
        $normalized_file_path = realpath($file_path);
        $normalized_allowed_base_path = realpath($allowed_base_path);

        if (!$normalized_file_path || !$normalized_allowed_base_path || strpos($normalized_file_path, $normalized_allowed_base_path) !== 0) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR AJAX IMPORT]: Ruta de archivo inválida (¿intento de path traversal?): {$file_path}");
            wp_send_json_error(array('message' => __('La ruta del archivo no es válida o no está permitida.', 'md-import-force')));
        }

        // Verificar que el archivo existe
        if (!file_exists($normalized_file_path)) {
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
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-handler.php'; // Ruta corregida
        $importer = new MD_Import_Force_Handler(); // Clase corregida

        // Realizar la importación (siempre fuerza IDs)
        $result = $importer->start_import($file_path); // Método y parámetros corregidos

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

        // No iniciar un nuevo buffer para evitar problemas
        // Asegurarse de que no haya salida antes de enviar el JSON

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
        // Verificar nonce
        check_ajax_referer('md_import_force_nonce', 'nonce');

        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('No tienes permisos para realizar esta acción.', 'md-import-force')));
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
        // Verificar nonce
        check_ajax_referer('md_import_force_nonce', 'nonce');

        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('No tienes permisos para realizar esta acción.', 'md-import-force')));
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

    /**
     * Maneja la solicitud para detener todas las importaciones.
     * Establece una bandera en las opciones de WordPress y/o un transient específico por import_id,
     * y también cancela tareas programadas con Action Scheduler si está disponible.
     */
    public function handle_stop_imports_request( WP_REST_Request $request ) {
        // Debugging log
        error_log('MD Import Force: handle_stop_imports_request called');
        
        $params = $request->get_params();
        error_log('MD Import Force: Stop imports request params: ' . print_r($params, true));
        
        $import_id = isset($params['import_id']) ? sanitize_text_field($params['import_id']) : '';
        
        // Cargar el gestor de trabajos
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-job-manager.php';
        $job_manager = MD_Import_Force_Job_Manager::get_instance();
        
        // Registrar en el log la solicitud de detención
        if (!empty($import_id)) {
            MD_Import_Force_Logger::log_message("MD Import Force [STOP REQUEST]: Solicitud para detener importación específica con import_id: {$import_id}");
            
            // Establecer un transient específico para este import_id
            // El transient durará 1 hora (3600 segundos) para asegurar que la importación tenga tiempo de detectarlo
            set_transient('md_import_force_stop_request_' . $import_id, true, HOUR_IN_SECONDS);
            
            // También establecemos la bandera global para mayor compatibilidad
            update_option('md_import_force_stop_all_imports_requested', true);
            
            // Intentar cancelar tareas programadas para este import_id específico
            $cancelled_tasks = $job_manager->cancel_imports($import_id);
            
            $message = sprintf(__('Solicitud para detener la importación ID %s recibida. El proceso se detendrá en breve.', 'md-import-force'), $import_id);
            if ($cancelled_tasks > 0) {
                $message .= sprintf(__(' Se han cancelado %d tareas programadas.', 'md-import-force'), $cancelled_tasks);
            }
            
            error_log("MD Import Force: Successfully processed stop request for import_id: {$import_id}");
            
            return new WP_REST_Response(
                array(
                    'success' => true,
                    'message' => $message,
                    'cancelled_tasks' => $cancelled_tasks
                ),
                200
            );
        } else {
            // Comportamiento original: detener todas las importaciones
            MD_Import_Force_Logger::log_message("MD Import Force [STOP REQUEST]: Solicitud para detener todas las importaciones");
            update_option('md_import_force_stop_all_imports_requested', true);
            
            // Intentar cancelar todas las tareas programadas
            $cancelled_tasks = $job_manager->cancel_imports();
            
            $message = __('Solicitud para detener todas las importaciones recibida. Los procesos en curso se detendrán en breve.', 'md-import-force');
            if ($cancelled_tasks > 0) {
                $message .= sprintf(__(' Se han cancelado %d tareas programadas.', 'md-import-force'), $cancelled_tasks);
            }
            
            error_log("MD Import Force: Successfully processed global stop request");
            
            return new WP_REST_Response(
                array(
                    'success' => true,
                    'message' => $message,
                    'cancelled_tasks' => $cancelled_tasks
                ),
                200
            );
        }
    }
}

// Inicializar el plugin
function md_import_force_init() {
    MD_Import_Force::get_instance();
    // Ensure Job Manager is initialized and its hooks are registered
    if (class_exists('MD_Import_Force_Job_Manager')) {
        MD_Import_Force_Job_Manager::get_instance(); 
    }
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
    
    // Limpiar eventos programados
    $timestamp = wp_next_scheduled('md_import_force_cleanup_temp_files');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'md_import_force_cleanup_temp_files');
    }
}
