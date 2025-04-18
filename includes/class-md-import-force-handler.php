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
                    'message' => $result['message'] ?? __('La importación se ha realizado con éxito', 'md-import-force')
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
     * Lee el contenido del log de errores del plugin.
     */
    public function read_error_log() {
        if (!current_user_can('manage_options')) {
            return new WP_Error('permission_denied', __('No tienes permisos para ver el log.', 'md-import-force'));
        }

        // Ruta al archivo de log personalizado
        $log_path = __DIR__ . '/../logs/md-import-force.log';

        if (!file_exists($log_path)) {
            // Loggear que el archivo no existe usando el logger personalizado
            if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message(__('El archivo de log no fue encontrado en la ruta esperada: ', 'md-import-force') . $log_path);
            }
            return new WP_Error('log_not_found', __('El archivo de log no fue encontrado en la ruta esperada: ', 'md-import-force') . $log_path);
        }

        if (!is_readable($log_path)) {
             if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message(__('No tienes permisos para leer el archivo de log en la ruta: ', 'md-import-force') . $log_path);
            }
            return new WP_Error('permission_denied', __('No tienes permisos para leer el archivo de log.', 'md-import-force'));
        }

        $content = file_get_contents($log_path);

        return array('success' => true, 'log_content' => $content);
    }

    /**
     * Limpia el contenido del log de errores del plugin.
     */
    public function clear_error_log() {
        if (!current_user_can('manage_options')) {
            return new WP_Error('permission_denied', __('No tienes permisos para limpiar el log.', 'md-import-force'));
        }

        // Ruta al archivo de log personalizado
        $log_path = MD_IMPORT_FORCE_PLUGIN_DIR . 'logs/md-import-force.log';

        if (!file_exists($log_path)) {
             if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message(__('El archivo de log no fue encontrado en la ruta esperada: ', 'md-import-force') . $log_path);
            }
            return new WP_Error('log_not_found', __('El archivo de log no fue encontrado en la ruta esperada: ', 'md-import-force') . $log_path);
        }

         if (!is_writable($log_path)) {
             if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message(__('No tienes permisos para escribir en el archivo de log en la ruta: ', 'md-import-force') . $log_path);
            }
            return new WP_Error('permission_denied', __('No tienes permisos para escribir en el archivo de log.', 'md-import-force'));
        }

        if (file_put_contents($log_path, '') === false) {
             if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message(__('No se pudo limpiar el archivo de log en la ruta: ', 'md-import-force') . $log_path);
            }
             return new WP_Error('clear_failed', __('No se pudo limpiar el archivo de log.', 'md-import-force'));
        }

        // Loggear la limpieza exitosa
        if (class_exists('MD_Import_Force_Logger')) {
            MD_Import_Force_Logger::log_message(__('Log de errores limpiado con éxito.', 'md-import-force'));
        }

        return array('success' => true, 'message' => __('Log de errores limpiado con éxito.', 'md-import-force'));
    }



} // Fin clase
