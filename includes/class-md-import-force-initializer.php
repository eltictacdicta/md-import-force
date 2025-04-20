<?php
/**
 * Clase para manejar la inicialización del plugin
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

class MD_Import_Force_Initializer {

    /**
     * Inicializa el plugin
     */
    public static function init() {
        // Cargar las clases necesarias
        self::load_dependencies();

        // Inicializar la clase principal
        MD_Import_Force::get_instance();

        // Inicializar el manejador AJAX
        new MD_Import_Force_Ajax_Handler();
    }

    /**
     * Carga las dependencias del plugin
     */
    private static function load_dependencies() {
        // Incluir clases base
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-logger.php';
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-error-handler.php';
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-file-manager.php';

        // Incluir clases de procesamiento
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-file-processor.php';
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-skipped-items-tracker.php';
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-progress-tracker.php';

        // Incluir clases de importación
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-post-importer.php';
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-taxonomy-importer.php';
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-media-handler.php';
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-comment-importer.php';

        // Incluir clases de gestión
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-import-manager.php';
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-ajax-handler.php';
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-handler.php';
    }

    /**
     * Activación del plugin
     */
    public static function activate() {
        // Crear directorios necesarios
        $file_manager = new MD_Import_Force_File_Manager();
        $file_manager->ensure_directories();

        // Registrar en el log
        if (class_exists('MD_Import_Force_Logger')) {
            MD_Import_Force_Logger::log_message("MD Import Force: Plugin activado");
        }
    }

    /**
     * Desactivación del plugin
     */
    public static function deactivate() {
        // Limpiar opciones si es necesario
        delete_option('md_import_force_progress_data');
        delete_option('md_import_force_current_session');

        // Registrar en el log
        if (class_exists('MD_Import_Force_Logger')) {
            MD_Import_Force_Logger::log_message("MD Import Force: Plugin desactivado");
        }
    }
}
