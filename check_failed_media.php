<?php
/**
 * Script para consultar los medios que fallaron en la importación
 */

// Cargar WordPress
require_once(__DIR__ . '/../../../wp-config.php');

$import_run_guid = '6d6ba31d-308e-4fba-a898-1b915ac1223e';

global $wpdb;

// Consultar medios fallidos
$failed_media = $wpdb->get_results($wpdb->prepare("
    SELECT id, post_id, original_post_id_from_file, media_type, original_url, last_attempt_message, attempts, created_at, updated_at
    FROM wp_md_import_force_media_queue 
    WHERE import_run_guid = %s AND status = 'failed'
    ORDER BY id
", $import_run_guid));

echo "=== MEDIOS QUE FALLARON EN LA IMPORTACIÓN ===\n\n";
echo "GUID de importación: " . $import_run_guid . "\n";
echo "Total de medios fallidos: " . count($failed_media) . "\n\n";

if (!empty($failed_media)) {
    foreach ($failed_media as $media) {
        echo "--- MEDIO FALLIDO #" . $media->id . " ---\n";
        echo "Post ID: " . $media->post_id . "\n";
        echo "Post ID original del archivo: " . $media->original_post_id_from_file . "\n";
        echo "Tipo de medio: " . $media->media_type . "\n";
        echo "URL original: " . $media->original_url . "\n";
        echo "Mensaje de error: " . $media->last_attempt_message . "\n";
        echo "Intentos: " . $media->attempts . "\n";
        echo "Creado: " . $media->created_at . "\n";
        echo "Actualizado: " . $media->updated_at . "\n";
        
        // Obtener información del post
        $post = get_post($media->post_id);
        if ($post) {
            echo "Título del post: " . $post->post_title . "\n";
        }
        
        echo "\n";
    }
} else {
    echo "No se encontraron medios fallidos para este GUID.\n";
}

// Estadísticas generales
$stats = $wpdb->get_results($wpdb->prepare("
    SELECT status, COUNT(*) as count
    FROM wp_md_import_force_media_queue 
    WHERE import_run_guid = %s
    GROUP BY status
", $import_run_guid));

echo "=== ESTADÍSTICAS GENERALES ===\n";
foreach ($stats as $stat) {
    echo ucfirst($stat->status) . ": " . $stat->count . "\n";
} 