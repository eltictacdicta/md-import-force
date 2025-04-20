<?php
/**
 * Clase para manejar la importación forzada de contenido (compatible con md-import-export)
 * Versión Simplificada: Procesamiento síncrono, forzado de ID/Slug con funciones WP estándar.
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

// Incluir archivos necesarios de WordPress Core
require_once(ABSPATH . 'wp-admin/includes/post.php');
require_once(ABSPATH . 'wp-admin/includes/taxonomy.php');
require_once(ABSPATH . 'wp-admin/includes/import.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');
require_once(ABSPATH . 'wp-includes/pluggable.php');
require_once(ABSPATH . 'wp-includes/formatting.php');
require_once(ABSPATH . 'wp-includes/general-template.php');

// Incluir clases del plugin
require_once(dirname(__FILE__) . '/class-md-import-force-file-processor.php');
require_once(dirname(__FILE__) . '/class-md-import-force-import-manager.php');

/**
 * Clase principal para manejar la importación de contenido
 *
 * Esta clase coordina el proceso de importación, incluyendo la lectura de archivos,
 * la gestión de la importación de datos y la limpieza de archivos temporales.
 */
class MD_Import_Force_Handler {

    /**
     * Procesador de archivos para leer archivos JSON/ZIP
     */
    private $file_processor;

    /**
     * Gestor de importación para procesar los datos
     */
    private $import_manager;

    /**
     * Gestor de archivos para manejar la limpieza
     */
    private $file_manager;

    /**
     * Constructor
     */
    public function __construct() {
        $this->file_processor = new MD_Import_Force_File_Processor();
        $this->import_manager = new MD_Import_Force_Import_Manager();
        $this->file_manager = new MD_Import_Force_File_Manager();
    }

    /**
     * Previsualiza el contenido del archivo de importación (primer JSON en ZIP o JSON individual).
     * Muestra información del sitio de origen y los primeros registros de posts/páginas.
     *
     * @param string $file_path Ruta al archivo a previsualizar
     * @return array Resultado de la previsualización
     */
    public function preview_import_file($file_path) {
        try {
            // Registrar inicio de la previsualización
            MD_Import_Force_Logger::log_message("MD Import Force [DEBUG PREVIEW]: Iniciando previsualización para archivo: " . $file_path);

            // Verificar permisos
            if (!current_user_can('import')) {
                MD_Import_Force_Logger::log_message("MD Import Force [DEBUG PREVIEW]: Error de permisos.");
                throw new Exception(__('No tienes permisos para previsualizar.', 'md-import-force'));
            }

            // Verificar que el archivo existe
            if (!file_exists($file_path)) {
                MD_Import_Force_Logger::log_message("MD Import Force [DEBUG PREVIEW]: Archivo no encontrado en la ruta: " . $file_path);
                throw new Exception(__('Archivo no encontrado.', 'md-import-force'));
            }

            // Leer el archivo de importación
            $import_data = $this->read_import_file($file_path);

            // Si es un array (viene de un ZIP con múltiples JSONs), tomamos el primero.
            // Si es un solo conjunto de datos (viene de un JSON individual), ya está en el formato correcto.
            $data_to_preview = is_array($import_data) && isset($import_data[0]) ? $import_data[0] : $import_data;

            // Verificar que el formato del archivo es válido
            if (!isset($data_to_preview['site_info']) || !isset($data_to_preview['posts'])) {
                MD_Import_Force_Logger::log_message("MD Import Force [DEBUG PREVIEW]: Formato de archivo inválido. Faltan 'site_info' o 'posts'.");
                throw new Exception(__('Formato de archivo de importación inválido para previsualizar.', 'md-import-force'));
            }

            // Extraer información relevante
            $source_site_info = $data_to_preview['site_info'];
            $posts_data = $data_to_preview['posts'];

            // Limitar el número de registros para la previsualización
            $preview_records = array_slice($posts_data, 0, 10); // Mostrar los primeros 10 registros

            // Registrar éxito y devolver resultado
            MD_Import_Force_Logger::log_message("MD Import Force [DEBUG PREVIEW]: Previsualización generada con éxito.");
            return array(
                'success' => true,
                'site_info' => $source_site_info,
                'total_records' => count($posts_data),
                'preview_records' => $preview_records,
                'file_path' => $file_path,
                'message' => __('Previsualización generada con éxito.', 'md-import-force')
            );

        } catch (Exception $e) {
            // Registrar error y devolver resultado
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR PREVIEW]: " . $e->getMessage());
            return array(
                'success' => false,
                'data' => array('message' => $e->getMessage())
            );
        }
    }

    /**
     * Inicia el proceso de importación.
     *
     * @param string $file_path Ruta al archivo a importar
     * @return array Resultado de la importación
     */
    public function start_import($file_path) {
        try {
            // Verificar permisos
            if (!current_user_can('import')) {
                throw new Exception(__('No tienes permisos para importar.', 'md-import-force'));
            }

            // Verificar que el archivo existe
            if (!file_exists($file_path)) {
                throw new Exception(__('Archivo no encontrado.', 'md-import-force'));
            }

            // Leer el archivo de importación
            $import_data = $this->read_import_file($file_path);

            // Realizar la importación usando el gestor de importación
            $result = $this->import_manager->import_data($import_data);

            // Limpiar el archivo de importación si la importación fue exitosa
            if ($result['success']) {
                $cleanup_result = $this->cleanup_import_file($file_path);
                $result['cleanup'] = $cleanup_result;

                MD_Import_Force_Logger::log_message("MD Import Force: Limpieza de archivo después de importación: " .
                    ($cleanup_result ? 'Exitosa' : 'Fallida'));
            }

            return $result;

        } catch (Exception $e) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR FATAL]: " . $e->getMessage());
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Lee el archivo de importación (JSON o ZIP).
     * Si es ZIP, devuelve un array de datos de importación (uno por JSON encontrado).
     * Si es JSON, devuelve un solo conjunto de datos de importación.
     *
     * @param string $file_path Ruta al archivo a leer
     * @return array Datos de importación
     */
    private function read_import_file($file_path) {
        return $this->file_processor->read_import_file($file_path);
    }

    /**
     * Limpia el archivo de importación después de procesarlo.
     * Elimina el archivo ZIP o JSON para evitar residuos en el servidor.
     *
     * @param string $file_path Ruta al archivo que se debe eliminar
     * @return bool True si se eliminó correctamente, False en caso contrario
     */
    public function cleanup_import_file($file_path) {
        if (empty($file_path) || !file_exists($file_path)) {
            MD_Import_Force_Logger::log_message("MD Import Force [CLEANUP]: El archivo {$file_path} no existe para limpieza.");
            return false;
        }

        // Usar el gestor de archivos para eliminar el archivo
        return $this->file_manager->delete_file($file_path);
    }

    /**
     * Limpia todos los archivos de importación antiguos en el directorio.
     * Elimina archivos ZIP y JSON que tengan más de cierto tiempo de antigüedad.
     *
     * @param int $hours_old Eliminar archivos más antiguos que estas horas (por defecto 24 horas)
     * @return array Resultado de la limpieza con contadores
     */
    public function cleanup_all_import_files($hours_old = 24) {
        // Usar el gestor de archivos para limpiar archivos antiguos
        $result = $this->file_manager->cleanup_old_files($hours_old);

        // Registrar el resultado en el log
        MD_Import_Force_Logger::log_message(
            "MD Import Force [CLEANUP ALL]: Limpieza completada. " .
            "Eliminados: {$result['deleted']}, Fallidos: {$result['failed']}, Omitidos: {$result['skipped']}"
        );

        return $result;
    }
} // Fin clase
