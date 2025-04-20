<?php
/**
 * Clase para importar posts y páginas
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

class MD_Import_Force_Post_Importer {

    private $id_mapping = [];
    private $source_site_info = [];
    private $progress_tracker;
    private $media_handler;
    private $taxonomy_importer;
    private $comment_importer;

    public function __construct(
        $id_mapping = [],
        $source_site_info = [],
        $taxonomy_importer = null,
        $media_handler = null,
        $comment_importer = null,
        $progress_tracker = null
    ) {
        $this->id_mapping = $id_mapping;
        $this->source_site_info = $source_site_info;
        $this->taxonomy_importer = $taxonomy_importer ?: new MD_Import_Force_Taxonomy_Importer($id_mapping);
        $this->media_handler = $media_handler ?: new MD_Import_Force_Media_Handler($source_site_info);
        $this->comment_importer = $comment_importer ?: new MD_Import_Force_Comment_Importer();
        $this->progress_tracker = $progress_tracker ?: new MD_Import_Force_Progress_Tracker();
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
     */
    public function import_posts($items_data) {
        $imported = 0; $updated = 0; $skipped = 0; $total = count($items_data);
        MD_Import_Force_Logger::log_message("MD Import Force: Procesando {$total} posts/páginas...");

        // Enviar información de inicio para la barra de progreso
        $this->progress_tracker->send_progress_update(0, $total, null);

        $count = 0;
        $update_frequency = max(1, intval($total / 20)); // Actualizar aproximadamente 20 veces durante el proceso

        foreach ($items_data as $item_data) {
            $count++;
            $id = $item_data['ID'] ?? 'N/A';
            $title = $item_data['post_title'] ?? '[Sin Título]';
            $type = $item_data['post_type'] ?? 'post';

            // Enviar actualización de progreso solo cada cierto número de elementos
            // o para el primer y último elemento
            if ($count == 1 || $count == $total || $count % $update_frequency == 0) {
                $current_item = "ID: {$id} - {$title} ({$type})";
                $this->progress_tracker->send_progress_update($count, $total, $current_item);
            }

            try {
                $res = $this->process_post_item($item_data);
                if ($res === 'imported') $imported++; elseif ($res === 'updated') $updated++; else $skipped++;
            } catch (Exception $e) {
                MD_Import_Force_Logger::log_message("MD Import Force [ERROR] Post/Página ID {$id} ('{$title}'): " . $e->getMessage()); $skipped++;
            }
            if (function_exists('wp_cache_flush')) wp_cache_flush(); if (function_exists('gc_collect_cycles')) gc_collect_cycles();

            // No hacemos pausa en cada iteración para mejorar el rendimiento
            // Solo hacemos pausa cuando enviamos una actualización de progreso
        }

        // Enviar actualización final
        $this->progress_tracker->send_progress_update($total, $total, __('Importación completada con éxito', 'md-import-force'));

        // Marcar la importación como completada
        if (method_exists($this->progress_tracker, 'mark_as_completed')) {
            $this->progress_tracker->mark_as_completed();
        }

        $msg = sprintf(__('Posts/Páginas: %d nuevos, %d actualizados, %d omitidos.', 'md-import-force'), $imported, $updated, $skipped);
        MD_Import_Force_Logger::log_message("MD Import Force: " . $msg);
        return ['success' => true, 'new_count' => $imported, 'updated_count' => $updated, 'skipped_count' => $skipped, 'message' => $msg];
    }

    /**
     * Procesa un único post/página.
     */
    private function process_post_item($item_data) {
        $id = intval($item_data['ID'] ?? 0);
        $title = $item_data['post_title'] ?? '[Sin Título]';
        $type = $item_data['post_type'] ?? 'post';

        if ($id <= 0) {
            MD_Import_Force_Logger::log_message("MD Import Force [SKIP] Post ID {$id}: ID inválido.");
            return 'skipped';
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
            if ($existing->post_type === $type) { $item_arr['ID'] = $id; $action = 'update'; }
            else { MD_Import_Force_Logger::log_message("MD Import Force [CONFLICT/SKIP] Post ID {$id}: Tipo existente '{$existing->post_type}' != Importado '{$type}'."); return 'skipped'; }
        } else { $item_arr['import_id'] = $id; $action = 'insert'; }

        $result_id = wp_insert_post($item_arr, true);

        if (is_wp_error($result_id)) {
             if ($action === 'insert' && $result_id->get_error_code() === 'invalid_post_id') {
                 MD_Import_Force_Logger::log_message("MD Import Force [WARN] Post ID {$id}: Falló inserción ('invalid_post_id'), reintentando como update.");
                 $item_arr['ID'] = $id; unset($item_arr['import_id']); $result_id = wp_insert_post($item_arr, true);
                 if (!is_wp_error($result_id)) $action = 'update';
                 else { MD_Import_Force_Logger::log_message("MD Import Force [ERROR] Post ID {$id}: Falló update fallback: " . $result_id->get_error_message()); return 'skipped'; }
             } elseif (is_wp_error($result_id)) throw new Exception("Error {$action} Post ID {$id}: " . $result_id->get_error_message());
        }

        $processed_id = $result_id;
        if ($processed_id != $id) { MD_Import_Force_Logger::log_message("MD Import Force [WARN] Post ID {$id}: ID procesado {$processed_id} != ID original. Omitiendo post-procesado."); return 'skipped'; }

        $this->id_mapping[$id] = $processed_id;
        $this->save_meta_data($processed_id, $item_data);
        update_post_meta($processed_id, '_md_original_id', $id);

        // Procesar imagen destacada
        if (!empty($item_data['featured_image'])) {
            $this->media_handler->process_featured_image($processed_id, $item_data['featured_image']);
        }

        // Procesar imágenes en contenido
        $this->media_handler->process_content_images($processed_id, $item_data);

        // Asignar categorías
        if (!empty($item_data['categories'])) {
            $this->taxonomy_importer->assign_categories($processed_id, $item_data['categories']);
        }

        // Asignar etiquetas
        if (!empty($item_data['tags'])) {
            $this->taxonomy_importer->assign_tags($processed_id, $item_data['tags']);
        }

        // Importar comentarios
        if (!empty($item_data['comments'])) {
            $this->comment_importer->import_comments($processed_id, $item_data['comments']);
        }

        return $action === 'insert' ? 'imported' : 'updated';
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
                if (is_array($val) && isset($val['value'])) $val = $val['value'];
                update_post_meta($post_id, $key, $val);
            }
        }
    }


}
