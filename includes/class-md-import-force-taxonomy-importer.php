<?php
/**
 * Clase para importar taxonomías
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

class MD_Import_Force_Taxonomy_Importer {

    private $id_mapping = [];

    public function __construct($id_mapping = []) {
        $this->id_mapping = $id_mapping;
    }

    /**
     * Establece el mapeo de IDs
     */
    public function set_id_mapping($id_mapping) {
        $this->id_mapping = $id_mapping;
    }

    /**
     * Obtiene el mapeo de IDs actualizado
     */
    public function get_id_mapping() {
        return $this->id_mapping;
    }

    /**
     * Importa términos de una taxonomía específica.
     */
    public function import_terms($terms_data, $taxonomy) {
        $term_mapping = array();
        MD_Import_Force_Logger::log_message("MD Import Force: Procesando " . count($terms_data) . " términos para '{$taxonomy}'.");
        foreach ($terms_data as $term_data) {
            try {
                $res = $this->process_term_item($term_data, $taxonomy);
                if ($res && isset($res['id']) && isset($res['original_id'])) {
                    $term_mapping[$res['original_id']] = $res['id'];
                    MD_Import_Force_Logger::log_message("MD Import Force Terms [DEBUG] Mapeando Term ID {$res['original_id']} a {$res['id']}");
                }
            } catch (Exception $e) { MD_Import_Force_Logger::log_message("MD Import Force Terms [ERROR] Term ID {$term_data['term_id']}: " . $e->getMessage()); }
            if (function_exists('wp_cache_flush')) wp_cache_flush(); if (function_exists('gc_collect_cycles')) gc_collect_cycles();
        }
        $prefix = $taxonomy . '_';
        foreach ($term_mapping as $old => $new) {
            $this->id_mapping[$prefix . $old] = $new;
            // También almacenamos el mapeo sin prefijo para compatibilidad
            $this->id_mapping[$old] = $new;
        }
        MD_Import_Force_Logger::log_message("MD Import Force: Importación de términos para '{$taxonomy}' completada. Términos mapeados: " . count($term_mapping));
        
        return $this->id_mapping;
    }

    /**
     * Procesa un único término.
     */
    private function process_term_item($term_data, $taxonomy) {
        $id = intval($term_data['term_id'] ?? 0);
        $name = $term_data['name'] ?? '[Sin Nombre]';
        $slug = sanitize_title($term_data['slug'] ?? $name);
        $desc = $term_data['description'] ?? '';

        if ($id <= 0 || empty($name) || empty($slug)) {
            MD_Import_Force_Logger::log_message("MD Import Force Terms [SKIP] Term ID {$id}: Datos inválidos.");
            return false;
        }

        $action = '';
        $existing = get_term_by('id', $id, $taxonomy);
        $processed_id = 0;

        if ($existing) {
            // El término ya existe con este ID
            if ($existing->taxonomy === $taxonomy) {
                // Actualizar el término existente
                $term_arr = ['name' => $name, 'description' => $desc, 'slug' => $slug];
                $result = wp_update_term($id, $taxonomy, $term_arr);
                if (is_wp_error($result)) {
                    MD_Import_Force_Logger::log_message("MD Import Force Terms [ERROR] Term ID {$id}: Error al actualizar término: " . $result->get_error_message());
                    return false;
                }
                $processed_id = $id;
                $action = 'update';
                MD_Import_Force_Logger::log_message("MD Import Force Terms [SUCCESS] Term ID {$id}: Actualizado correctamente.");
            } else {
                // Existe un término con este ID pero de otra taxonomía
                MD_Import_Force_Logger::log_message("MD Import Force Terms [CONFLICT] Term ID {$id}: Ya existe un término con este ID pero de taxonomía '{$existing->taxonomy}' != '{$taxonomy}'.");
                
                // Usar el método estándar de WordPress
                $term_arr = ['description' => $desc, 'slug' => $slug];
                $result = wp_insert_term($name, $taxonomy, $term_arr);
                if (is_wp_error($result)) {
                    if ($result->get_error_code() === 'term_exists') {
                        // El término ya existe con este nombre/slug
                        $term_id = $result->get_error_data();
                        if (is_array($term_id)) $term_id = $term_id['term_id'];
                        $processed_id = $term_id;
                        $action = 'existing';
                        MD_Import_Force_Logger::log_message("MD Import Force Terms [INFO] Term ID {$id}: Usando término existente con ID {$processed_id}.");
                    } else {
                        MD_Import_Force_Logger::log_message("MD Import Force Terms [ERROR] Term ID {$id}: Error al insertar término: " . $result->get_error_message());
                        return false;
                    }
                } else {
                    $processed_id = $result['term_id'];
                }
            }
        } else {
            // No existe un término con ese ID, podemos intentar forzarlo
            MD_Import_Force_Logger::log_message("MD Import Force Terms [INFO] Term ID {$id}: Intentando forzar ID original.");

            // Primero, insertar el término en wp_terms
            global $wpdb;
            $wpdb->insert(
                $wpdb->terms,
                [
                    'term_id' => $id,
                    'name' => $name,
                    'slug' => $slug,
                    'term_group' => 0
                ],
                ['%d', '%s', '%s', '%d']
            );

            if ($wpdb->insert_id != $id) {
                MD_Import_Force_Logger::log_message("MD Import Force Terms [ERROR] Term ID {$id}: No se pudo forzar ID en wp_terms. ID resultante: {$wpdb->insert_id}");
                // Limpiar el término insertado incorrectamente
                if ($wpdb->insert_id) {
                    $wpdb->delete($wpdb->terms, ['term_id' => $wpdb->insert_id]);
                }
                return false;
            }

            // Luego, obtener el term_taxonomy_id para esta taxonomía
            $wpdb->insert(
                $wpdb->term_taxonomy,
                [
                    'term_id' => $id,
                    'taxonomy' => $taxonomy,
                    'description' => $desc,
                    'parent' => 0,
                    'count' => 0
                ],
                ['%d', '%s', '%s', '%d', '%d']
            );

            $term_taxonomy_id = $wpdb->insert_id;
            if (!$term_taxonomy_id) {
                MD_Import_Force_Logger::log_message("MD Import Force Terms [ERROR] Term ID {$id}: No se pudo insertar en wp_term_taxonomy.");
                // Limpiar el término insertado
                $wpdb->delete($wpdb->terms, ['term_id' => $id]);
                return false;
            }

            $processed_id = $id;
            $action = 'force_insert';
            MD_Import_Force_Logger::log_message("MD Import Force Terms [SUCCESS] Term ID {$id}: ID forzado correctamente.");

            // Limpiar la caché de términos
            clean_term_cache($id, $taxonomy);
        }

        // Actualizar metadatos SEO
        $this->update_term_seo_meta($processed_id, $term_data);
        return ['id' => $processed_id, 'original_id' => $id, 'action' => $action];
    }

    /**
     * Actualiza metadatos SEO para términos
     */
    private function update_term_seo_meta($term_id, $term_data) {
        if (!empty($term_data['meta_title'])) {
            update_term_meta($term_id, '_yoast_wpseo_title', $term_data['meta_title']);
            update_term_meta($term_id, '_aioseo_title', $term_data['meta_title']);
        }
        if (!empty($term_data['meta_description'])) {
            update_term_meta($term_id, '_yoast_wpseo_metadesc', $term_data['meta_description']);
            update_term_meta($term_id, '_aioseo_description', $term_data['meta_description']);
        }
        if (!empty($term_data['meta_data']) && is_array($term_data['meta_data'])) {
            foreach ($term_data['meta_data'] as $key => $val) {
                if (is_array($val) && isset($val['value'])) $val = $val['value'];
                update_term_meta($term_id, $key, $val);
            }
        }
    }

    /**
     * Asigna categorías
     */
    public function assign_categories($post_id, $category_ids) { 
        $this->assign_terms($post_id, $category_ids, 'category'); 
    }
    
    /**
     * Asigna etiquetas
     */
    public function assign_tags($post_id, $tag_ids) { 
        $this->assign_terms($post_id, $tag_ids, 'post_tag'); 
    }
    
    /**
     * Asigna términos
     */
    public function assign_terms($post_id, $original_ids, $tax) {
        if (empty($original_ids) || !is_array($original_ids)) return; 
        
        $new_ids_all = []; 
        $prefix = $tax . '_';
        $found_count_total = 0;
        $not_found_count_total = 0;
        $total_mapping_entries = count($this->id_mapping);
        
        foreach ($original_ids as $old) {
            $old = intval($old);
            if ($old <= 0) continue;

            // Intentar obtener el ID mapeado con prefijo
            $new = $this->get_mapped_id($prefix . $old);

            // Si no se encuentra con prefijo, intentar sin prefijo
            if (!$new) $new = $this->get_mapped_id($old);

            if ($new) {
                $new_ids_all[] = intval($new);
                $found_count_total++;
                // MD_Import_Force_Logger::log_message("MD Import Force [DEBUG] Post ID {$post_id}: Término {$old} ({$tax}) mapeado a {$new}."); // Reduced verbosity
            } else {
                $not_found_count_total++;
                MD_Import_Force_Logger::log_message("MD Import Force [WARN] Post ID {$post_id}: No se encontró mapeo para término {$old} ({$tax}). Total mapeos disponibles: {$total_mapping_entries}");
                
                // Log de diagnóstico para ver qué mapeos están disponibles
                if ($total_mapping_entries > 0 && $not_found_count_total <= 3) { // Solo mostrar los primeros 3 para evitar spam
                    $sample_keys = array_slice(array_keys($this->id_mapping), 0, 10);
                    MD_Import_Force_Logger::log_message("MD Import Force [DEBUG] Muestra de mapeos disponibles: " . implode(', ', $sample_keys));
                }
            }
        }

        if ($found_count_total > 0) {
            MD_Import_Force_Logger::log_message("MD Import Force [INFO] Post ID {$post_id}: Mapeados {$found_count_total} términos de {$tax}. Total no encontrados: {$not_found_count_total}. Procediendo a asignar en lotes.");
            
            $batch_size = 100;
            $chunked_new_ids = array_chunk($new_ids_all, $batch_size);
            $batch_num = 0;
            $errors_in_batch = false;

            foreach ($chunked_new_ids as $batch_ids) {
                $batch_num++;
                MD_Import_Force_Logger::log_message("MD Import Force [INFO] Post ID {$post_id}: Asignando lote {$batch_num} de términos ({$tax}), tamaño: " . count($batch_ids));
                // El tercer parámetro false indica que no se deben reemplazar todos los términos, sino añadir estos.
                // Si se quiere reemplazar todos los términos con la primera llamada y luego añadir, se gestionaría de forma diferente
                // o se llamaría wp_remove_object_terms antes si es necesario limpiar.
                // Por ahora, asumimos que queremos añadir todos los términos mapeados.
                $result = wp_set_object_terms($post_id, $batch_ids, $tax, true); // true para append
                if (is_wp_error($result)) {
                    MD_Import_Force_Logger::log_message("MD Import Force [ERROR] Post ID {$post_id}: Error asignando lote {$batch_num} de términos {$tax}: " . $result->get_error_message());
                    $errors_in_batch = true;
                }
            }
            if (!$errors_in_batch) {
                 MD_Import_Force_Logger::log_message("MD Import Force [SUCCESS] Post ID {$post_id}: Todos los lotes de términos {$tax} asignados.");
            } else {
                 MD_Import_Force_Logger::log_message("MD Import Force [WARN] Post ID {$post_id}: Se encontraron errores al asignar algunos lotes de términos {$tax}.");
            }

        } else if ($not_found_count_total > 0) {
            MD_Import_Force_Logger::log_message("MD Import Force [WARN] Post ID {$post_id}: No se pudo asignar ningún término de {$tax} ya que no se mapeó ninguno. Términos no encontrados: {$not_found_count_total}");
        }
    }

    /**
     * Obtiene un ID mapeado
     */
    private function get_mapped_id($original_id) {
        return isset($this->id_mapping[$original_id]) ? $this->id_mapping[$original_id] : false;
    }
}
