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
require_once(dirname(__FILE__) . '/class-md-import-force-post-importer.php');
require_once(dirname(__FILE__) . '/class-md-import-force-taxonomy-importer.php');
require_once(dirname(__FILE__) . '/class-md-import-force-media-handler.php');
require_once(dirname(__FILE__) . '/class-md-import-force-comment-importer.php');
require_once(dirname(__FILE__) . '/class-md-import-force-progress-tracker.php');

class MD_Import_Force_Handler {

    private $id_mapping = [];
    private $source_site_info = [];
    private $file_processor;
    private $post_importer;
    private $taxonomy_importer;
    private $media_handler;
    private $comment_importer;
    private $progress_tracker;

    public function __construct() {
        $this->id_mapping = [];
        $this->source_site_info = [];
        $this->file_processor = new MD_Import_Force_File_Processor();
        $this->progress_tracker = new MD_Import_Force_Progress_Tracker();
        $this->taxonomy_importer = new MD_Import_Force_Taxonomy_Importer($this->id_mapping);
        $this->media_handler = new MD_Import_Force_Media_Handler($this->source_site_info);
        $this->comment_importer = new MD_Import_Force_Comment_Importer();
        $this->post_importer = new MD_Import_Force_Post_Importer(
            $this->id_mapping,
            $this->source_site_info,
            $this->taxonomy_importer,
            $this->media_handler,
            $this->comment_importer,
            $this->progress_tracker
        );
    }

