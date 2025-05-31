<?php
/**
 * Script para limpiar todo y preparar una importación fresca
 */

// Cargar WordPress
require_once(dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-config.php');

echo "=== LIMPIEZA PARA IMPORTACIÓN FRESCA ===\n\n";

global $wpdb;

// 1. Limpiar cola de medios
echo "1. Limpiando cola de medios...\n";
$deleted_media = $wpdb->query("DELETE FROM {$wpdb->prefix}md_import_force_media_queue");
echo "   - Eliminados $deleted_media elementos de la cola de medios\n";

// 2. Cancelar todas las tareas de Action Scheduler relacionadas
echo "\n2. Cancelando tareas de Action Scheduler...\n";
if (function_exists('as_unschedule_all_actions')) {
    $cancelled_media = as_unschedule_all_actions('md_import_force_process_media_batch', [], 'md-import-force-media');
    $cancelled_import = as_unschedule_all_actions('md_import_force_process_import_batch', [], 'md-import-force');
    $cancelled_main = as_unschedule_all_actions('md_import_force_process_import', [], 'md-import-force');
    echo "   - Canceladas $cancelled_media tareas de medios\n";
    echo "   - Canceladas $cancelled_import tareas de importación por lotes\n";
    echo "   - Canceladas $cancelled_main tareas de importación principal\n";
} else {
    echo "   - Action Scheduler no disponible\n";
}

// 3. Limpiar archivos de progreso
echo "\n3. Limpiando archivos de progreso...\n";
$upload_dir = wp_upload_dir();
$progress_dir = $upload_dir['basedir'] . '/md-import-force-progress/';
if (is_dir($progress_dir)) {
    $files = glob($progress_dir . '*');
    $deleted_files = 0;
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
            $deleted_files++;
        }
    }
    echo "   - Eliminados $deleted_files archivos de progreso\n";
} else {
    echo "   - No hay directorio de progreso\n";
}

// 4. Limpiar datos temporales de importación
echo "\n4. Limpiando datos temporales...\n";
$temp_dir = $upload_dir['basedir'] . '/md-import-force-temp/';
if (is_dir($temp_dir)) {
    $files = glob($temp_dir . '*');
    $deleted_temp = 0;
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
            $deleted_temp++;
        }
    }
    echo "   - Eliminados $deleted_temp archivos temporales\n";
} else {
    echo "   - No hay directorio temporal\n";
}

// 5. Limpiar opciones de WordPress relacionadas
echo "\n5. Limpiando opciones de WordPress...\n";
delete_option('md_import_force_stop_all_imports_requested');
delete_transient('md_import_force_stop_request_*'); // Nota: esto no elimina todos los transients con wildcard
echo "   - Opciones de detención limpiadas\n";

// 6. Mostrar estadísticas de posts importados (opcional para referencia)
echo "\n6. Estadísticas de posts (para referencia):\n";
$post_counts = $wpdb->get_results("
    SELECT post_type, post_status, COUNT(*) as count 
    FROM {$wpdb->posts} 
    WHERE post_date > DATE_SUB(NOW(), INTERVAL 1 DAY)
    GROUP BY post_type, post_status
    ORDER BY post_type, post_status
", ARRAY_A);

if ($post_counts) {
    foreach ($post_counts as $count) {
        echo "   - {$count['post_type']} ({$count['post_status']}): {$count['count']}\n";
    }
} else {
    echo "   - No hay posts recientes\n";
}

echo "\n=== LIMPIEZA COMPLETADA ===\n";
echo "El sistema está listo para una nueva importación.\n";
echo "Puedes proceder a importar tu archivo desde la interfaz de WordPress.\n\n";

// Opcional: Mostrar archivos de importación disponibles
$import_files_dir = $upload_dir['basedir'] . '/md-import-force/';
if (is_dir($import_files_dir)) {
    $import_files = glob($import_files_dir . '*.{json,zip}', GLOB_BRACE);
    if ($import_files) {
        echo "Archivos de importación disponibles:\n";
        foreach ($import_files as $file) {
            $size = filesize($file);
            $date = date('Y-m-d H:i:s', filemtime($file));
            echo "   - " . basename($file) . " (" . size_format($size) . ") - $date\n";
        }
    }
}
?> 