<?php
/**
 * Script para verificar el estado de los medios
 */

// Cargar WordPress
require_once(__DIR__ . '/../../../wp-config.php');

global $wpdb;

// Verificar todos los estados
$all_status = $wpdb->get_results("
    SELECT status, COUNT(*) as count 
    FROM wp_md_import_force_media_queue 
    GROUP BY status
");

echo "=== ESTADOS DE MEDIOS EN LA COLA ===\n";
foreach ($all_status as $status) {
    echo $status->status . ": " . $status->count . "\n";
}

// Verificar GUIDs más recientes
$recent_guids = $wpdb->get_results("
    SELECT import_run_guid, status, COUNT(*) as count 
    FROM wp_md_import_force_media_queue 
    GROUP BY import_run_guid, status 
    ORDER BY import_run_guid DESC 
    LIMIT 20
");

echo "\n=== GUIDS RECIENTES ===\n";
foreach ($recent_guids as $guid) {
    echo $guid->import_run_guid . " - " . $guid->status . ": " . $guid->count . "\n";
}

// Verificar si hay algún medio con intentos > 1 (que podría indicar fallos)
$high_attempts = $wpdb->get_results("
    SELECT id, import_run_guid, post_id, media_type, original_url, status, attempts, last_attempt_message
    FROM wp_md_import_force_media_queue 
    WHERE attempts > 1
    ORDER BY attempts DESC
    LIMIT 10
");

echo "\n=== MEDIOS CON MÚLTIPLES INTENTOS ===\n";
foreach ($high_attempts as $media) {
    echo "ID: " . $media->id . " - GUID: " . $media->import_run_guid . " - Status: " . $media->status . " - Intentos: " . $media->attempts . "\n";
    echo "URL: " . $media->original_url . "\n";
    echo "Mensaje: " . $media->last_attempt_message . "\n\n";
} 