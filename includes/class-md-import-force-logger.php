<?php
/**
 * Clase para manejar el registro de logs del plugin
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

// Asegurar que las funciones de WordPress están disponibles
if (!function_exists('current_user_can')) {
    require_once(ABSPATH . 'wp-includes/pluggable.php');
}

class MD_Import_Force_Logger {

    /**
     * Registra un mensaje en el archivo de log
     *
     * @param string $message Mensaje a registrar
     */
    public static function log_message( $message ) {
        $log_file = __DIR__ . '/../logs/md-import-force.log';
        $timestamp = date( 'Y-m-d H:i:s' );

        if (is_array($message) || is_object($message)) {
            $message_str = print_r($message, true);
        } else {
            $message_str = $message;
        }

        $log_entry = "[{$timestamp}] {$message_str}" . PHP_EOL;

        // Ensure the logs directory exists
        $log_dir = dirname( $log_file );
        if ( ! file_exists( $log_dir ) ) {
            mkdir( $log_dir, 0755, true );
        }

        file_put_contents( $log_file, $log_entry, FILE_APPEND );
    }

    /**
     * Lee el contenido del log de errores del plugin.
     *
     * @return array|WP_Error Contenido del log o error
     */
    public static function read_error_log() {
        if (!current_user_can('manage_options')) {
            return new WP_Error('permission_denied', __('No tienes permisos para ver el log.', 'md-import-force'));
        }

        // Ruta al archivo de log personalizado
        $log_path = __DIR__ . '/../logs/md-import-force.log';

        if (!file_exists($log_path)) {
            // Loggear que el archivo no existe usando el logger personalizado
            self::log_message(__('El archivo de log no fue encontrado en la ruta esperada: ', 'md-import-force') . $log_path);
            return new WP_Error('log_not_found', __('El archivo de log no fue encontrado en la ruta esperada: ', 'md-import-force') . $log_path);
        }

        if (!is_readable($log_path)) {
            self::log_message(__('No tienes permisos para leer el archivo de log en la ruta: ', 'md-import-force') . $log_path);
            return new WP_Error('permission_denied', __('No tienes permisos para leer el archivo de log.', 'md-import-force'));
        }

        $content = file_get_contents($log_path);

        return array('success' => true, 'log_content' => $content);
    }

    /**
     * Limpia el contenido del log de errores del plugin.
     *
     * @return array|WP_Error Resultado de la operación o error
     */
    public static function clear_error_log() {
        if (!current_user_can('manage_options')) {
            return new WP_Error('permission_denied', __('No tienes permisos para limpiar el log.', 'md-import-force'));
        }

        // Ruta al archivo de log personalizado
        $log_path = __DIR__ . '/../logs/md-import-force.log';

        if (!file_exists($log_path)) {
            // Use error_log instead of self::log_message to avoid circular dependency
            error_log('MD Import Force: Log file not found at path: ' . $log_path);
            return new WP_Error('log_not_found', __('El archivo de log no fue encontrado en la ruta esperada: ', 'md-import-force') . $log_path);
        }

        if (!is_writable($log_path)) {
            // Use error_log instead of self::log_message to avoid circular dependency
            error_log('MD Import Force: Log file not writable at path: ' . $log_path);
            return new WP_Error('permission_denied', __('No tienes permisos para escribir en el archivo de log.', 'md-import-force'));
        }

        if (file_put_contents($log_path, '') === false) {
            // Use error_log instead of self::log_message to avoid circular dependency
            error_log('MD Import Force: Failed to clear log file at path: ' . $log_path);
            return new WP_Error('clear_failed', __('No se pudo limpiar el archivo de log.', 'md-import-force'));
        }

        // Log file cleared successfully - no need to log this to prevent FastCGI errors

        return array('success' => true, 'message' => __('Log de errores limpiado con éxito.', 'md-import-force'));
    }

    /**
     * Crea la tabla de log si no existe o la actualiza si es necesario.
     */
    public static function create_log_table_if_needed() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'mdif_logs';
        
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            timestamp datetime NOT NULL,
            level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}