    /**
     * Previsualiza el contenido del archivo de importación (primer JSON en ZIP o JSON individual).
     * Muestra información del sitio de origen y los primeros registros de posts/páginas.
     */
    public function preview_import_file($file_path) {
        try {
            MD_Import_Force_Logger::log_message("MD Import Force [DEBUG PREVIEW]: Iniciando previsualización para archivo: " . $file_path);
            if (!current_user_can('import')) {
                MD_Import_Force_Logger::log_message("MD Import Force [DEBUG PREVIEW]: Error de permisos.");
                throw new Exception(__('No tienes permisos para previsualizar.', 'md-import-force'));
            }
            MD_Import_Force_Logger::log_message("MD Import Force [DEBUG PREVIEW]: Permisos verificados.");
            if (!file_exists($file_path)) {
                MD_Import_Force_Logger::log_message("MD Import Force [DEBUG PREVIEW]: Archivo no encontrado en la ruta: " . $file_path);
                throw new Exception(__('Archivo no encontrado.', 'md-import-force'));
            }
            MD_Import_Force_Logger::log_message("MD Import Force [DEBUG PREVIEW]: Archivo encontrado.");

            $import_data = $this->read_import_file($file_path);
            MD_Import_Force_Logger::log_message("MD Import Force [DEBUG PREVIEW]: Archivo de importación leído.");

            // Si es un array (viene de un ZIP con múltiples JSONs), tomamos el primero.
            // Si es un solo conjunto de datos (viene de un JSON individual), ya está en el formato correcto.
            $data_to_preview = is_array($import_data) && isset($import_data[0]) ? $import_data[0] : $import_data;

            if (!isset($data_to_preview['site_info']) || !isset($data_to_preview['posts'])) {
                MD_Import_Force_Logger::log_message("MD Import Force [DEBUG PREVIEW]: Formato de archivo inválido. Faltan 'site_info' o 'posts'.");
                throw new Exception(__('Formato de archivo de importación inválido para previsualizar.', 'md-import-force'));
            }
            MD_Import_Force_Logger::log_message("MD Import Force [DEBUG PREVIEW]: Formato de archivo verificado.");

            $source_site_info = $data_to_preview['site_info'];
            $posts_data = $data_to_preview['posts'];
            MD_Import_Force_Logger::log_message("MD Import Force [DEBUG PREVIEW]: Datos de sitio y posts extraídos.");

            // Limitar el número de registros para la previsualización
            $preview_records = array_slice($posts_data, 0, 10); // Mostrar los primeros 10 registros

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
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR PREVIEW]: " . $e->getMessage());
            return array('success' => false, 'data' => array('message' => $e->getMessage())); // Devolver 'data' para que JS lo muestre
        }
    }

    /**
     * Inicia el proceso de importación.
     */
    public function start_import($file_path) {
        try {
            if (!current_user_can('import')) throw new Exception(__('No tienes permisos para importar.', 'md-import-force'));
            if (!file_exists($file_path)) throw new Exception(__('Archivo no encontrado.', 'md-import-force'));

            $import_data = $this->read_import_file($file_path);

            $total_imported = 0;
            $total_new = 0;
            $total_updated = 0;
            $total_skipped = 0;
            $messages = [];

            // Si es un array (viene de un ZIP con múltiples JSONs)
            if (is_array($import_data) && isset($import_data[0])) {
                MD_Import_Force_Logger::log_message("MD Import Force: Procesando múltiples archivos JSON desde ZIP.");
                foreach ($import_data as $single_import_data) {
                    if (!isset($single_import_data['site_info']) || !isset($single_import_data['posts'])) {
                        $messages[] = __('Saltando un archivo JSON en el ZIP: Formato inválido.', 'md-import-force');
                        $total_skipped += count($single_import_data['posts'] ?? []); // Intentar contar posts si existen para el skipped count
                        MD_Import_Force_Logger::log_message("MD Import Force [WARN]: Saltando un archivo JSON en el ZIP: Formato inválido.");
                        continue;
                    }

                    $this->source_site_info = $single_import_data['site_info'];
                    MD_Import_Force_Logger::log_message("MD Import Force: Info sitio origen (desde ZIP): " . ($this->source_site_info['site_url'] ?? 'N/A'));
                    // No resetear id_mapping aquí para permitir mapeo cruzado si es necesario, aunque simplificado no lo usa así.

                    // Importar Términos
                    if (!empty($single_import_data['categories'])) $this->import_terms($single_import_data['categories'], 'category');
                    if (!empty($single_import_data['tags'])) $this->import_terms($single_import_data['tags'], 'post_tag');
                    // Aquí se podrían importar otras taxonomías si estuvieran en $single_import_data['taxonomies']

                    // Importar Posts/Páginas
                    $result = $this->import_posts($single_import_data['posts'] ?? []);
                    $total_imported += ($result['new_count'] ?? 0) + ($result['updated_count'] ?? 0);
                    $total_new += $result['new_count'] ?? 0;
                    $total_updated += $result['updated_count'] ?? 0;
                    $total_skipped += $result['skipped_count'] ?? 0;
                    if (!empty($result['message'])) $messages[] = $result['message'];

                    // Guardar los elementos omitidos
                    $skipped_items = $result['skipped_items'] ?? [];

                    if (function_exists('wp_cache_flush')) wp_cache_flush();
                    MD_Import_Force_Logger::log_message("MD Import Force: Procesamiento de un archivo JSON en ZIP finalizado.");
                }
                $final_message = sprintf(__('Importación de ZIP finalizada. Total: %d nuevos, %d actualizados, %d omitidos.', 'md-import-force'), $total_new, $total_updated, $total_skipped);
                $messages[] = $final_message;
                MD_Import_Force_Logger::log_message("MD Import Force: " . $final_message);

                return array(
                    'success' => true,
                    'imported_count' => $total_imported,
                    'new_count' => $total_new,
                    'updated_count' => $total_updated,
                    'skipped_count' => $total_skipped,
                    'skipped_items' => $skipped_items ?? [],
                    'message' => implode("\n", $messages)
                );

            } else { // Si es un solo conjunto de datos (viene de un JSON individual)
                MD_Import_Force_Logger::log_message("MD Import Force: Procesando archivo JSON individual.");
                if (!isset($import_data['site_info']) || !isset($import_data['posts'])) throw new Exception(__('Formato JSON inválido.', 'md-import-force'));

                $this->source_site_info = $import_data['site_info'];
                MD_Import_Force_Logger::log_message("MD Import Force: Info sitio origen: " . ($this->source_site_info['site_url'] ?? 'N/A'));
                $this->id_mapping = array();
                MD_Import_Force_Logger::log_message("MD Import Force: Mapeo IDs inicializado.");

                // Importar Términos
                if (!empty($import_data['categories'])) $this->import_terms($import_data['categories'], 'category');
                if (!empty($import_data['tags'])) $this->import_terms($import_data['tags'], 'post_tag');
                // Aquí se podrían importar otras taxonomías si estuvieran en $import_data['taxonomies']

                // Importar Posts/Páginas
                $result = $this->import_posts($import_data['posts'] ?? []);

                if (function_exists('wp_cache_flush')) wp_cache_flush();
                MD_Import_Force_Logger::log_message("MD Import Force: Importación finalizada.");

                return array(
                    'success' => true,
                    'imported_count' => ($result['new_count'] ?? 0) + ($result['updated_count'] ?? 0),
                    'new_count' => $result['new_count'] ?? 0,
                    'updated_count' => $result['updated_count'] ?? 0,
                    'skipped_count' => $result['skipped_count'] ?? 0,
                    'skipped_items' => $result['skipped_items'] ?? [],
                    'message' => __('La importación se ha realizado con éxito', 'md-import-force')
                );
            }

        } catch (Exception $e) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR FATAL]: " . $e->getMessage());
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Lee el archivo de importación (JSON o ZIP).
     * Si es ZIP, devuelve un array de datos de importación (uno por JSON encontrado).
     * Si es JSON, devuelve un solo conjunto de datos de importación.
     */
    private function read_import_file($file_path) {
        return $this->file_processor->read_import_file($file_path);
    }



    /**
     * Importa posts/páginas uno por uno.
     */
    private function import_posts($items_data) {
        $this->post_importer->set_id_mapping($this->id_mapping);
        $this->post_importer->set_source_site_info($this->source_site_info);
        $result = $this->post_importer->import_posts($items_data);
        $this->id_mapping = $this->post_importer->get_id_mapping();
        return $result;
    }



    /**
     * Importa términos de una taxonomía específica.
     */
    private function import_terms($terms_data, $taxonomy) {
        $this->taxonomy_importer->set_id_mapping($this->id_mapping);
        $this->id_mapping = $this->taxonomy_importer->import_terms($terms_data, $taxonomy);
        return $this->id_mapping;
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

        // Intentar eliminar el archivo
        if (@unlink($file_path)) {
            MD_Import_Force_Logger::log_message("MD Import Force [CLEANUP]: Archivo eliminado con éxito: {$file_path}");
            return true;
        } else {
            MD_Import_Force_Logger::log_message("MD Import Force [CLEANUP ERROR]: No se pudo eliminar el archivo: {$file_path}");
            return false;
        }
    }

    /**
     * Limpia todos los archivos de importación antiguos en el directorio.
     * Elimina archivos ZIP y JSON que tengan más de cierto tiempo de antigüedad.
     *
     * @param int $hours_old Eliminar archivos más antiguos que estas horas (por defecto 24 horas)
     * @return array Resultado de la limpieza con contadores
     */
    public function cleanup_all_import_files($hours_old = 24) {
        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/md-import-force/';
        $result = [
            'success' => true,
            'deleted' => 0,
            'failed' => 0,
            'skipped' => 0
        ];

        if (!file_exists($target_dir) || !is_dir($target_dir)) {
            MD_Import_Force_Logger::log_message("MD Import Force [CLEANUP ALL]: El directorio {$target_dir} no existe.");
            $result['success'] = false;
            return $result;
        }

        $time_threshold = time() - ($hours_old * 3600); // Convertir horas a segundos
        $files = glob($target_dir . '*');

        foreach ($files as $file) {
            // Saltar directorios
            if (is_dir($file)) {
                $result['skipped']++;
                continue;
            }

            // Verificar la extensión del archivo
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if ($ext !== 'zip' && $ext !== 'json') {
                $result['skipped']++;
                continue;
            }

            // Verificar la antigüedad del archivo
            $file_time = filemtime($file);
            if ($file_time > $time_threshold) {
                $result['skipped']++;
                continue;
            }

            // Intentar eliminar el archivo
            if (@unlink($file)) {
                MD_Import_Force_Logger::log_message("MD Import Force [CLEANUP ALL]: Archivo antiguo eliminado: {$file}");
                $result['deleted']++;
            } else {
                MD_Import_Force_Logger::log_message("MD Import Force [CLEANUP ALL ERROR]: No se pudo eliminar archivo antiguo: {$file}");
                $result['failed']++;
            }
        }

        MD_Import_Force_Logger::log_message("MD Import Force [CLEANUP ALL]: Limpieza completada. Eliminados: {$result['deleted']}, Fallidos: {$result['failed']}, Omitidos: {$result['skipped']}");
        return $result;
    }
} // Fin clase
