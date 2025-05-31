<?php
/**
 * Script temporal para programar el procesamiento de medios manualmente
 */

// Cargar WordPress
require_once(dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-config.php');

// Verificar que Action Scheduler esté disponible
if (!function_exists('as_schedule_single_action')) {
    die('Action Scheduler no está disponible');
}

// GUID con más medios pendientes
$import_run_guid = '172481a6-a595-4ad9-ad1f-e5c7bcf01c44';

// Necesitamos encontrar el import_id asociado a este GUID
// Miramos en la cola de medios para obtener un ejemplo
global $wpdb;
$sample_row = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}md_import_force_media_queue WHERE import_run_guid = %s LIMIT 1",
    $import_run_guid
), ARRAY_A);

if (!$sample_row) {
    die("No se encontraron medios para el GUID: $import_run_guid");
}

echo "Muestra de la cola de medios:\n";
print_r($sample_row);

// Para obtener el import_id, necesitamos hacer una estimación basada en los archivos de importación
$upload_dir = wp_upload_dir();
$import_files_dir = $upload_dir['basedir'] . '/md-import-force/';

if (is_dir($import_files_dir)) {
    $files = glob($import_files_dir . '*.{json,zip}', GLOB_BRACE);
    if (!empty($files)) {
        // Usar el archivo más reciente como import_id por defecto
        $import_id = max($files);
        echo "Import ID detectado: $import_id\n";
    } else {
        $import_id = '/var/www/html/wp-content/uploads/md-import-force/import-recent.json'; // Fallback
        echo "Usando import_id fallback: $import_id\n";
    }
} else {
    $import_id = '/var/www/html/wp-content/uploads/md-import-force/import-recent.json'; // Fallback
    echo "Usando import_id fallback: $import_id\n";
}

// Opciones de importación (asumimos que handle_attachments está activo)
$options = [
    'handle_attachments' => true,
    'force_ids' => true,
    'force_author' => false,
    'generate_thumbnails' => true,
    'import_only_missing' => false
];

echo "Programando procesamiento de medios para GUID: $import_run_guid\n";
echo "Import ID: $import_id\n";
echo "Opciones: " . json_encode($options) . "\n";

// Programar la acción de procesamiento de medios
$action_id = as_schedule_single_action(
    time() + 5, // Ejecutar en 5 segundos
    'md_import_force_process_media_batch',
    array(
        'import_run_guid' => $import_run_guid,
        'import_id' => $import_id,
        'options' => $options,
        'batch_offset' => 0 // Comenzar desde el primer lote
    ),
    'md-import-force-media' // Grupo específico para medios
);

if ($action_id) {
    echo "✅ Procesamiento de medios programado exitosamente!\n";
    echo "Action ID: $action_id\n";
    echo "GUID: $import_run_guid\n";
    echo "El procesamiento comenzará en 5 segundos...\n";
} else {
    echo "❌ Error al programar procesamiento de medios\n";
}
?> 