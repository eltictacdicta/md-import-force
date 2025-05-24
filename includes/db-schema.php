<?php
/**
 * Funciones para la creación y manejo del esquema de base de datos personalizado.
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

/**
 * Nombre de la tabla para la cola de procesamiento de medios.
 */
function mdif_get_media_queue_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'md_import_force_media_queue';
}

/**
 * Crea/actualiza la tabla de la cola de procesamiento de medios si no existe.
 */
function mdif_create_media_queue_table() {
    global $wpdb;
    $table_name = mdif_get_media_queue_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        import_run_guid VARCHAR(36) NOT NULL,
        post_id BIGINT(20) UNSIGNED NOT NULL,
        original_post_id_from_file VARCHAR(255) NULL,
        media_type VARCHAR(50) NOT NULL,
        original_url TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        new_attachment_id BIGINT(20) UNSIGNED NULL,
        new_media_url TEXT NULL,
        attempts INT(11) NOT NULL DEFAULT 0,
        last_attempt_message TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY import_run_guid (import_run_guid),
        KEY status (status),
        KEY post_id (post_id),
        KEY original_url (original_url(255))
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Verificar si la tabla fue creada (o ya existía)
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    if ($table_exists) {
        MD_Import_Force_Logger::log_message("MD Import Force [DB]: Tabla '$table_name' verificada/creada exitosamente.");
    } else {
        MD_Import_Force_Logger::log_message("MD Import Force [DB ERROR]: La tabla '$table_name' NO pudo ser creada.");
    }
}

/**
 * Podríamos añadir aquí funciones para interactuar con la tabla,
 * pero es mejor tener una clase dedicada como MD_Import_Force_Media_Queue_Manager.
 */

