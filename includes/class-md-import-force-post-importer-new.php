<?php
/**
 * Clase para manejar la importación de posts/páginas
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

class MD_Import_Force_Post_Importer {

    /**
     * Mapeo de IDs entre el sitio de origen y el sitio de destino
     */
    private $id_mapping = [];

    /**
     * Información del sitio de origen
     */
    private $source_site_info = [];

    /**
     * Instancia del importador de taxonomías
     */
    private $taxonomy_importer;

    /**
     * Instancia del manejador de medios
     */
    private $media_handler;

    /**
     * Instancia del importador de comentarios
     */
    private $comment_importer;

    /**
     * Instancia del rastreador de progreso
     */
    private $progress_tracker;

    /**
     * Constructor
     */
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
        try {
            $imported = 0; $updated = 0; $skipped = 0; $total = count($items_data);
            MD_Import_Force_Logger::log_message("MD Import Force: Procesando {$total} posts/páginas...");

            // Iniciar el proceso de importación
            // Enviar información de inicio para la barra de progreso
            $this->progress_tracker->send_progress_update(0, $total, null);

            // Establecer un límite de tiempo más alto para la importación
            if (function_exists('set_time_limit')) {
                @set_time_limit(300); // 5 minutos
            }

            // Calcular la frecuencia de actualización de progreso
            $count = 0;
            $update_frequency = max(1, intval($total / 20)); // Actualizar aproximadamente 20 veces durante el proceso

            // Detectar automáticamente la URL del sitio de origen si está disponible
            if (!empty($this->source_site_info['site_url']) && class_exists('MD_Import_Force_URL_Handler')) {
                $this->media_handler->set_source_url($this->source_site_info['site_url']);
                MD_Import_Force_Logger::log_message("MD Import Force: URL de origen establecida: {$this->source_site_info['site_url']}");
            }

            // Procesar cada elemento
            foreach ($items_data as $item_data) {
                $count++;
                $id = intval($item_data['ID'] ?? 0);
                $title = $item_data['post_title'] ?? '[Sin Título]';
                $type = $item_data['post_type'] ?? 'post';

                // Enviar actualización de progreso solo cada cierto número de elementos
                // o para el primer y último elemento
                if ($count == 1 || $count == $total || $count % $update_frequency == 0) {
                    $current_item = "ID: {$id} - {$title} ({$type})";
                    $this->progress_tracker->send_progress_update($count, $total, $current_item);
                }

                // Procesar el elemento con manejo de errores
                try {
                    $res = $this->process_post_item($item_data);
                    if ($res === 'imported') $imported++;
                    elseif ($res === 'updated') $updated++;
                    else $skipped++;
                } catch (Exception $e) {
                    MD_Import_Force_Logger::log_message("MD Import Force [ERROR] Post/Página ID {$id} ('{$title}'): " . $e->getMessage());
                    $skipped++;
                }

                // Limpiar la memoria después de cada elemento
                if (function_exists('wp_cache_flush')) wp_cache_flush();
                if (function_exists('gc_collect_cycles')) gc_collect_cycles();

                // Verificar si debemos hacer una pausa para evitar timeouts
                if ($count % 10 == 0) {
                    // Pequeña pausa para permitir que el servidor respire
                    usleep(100000); // 0.1 segundos
                }
            }

            // Enviar actualización final
            $this->progress_tracker->send_progress_update($total, $total, __('Importación completada con éxito', 'md-import-force'));

            // Asegurarse de que los datos se envíen al navegador
            if (function_exists('ob_flush')) ob_flush();
            if (function_exists('flush')) flush();

            // Esperar un momento para asegurar que los datos se envíen
            usleep(500000); // 0.5 segundos

            // Marcar la importación como completada
            if (method_exists($this->progress_tracker, 'mark_as_completed')) {
                $this->progress_tracker->mark_as_completed();

                // Asegurarse de que los datos de completado se envíen al navegador
                if (function_exists('ob_flush')) ob_flush();
                if (function_exists('flush')) flush();
            }

            $msg = sprintf(__('Posts/Páginas: %d nuevos, %d actualizados, %d omitidos.', 'md-import-force'), $imported, $updated, $skipped);
            MD_Import_Force_Logger::log_message("MD Import Force: " . $msg);

            // Registrar en el log para depuración
            MD_Import_Force_Logger::log_message("MD Import Force [DEBUG]: Importación finalizada con {$imported} nuevos, {$updated} actualizados, {$skipped} omitidos.");

            return [
                'success' => true,
                'new_count' => $imported,
                'updated_count' => $updated,
                'skipped_count' => $skipped,
                'message' => $msg
            ];
        } catch (Exception $e) {
            // Capturar cualquier error no manejado
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR FATAL]: Error en import_posts: " . $e->getMessage());

            // Intentar marcar la importación como completada para evitar que se quede congelada
            try {
                if (method_exists($this->progress_tracker, 'mark_as_completed')) {
                    $this->progress_tracker->mark_as_completed();
                }
            } catch (Exception $inner_e) {
                // Ignorar errores al intentar marcar como completada
            }

            return [
                'success' => false,
                'new_count' => 0,
                'updated_count' => 0,
                'skipped_count' => 0,
                'message' => "Error: " . $e->getMessage()
            ];
        }
    }

    /**
     * Procesa un único post/página.
     */
    private function process_post_item($item_data) {
        $id = intval($item_data['ID'] ?? 0);
        $title = $item_data['post_title'] ?? '[Sin Título]';
        $type = $item_data['post_type'] ?? 'post';

        // Omitir directamente los elementos de tipo oembed_cache
        if ($type === 'oembed_cache') {
            $reason = "Tipo 'oembed_cache' omitido por configuración";
            MD_Import_Force_Logger::log_message("MD Import Force [SKIP] Post ID {$id}: {$reason}.");
            return 'skipped';
        }

        if ($id <= 0) {
            $reason = "ID inválido";
            MD_Import_Force_Logger::log_message("MD Import Force [SKIP] Post ID {$id}: {$reason}.");
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

        // Establecer fecha de publicación si está disponible
        if (!empty($item_data['post_date'])) {
            $item_arr['post_date'] = $item_data['post_date'];
            $item_arr['post_date_gmt'] = $item_data['post_date_gmt'] ?? $item_data['post_date'];
        }

        // Establecer fecha de modificación si está disponible
        if (!empty($item_data['post_modified'])) {
            $item_arr['post_modified'] = $item_data['post_modified'];
            $item_arr['post_modified_gmt'] = $item_data['post_modified_gmt'] ?? $item_data['post_modified'];
        }

        // Verificar si el post ya existe
        $existing = get_post($id);
        $action = '';

        if ($existing) {
            if ($existing->post_type === $type) {
                $item_arr['ID'] = $id;
                $action = 'update';
            } else {
                $reason = "Tipo existente '{$existing->post_type}' != Importado '{$type}'";
                MD_Import_Force_Logger::log_message("MD Import Force [CONFLICT/SKIP] Post ID {$id}: {$reason}.");
                return 'skipped';
            }
        } else {
            $item_arr['import_id'] = $id;
            $action = 'insert';
        }

        // Insertar o actualizar el post
        $result_id = wp_insert_post($item_arr, true);

        if (is_wp_error($result_id)) {
             if ($action === 'insert' && $result_id->get_error_code() === 'invalid_post_id') {
                 MD_Import_Force_Logger::log_message("MD Import Force [WARN] Post ID {$id}: Falló inserción ('invalid_post_id'), reintentando como update.");
                 $item_arr['ID'] = $id; unset($item_arr['import_id']); $result_id = wp_insert_post($item_arr, true);
                 if (!is_wp_error($result_id)) $action = 'update';
                 else {
                     $reason = "Falló update fallback: " . $result_id->get_error_message();
                     MD_Import_Force_Logger::log_message("MD Import Force [ERROR] Post ID {$id}: {$reason}");
                     return 'skipped';
                 }
             } elseif (is_wp_error($result_id)) throw new Exception("Error {$action} Post ID {$id}: " . $result_id->get_error_message());
        }

        $processed_id = $result_id;

        if ($processed_id != $id) {
            $reason = "ID procesado {$processed_id} != ID original. Omitiendo post-procesado.";
            MD_Import_Force_Logger::log_message("MD Import Force [WARN] Post ID {$id}: {$reason}");
            return 'skipped';
        }

        $this->id_mapping[$id] = $processed_id;
        $this->save_meta_data($processed_id, $item_data);
        update_post_meta($processed_id, '_md_original_id', $id);

        try {
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
        } catch (Exception $e) {
            // Registrar el error pero continuar con el siguiente elemento
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR] Post ID {$id}: Error en post-procesado: " . $e->getMessage());
        }

        return $action === 'insert' ? 'imported' : 'updated';
    }

    /**
     * Guarda metadatos del post
     */
    private function save_meta_data($post_id, $post_data) {
        // Metadatos SEO básicos
        if (!empty($post_data['meta_title'])) {
            update_post_meta($post_id, '_yoast_wpseo_title', $post_data['meta_title']);
            update_post_meta($post_id, '_aioseo_title', $post_data['meta_title']);
            update_post_meta($post_id, 'rank_math_title', $post_data['meta_title']);
        }

        if (!empty($post_data['meta_description'])) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $post_data['meta_description']);
            update_post_meta($post_id, '_aioseo_description', $post_data['meta_description']);
            update_post_meta($post_id, 'rank_math_description', $post_data['meta_description']);
        }

        // Metadatos específicos de Rank Math
        if (!empty($post_data['breadcrumb_title'])) {
            update_post_meta($post_id, 'rank_math_breadcrumb_title', $post_data['breadcrumb_title']);
        }

        // Lista de metadatos esenciales de Rank Math (excluyendo schema)
        $rank_math_essential_metas = array(
            'rank_math_title',
            'rank_math_description',
            'rank_math_focus_keyword',
            'rank_math_canonical_url',
            'rank_math_robots',
            'rank_math_breadcrumb_title',
            'rank_math_og_title',
            'rank_math_og_description',
            'rank_math_og_image',
            'rank_math_twitter_title',
            'rank_math_twitter_description',
            'rank_math_twitter_image'
        );

        // Lista de metadatos a excluir (relacionados con schema)
        $excluded_meta_keys = array(
            '_rank_math_schema_',
            'rank_math_schema_',
            'rank_math_snippet_'
        );

        if (!empty($post_data['meta_data']) && is_array($post_data['meta_data'])) {
            foreach ($post_data['meta_data'] as $key => $val) {
                // Verificar si es un metadato a excluir
                $should_exclude = false;
                foreach ($excluded_meta_keys as $excluded_prefix) {
                    if (strpos($key, $excluded_prefix) === 0) {
                        $should_exclude = true;
                        break;
                    }
                }

                // Si es un metadato de Rank Math, verificar si está en la lista de esenciales
                if (strpos($key, 'rank_math_') === 0 && !in_array($key, $rank_math_essential_metas)) {
                    $should_exclude = true;
                }

                // Si no debe excluirse, importar el metadato
                if (!$should_exclude) {
                    if (is_array($val)) {
                        foreach ($val as $v) {
                            add_post_meta($post_id, $key, $v);
                        }
                    } else {
                        update_post_meta($post_id, $key, $val);
                    }
                } else {
                    // Registrar en el log los metadatos excluidos para depuración
                    MD_Import_Force_Logger::log_message("MD Import Force [INFO] Post ID {$post_id}: Metadato excluido: {$key}");
                }
            }
        }
    }
}
