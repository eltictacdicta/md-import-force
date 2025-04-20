<?php
/**
 * Clase para rastrear el progreso de importación
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

class MD_Import_Force_Progress_Tracker {

    private $import_session_id = '';
    private $temp_dir = '';

    public function __construct() {
        // Generar un ID único para la sesión de importación
        $this->import_session_id = uniqid('import_');

        // Crear directorio temporal si no existe
        $this->init_temp_directory();

        // Limpiar datos de progreso anteriores
        $this->clear_previous_progress_data();

        // Guardar el ID de sesión en una opción temporal inmediatamente
        if (function_exists('update_option')) {
            update_option('md_import_force_current_session', $this->import_session_id, false);
        }

        // Guardar también en un archivo para mayor seguridad
        $session_file = $this->temp_dir . '/current_session.txt';
        @file_put_contents($session_file, $this->import_session_id);

        // Inicializar datos de progreso con valores por defecto
        $this->initialize_progress_data();

        // Registrar en el log para depuración
        if (class_exists('MD_Import_Force_Logger')) {
            MD_Import_Force_Logger::log_message("MD Import Force: Iniciando seguimiento de progreso con ID: {$this->import_session_id}");
        }
    }

    /**
     * Inicializa el directorio temporal
     */
    private function init_temp_directory() {
        // Intentar usar wp_upload_dir si está disponible
        if (function_exists('wp_upload_dir')) {
            $upload_dir = wp_upload_dir();
            $this->temp_dir = $upload_dir['basedir'] . '/md-import-force-temp';
        } else {
            // Fallback a directorio del plugin
            $this->temp_dir = dirname(dirname(__FILE__)) . '/temp';
        }

        // Crear directorio si no existe
        if (!file_exists($this->temp_dir)) {
            if (function_exists('wp_mkdir_p')) {
                wp_mkdir_p($this->temp_dir);
            } else {
                @mkdir($this->temp_dir, 0755, true);
            }
        }
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

        // Enviar datos directamente al navegador si estamos en una solicitud AJAX
        if (defined('DOING_AJAX') && DOING_AJAX) {
            echo "\n<progress-update data-timestamp=\"" . time() . "\">" . json_encode($data) . "</progress-update>\n";
            if (function_exists('ob_flush')) ob_flush();
            if (function_exists('flush')) flush();
        }
    }

    /**
     * Guarda los datos de progreso en un archivo temporal.
     *
     * @param array $data Datos de progreso a guardar
     */
    private function save_progress_data($data) {
        // Asegurarse de que el directorio temporal existe
        if (!file_exists($this->temp_dir)) {
            $this->init_temp_directory();
        }

        // Ruta al archivo de progreso
        $progress_file = $this->temp_dir . '/' . $this->import_session_id . '_progress.json';

        // Guardar los datos en el archivo
        @file_put_contents($progress_file, json_encode($data));

        // Guardar también en la opción de WordPress si está disponible
        if (function_exists('update_option')) {
            update_option('md_import_force_progress_data', $data, false);
        }
    }

    /**
     * Limpia los datos de progreso anteriores
     */
    private function clear_previous_progress_data() {
        // Limpiar la opción de WordPress si está disponible
        if (function_exists('delete_option')) {
            delete_option('md_import_force_progress_data');
        }

        // Intentar eliminar archivos de progreso anteriores
        if (file_exists($this->temp_dir)) {
            $files = glob($this->temp_dir . '/*_progress.json');
            if (is_array($files)) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
        }

        // Registrar en el log
        if (class_exists('MD_Import_Force_Logger')) {
            MD_Import_Force_Logger::log_message("MD Import Force: Datos de progreso anteriores limpiados");
        }
    }

    /**
     * Inicializa los datos de progreso con valores por defecto
     */
    private function initialize_progress_data() {
        $data = [
            'current' => 0,
            'total' => 0,
            'percent' => 0,
            'current_item' => 'Iniciando importación...',
            'timestamp' => microtime(true),
            'status' => 'starting'
        ];

        $this->save_progress_data($data);
    }

    /**
     * Marca la importación como completada
     */
    public function mark_as_completed() {
        $data = [
            'current' => 100,
            'total' => 100,
            'percent' => 100,
            'current_item' => 'Importación completada',
            'timestamp' => microtime(true),
            'status' => 'completed'
        ];

        $this->save_progress_data($data);

        // Enviar datos directamente al navegador si estamos en una solicitud AJAX
        if (defined('DOING_AJAX') && DOING_AJAX) {
            echo "\n<progress-update data-timestamp=\"" . time() . "\">" . json_encode($data) . "</progress-update>\n";
            if (function_exists('ob_flush')) ob_flush();
            if (function_exists('flush')) flush();

            // Registrar en el log para depuración
            if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message("MD Import Force: Importación marcada como completada y enviada al navegador");
            }
        }
    }
}
