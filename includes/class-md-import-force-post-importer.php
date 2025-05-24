<?php
/**
 * Clase para importar posts y páginas
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

// Incluir el rastreador de elementos omitidos
require_once(dirname(__FILE__) . '/class-md-import-force-skipped-items-tracker.php');
// Asegurarse que el progress tracker está disponible para los métodos estáticos
require_once(dirname(__FILE__) . '/class-md-import-force-progress-tracker.php');

class MD_Import_Force_Post_Importer {

    private $id_mapping = [];
    private $source_site_info = [];
    // private $progress_tracker; // No longer an instance property
    private $media_handler;
    private $taxonomy_importer;
    private $comment_importer;
    private $skipped_items_tracker;

    public function __construct(
        $id_mapping = [],
        $source_site_info = [],
        $taxonomy_importer = null,
        $media_handler = null,
        $comment_importer = null
        // $progress_tracker = null // Removed from constructor
    ) {
        $this->id_mapping = $id_mapping;
        $this->source_site_info = $source_site_info;
        $this->taxonomy_importer = $taxonomy_importer ?: new MD_Import_Force_Taxonomy_Importer($id_mapping);
        $this->media_handler = $media_handler ?: new MD_Import_Force_Media_Handler($source_site_info);
        $this->comment_importer = $comment_importer ?: new MD_Import_Force_Comment_Importer();
        // $this->progress_tracker = $progress_tracker ?: new MD_Import_Force_Progress_Tracker(); // Removed
        $this->skipped_items_tracker = MD_Import_Force_Skipped_Items_Tracker::get_instance();
    }

    /**
     * Establece el mapeo de IDs
     */
    public function set_id_mapping($id_mapping) {
        $this->id_mapping = $id_mapping;
        $this->taxonomy_importer->set_id_mapping($id_mapping);
    }

    /**
     * Obtiene el mapeo de IDs actualizado
     */
    public function get_id_mapping() {
        return $this->id_mapping;
    }

    /**
     * Establece la información del sitio de origen
     */
    public function set_source_site_info($source_site_info) {
        $this->source_site_info = $source_site_info;
        $this->media_handler->set_source_site_info($source_site_info);
    }

    /**
     * Importa posts/páginas uno por uno.
     * @param array $items_data Datos de los posts a importar.
     * @param string $import_id ID único de la importación global.
     * @param array $options Opciones de importación.
     * @param int &$overall_processed_count_ref Referencia al contador global de elementos procesados (actualizado por este método).
     * @param int $overall_total_items_in_file Total de elementos en el archivo de importación global.
     * @return array Estadísticas de esta tanda de posts (new_count, updated_count, skipped_count, etc.).
     */
    public function import_posts($items_data, $import_id, $options, &$overall_processed_count_ref, $overall_total_items_in_file) {
        $new_count_local = 0; 
        $updated_count_local = 0; 
        $skipped_count_local = 0;
        $total_items_in_this_batch = count($items_data);
        $media_references_for_queue = []; // Array para recopilar todas las referencias de medios de esta tanda
        
        MD_Import_Force_Logger::log_message("MD Import Force [POST_IMPORTER]: Iniciando procesamiento de {$total_items_in_this_batch} posts para import_id: {$import_id}. Avance global actual: {$overall_processed_count_ref}/{$overall_total_items_in_file}. Total global esperado: {$overall_total_items_in_file}");

        // Limpiar el rastreador de elementos omitidos para esta tanda (si se quiere por tanda, o globalmente en Handler)
        // Si es global, el Handler lo gestiona. Si es por tanda (ej. por JSON en un ZIP), aquí.
        // $this->skipped_items_tracker->clear(); // Comentado, la gestión de skipped_items es ahora más global en el Handler.

        // No se usa el $this->progress_tracker, se usan los métodos estáticos de MD_Import_Force_Progress_Tracker
        // La actualización inicial de progreso (total_items_in_file) la hace el Handler.

        foreach ($items_data as $item_data) {
            $overall_processed_count_ref++; // Incrementar el contador global de procesados
            
            $original_id = $item_data['ID'] ?? 'N/A';
            $title = $item_data['post_title'] ?? '[Sin Título]';
            $type = $item_data['post_type'] ?? 'post';

            // >>> INICIO: Lógica para importar solo faltantes <<<
            if (isset($options['import_only_missing']) && ($options['import_only_missing'] === true || $options['import_only_missing'] === '1' || $options['import_only_missing'] === 1)) {
                // Loguear el valor exacto para diagnóstico
                $option_value = var_export($options['import_only_missing'], true);
                MD_Import_Force_Logger::log_message("MD Import Force [POST_IMPORTER]: Opción import_only_missing activada con valor: {$option_value}");
                
                $existing_post_check = get_page_by_title(html_entity_decode($title), OBJECT, $type);
                if ($existing_post_check !== null && $existing_post_check->post_status !== 'trash') {
                    $skipped_count_local++;
                    $reason = __('Omitido: El post ya existe (opción importar solo faltantes activa)', 'md-import-force');
                    $this->skipped_items_tracker->add_skipped_item($original_id, $title, $type, $reason);
                    MD_Import_Force_Logger::log_message("MD Import Force [SKIP POST - ONLY MISSING]: Post ID {$original_id} ('{$title}') omitido porque ya existe.");
                    
                    // Actualizar progreso para este item omitido
                    $current_item_message_skipped = sprintf(__('Omitido (%d/%d): %s ID %s (%s) - Ya existe', 'md-import-force'),
                        $overall_processed_count_ref,
                        $overall_total_items_in_file,
                        $type,
                        $original_id,
                        $title
                    );
                    MD_Import_Force_Progress_Tracker::update_progress(
                        $import_id,
                        $overall_processed_count_ref,
                        $overall_total_items_in_file,
                        $current_item_message_skipped
                    );
                    continue; // Saltar al siguiente item
                }
            }
            // >>> FIN: Lógica para importar solo faltantes <<<

            // Verificar si se ha solicitado detener las importaciones (global o específica)
            if (get_option('md_import_force_stop_all_imports_requested', false) || get_transient('md_import_force_stop_request_' . $import_id)) {
                $stop_reason = get_transient('md_import_force_stop_request_' . $import_id) 
                    ? "solicitud específica para import_id {$import_id}" 
                    : "solicitud global";
                    
                MD_Import_Force_Logger::log_message("MD Import Force [STOP REQUESTED]: Importación detenida por {$stop_reason} después de procesar {$overall_processed_count_ref} elementos.");
                
                // Actualizar el progreso indicando que se detuvo manualmente
                MD_Import_Force_Progress_Tracker::update_status(
                    $import_id, 
                    'stopped', 
                    __('Importación detenida manualmente por el usuario.', 'md-import-force')
                );
                
                // Devolver los resultados parciales hasta el momento de la detención
                return [
                    'new_count' => $new_count_local,
                    'updated_count' => $updated_count_local,
                    'skipped_count' => $skipped_count_local,
                    'processed_count' => $overall_processed_count_ref,
                    'total_count' => $overall_total_items_in_file,
                    'stopped_manually' => true,
                    'message' => __('Importación detenida manualmente por el usuario.', 'md-import-force')
                ];
            }

            $current_item_message = sprintf(__('Procesando (%d/%d): %s ID %s (%s)', 'md-import-force'),
                $overall_processed_count_ref,
                $overall_total_items_in_file,
                $type,
                $original_id,
                $title
            );
            MD_Import_Force_Progress_Tracker::update_progress(
                $import_id,
                $overall_processed_count_ref,
                $overall_total_items_in_file,
                $current_item_message
            );

            try {
                // Modificado: process_post_item ahora también devuelve las referencias de medios para el post actual
                $result_array = $this->process_post_item($item_data, $options); // $options se pasan aquí
                $res = $result_array['status'];
                
                if (isset($result_array['media_references']) && !empty($result_array['media_references'])) {
                    $media_references_for_queue = array_merge($media_references_for_queue, $result_array['media_references']);
                }

                if ($res === 'imported') $new_count_local++;
                elseif ($res === 'updated') $updated_count_local++;
                else $skipped_count_local++;
            } catch (Exception $e) {
                MD_Import_Force_Logger::log_message("MD Import Force [POST_IMPORTER ERROR] Post/Página ID {$original_id} ('{$title}') para import_id {$import_id}: " . $e->getMessage());
                $skipped_count_local++;
                $this->skipped_items_tracker->add_skipped_item($original_id, $title, $type, $e->getMessage());
            }
            // No wp_cache_flush o gc_collect_cycles aquí para no ralentizar el proceso en segundo plano.
            // El Handler puede hacer un flush al final de todo el job si es necesario.
        }

        // Las actualizaciones finales de progreso (mark_complete o failed) las hace el cron job execute_background_import
        // basado en el resultado del Handler.

        $msg = sprintf(__('Sub-tanda de Posts/Páginas: %d nuevos, %d actualizados, %d omitidos.', 'md-import-force'), $new_count_local, $updated_count_local, $skipped_count_local);
        MD_Import_Force_Logger::log_message("MD Import Force [POST_IMPORTER]: " . $msg . " para import_id {$import_id}");

        // Skipped items son acumulados globalmente por el Handler ahora.
        // Aquí solo devolvemos las stats de esta tanda específica.
        // El skipped_items_tracker es una instancia singleton, así que el Handler puede obtener el global.
        // Sin embargo, para ZIPs, el Handler podría querer stats por archivo JSON.
        // Por simplicidad, el Handler acumulará los skipped_items.

        return [
            // 'success' => true, // El handler determinará el success general
            'new_count' => $new_count_local,
            'updated_count' => $updated_count_local,
            'skipped_count' => $skipped_count_local,
            'media_references' => $media_references_for_queue, // Devolver las referencias de medios recopiladas
            'message' => $msg
            // No devolver 'skipped_items' aquí directamente, el Handler lo recuperará de $this->skipped_items_tracker globalmente si es necesario
            // o lo acumulará a partir de los errores y skips que logueamos y contamos.
        ];
    }

    /**
     * Extrae todas las URLs de imágenes únicas del contenido HTML.
     *
     * @param string $content Contenido HTML.
     * @return array Array de URLs de imágenes únicas.
     */
    private function extract_image_urls_from_content($content) {
        $image_urls = [];
        if (empty($content)) {
            return $image_urls;
        }

        if (class_exists('DOMDocument')) {
            $doc = new DOMDocument();
            @$doc->loadHTML('<?xml encoding="utf-8" ?><html><body>' . $content . '</body></html>'); // Suppress warnings for malformed HTML
            libxml_clear_errors(); 

            $img_tags = $doc->getElementsByTagName('img');
            foreach ($img_tags as $img) {
                $src = $img->getAttribute('src');
                if (!empty($src)) {
                    $image_urls[] = trim($src);
                }
            }
        } else {
            // Fallback a regex si DOMDocument no está disponible (menos robusto)
            // Corrected Regex for PHP single-quoted string:
            preg_match_all('/<img[^>]+src\s*=\s*([\'"])(.*?)\1[^>]*>/i', $content, $matches);
            if (!empty($matches[2])) { // Match group 2 contains the URL
                foreach ($matches[2] as $src) {
                    $image_urls[] = trim($src);
                }
            }
        }
        
        return array_unique($image_urls);
    }

    /**
     * Procesa un único post/página.
     * Devuelve un array con el estado y las referencias de medios.
     */
    private function process_post_item($item_data, $options = []) {
        $id = intval($item_data['ID'] ?? 0);
        $title = $item_data['post_title'] ?? '[Sin Título]';
        $type = $item_data['post_type'] ?? 'post';
        $current_post_media_references = []; // Referencias de medios para ESTE post

        // Omitir directamente los elementos de tipo oembed_cache
        if ($type === 'oembed_cache') {
            $reason = "Tipo 'oembed_cache' omitido por configuración";
            MD_Import_Force_Logger::log_message("MD Import Force [SKIP] Post ID {$id}: {$reason}.");
            $this->skipped_items_tracker->add_skipped_item($id, $title, $type, $reason);
            return ['status' => 'skipped', 'media_references' => $current_post_media_references];
        }

        if ($id <= 0) {
            $reason = "ID inválido";
            MD_Import_Force_Logger::log_message("MD Import Force [SKIP] Post ID {$id}: {$reason}.");
            $this->skipped_items_tracker->add_skipped_item($id, $title, $type, $reason);
            return ['status' => 'skipped', 'media_references' => $current_post_media_references];
        }

        // Preparar datos del post
        $item_arr = [
            'post_title' => $title,
            'post_content' => $item_data['post_content'] ?? '',
            'post_excerpt' => $item_data['post_excerpt'] ?? '',
            'post_status' => $item_data['post_status'] ?? 'publish',
            'post_type' => $type,
            'post_name' => $item_data['post_name'] ?? sanitize_title($title),
            'post_author' => 1, // Por defecto, el primer admin
            'post_parent' => 0, // Por defecto, sin padre
            'menu_order' => intval($item_data['menu_order'] ?? 0),
            'comment_status' => $item_data['comment_status'] ?? 'closed',
            'ping_status' => $item_data['ping_status'] ?? 'closed',
        ];

        // Manejar fecha de publicación
        $item_arr['post_date'] = $item_data['post_date'] ?? current_time('mysql');
        $item_arr['post_date_gmt'] = $item_data['post_date_gmt'] ?? get_gmt_from_date($item_arr['post_date']);
        $item_arr['post_modified'] = $item_data['post_modified'] ?? $item_arr['post_date'];
        $item_arr['post_modified_gmt'] = $item_data['post_modified_gmt'] ?? $item_arr['post_date_gmt'];

        $action = ''; $existing = get_post($id);

        if ($existing) {
            if ($existing->post_type === $type) {
                $item_arr['ID'] = $id;
                $action = 'update';
            } else {
                $reason = "Tipo existente '{$existing->post_type}' != Importado '{$type}'";
                MD_Import_Force_Logger::log_message("MD Import Force [CONFLICT/SKIP] Post ID {$id}: {$reason}.");
                $this->skipped_items_tracker->add_skipped_item($id, $title, $type, $reason);
                return ['status' => 'skipped', 'media_references' => $current_post_media_references];
            }
        } else {
            $item_arr['import_id'] = $id;
            $action = 'insert';
        }

        // Ensure 'force_ids' option is respected for inserts
        if ($action === 'insert' && !(isset($options['force_ids']) && $options['force_ids'])) {
            // If not forcing IDs, remove import_id to let WP generate a new ID
            unset($item_arr['import_id']);
        }

        $result_id = wp_insert_post($item_arr, true);

        if (is_wp_error($result_id)) {
             if ($action === 'insert' && $result_id->get_error_code() === 'invalid_post_id' && isset($item_arr['import_id'])) {
                 // This case implies an attempt to insert with an ID that already exists,
                 // or some other ID-related insertion issue when import_id was set.
                 // If force_ids was true, we might want to retry as an update if the post truly exists with that ID.
                 MD_Import_Force_Logger::log_message("MD Import Force [WARN] Post ID {$id}: Falló inserción con import_id ('invalid_post_id'), verificando si existe para reintentar como update.");
                 $existing_check = get_post($id);
                 if ($existing_check && $existing_check->post_type === $type) {
                     $item_arr['ID'] = $id; 
                     unset($item_arr['import_id']); 
                     $result_id = wp_insert_post($item_arr, true);
                     if (!is_wp_error($result_id)) {
                        $action = 'update'; // Successfully updated
                     } else {
                        $reason = "Falló update tras fallo de insert con ID: " . $result_id->get_error_message();
                        MD_Import_Force_Logger::log_message("MD Import Force [ERROR] Post ID {$id}: {$reason}");
                        $this->skipped_items_tracker->add_skipped_item($id, $title, $type, $reason);
                        return ['status' => 'skipped', 'media_references' => $current_post_media_references];
                     }
                 } else {
                    $reason = "Falló inserción con ID {$id} ('invalid_post_id') y el post no existe o es de tipo incorrecto para update.";
                    MD_Import_Force_Logger::log_message("MD Import Force [ERROR] Post ID {$id}: {$reason} - Detalle: " . $result_id->get_error_message());
                    $this->skipped_items_tracker->add_skipped_item($id, $title, $type, $reason . " - " . $result_id->get_error_message());
                    return ['status' => 'skipped', 'media_references' => $current_post_media_references];
                 }

             } else { // Other wp_insert_post errors
                 $reason = "Error en {$action} para Post ID {$id}: " . $result_id->get_error_message();
                 MD_Import_Force_Logger::log_message("MD Import Force [ERROR] {$reason}");
                 $this->skipped_items_tracker->add_skipped_item($id, $title, $type, $reason);
                 // throw new Exception($reason); // Or return skipped
                 return ['status' => 'skipped', 'media_references' => $current_post_media_references];
             }
        }

        $processed_id = $result_id;

        // If forcing IDs, and the resulting ID doesn't match, this is an issue.
        if (isset($options['force_ids']) && $options['force_ids'] && $processed_id != $id) {
            $reason = "ID procesado {$processed_id} != ID original {$id} con force_ids activo. Esto no debería ocurrir si wp_insert_post funcionó como se esperaba.";
            MD_Import_Force_Logger::log_message("MD Import Force [CRITICAL WARN] Post ID {$id}: {$reason}");
            // This might be a critical state, decide if to skip or flag heavily
            $this->skipped_items_tracker->add_skipped_item($id, $title, $type, $reason);
            // Depending on severity, might return 'skipped'
        }
        
        // If not forcing IDs, $processed_id might be different from $id (if $id was from source and new post created)
        // $id_mapping should map $original_id_from_file to $processed_wp_id
        $this->id_mapping[$id] = $processed_id; 
        
        // --- INICIO: Recopilación de Referencias de Medios (en lugar de procesamiento inmediato) ---
        // Imagen destacada
        if (!empty($item_data['featured_image'])) {
            $current_post_media_references[] = [
                // 'import_run_guid' => $this->current_import_run_guid, // Se añadirá en el Handler/JobManager
                'post_id' => $processed_id,
                'original_post_id_from_file' => $id, // ID original del post del archivo
                'media_type' => 'featured_image',
                'original_url' => $item_data['featured_image']
            ];
        }

        // Imágenes en contenido
        if (!empty($item_data['post_content'])) {
            $content_image_urls = $this->extract_image_urls_from_content($item_data['post_content']);
            foreach ($content_image_urls as $img_url) {
                $current_post_media_references[] = [
                    // 'import_run_guid' => $this->current_import_run_guid,
                    'post_id' => $processed_id,
                    'original_post_id_from_file' => $id,
                    'media_type' => 'content_image',
                    'original_url' => $img_url
                ];
            }
        }
        // --- FIN: Recopilación de Referencias de Medios ---
        
        // Procesar metadatos
        $this->save_meta_data($processed_id, $item_data);
        update_post_meta($processed_id, '_md_original_id', $id); // Guardar el ID original como meta

        // Procesar imágenes en contenido - YA NO SE HACE AQUÍ
        // $this->media_handler->process_content_images($processed_id, $item_data);

        // Asignar categorías y etiquetas
        if (!empty($item_data['categories'])) {
            $this->taxonomy_importer->assign_terms($processed_id, $item_data['categories'], 'category');
        }
        if (!empty($item_data['tags'])) {
            $this->taxonomy_importer->assign_terms($processed_id, $item_data['tags'], 'post_tag');
        }

        // Importar comentarios
        if (!empty($item_data['comments'])) {
            $this->comment_importer->import_comments($processed_id, $item_data['comments'], $this->id_mapping);
        }

        // Return 'imported' for new posts, 'updated' for existing.
        $final_status = ($action === 'insert' && !is_wp_error($result_id)) ? 'imported' : $action;
        if (is_wp_error($result_id)) $final_status = 'skipped'; // Ensure errors lead to skipped status if not caught earlier

        return ['status' => $final_status, 'media_references' => $current_post_media_references]; 
    }

    /**
     * Guarda metadatos del post
     */
    private function save_meta_data($post_id, $post_data) {
        if (!empty($post_data['meta_title'])) { update_post_meta($post_id, '_yoast_wpseo_title', $post_data['meta_title']); update_post_meta($post_id, '_aioseo_title', $post_data['meta_title']); }
        if (!empty($post_data['meta_description'])) { update_post_meta($post_id, '_yoast_wpseo_metadesc', $post_data['meta_description']); update_post_meta($post_id, '_aioseo_description', $post_data['meta_description']); }
        if (!empty($post_data['breadcrumb_title'])) update_post_meta($post_id, 'rank_math_breadcrumb_title', $post_data['breadcrumb_title']);
        if (!empty($post_data['meta_data']) && is_array($post_data['meta_data'])) {
            foreach ($post_data['meta_data'] as $key => $val) {
                if (is_array($val) && isset($val['value'])) $val = $val['value']; // Handle Msls_Admin_Import - Export format
                update_post_meta($post_id, $key, $val);
            }
        }
    }


}
