<?php
/**
 * Clase para manejar la gestión de archivos del plugin
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

class MD_Import_Force_File_Manager {

    /**
     * Directorio base para archivos de importación
     */
    private $import_dir;

    /**
     * Directorio temporal para archivos de progreso
     */
    private $temp_dir;

    /**
     * Constructor
     */
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->import_dir = $upload_dir['basedir'] . '/md-import-force/';
        $this->temp_dir = $upload_dir['basedir'] . '/md-import-force-temp/';
        
        // Asegurar que los directorios existan
        $this->ensure_directories();
    }

    /**
     * Asegura que los directorios necesarios existan
     */
    public function ensure_directories() {
        if (!file_exists($this->import_dir)) {
            wp_mkdir_p($this->import_dir);
        }
        
        if (!file_exists($this->temp_dir)) {
            wp_mkdir_p($this->temp_dir);
        }
    }

    /**
     * Guarda un archivo subido en el directorio de importación
     * 
     * @param array $file Datos del archivo subido ($_FILES['import_file'])
     * @param string $prefix Prefijo para el nombre del archivo
     * @return array Resultado de la operación
     */
    public function save_uploaded_file($file, $prefix = 'import') {
        // Verificar que se haya subido un archivo
        if (empty($file) || empty($file['tmp_name'])) {
            return array(
                'success' => false,
                'message' => __('No se ha subido ningún archivo.', 'md-import-force')
            );
        }

        // Generar nombre de archivo único
        $file_name = $prefix . '-' . time();
        
        // Añadir el nombre original si está disponible
        if (!empty($file['name'])) {
            $file_name .= '-' . sanitize_file_name($file['name']);
        } else {
            $file_name .= '.json';
        }
        
        $target_file = $this->import_dir . $file_name;

        // Mover archivo subido
        if (move_uploaded_file($file['tmp_name'], $target_file)) {
            return array(
                'success' => true,
                'message' => __('Archivo subido correctamente.', 'md-import-force'),
                'file_path' => $target_file,
                'file_name' => $file_name
            );
        } else {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR]: Error al mover archivo temporal a {$target_file}");
            return array(
                'success' => false,
                'message' => __('Error al subir el archivo.', 'md-import-force')
            );
        }
    }

    /**
     * Elimina un archivo de importación
     * 
     * @param string $file_path Ruta completa al archivo
     * @return bool True si se eliminó correctamente, false en caso contrario
     */
    public function delete_file($file_path) {
        // Verificar que el archivo existe y está dentro del directorio de importación
        if (!file_exists($file_path) || strpos($file_path, $this->import_dir) !== 0) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR]: Intento de eliminar archivo fuera del directorio de importación: {$file_path}");
            return false;
        }

        $result = @unlink($file_path);
        if ($result) {
            MD_Import_Force_Logger::log_message("MD Import Force: Archivo eliminado correctamente: {$file_path}");
        } else {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR]: No se pudo eliminar el archivo: {$file_path}");
        }
        
        return $result;
    }

    /**
     * Limpia todos los archivos de importación antiguos
     * 
     * @param int $hours_old Eliminar archivos más antiguos que estas horas
     * @return array Resultado de la limpieza con contadores
     */
    public function cleanup_old_files($hours_old = 24) {
        $result = [
            'success' => true,
            'deleted' => 0,
            'failed' => 0,
            'skipped' => 0
        ];

        if (!file_exists($this->import_dir) || !is_dir($this->import_dir)) {
            MD_Import_Force_Logger::log_message("MD Import Force [CLEANUP]: El directorio {$this->import_dir} no existe.");
            $result['success'] = false;
            return $result;
        }

        // Calcular el tiempo límite
        $time_limit = time() - ($hours_old * 3600);

        // Obtener todos los archivos en el directorio
        $files = glob($this->import_dir . '*');
        
        if (!is_array($files) || empty($files)) {
            return $result;
        }

        foreach ($files as $file) {
            // Omitir directorios
            if (is_dir($file)) {
                $result['skipped']++;
                continue;
            }

            // Verificar la antigüedad del archivo
            $file_time = filemtime($file);
            if ($file_time && $file_time < $time_limit) {
                if (@unlink($file)) {
                    $result['deleted']++;
                    MD_Import_Force_Logger::log_message("MD Import Force [CLEANUP]: Archivo eliminado: {$file}");
                } else {
                    $result['failed']++;
                    MD_Import_Force_Logger::log_message("MD Import Force [CLEANUP ERROR]: No se pudo eliminar: {$file}");
                }
            } else {
                $result['skipped']++;
            }
        }

        return $result;
    }

    /**
     * Limpia los archivos temporales de progreso
     * 
     * @return array Resultado de la limpieza
     */
    public function cleanup_temp_files() {
        $result = [
            'success' => true,
            'deleted' => 0,
            'failed' => 0
        ];

        if (!file_exists($this->temp_dir) || !is_dir($this->temp_dir)) {
            return $result;
        }

        // Obtener todos los archivos de progreso
        $files = glob($this->temp_dir . '*_progress.json');
        
        if (!is_array($files) || empty($files)) {
            return $result;
        }

        foreach ($files as $file) {
            if (@unlink($file)) {
                $result['deleted']++;
            } else {
                $result['failed']++;
            }
        }

        // También eliminar el archivo de sesión actual
        $session_file = $this->temp_dir . 'current_session.txt';
        if (file_exists($session_file) && @unlink($session_file)) {
            $result['deleted']++;
        }

        return $result;
    }

    /**
     * Obtiene la ruta al directorio de importación
     * 
     * @return string Ruta al directorio de importación
     */
    public function get_import_dir() {
        return $this->import_dir;
    }

    /**
     * Obtiene la ruta al directorio temporal
     * 
     * @return string Ruta al directorio temporal
     */
    public function get_temp_dir() {
        return $this->temp_dir;
    }
}
