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
require_once plugin_dir_path(__FILE__) . 'includes/class-md-import-force-media-queue-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-md-import-force-comment-importer.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-md-import-force-progress-tracker.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-md-import-force-job-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-md-import-force-content-updater.php';
require_once plugin_dir_path(__FILE__) . 'includes/db-schema.php';

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

        // Hook para el procesamiento de medios
        add_action('md_import_force_process_media_batch', array('MD_Import_Force_Media_Queue_Manager', 'process_media_batch'), 10, 4);

        // Hook para la actualización de contenido de posts con URLs de medios importados
        add_action('md_import_force_update_post_content_media_urls', array('MD_Import_Force_Content_Updater', 'process_content_update_batch'), 10, 4);

        // Asegurar que la tabla de cola de medios exista (se ejecuta en cada carga, dbDelta es eficiente)
        add_action('plugins_loaded', 'mdif_create_media_queue_table', 5); // Prioridad temprana pero después de cargar clases básicas

        // Los manejadores AJAX antiguos han sido eliminados en favor de la REST API
    }

    /**
     * Registrar endpoints de la REST API
     */
    public function register_rest_routes() {
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
            },
            'args' => array()
        ));

        register_rest_route('md-import-force/v1', '/log', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'handle_rest_clear_log'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'args' => array()
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

        register_rest_route('md-import-force/v1', '/stop-imports', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_stop_imports_request'),
            'permission_callback' => function () {
                return current_user_can('manage_options');
            }
        ));
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
            // 'ajax_url' => admin_url('admin-ajax.php'), // Eliminado ya que no se usa
            'rest_url' => rest_url('md-import-force/v1/'),
            'nonce' => wp_create_nonce('md_import_force_nonce'), // Puede que ya no sea necesario si todo es REST
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
        $params = $request->get_json_params();
        $file_path = sanitize_text_field($params['file_path'] ?? '');
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
        $available_options = array('force_ids', 'force_author', 'handle_attachments', 'generate_thumbnails', 'import_only_missing');
        foreach ($available_options as $option_key) {
            if ($option_key === 'import_only_missing') {
                // Procesar específicamente esta opción para asegurar que llega correctamente
                $options[$option_key] = isset($params[$option_key]) ? (($params[$option_key] === '1' || $params[$option_key] === 1 || $params[$option_key] === true) ? true : false) : false;
                MD_Import_Force_Logger::log_message("MD Import Force [REST IMPORT OPTIONS]: import_only_missing recibido: " . var_export($params[$option_key] ?? 'no definido', true) . ", procesado como: " . var_export($options[$option_key], true));
            } else {
                $options[$option_key] = isset($params[$option_key]) ? filter_var($params[$option_key], FILTER_VALIDATE_BOOLEAN) : false;
            }
        }
        
        MD_Import_Force_Logger::log_message("MD Import Force [INFO REST IMPORT]: Programando importación para import_id: {$import_id} con opciones: " . json_encode($options));

        // >>> INICIO: Calcular total de posts y actualizar progreso inicial <<<
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-file-processor.php';
        $file_processor = new MD_Import_Force_File_Processor();
        $total_items_real = 0;
        $import_data_for_count = null; // Para evitar posible error si read_file falla antes de asignación

        try {
            // Usar $normalized_file_path que ya fue validado y es un path real
            $import_data_for_count = $file_processor->read_file($normalized_file_path); 
            
            if (is_array($import_data_for_count) && isset($import_data_for_count[0]) && isset($import_data_for_count[0]['posts'])) { // ZIP con múltiples JSONs
                foreach($import_data_for_count as $single_data_check) {
                    $total_items_real += count($single_data_check['posts'] ?? []);
                }
            } elseif (isset($import_data_for_count['posts'])) { // JSON único
                $total_items_real = count($import_data_for_count['posts']);
            }
            MD_Import_Force_Logger::log_message("MD Import Force [INFO REST IMPORT]: Total de posts calculado en handle_rest_import: {$total_items_real} para archivo {$normalized_file_path}");
        } catch (Exception $e) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR REST IMPORT]: Error al leer archivo para contar posts en handle_rest_import ({$normalized_file_path}): " . $e->getMessage());
            return new WP_Error('file_read_error_for_count', __('Error al leer el archivo para determinar el total de elementos. Verifique el log para más detalles.', 'md-import-force'), array('status' => 500));
        }

        // No es necesario verificar $total_items_real === 0 aquí como error fatal, 
        // ya que Job Manager también lo hará y podría ser una importación de archivo vacío intencional.
        // Simplemente se logueará y el progreso se inicializará con 0.

        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-progress-tracker.php';
        MD_Import_Force_Progress_Tracker::initialize_progress(
            $import_id, 
            $total_items_real, 
            __('Importación preparada, esperando inicio en segundo plano...', 'md-import-force') // Mensaje actualizado
        );
        MD_Import_Force_Logger::log_message("MD Import Force [INFO REST IMPORT]: Progreso inicializado desde handle_rest_import con total: {$total_items_real} para import_id: {$import_id}");
        // La línea original MD_Import_Force_Progress_Tracker::initialize_progress($import_id, 1, ...); SE ELIMINA
        // >>> FIN: Calcular total de posts y actualizar progreso inicial <<<

        // Generar un GUID único para esta ejecución de importación completa
        $import_run_guid = wp_generate_uuid4();
        MD_Import_Force_Logger::log_message("MD Import Force [REST IMPORT]: Nuevo GUID de ejecución de importación generado: {$import_run_guid} para archivo: {$file_path}");

        // Programar la importación en segundo plano
        // Pasar el import_run_guid a Job_Manager para que lo pase a los trabajos de Action Scheduler
        $job_manager_instance = MD_Import_Force_Job_Manager::get_instance();
        $job_scheduled = $job_manager_instance->schedule_import($file_path, $options, $import_run_guid);

        if ($job_scheduled) {
            // Inicializar el tracker de progreso
            require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-progress-tracker.php';
            // Total items se actualizará después por el handler, iniciamos con 0 o 1 para indicar "un trabajo grande"
            // MD_Import_Force_Progress_Tracker::initialize_progress($import_id, 1, __('Importación en cola...', 'md-import-force')); // ESTA LÍNEA SE ELIMINA
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
        } else {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR REST IMPORT]: Error al programar la importación en segundo plano.");
            return new WP_Error('import_scheduling_error', __('Error al programar la importación en segundo plano.', 'md-import-force'), array('status' => 500));
        }
    }

    /**
     * Ejecuta la importación en segundo plano (hook para Action Scheduler)
     * Esta función es estática para ser llamada por Action Scheduler sin instanciar toda la clase.
     * MODIFICADO: Añadir $import_run_guid
     */
    public static function execute_background_import($import_id, $options, $import_run_guid) {
        MD_Import_Force_Logger::log_message("MD Import Force [BACKGROUND JOB]: Iniciando ejecución para import_id: {$import_id}, GUID: {$import_run_guid}");
        
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-handler.php';
        $handler = new MD_Import_Force_Handler();

        // Pasar el import_run_guid al handler, o modificar process_batch para aceptarlo
        // Por ahora, podríamos hacerlo una propiedad temporal si el handler se instancia por job.
        // Sin embargo, si JobManager es quien llama a process_batch, JobManager debe pasarlo.
        // Lo ideal es que JobManager::execute_background_import llame a un método en Handler que acepte el GUID.

        // Esta llamada es al Job Manager, que luego podría llamar a process_batch del Handler.
        // Necesitamos asegurar que JobManager pasa el GUID a process_batch.
        MD_Import_Force_Job_Manager::process_import_batch_action($import_id, $options, $import_run_guid);
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
        try {
            $result = MD_Import_Force_Logger::clear_error_log();

            if (is_wp_error($result)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('MD Import Force: clear_error_log returned WP_Error: ' . $result->get_error_message());
                }
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
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('MD Import Force: Log clear failed, result: ' . print_r($result, true));
                    }
                    return rest_ensure_response(array(
                        'success' => false,
                        'message' => isset($result['message']) ? $result['message'] : __('Error al limpiar el log.', 'md-import-force'),
                        'data' => $result // Para depuración
                    ));
                }
            }
        } catch (Exception $e) {
            error_log('MD Import Force: Exception in handle_rest_clear_log: ' . $e->getMessage());
            return new WP_Error('clear_log_exception', $e->getMessage(), array('status' => 500));
        } catch (Error $e) {
            error_log('MD Import Force: Fatal error in handle_rest_clear_log: ' . $e->getMessage());
            return new WP_Error('clear_log_fatal_error', $e->getMessage(), array('status' => 500));
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

/**
 * Función que se ejecuta al activar el plugin
 */
function md_import_force_activate() {
    // Crear o verificar la tabla de log
    MD_Import_Force_Logger::create_log_table_if_needed();
    // Crear o verificar la tabla de progreso
    MD_Import_Force_Progress_Tracker::create_progress_table_if_needed();
    // Crear o verificar la tabla de cola de medios
    mdif_create_media_queue_table();

    // Aquí podrías añadir cualquier otra configuración inicial necesaria
    MD_Import_Force_Logger::log_message("MD Import Force: Plugin activado.");
}
register_activation_hook(__FILE__, 'md_import_force_activate');

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
