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
require_once(dirname(__FILE__) . '/class-md-import-force-media-queue-manager.php');

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
     * Guarda el mapeo de términos en transients para persistir entre lotes
     */
    private function save_terms_mapping($import_id, $mapping) {
        $cache_key = 'mdif_terms_mapping_' . md5($import_id);
        // Guardar por 1 hora para que persista durante toda la importación
        set_transient($cache_key, $mapping, HOUR_IN_SECONDS);
        MD_Import_Force_Logger::log_message("MD Import Force [MAPPING CACHE]: Mapeo de términos guardado en caché para import_id: {$import_id}. Términos mapeados: " . count($mapping));
    }

    /**
     * Recupera el mapeo de términos desde transients
     */
    private function load_terms_mapping($import_id) {
        $cache_key = 'mdif_terms_mapping_' . md5($import_id);
        $mapping = get_transient($cache_key);
        if ($mapping && is_array($mapping)) {
            MD_Import_Force_Logger::log_message("MD Import Force [MAPPING CACHE]: Mapeo de términos recuperado desde caché para import_id: {$import_id}. Términos mapeados: " . count($mapping));
            return $mapping;
        }
        MD_Import_Force_Logger::log_message("MD Import Force [MAPPING CACHE]: No se encontró mapeo de términos en caché para import_id: {$import_id}.");
        return [];
    }

    /**
     * Limpia el mapeo de términos en caché
     */
    private function clear_terms_mapping($import_id) {
        $cache_key = 'mdif_terms_mapping_' . md5($import_id);
        delete_transient($cache_key);
        MD_Import_Force_Logger::log_message("MD Import Force [MAPPING CACHE]: Mapeo de términos eliminado de caché para import_id: {$import_id}.");
    }

    /**
     * Verifica el estado del mapeo de términos (método público para job manager)
     */
    public function check_terms_mapping_status($import_id) {
        $mapping = $this->load_terms_mapping($import_id);
        $count = count($mapping);
        MD_Import_Force_Logger::log_message("MD Import Force [MAPPING STATUS]: Import ID {$import_id} tiene {$count} términos mapeados en caché.");
        return $count;
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

            // Detectar URL base del proyecto actual
            $current_site_url = home_url();
            $current_site_name = get_bloginfo('name');
            
            // Obtener URL del sitio de origen
            $source_site_url = isset($source_site_info['site_url']) ? $source_site_info['site_url'] : '';
            
            // Información de reemplazo de URLs
            $url_replacement_info = array(
                'source_url' => $source_site_url,
                'target_url' => $current_site_url,
                'will_replace' => !empty($source_site_url) && $source_site_url !== $current_site_url
            );

            $all_posts_in_file = $posts_data;
            $missing_posts = [];
            $existing_posts_in_file_count = 0;

            foreach ($all_posts_in_file as $record) {
                $existing_post = get_page_by_title(html_entity_decode($record['post_title']), OBJECT, $record['post_type']);
                $is_existing = ($existing_post !== null && $existing_post->post_status !== 'trash');
                
                if (!$is_existing) {
                    $record['already_exists'] = false; // Aunque solo mostraremos los que faltan, mantenemos la clave por consistencia
                    $missing_posts[] = $record;
                } else {
                    $existing_posts_in_file_count++;
                }
            }

            $preview_records = array_slice($missing_posts, 0, 50); // Tomamos los primeros 50 de los que FALTAN
            $total_missing_in_file = count($missing_posts);

            // Extract IDs of missing posts to potentially use in 'import only missing'
            $missing_post_ids = array_map(function($post) {
                return $post['ID']; // Assuming 'ID' is the original ID from the import file
            }, $missing_posts);

            if (!empty($missing_post_ids)) {
                set_transient('mdif_missing_ids_' . md5($file_path), $missing_post_ids, HOUR_IN_SECONDS * 2); // Store for 2 hours
                MD_Import_Force_Logger::log_message("MD Import Force [DEBUG PREVIEW]: Stored " . count($missing_post_ids) . " missing post IDs in transient for file hash: " . md5($file_path));
            } else {
                // Ensure any old transient for this file is cleared if no missing posts are found now
                delete_transient('mdif_missing_ids_' . md5($file_path));
                MD_Import_Force_Logger::log_message("MD Import Force [DEBUG PREVIEW]: No missing posts found or IDs array empty. Cleared any existing transient for file hash: " . md5($file_path));
            }

            MD_Import_Force_Logger::log_message("MD Import Force [DEBUG PREVIEW]: Previsualización generada con éxito. Faltantes en archivo: " . $total_missing_in_file);
            return array(
                'success' => true,
                'site_info' => $source_site_info,
                'current_site_info' => array(
                    'site_url' => $current_site_url,
                    'site_name' => $current_site_name
                ),
                'url_replacement_info' => $url_replacement_info,
                'total_records_in_file' => count($all_posts_in_file), // Total original en el archivo
                'total_missing_in_file' => $total_missing_in_file, // Total de los que realmente faltan
                'total_existing_in_file' => $existing_posts_in_file_count, // Total de los que ya existen en el archivo
                'preview_records' => $preview_records, // Los primeros 50 de los que faltan
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
    public function start_import($import_id, $options = array()) {
        MD_Import_Force_Logger::log_message("MD Import Force [DEBUG HANDLER START_IMPORT]: Entrando en Handler::start_import. Import ID: {$import_id}, Opciones: " . json_encode($options));

        MD_Import_Force_Logger::log_message("MD Import Force [HANDLER START_IMPORT]: Iniciando proceso de importación para import_id: {$import_id}");
        
        // Registrar las opciones recibidas para diagnóstico
        $import_only_missing = isset($options['import_only_missing']) && $options['import_only_missing'] ? 'SÍ' : 'NO';
        MD_Import_Force_Logger::log_message("MD Import Force [HANDLER START_IMPORT OPTIONS]: Opción import_only_missing: {$import_only_missing}");
        
        // >>> INICIO: Limpiar banderas de detención existentes <<<
        // Limpiar la bandera global de detención
        delete_option('md_import_force_stop_all_imports_requested');
        // Limpiar cualquier transient específico para este import_id que pudiera quedar de una ejecución anterior
        delete_transient('md_import_force_stop_request_' . $import_id);
        MD_Import_Force_Logger::log_message("MD Import Force [HANDLER START_IMPORT]: Banderas de detención limpiadas para import_id: {$import_id}");
        // >>> FIN: Limpiar banderas de detención existentes <<<
        
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

            // >>> INICIO: Optimización para "Importar solo faltantes" usando IDs de previsualización
            $original_posts_count_before_filter = 0;
            if (isset($options['import_only_missing']) && $options['import_only_missing']) {
                $missing_post_ids_from_preview = get_transient('mdif_missing_ids_' . md5($import_id));

                if ($missing_post_ids_from_preview && is_array($missing_post_ids_from_preview) && !empty($missing_post_ids_from_preview)) {
                    MD_Import_Force_Logger::log_message("MD Import Force [HANDLER START_IMPORT]: Opción 'solo faltantes' activa. Se encontraron " . count($missing_post_ids_from_preview) . " IDs de posts faltantes en transient para {$import_id}. Filtrando posts...");

                    $filter_function = function($post) use ($missing_post_ids_from_preview) {
                        return isset($post['ID']) && in_array($post['ID'], $missing_post_ids_from_preview);
                    };

                    if (is_array($import_data) && isset($import_data[0])) { // ZIP con múltiples archivos
                        foreach ($import_data as &$single_file_data_ref) { // Usar referencia para modificar el array original
                            if (isset($single_file_data_ref['posts']) && is_array($single_file_data_ref['posts'])) {
                                $original_posts_count_before_filter += count($single_file_data_ref['posts']);
                                $single_file_data_ref['posts'] = array_filter($single_file_data_ref['posts'], $filter_function);
                            }
                        }
                        unset($single_file_data_ref); // Romper la referencia
                    } elseif (isset($import_data['posts']) && is_array($import_data['posts'])) { // Archivo JSON único
                        $original_posts_count_before_filter = count($import_data['posts']);
                        $import_data['posts'] = array_filter($import_data['posts'], $filter_function);
                    }
                    MD_Import_Force_Logger::log_message("MD Import Force [HANDLER START_IMPORT]: Posts filtrados según IDs de previsualización.");
                } else {
                    MD_Import_Force_Logger::log_message("MD Import Force [HANDLER START_IMPORT]: Opción 'solo faltantes' activa, pero no se encontraron IDs en transient (o estaba vacío) para {$import_id}. Se procederá con la lógica de omisión estándar dentro del importador.");
                }
            }
            // >>> FIN: Optimización

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

            if (isset($options['import_only_missing']) && $options['import_only_missing'] && $original_posts_count_before_filter > 0) {
                 MD_Import_Force_Logger::log_message("MD Import Force [HANDLER START_IMPORT]: Recalculado overall_total_posts_in_file DESPUÉS de filtro 'solo faltantes': " . $overall_total_posts_in_file . " (originalmente eran " . $original_posts_count_before_filter . " antes de filtrar, si se aplicó filtro). Import ID: " . $import_id);
            } else {
                 MD_Import_Force_Logger::log_message("MD Import Force [HANDLER START_IMPORT]: Calculado overall_total_posts_in_file: " . $overall_total_posts_in_file . " para import_id: " . $import_id);
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
                    
                    // Cargar mapeo existente de términos desde caché
                    $this->id_mapping = $this->load_terms_mapping($import_id);
                    $this->taxonomy_importer->set_id_mapping($this->id_mapping);
                    $this->post_importer->set_id_mapping($this->id_mapping);

                    MD_Import_Force_Logger::log_message("MD Import Force: Info sitio origen (desde ZIP, archivo {$file_index}): " . ($this->source_site_info['site_url'] ?? 'N/A'));

                    MD_Import_Force_Progress_Tracker::update_status($import_id, 'processing', sprintf(__('Importando términos (archivo ZIP %d)...', 'md-import-force'), $file_index));
                    if (!empty($single_import_data['categories'])) {
                        $this->import_terms($import_id, $single_import_data['categories'], 'category');
                        // Actualizar el mapeo después de importar términos
                        $this->id_mapping = array_merge($this->id_mapping, $this->taxonomy_importer->get_id_mapping());
                        $this->post_importer->set_id_mapping($this->id_mapping);
                    }
                    if (!empty($single_import_data['tags'])) {
                        $this->import_terms($import_id, $single_import_data['tags'], 'post_tag');
                        // Actualizar el mapeo después de importar términos
                        $this->id_mapping = array_merge($this->id_mapping, $this->taxonomy_importer->get_id_mapping());
                        $this->post_importer->set_id_mapping($this->id_mapping);
                    }

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
                
                // Cargar mapeo existente de términos desde caché
                $this->id_mapping = $this->load_terms_mapping($import_id);
                $this->taxonomy_importer->set_id_mapping($this->id_mapping);
                $this->post_importer->set_id_mapping($this->id_mapping);

                MD_Import_Force_Logger::log_message("MD Import Force: Info sitio origen: " . ($this->source_site_info['site_url'] ?? 'N/A'));
                MD_Import_Force_Logger::log_message("MD Import Force: Mapeo IDs inicializado para import_id: {$import_id}.");

                MD_Import_Force_Progress_Tracker::update_status($import_id, 'processing', __('Importando términos...', 'md-import-force'));
                if (!empty($import_data['categories'])) {
                    $this->import_terms($import_id, $import_data['categories'], 'category');
                    // Actualizar el mapeo después de importar términos
                    $this->id_mapping = array_merge($this->id_mapping, $this->taxonomy_importer->get_id_mapping());
                    $this->post_importer->set_id_mapping($this->id_mapping);
                }
                if (!empty($import_data['tags'])) {
                    $this->import_terms($import_id, $import_data['tags'], 'post_tag');
                    // Actualizar el mapeo después de importar términos
                    $this->id_mapping = array_merge($this->id_mapping, $this->taxonomy_importer->get_id_mapping());
                    $this->post_importer->set_id_mapping($this->id_mapping);
                }

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
            // Limpiar el mapeo de términos en caso de error
            $this->clear_terms_mapping($import_id);
            
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
        } finally {
            // Siempre limpiar el mapeo de términos al final de la importación
            $this->clear_terms_mapping($import_id);
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
        MD_Import_Force_Logger::log_message("MD Import Force [HANDLER IMPORT_TERMS]: Iniciando importación de " . count($terms_data) . " términos para taxonomía {$taxonomy}, import_id: {$import_id}.");
        
        // Verificar qué términos ya están en el mapeo
        $existing_mapping = $this->load_terms_mapping($import_id);
        $prefix = $taxonomy . '_';
        $terms_to_process = [];
        $skipped_count = 0;
        
        foreach ($terms_data as $term_data) {
            $term_id = $term_data['term_id'] ?? 0;
            if ($term_id > 0) {
                // Verificar si ya está mapeado (con y sin prefijo)
                $already_mapped = isset($existing_mapping[$prefix . $term_id]) || isset($existing_mapping[$term_id]);
                if (!$already_mapped) {
                    $terms_to_process[] = $term_data;
                } else {
                    $skipped_count++;
                }
            }
        }
        
        if ($skipped_count > 0) {
            MD_Import_Force_Logger::log_message("MD Import Force [HANDLER IMPORT_TERMS]: Omitiendo {$skipped_count} términos ya procesados para taxonomía {$taxonomy}.");
        }
        
        if (empty($terms_to_process)) {
            MD_Import_Force_Logger::log_message("MD Import Force [HANDLER IMPORT_TERMS]: Todos los términos para taxonomía {$taxonomy} ya están procesados. Saltando.");
            return $existing_mapping;
        }
        
        MD_Import_Force_Logger::log_message("MD Import Force [HANDLER IMPORT_TERMS]: Procesando " . count($terms_to_process) . " términos nuevos para taxonomía {$taxonomy}.");
        
        // Importar solo los términos que necesitan procesamiento
        $new_mapping = $this->taxonomy_importer->import_terms($terms_to_process, $taxonomy);
        
        // Combinar el mapeo existente con el nuevo
        $combined_mapping = array_merge($existing_mapping, $new_mapping);
        
        // Guardar el mapeo combinado
        $this->save_terms_mapping($import_id, $combined_mapping);
        
        // Actualizar el mapeo local
        $this->id_mapping = $combined_mapping;
        
        return $combined_mapping;
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
     * @param array $import_data Datos de importación completos (para este lote, o el archivo de datos temporal)
     * @param int $start_index Índice de inicio del lote DENTRO de $import_data['posts'] (o estructura similar si es ZIP)
     * @param int $batch_size Tamaño del lote
     * @param int &$current_processed_count Contador actual de elementos procesados (referencia global para esta fase de posts)
     * @param int $total_items Total de elementos a procesar (en esta fase de posts)
     * @param string $import_run_guid GUID único para toda la ejecución de esta importación
     * @param int $batch_run_start_time Timestamp de cuándo comenzó el procesamiento del lote actual del Job Manager.
     * @param int $time_limit_for_this_run Tiempo máximo en segundos para esta ejecución del lote.
     * @return array Resultado del procesamiento del lote
     */
    public function process_batch(
        $import_id, 
        $options, 
        $import_data, // Full data for the import phase (e.g., from temp file)
        $current_batch_start_index, // Global start index for items this Action Scheduler job is meant to process
        $current_batch_size,      // Number of items this Action Scheduler job is meant to process from the global list
        &$overall_processed_count_ref, 
        $total_items, 
        $import_run_guid,
        $batch_run_start_time,
        $time_limit_for_this_run
    ) {
        // Monitoreo inicial de memoria
        $memory_start = memory_get_usage(true);
        $memory_peak_start = memory_get_peak_usage(true);
        
        MD_Import_Force_Logger::log_message("MD Import Force [HANDLER BATCH]: GUID: {$import_run_guid}, ImportID: {$import_id}, Lote inicia en índice global: {$current_batch_start_index}, tamaño solicitado para este job: {$current_batch_size}. Tiempo límite: {$time_limit_for_this_run}s. Memoria inicial: " . size_format($memory_start));
        
        // Limpieza de memoria al inicio
        $this->cleanup_handler_memory();
        
        if (get_option('md_import_force_stop_all_imports_requested', false) || get_transient('md_import_force_stop_request_' . $import_id)) {
            $stop_reason = get_transient('md_import_force_stop_request_' . $import_id)
                ? "solicitud específica para import_id {$import_id}"
                : "solicitud global";
            MD_Import_Force_Logger::log_message("MD Import Force [STOP REQUESTED]: Lote detenido por {$stop_reason} al inicio de Handler::process_batch.");
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
                'items_actually_processed_this_run' => 0,
                'time_exceeded' => false,
                'stopped_manually' => true,
                'processed_count_overall' => $overall_processed_count_ref,
                'message' => __('Importación detenida por solicitud del usuario.', 'md-import-force')
            ];
        }
        
        $batch_items_for_this_call = [];
        $current_file_data_for_batch = null; // Used if ZIP

        // Determine the actual items to process in THIS specific call to import_posts
        // This logic needs to correctly slice the $import_data based on $current_batch_start_index and $current_batch_size
        // $import_data is the full dataset (e.g. content of the temp file, which could be an array of file contents for a ZIP)

        if (is_array($import_data) && isset($import_data[0]['site_info']) && isset($import_data[0]['posts'])) {
            // Handle ZIP: $import_data is an array of [ 'site_info' => ..., 'posts' => ..., 'categories' => ..., 'tags' => ... ]
            MD_Import_Force_Logger::log_message("MD Import Force [HANDLER BATCH]: Procesando ZIP. Buscando items para índice global {$current_batch_start_index}, tamaño {$current_batch_size}.");
            
            $cumulative_item_count = 0;
            $items_collected_for_this_run = [];
            $zip_file_processed_terms = []; // Track per-file term processing: [file_hash => true]

            foreach ($import_data as $file_content_index => $single_file_data) {
                if (!isset($single_file_data['posts']) || !is_array($single_file_data['posts'])) continue;

                $posts_in_this_file = $single_file_data['posts'];
                $count_in_this_file = count($posts_in_this_file);

                // Check if this file is relevant for the current_batch_start_index and current_batch_size
                if ($cumulative_item_count + $count_in_this_file > $current_batch_start_index) {
                    $start_in_this_file = max(0, $current_batch_start_index - $cumulative_item_count);
                    $num_to_take_from_this_file = min(
                        $count_in_this_file - $start_in_this_file, // available in this file from start_in_this_file
                        $current_batch_size - count($items_collected_for_this_run) // remaining needed for this job
                    );

                    if ($num_to_take_from_this_file > 0) {
                        $items_slice_from_file = array_slice($posts_in_this_file, $start_in_this_file, $num_to_take_from_this_file);
                        $items_collected_for_this_run = array_merge($items_collected_for_this_run, $items_slice_from_file);
                        
                        // Set source_site_info from the FIRST file that contributes items to this batch
                        if (empty($this->source_site_info) && isset($single_file_data['site_info'])) {
                            $this->source_site_info = $single_file_data['site_info'];
                             MD_Import_Force_Logger::log_message("MD Import Force [HANDLER BATCH]: Site info establecida desde archivo ZIP índice {$file_content_index}.");
                        }

                        // Process terms for this part of the ZIP file if it's the beginning of processing *this specific file's content*
                        // and this batch is starting at the beginning of this file's items.
                        $file_hash_for_terms = md5(json_encode($single_file_data['site_info'])); // Unique ID for this file within zip
                        if ($start_in_this_file == 0 && !isset($zip_file_processed_terms[$file_hash_for_terms])) {
                            MD_Import_Force_Logger::log_message("MD Import Force [HANDLER BATCH]: Procesando términos para archivo ZIP índice {$file_content_index} (hash: {$file_hash_for_terms}).");
                            
                            // Limpieza de memoria antes de procesar términos
                            $this->cleanup_handler_memory();
                            
                            $this->id_mapping = $this->load_terms_mapping($import_id); // Load fresh before term import
                            $this->taxonomy_importer->set_id_mapping($this->id_mapping);
                            $this->post_importer->set_id_mapping($this->id_mapping); // Ensure post importer also has it

                            if (!empty($single_file_data['categories'])) {
                                $this->import_terms($import_id, $single_file_data['categories'], 'category');
                                $this->id_mapping = array_merge($this->id_mapping, $this->taxonomy_importer->get_id_mapping());
                                // Limpieza intermedia
                                $this->cleanup_handler_memory();
                            }
                            if (!empty($single_file_data['tags'])) {
                                $this->import_terms($import_id, $single_file_data['tags'], 'post_tag');
                                $this->id_mapping = array_merge($this->id_mapping, $this->taxonomy_importer->get_id_mapping());
                                // Limpieza intermedia
                                $this->cleanup_handler_memory();
                            }
                            $this->save_terms_mapping($import_id, $this->id_mapping); // Save updated mapping
                            $zip_file_processed_terms[$file_hash_for_terms] = true;
                        }
                    }
                }
                $cumulative_item_count += $count_in_this_file;
                if (count($items_collected_for_this_run) >= $current_batch_size) {
                    break; // Collected enough items for this job
                }
            }
            $batch_items_for_this_call = $items_collected_for_this_run;
            if (empty($this->source_site_info) && isset($import_data[0]['site_info'])) { // Fallback if somehow not set
                 $this->source_site_info = $import_data[0]['site_info'];
            }

        } elseif (isset($import_data['posts']) && isset($import_data['site_info'])) { // Single JSON structure
            MD_Import_Force_Logger::log_message("MD Import Force [HANDLER BATCH]: Procesando JSON único. Índice global {$current_batch_start_index}, tamaño {$current_batch_size}.");
            $this->source_site_info = $import_data['site_info'];
            
            // Term processing only if this batch starts at the very beginning of the file (global index 0)
            if ($current_batch_start_index == 0) {
                MD_Import_Force_Logger::log_message("MD Import Force [HANDLER BATCH]: Procesando términos para JSON único (inicio de archivo).");
                
                // Limpieza de memoria antes de procesar términos
                $this->cleanup_handler_memory();
                
                $this->id_mapping = $this->load_terms_mapping($import_id); // Load fresh
                $this->taxonomy_importer->set_id_mapping($this->id_mapping);
                 $this->post_importer->set_id_mapping($this->id_mapping);

                if (!empty($import_data['categories'])) {
                    $this->import_terms($import_id, $import_data['categories'], 'category');
                    $this->id_mapping = array_merge($this->id_mapping, $this->taxonomy_importer->get_id_mapping());
                    // Limpieza intermedia
                    $this->cleanup_handler_memory();
                }
                if (!empty($import_data['tags'])) {
                    $this->import_terms($import_id, $import_data['tags'], 'post_tag');
                    $this->id_mapping = array_merge($this->id_mapping, $this->taxonomy_importer->get_id_mapping());
                    // Limpieza intermedia
                    $this->cleanup_handler_memory();
                }
                 $this->save_terms_mapping($import_id, $this->id_mapping); // Save updated mapping
            }
            // Slice the posts from the single JSON data based on global start index and batch size for this job
            $batch_items_for_this_call = array_slice($import_data['posts'], $current_batch_start_index, $current_batch_size);

        } else {
            MD_Import_Force_Logger::log_message("MD Import Force [HANDLER BATCH ERROR]: Estructura de import_data inválida. ImportID: {$import_id}");
            return [
                'success' => false, 'new_count' => 0, 'updated_count' => 0, 'skipped_count' => 0,
                'items_actually_processed_this_run' => 0, 'time_exceeded' => false, 'stopped_manually' => false,
                'processed_count_overall' => $overall_processed_count_ref,
                'message' => __('Error: Formato de datos de importación inválido.', 'md-import-force')
            ];
        }

        if (empty($batch_items_for_this_call)) {
             MD_Import_Force_Logger::log_message("MD Import Force [HANDLER BATCH WARN]: No se prepararon items para procesar para el índice global {$current_batch_start_index}, tamaño {$current_batch_size}. ImportID: {$import_id}. Esto puede ser normal si es el final de la importación y el último lote es más pequeño.");
            return [
                'success' => true, 'new_count' => 0, 'updated_count' => 0, 'skipped_count' => 0,
                'items_actually_processed_this_run' => 0,
                'time_exceeded' => false,
                'stopped_manually' => false,
                'processed_count_overall' => $overall_processed_count_ref,
                'message' => __('No hay más items en este lote para procesar.', 'md-import-force')
            ];
        }
        MD_Import_Force_Logger::log_message("MD Import Force [HANDLER BATCH]: Preparados " . count($batch_items_for_this_call) . " items para procesar en esta corrida. ImportID: {$import_id}");

        // Limpieza de memoria antes del procesamiento principal
        $this->cleanup_handler_memory();
        
        // Verificar memoria disponible antes de continuar
        $memory_before_posts = memory_get_usage(true);
        $memory_limit = ini_get('memory_limit');
        $memory_limit_bytes = $this->convert_memory_limit_to_bytes($memory_limit);
        $memory_usage_ratio = $memory_before_posts / $memory_limit_bytes;
        
        if ($memory_usage_ratio > 0.8) { // Si estamos usando más del 80% de memoria
            MD_Import_Force_Logger::log_message("MD Import Force [HANDLER BATCH WARNING]: Uso de memoria alto (" . round($memory_usage_ratio * 100, 2) . "%) antes del procesamiento de posts. Memoria: " . size_format($memory_before_posts));
            // Reducir el tamaño del lote dinámicamente
            if (count($batch_items_for_this_call) > 1) {
                $reduced_size = max(1, floor(count($batch_items_for_this_call) / 2));
                $batch_items_for_this_call = array_slice($batch_items_for_this_call, 0, $reduced_size);
                MD_Import_Force_Logger::log_message("MD Import Force [HANDLER BATCH]: Tamaño de lote reducido a {$reduced_size} items por uso alto de memoria.");
            }
        }

        // Ensure handlers have the latest site_info and id_mapping
        $this->media_handler->set_source_site_info($this->source_site_info);
        $this->post_importer->set_source_site_info($this->source_site_info);
        
        // Load/refresh ID mapping before post import, as terms might have been processed above
        $this->id_mapping = $this->load_terms_mapping($import_id);
        $this->taxonomy_importer->set_id_mapping($this->id_mapping); // Though terms are done for this batch start
        $this->post_importer->set_id_mapping($this->id_mapping);


        $post_importer_result = $this->post_importer->import_posts(
            $batch_items_for_this_call,
            $import_id,
            $options,
            $overall_processed_count_ref, // Pass by reference
            $total_items,
            $batch_run_start_time,    // Pass through from Job Manager
            $time_limit_for_this_run  // Pass through from Job Manager
        );
        
        // Limpieza de memoria después del procesamiento de posts
        $this->cleanup_handler_memory();
        
        if (isset($post_importer_result['media_references']) && is_array($post_importer_result['media_references'])) {
            $media_refs_count = count($post_importer_result['media_references']);
            if ($media_refs_count > 0) {
                MD_Import_Force_Logger::log_message("MD Import Force [HANDLER BATCH]: Se encontraron {$media_refs_count} referencias de medios para GUID: {$import_run_guid}. Guardando en cola...");
                foreach ($post_importer_result['media_references'] as $ref) {
                    MD_Import_Force_Media_Queue_Manager::add_item(
                        $import_run_guid,
                        $ref['post_id'],
                        $ref['original_post_id_from_file'],
                        $ref['media_type'],
                        $ref['original_url']
                    );
                }
            }
        }
        
        // Monitoreo final de memoria
        $memory_end = memory_get_usage(true);
        $memory_peak_end = memory_get_peak_usage(true);
        
        MD_Import_Force_Logger::log_message("MD Import Force [HANDLER BATCH]: Lote completado. Items procesados: " . ($post_importer_result['items_actually_processed_this_run'] ?? 0) . ". Memoria final: " . size_format($memory_end) . ", Pico: " . size_format($memory_peak_end) . ", Diferencia: " . size_format($memory_end - $memory_start));
        
        return $post_importer_result;
    }

    private function cleanup_handler_memory() {
        // Forzar liberación de memoria PHP
        if (function_exists('gc_collect_cycles')) {
            $cycles_freed = gc_collect_cycles();
            if ($cycles_freed > 0) {
                MD_Import_Force_Logger::log_message("MD Import Force [HANDLER MEMORY]: Liberados {$cycles_freed} ciclos de memoria en handler.");
            }
        }
        
        // Limpiar cache de WordPress
        wp_cache_flush();
        
        // Limpiar variables globales que podrían estar cargadas
        global $wp_object_cache, $wpdb;
        
        if (isset($wp_object_cache) && is_object($wp_object_cache)) {
            if (method_exists($wp_object_cache, 'flush')) {
                $wp_object_cache->flush();
            }
        }
        
        // Limpiar cache de base de datos
        if (isset($wpdb) && is_object($wpdb)) {
            $wpdb->flush();
        }
        
        // Limpiar arrays grandes del handler si existen
        if (isset($this->id_mapping) && is_array($this->id_mapping) && count($this->id_mapping) > 1000) {
            // Solo limpiar si el mapeo es muy grande, pero recargar después si es necesario
            MD_Import_Force_Logger::log_message("MD Import Force [HANDLER MEMORY]: ID mapping muy grande (" . count($this->id_mapping) . " items), considerando optimización.");
        }
    }

    private function convert_memory_limit_to_bytes($memory_limit) {
        if (empty($memory_limit) || $memory_limit == '-1') {
            // Sin límite de memoria
            return PHP_INT_MAX;
        }
        
        $memory_limit = trim($memory_limit);
        $unit = strtolower(substr($memory_limit, -1));
        $size = (int) substr($memory_limit, 0, -1);
        
        switch ($unit) {
            case 'g':
                $size *= 1024;
            case 'm':
                $size *= 1024;
            case 'k':
                $size *= 1024;
                break;
            default:
                // Asumir que ya está en bytes si no hay unidad
                $size = (int) $memory_limit;
        }
        
        return $size;
    }
} // Fin clase
