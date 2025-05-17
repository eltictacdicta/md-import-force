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
require_once(dirname(__FILE__) . '/class-md-import-force-skipped-items-tracker.php');

class MD_Import_Force_Handler {

    private $id_mapping = [];
    private $source_site_info = [];
    private $file_processor;
    private $post_importer;
    private $taxonomy_importer;
    private $media_handler;
    private $comment_importer;
    private $skipped_items_tracker;

    public function __construct() {
        $this->id_mapping = [];
        $this->source_site_info = [];
        $this->file_processor = new MD_Import_Force_File_Processor();
        $this->taxonomy_importer = new MD_Import_Force_Taxonomy_Importer($this->id_mapping);
        $this->media_handler = new MD_Import_Force_Media_Handler($this->source_site_info);
        $this->comment_importer = new MD_Import_Force_Comment_Importer();
        $this->post_importer = new MD_Import_Force_Post_Importer(
            $this->id_mapping,
            $this->source_site_info,
            $this->taxonomy_importer,
            $this->media_handler,
            $this->comment_importer
        );
        $this->skipped_items_tracker = MD_Import_Force_Skipped_Items_Tracker::get_instance();
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

            $data_to_preview = is_array($import_data) && isset($import_data[0]) ? $import_data[0] : $import_data;

            if (!isset($data_to_preview['site_info']) || !isset($data_to_preview['posts'])) {
                MD_Import_Force_Logger::log_message("MD Import Force [DEBUG PREVIEW]: Formato de archivo inválido. Faltan 'site_info' o 'posts'.");
                throw new Exception(__('Formato de archivo de importación inválido para previsualizar.', 'md-import-force'));
            }
            MD_Import_Force_Logger::log_message("MD Import Force [DEBUG PREVIEW]: Formato de archivo verificado.");

            $source_site_info = $data_to_preview['site_info'];
            $posts_data = $data_to_preview['posts'];
            MD_Import_Force_Logger::log_message("MD Import Force [DEBUG PREVIEW]: Datos de sitio y posts extraídos.");

            $preview_records = array_slice($posts_data, 0, 10);

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
            return array('success' => false, 'data' => array('message' => $e->getMessage()));
        }
    }

    /**
     * Inicia el proceso de importación.
     * @param string $import_id El ID de la importación (normalmente la ruta al archivo).
     * @param array $options Opciones de importación.
     * @return array Resultado de la importación con estadísticas.
     */
    public function start_import($import_id, $options = []) {
        MD_Import_Force_Logger::log_message("MD Import Force [HANDLER START_IMPORT]: Iniciando para import_id: {$import_id}");
        MD_Import_Force_Progress_Tracker::update_status($import_id, 'processing', __('Leyendo archivo de importación...', 'md-import-force'));

        $this->skipped_items_tracker->clear();

        try {
            // Verificar inmediatamente si hay solicitud para detener todas las importaciones
            if (get_option('md_import_force_stop_all_imports_requested', false)) {
                MD_Import_Force_Logger::log_message("MD Import Force [STOP REQUESTED]: Solicitud de detención detectada al inicio para import_id {$import_id}.");
                MD_Import_Force_Progress_Tracker::update_status(
                    $import_id, 
                    'stopped', 
                    __('Importación detenida por solicitud del usuario.', 'md-import-force')
                );
                return [
                    'success' => true,
                    'new_count' => 0,
                    'updated_count' => 0,
                    'skipped_count' => 0,
                    'processed_count' => 0,
                    'total_count' => 0,
                    'stopped_manually' => true,
                    'message' => __('Importación detenida por solicitud del usuario antes de comenzar.', 'md-import-force')
                ];
            }
            
            if (!file_exists($import_id)) {
                throw new Exception(__('Archivo de importación no encontrado: ', 'md-import-force') . $import_id);
            }

            $import_data = $this->read_import_file($import_id);
            MD_Import_Force_Logger::log_message("MD Import Force [HANDLER START_IMPORT]: Archivo {$import_id} leído.");

            $overall_total_posts_in_file = 0;
            $overall_processed_count = 0;
            $overall_new_count = 0;
            $overall_updated_count = 0;
            $overall_skipped_count = 0;
            $messages = [];
            $success = true;

            if (is_array($import_data) && isset($import_data[0])) {
                foreach($import_data as $single_data_check) {
                    $overall_total_posts_in_file += count($single_data_check['posts'] ?? []);
                }
            } elseif (isset($import_data['posts'])) {
                $overall_total_posts_in_file = count($import_data['posts']);
            }
            
            MD_Import_Force_Progress_Tracker::update_progress(
                $import_id, 
                0, 
                $overall_total_posts_in_file, 
                __('Archivo leído, iniciando procesamiento de datos.', 'md-import-force')
            );

            if (is_array($import_data) && isset($import_data[0])) {
                MD_Import_Force_Logger::log_message("MD Import Force [HANDLER]: Procesando múltiples archivos JSON desde ZIP para import_id: {$import_id}.");
                
                $file_index = 0;
                foreach ($import_data as $single_import_data) {
                    $file_index++;
                    
                    // Verificamos solicitud global de detención
                    if (get_option('md_import_force_stop_all_imports_requested', false)) {
                        MD_Import_Force_Logger::log_message("MD Import Force [STOP REQUESTED]: Detención solicitada detectada en Handler antes de procesar el archivo JSON {$file_index} del ZIP para import_id {$import_id}.");
                        MD_Import_Force_Progress_Tracker::update_status(
                            $import_id,
                            'stopped',
                            sprintf(__('Importación detenida por solicitud del usuario (procesando archivo ZIP %d).', 'md-import-force'), $file_index)
                        );
                        $skipped_items = $this->skipped_items_tracker->get_all_skipped_items();
                        return [
                            'success' => true, // Partial success
                            'new_count' => $overall_new_count,
                            'updated_count' => $overall_updated_count,
                            'skipped_count' => $overall_skipped_count,
                            'processed_count' => $overall_processed_count, // This will be the count before this file
                            'total_count' => $overall_total_posts_in_file,
                            'skipped_items' => $skipped_items,
                            'stopped_manually' => true,
                            'message' => sprintf(__('Importación detenida por el usuario (procesando archivo ZIP %d de %d).', 'md-import-force'), $file_index, count($import_data))
                        ];
                    }
                    
                    // También verificamos solicitud específica de detención para este import_id (nuevo)
                    if (get_transient('md_import_force_stop_request_' . $import_id)) {
                        MD_Import_Force_Logger::log_message("MD Import Force [STOP REQUESTED]: Detención específica solicitada para import_id {$import_id} antes de procesar el archivo JSON {$file_index}.");
                        MD_Import_Force_Progress_Tracker::update_status(
                            $import_id,
                            'stopped',
                            sprintf(__('Importación detenida por solicitud específica del usuario (procesando archivo ZIP %d).', 'md-import-force'), $file_index)
                        );
                        $skipped_items = $this->skipped_items_tracker->get_all_skipped_items();
                        return [
                            'success' => true, // Partial success
                            'new_count' => $overall_new_count,
                            'updated_count' => $overall_updated_count,
                            'skipped_count' => $overall_skipped_count,
                            'processed_count' => $overall_processed_count,
                            'total_count' => $overall_total_posts_in_file,
                            'skipped_items' => $skipped_items,
                            'stopped_manually' => true,
                            'message' => sprintf(__('Importación detenida específicamente por el usuario (procesando archivo ZIP %d de %d).', 'md-import-force'), $file_index, count($import_data))
                        ];
                    }
                    
                    MD_Import_Force_Progress_Tracker::update_status($import_id, 'processing', sprintf(__('Procesando archivo %d del ZIP...', 'md-import-force'), $file_index));

                    if (!isset($single_import_data['site_info']) || !isset($single_import_data['posts'])) {
                        $messages[] = sprintf(__('Saltando archivo JSON %d en ZIP: Formato inválido.', 'md-import-force'), $file_index);
                        $num_posts_in_invalid_json = count($single_import_data['posts'] ?? []);
                        $overall_skipped_count += $num_posts_in_invalid_json;
                        $overall_processed_count += $num_posts_in_invalid_json;
                        MD_Import_Force_Progress_Tracker::update_progress($import_id, $overall_processed_count, $overall_total_posts_in_file, sprintf(__('Archivo %d del ZIP con formato inválido, %d posts saltados.', 'md-import-force'), $file_index, $num_posts_in_invalid_json));
                        MD_Import_Force_Logger::log_message("MD Import Force [WARN]: Saltando un archivo JSON en el ZIP (índice {$file_index}): Formato inválido para import_id: {$import_id}.");
                        if(!empty($single_import_data['posts'])) {
                            foreach($single_import_data['posts'] as $p) {
                                $this->skipped_items_tracker->add_skipped_item($p['ID'] ?? 'N/A', $p['post_title'] ?? '[Sin Título]', $p['post_type'] ?? 'post', 'Archivo JSON padre inválido');
                            }
                        }
                        continue;
                    }

                    $this->source_site_info = $single_import_data['site_info'];
                    $this->media_handler->set_source_site_info($this->source_site_info);
                    $this->post_importer->set_source_site_info($this->source_site_info);
                    $this->id_mapping = array();
                    $this->taxonomy_importer->set_id_mapping($this->id_mapping);
                    $this->post_importer->set_id_mapping($this->id_mapping);

                    MD_Import_Force_Logger::log_message("MD Import Force: Info sitio origen (desde ZIP, archivo {$file_index}): " . ($this->source_site_info['site_url'] ?? 'N/A'));

                    MD_Import_Force_Progress_Tracker::update_status($import_id, 'processing', sprintf(__('Importando términos (archivo ZIP %d)...', 'md-import-force'), $file_index));
                    if (!empty($single_import_data['categories'])) $this->import_terms($import_id, $single_import_data['categories'], 'category');
                    if (!empty($single_import_data['tags'])) $this->import_terms($import_id, $single_import_data['tags'], 'post_tag');

                    MD_Import_Force_Progress_Tracker::update_status($import_id, 'processing', sprintf(__('Importando posts (archivo ZIP %d)...', 'md-import-force'), $file_index));
                    
                    $result_single = $this->post_importer->import_posts(
                        $single_import_data['posts'] ?? [], 
                        $import_id, 
                        $options, 
                        $overall_processed_count, 
                        $overall_total_posts_in_file
                    );

                    // Verificar si la importación fue detenida manualmente
                    if (isset($result_single['stopped_manually']) && $result_single['stopped_manually']) {
                        // Si se detuvo manualmente, devolvemos el resultado parcial
                        $overall_new_count += $result_single['new_count'] ?? 0;
                        $overall_updated_count += $result_single['updated_count'] ?? 0;
                        $overall_skipped_count += $result_single['skipped_count'] ?? 0;

                        $skipped_items = $this->skipped_items_tracker->get_all_skipped_items();

                        return [
                            'success' => true, // Se considera éxito aunque sea una importación parcial
                            'new_count' => $overall_new_count,
                            'updated_count' => $overall_updated_count,
                            'skipped_count' => $overall_skipped_count,
                            'processed_count' => $overall_processed_count,
                            'total_count' => $overall_total_posts_in_file,
                            'skipped_items' => $skipped_items,
                            'stopped_manually' => true,
                            'message' => $result_single['message'] ?? __('Importación detenida manualmente por el usuario.', 'md-import-force')
                        ];
                    }

                    $overall_new_count += $result_single['new_count'] ?? 0;
                    $overall_updated_count += $result_single['updated_count'] ?? 0;
                    $overall_skipped_count += $result_single['skipped_count'] ?? 0;
                    if (!empty($result_single['message'])) $messages[] = $result_single['message'];
                    
                    if (function_exists('wp_cache_flush')) wp_cache_flush();
                    MD_Import_Force_Logger::log_message("MD Import Force: Procesamiento de archivo JSON {$file_index} en ZIP finalizado para import_id: {$import_id}.");
                }
                
                $final_message = sprintf(__('Importación de ZIP finalizada. Total: %d nuevos, %d actualizados, %d omitidos de %d registros.', 'md-import-force'), 
                    $overall_new_count, $overall_updated_count, $overall_skipped_count, $overall_total_posts_in_file);
                $messages[] = $final_message;
                MD_Import_Force_Logger::log_message("MD Import Force [HANDLER]: " . $final_message . " para import_id: {$import_id}");

            } else {
                MD_Import_Force_Logger::log_message("MD Import Force [HANDLER]: Procesando archivo JSON individual para import_id: {$import_id}.");
                if (!isset($import_data['site_info']) || !isset($import_data['posts'])) {
                    throw new Exception(__('Formato JSON inválido. Falta site_info o posts.', 'md-import-force'));
                }

                $this->source_site_info = $import_data['site_info'];
                $this->media_handler->set_source_site_info($this->source_site_info);
                $this->post_importer->set_source_site_info($this->source_site_info);
                $this->id_mapping = array();
                $this->taxonomy_importer->set_id_mapping($this->id_mapping);
                $this->post_importer->set_id_mapping($this->id_mapping);

                MD_Import_Force_Logger::log_message("MD Import Force: Info sitio origen: " . ($this->source_site_info['site_url'] ?? 'N/A'));
                MD_Import_Force_Logger::log_message("MD Import Force: Mapeo IDs inicializado para import_id: {$import_id}.");

                MD_Import_Force_Progress_Tracker::update_status($import_id, 'processing', __('Importando términos...', 'md-import-force'));
                if (!empty($import_data['categories'])) $this->import_terms($import_id, $import_data['categories'], 'category');
                if (!empty($import_data['tags'])) $this->import_terms($import_id, $import_data['tags'], 'post_tag');

                MD_Import_Force_Progress_Tracker::update_status($import_id, 'processing', __('Importando posts...', 'md-import-force'));
                $result_single = $this->post_importer->import_posts(
                    $import_data['posts'] ?? [], 
                    $import_id, 
                    $options,
                    $overall_processed_count, 
                    $overall_total_posts_in_file
                );

                // Verificar si la importación (de este único JSON) fue detenida manualmente
                if (isset($result_single['stopped_manually']) && $result_single['stopped_manually']) {
                    $overall_new_count = $result_single['new_count'] ?? 0;
                    $overall_updated_count = $result_single['updated_count'] ?? 0;
                    $overall_skipped_count = $result_single['skipped_count'] ?? 0;
                    $skipped_items = $this->skipped_items_tracker->get_all_skipped_items();
                    // $overall_processed_count ya está actualizado por referencia desde import_posts

                    return [
                        'success' => true, // Parcialmente exitoso
                        'new_count' => $overall_new_count,
                        'updated_count' => $overall_updated_count,
                        'skipped_count' => $overall_skipped_count,
                        'processed_count' => $overall_processed_count,
                        'total_count' => $overall_total_posts_in_file, // total_count es el total original del archivo
                        'skipped_items' => $skipped_items,
                        'stopped_manually' => true,
                        'message' => $result_single['message'] ?? __('Importación detenida manualmente por el usuario.', 'md-import-force')
                    ];
                }

                $overall_new_count = $result_single['new_count'] ?? 0;
                $overall_updated_count = $result_single['updated_count'] ?? 0;
                $overall_skipped_count = $result_single['skipped_count'] ?? 0;
                if (!empty($result_single['message'])) $messages[] = $result_single['message'];

                if (function_exists('wp_cache_flush')) wp_cache_flush();
                $final_message = sprintf(__('Importación de JSON finalizada. Total: %d nuevos, %d actualizados, %d omitidos de %d registros.', 'md-import-force'), 
                    $overall_new_count, $overall_updated_count, $overall_skipped_count, $overall_total_posts_in_file);
                $messages[] = $final_message;
                MD_Import_Force_Logger::log_message("MD Import Force [HANDLER]: Importación finalizada para import_id: {$import_id}. " . $final_message);
            }

            return array(
                'success' => $success,
                'message' => implode("\n", $messages),
                'imported_count' => $overall_new_count + $overall_updated_count,
                'new_count' => $overall_new_count,
                'updated_count' => $overall_updated_count,
                'skipped_count' => $overall_skipped_count,
                'total_items_in_file' => $overall_total_posts_in_file,
                'processed_count' => $overall_processed_count,
                'skipped_items' => $this->skipped_items_tracker->get_skipped_items()
            );

        } catch (Exception $e) {
            MD_Import_Force_Logger::log_message("MD Import Force [HANDLER START_IMPORT ERROR FATAL] para import_id: {$import_id}. Mensaje: " . $e->getMessage() . " Traza: " . $e->getTraceAsString());
            return array(
                'success' => false, 
                'message' => $e->getMessage(),
                'new_count' => $overall_new_count,
                'updated_count' => $overall_updated_count,
                'skipped_count' => $overall_skipped_count,
                'total_items_in_file' => $overall_total_posts_in_file,
                'processed_count' => $overall_processed_count,
                'skipped_items' => $this->skipped_items_tracker->get_skipped_items(),
                'error_details' => $e->getTraceAsString()
            );
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
     * (Esta función también podría necesitar pasar $import_id si se quiere loguear progreso de términos)
     */
    private function import_terms($import_id, $terms_data, $taxonomy) {
        MD_Import_Force_Logger::log_message("MD Import Force [HANDLER IMPORT_TERMS]: Importando " . count($terms_data) . " términos para taxonomía {$taxonomy}, import_id: {$import_id}.");
        
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

    /**
     * Procesa un lote de elementos para la importación (para Action Scheduler)
     * 
     * @param string $import_id ID de la importación
     * @param array $options Opciones de importación
     * @param array $import_data Datos de importación completos
     * @param int $start_index Índice de inicio del lote
     * @param int $batch_size Tamaño del lote
     * @param int &$current_processed_count Contador actual de elementos procesados (referencia)
     * @param int $total_items Total de elementos a procesar
     * @return array Resultado del procesamiento del lote
     */
    public function process_batch($import_id, $options, $import_data, $start_index, $batch_size, &$current_processed_count, $total_items) {
        MD_Import_Force_Logger::log_message("MD Import Force [HANDLER BATCH]: Procesando lote para import_id: {$import_id}, inicio: {$start_index}, tamaño: {$batch_size}");
        
        // Verificar si se ha solicitado detener las importaciones (global o específica)
        if (get_option('md_import_force_stop_all_imports_requested', false) || get_transient('md_import_force_stop_request_' . $import_id)) {
            $stop_reason = get_transient('md_import_force_stop_request_' . $import_id)
                ? "solicitud específica para import_id {$import_id}"
                : "solicitud global";
                
            MD_Import_Force_Logger::log_message("MD Import Force [STOP REQUESTED]: Lote detenido por {$stop_reason} al inicio del método process_batch.");
            MD_Import_Force_Progress_Tracker::update_status(
                $import_id,
                'stopped',
                __('Importación detenida por solicitud del usuario durante procesamiento por lotes.', 'md-import-force')
            );
            return [
                'success' => false,
                'new_count' => 0,
                'updated_count' => 0,
                'skipped_count' => 0,
                'processed_count' => $current_processed_count,
                'total_count' => $total_items,
                'stopped_manually' => true,
                'message' => __('Importación detenida por solicitud del usuario.', 'md-import-force')
            ];
        }
        
        // Inicializar contadores para este lote
        $batch_new_count = 0;
        $batch_updated_count = 0;
        $batch_skipped_count = 0;
        
        // Si es un ZIP con múltiples archivos JSON
        if (is_array($import_data) && isset($import_data[0])) {
            // Lógica para procesar un lote de un ZIP con múltiples archivos JSON
            // Esta lógica es más compleja y puede requerir un seguimiento del progreso entre archivos
            // Implementación simplificada:
            
            // Encontrar qué archivo JSON del ZIP y qué elementos procesar
            $total_processed_so_far = 0;
            $current_file_index = 0;
            $elements_in_current_file = 0;
            
            // Recorrer los archivos hasta encontrar dónde está el índice actual
            foreach ($import_data as $file_index => $single_import_data) {
                $elements_in_file = isset($single_import_data['posts']) ? count($single_import_data['posts']) : 0;
                
                if ($total_processed_so_far + $elements_in_file > $start_index) {
                    // Este es el archivo que contiene nuestro índice de inicio
                    $current_file_index = $file_index;
                    $elements_in_current_file = $elements_in_file;
                    break;
                }
                
                $total_processed_so_far += $elements_in_file;
            }
            
            // Calcular el índice de inicio dentro del archivo actual
            $file_start_index = $start_index - $total_processed_so_far;
            
            // Calcular cuántos elementos procesar en este archivo
            $elements_to_process = min($batch_size, $elements_in_current_file - $file_start_index);
            
            // Obtener el subconjunto de elementos a procesar
            $current_file_data = $import_data[$current_file_index];
            $batch_items = array_slice($current_file_data['posts'], $file_start_index, $elements_to_process);
            
            // Configurar el importer con la información del sitio de origen
            $this->source_site_info = $current_file_data['site_info'];
            $this->media_handler->set_source_site_info($this->source_site_info);
            $this->post_importer->set_source_site_info($this->source_site_info);
            
            // Procesar los términos de taxonomía primero si es el primer lote de este archivo
            if ($file_start_index == 0) {
                if (!empty($current_file_data['categories'])) $this->import_terms($import_id, $current_file_data['categories'], 'category');
                if (!empty($current_file_data['tags'])) $this->import_terms($import_id, $current_file_data['tags'], 'post_tag');
            }
            
            // Ahora procesar los posts del lote
            $batch_result = $this->post_importer->import_posts(
                $batch_items,
                $import_id,
                $options,
                $current_processed_count, // Referencia que será actualizada por import_posts
                $total_items
            );
            
            $batch_new_count = $batch_result['new_count'] ?? 0;
            $batch_updated_count = $batch_result['updated_count'] ?? 0;
            $batch_skipped_count = $batch_result['skipped_count'] ?? 0;
            
        } else {
            // Caso más simple: un solo archivo JSON
            // Obtener el subconjunto de elementos a procesar en este lote
            $batch_items = array_slice($import_data['posts'], $start_index, $batch_size);
            
            // Configurar el importer con la información del sitio de origen
            $this->source_site_info = $import_data['site_info'];
            $this->media_handler->set_source_site_info($this->source_site_info);
            $this->post_importer->set_source_site_info($this->source_site_info);
            
            // Procesar los términos de taxonomía primero si es el primer lote
            if ($start_index == 0) {
                if (!empty($import_data['categories'])) $this->import_terms($import_id, $import_data['categories'], 'category');
                if (!empty($import_data['tags'])) $this->import_terms($import_id, $import_data['tags'], 'post_tag');
            }
            
            // Ahora procesar los posts del lote
            $batch_result = $this->post_importer->import_posts(
                $batch_items,
                $import_id,
                $options,
                $current_processed_count, // Referencia que será actualizada por import_posts
                $total_items
            );
            
            $batch_new_count = $batch_result['new_count'] ?? 0;
            $batch_updated_count = $batch_result['updated_count'] ?? 0;
            $batch_skipped_count = $batch_result['skipped_count'] ?? 0;
        }
        
        // Verificar si hubo solicitud de detención durante el procesamiento
        if (isset($batch_result['stopped_manually']) && $batch_result['stopped_manually']) {
            return $batch_result; // Devolver el resultado que incluye la marca de detención
        }
        
        // Devolver los resultados del lote
        return [
            'success' => true,
            'new_count' => $batch_new_count,
            'updated_count' => $batch_updated_count,
            'skipped_count' => $batch_skipped_count,
            'processed_count' => $current_processed_count,
            'total_count' => $total_items,
            'message' => sprintf(__('Lote procesado: %d nuevos, %d actualizados, %d omitidos.', 'md-import-force'), 
                            $batch_new_count, $batch_updated_count, $batch_skipped_count)
        ];
    }
} // Fin clase
