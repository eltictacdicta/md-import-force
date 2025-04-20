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

// Definir constantes
define('MD_IMPORT_FORCE_VERSION', '1.0.0');
define('MD_IMPORT_FORCE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MD_IMPORT_FORCE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Incluir el inicializador del plugin
require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-initializer.php';

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
        wp_enqueue_script('md-import-force-admin', MD_IMPORT_FORCE_PLUGIN_URL . 'assets/js/admin/index.js', array('jquery'), MD_IMPORT_FORCE_VERSION, true);

        wp_localize_script('md-import-force-admin', 'md_import_force', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('md_import_force_nonce'),
            'plugin_url' => MD_IMPORT_FORCE_PLUGIN_URL,
            'i18n' => array(
                'uploading' => __('Subiendo archivo...', 'md-import-force'),
                'importing' => __('Importando contenido...', 'md-import-force'),
                'success' => __('La importación se ha realizado con éxito', 'md-import-force'),
                'error' => __('Error en la importación', 'md-import-force'),
                'completed' => __('Importación completada', 'md-import-force'),
                'processing' => __('Procesando elemento', 'md-import-force'),
            )
        ));
    }

    /**
     * Mostrar página de administración
     */
    public function display_admin_page() {
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'templates/admin-page.php';
    }
}

// Inicializar el plugin
function md_import_force_init() {
    MD_Import_Force_Initializer::init();
}
add_action('plugins_loaded', 'md_import_force_init');

// Activación del plugin
register_activation_hook(__FILE__, 'md_import_force_activate');
function md_import_force_activate() {
    MD_Import_Force_Initializer::activate();
}

// Desactivación del plugin
register_deactivation_hook(__FILE__, 'md_import_force_deactivate');
function md_import_force_deactivate() {
    MD_Import_Force_Initializer::deactivate();
}
