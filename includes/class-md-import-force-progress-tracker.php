<?php
/**
 * Clase para rastrear el progreso de importación
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

class MD_Import_Force_Progress_Tracker {

    private $import_session_id = '';

    public function __construct() {
        $this->import_session_id = uniqid('import_');
    }

    /**
     * Actualiza el archivo de progreso con la información actual.
     *
     * @param int $current Elemento actual que se está procesando
     * @param int $total Total de elementos a procesar
     * @param string|null $current_item Información sobre el elemento actual
     */
    public function send_progress_update($current, $total, $current_item = null) {
        // Calcular el porcentaje de progreso
        $percent = ($total > 0) ? round(($current / $total) * 100) : 0;

        // Preparar los datos para enviar
        $data = [
            'current' => $current,
            'total' => $total,
            'percent' => $percent,
            'current_item' => $current_item,
            'timestamp' => microtime(true),
            'status' => 'importing'
        ];

        // Guardar los datos en un archivo temporal
        $this->save_progress_data($data);
    }

    /**
     * Guarda los datos de progreso en un archivo temporal.
     *
     * @param array $data Datos de progreso a guardar
     */
    private function save_progress_data($data) {
        // Crear directorio de archivos temporales si no existe
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/md-import-force-temp';
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }

        // Generar un ID único para la sesión de importación actual si no existe
        if (empty($this->import_session_id)) {
            $this->import_session_id = uniqid('import_');
            // Guardar el ID de sesión en una opción temporal
            update_option('md_import_force_current_session', $this->import_session_id, false);
        }

        // Ruta al archivo de progreso
        $progress_file = $temp_dir . '/' . $this->import_session_id . '_progress.json';

        // Guardar los datos en el archivo
        file_put_contents($progress_file, json_encode($data));
    }
}
