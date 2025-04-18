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

class MD_Import_Force_Handler {

    private $id_mapping = array();
    private $source_site_info = array();

    public function __construct() {
        $this->id_mapping = array();
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
                    'message' => $result['message'] ?? __('La importación se ha realizado con exito', 'md-import-force')
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
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $import_data_array = [];

        if ($ext === 'zip') {
            if (!class_exists('ZipArchive')) throw new Exception(__('ZipArchive no habilitada.', 'md-import-force'));
            $zip = new ZipArchive;
            $res = $zip->open($file_path);

            if ($res === TRUE) {
                // Primero, buscar manifest.json o export_report.json para obtener site_info
                $site_info = null;
                $posts_files = [];
                $manifest_content = null;
                $report_content = null;

                // Buscar archivos importantes primero
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    // Ignorar directorios y archivos dentro de __MACOSX
                    if (substr($name, -1) === '/' || strpos($name, '__MACOSX/') === 0) continue;

                    $basename = basename($name);
                    if ($basename === 'manifest.json') {
                        $manifest_content = $zip->getFromIndex($i);
                    } elseif ($basename === 'export_report.json') {
                        $report_content = $zip->getFromIndex($i);
                    } elseif (preg_match('/^posts-\d+\.json$/', $basename)) {
                        $posts_files[] = ['index' => $i, 'name' => $name];
                    } elseif (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'json') {
                        // Otros archivos JSON que podrían contener datos completos
                        $json_content = $zip->getFromIndex($i);
                        if (!empty($json_content)) {
                            $data = json_decode($json_content, true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                // Si tiene site_info y posts, es un archivo válido completo
                                if (isset($data['site_info']) && isset($data['posts'])) {
                                    $import_data_array[] = $data;
                                    MD_Import_Force_Logger::log_message("MD Import Force [INFO]: Archivo JSON completo encontrado: '{$name}'");
                                }
                            } else {
                                MD_Import_Force_Logger::log_message("MD Import Force [WARN]: Error JSON en archivo '{$name}' dentro del ZIP: " . json_last_error_msg());
                            }
                        }
                    }
                }

                // Si encontramos manifest o report, extraer site_info
                if (!empty($manifest_content)) {
                    $manifest = json_decode($manifest_content, true);
                    if (json_last_error() === JSON_ERROR_NONE && isset($manifest['site_info'])) {
                        $site_info = $manifest['site_info'];
                        MD_Import_Force_Logger::log_message("MD Import Force [INFO]: Información del sitio encontrada en manifest.json");
                    }
                } elseif (!empty($report_content)) {
                    $report = json_decode($report_content, true);
                    if (json_last_error() === JSON_ERROR_NONE && isset($report['site_info'])) {
                        $site_info = $report['site_info'];
                        MD_Import_Force_Logger::log_message("MD Import Force [INFO]: Información del sitio encontrada en export_report.json");
                    }
                }

                // Si tenemos site_info y archivos de posts, combinarlos
                if ($site_info && !empty($posts_files)) {
                    MD_Import_Force_Logger::log_message("MD Import Force [INFO]: Combinando " . count($posts_files) . " archivos de posts");

                    $combined_data = [
                        'site_info' => $site_info,
                        'posts' => [],
                        'categories' => [],
                        'tags' => []
                    ];

                    // Procesar cada archivo de posts
                    foreach ($posts_files as $file_info) {
                        $json_content = $zip->getFromIndex($file_info['index']);
                        if (!empty($json_content)) {
                            $data = json_decode($json_content, true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                // Añadir posts
                                if (isset($data['posts']) && is_array($data['posts'])) {
                                    $combined_data['posts'] = array_merge($combined_data['posts'], $data['posts']);
                                }

                                // Añadir categorías si no se han añadido antes
                                if (isset($data['categories']) && is_array($data['categories']) && empty($combined_data['categories'])) {
                                    $combined_data['categories'] = $data['categories'];
                                }

                                // Añadir tags si no se han añadido antes
                                if (isset($data['tags']) && is_array($data['tags']) && empty($combined_data['tags'])) {
                                    $combined_data['tags'] = $data['tags'];
                                }
                            } else {
                                MD_Import_Force_Logger::log_message("MD Import Force [WARN]: Error JSON en archivo '{$file_info['name']}' dentro del ZIP: " . json_last_error_msg());
                            }
                        }
                    }

                    // Si tenemos posts, añadir el conjunto combinado
                    if (!empty($combined_data['posts'])) {
                        $import_data_array[] = $combined_data;
                        MD_Import_Force_Logger::log_message("MD Import Force [INFO]: Datos combinados con éxito. Total posts: " . count($combined_data['posts']));
                    }
                }

                $zip->close();

                // Si no encontramos datos válidos, intentar procesar el ZIP como un directorio
                if (empty($import_data_array)) {
                    MD_Import_Force_Logger::log_message("MD Import Force [WARN]: No se encontraron archivos JSON válidos en el formato esperado. Intentando extraer el ZIP...");
                    throw new Exception(__('No se encontraron archivos .json válidos en el ZIP con el formato esperado (site_info y posts).', 'md-import-force'));
                }

                return $import_data_array;

            } else {
                throw new Exception(__('No se pudo abrir ZIP.', 'md-import-force'));
            }
        } elseif ($ext === 'json') {
            $json_content = file_get_contents($file_path);
            if (empty($json_content)) throw new Exception(__('Contenido JSON vacío.', 'md-import-force'));
            $data = json_decode($json_content, true);
            if (json_last_error() !== JSON_ERROR_NONE) throw new Exception(__('Error JSON: ', 'md-import-force') . json_last_error_msg());
            return $data;
        } else {
            throw new Exception(__('Formato no soportado (.json o .zip).', 'md-import-force'));
        }
    }

     /**
     * Obtiene un ID mapeado (simplificado).
     */
    private function get_mapped_id($old_id) {
        // Comprobar si existe el mapeo directo
        if (isset($this->id_mapping[$old_id])) {
            return $this->id_mapping[$old_id];
        }

        // Comprobar si existe con prefijo de taxonomía
        if (isset($this->id_mapping['category_' . $old_id])) {
            return $this->id_mapping['category_' . $old_id];
        }

        if (isset($this->id_mapping['tag_' . $old_id])) {
            return $this->id_mapping['tag_' . $old_id];
        }

        // Si el ID es un string que contiene un prefijo de taxonomía, intentar extraer el ID numérico
        if (is_string($old_id)) {
            if (strpos($old_id, 'category_') === 0) {
                $numeric_id = substr($old_id, 9); // Longitud de 'category_'
                if (isset($this->id_mapping[$numeric_id])) {
                    return $this->id_mapping[$numeric_id];
                }
            } elseif (strpos($old_id, 'tag_') === 0) {
                $numeric_id = substr($old_id, 4); // Longitud de 'tag_'
                if (isset($this->id_mapping[$numeric_id])) {
                    return $this->id_mapping[$numeric_id];
                }
            }
        }

        return false;
    }

    /**
     * Importa posts/páginas uno por uno.
     */
    private function import_posts($items_data) {
        $imported = 0; $updated = 0; $skipped = 0; $total = count($items_data);
        MD_Import_Force_Logger::log_message("MD Import Force: Procesando {$total} posts/páginas...");
        foreach ($items_data as $item_data) {
            try {
                $res = $this->process_post_item($item_data);
                if ($res === 'imported') $imported++; elseif ($res === 'updated') $updated++; else $skipped++;
            } catch (Exception $e) {
                $id = $item_data['ID'] ?? 'N/A'; $title = $item_data['post_title'] ?? '[Sin Título]';
                MD_Import_Force_Logger::log_message("MD Import Force [ERROR] Post/Página ID {$id} ('{$title}'): " . $e->getMessage()); $skipped++;
            }
            if (function_exists('wp_cache_flush')) wp_cache_flush(); if (function_exists('gc_collect_cycles')) gc_collect_cycles();
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
        $type = $item_data['post_type'] ?? null;
        $title = $item_data['post_title'] ?? '[Sin Título]';
        $slug = sanitize_title($item_data['post_name'] ?? $title);

        if ($id <= 0 || empty($type) || !post_type_exists($type) || empty($slug)) {
            MD_Import_Force_Logger::log_message("MD Import Force [WARN]: Omitiendo item ID {$id} ('{$title}') por datos inválidos (ID, post_type o slug).");
            return 'skipped';
        }

        $item_arr = [
            'post_title' => $title, 'post_content' => $item_data['post_content'] ?? '', 'post_type' => $type,
            'post_name' => $slug, 'post_status' => $item_data['post_status'] ?? 'publish',
            'menu_order' => intval($item_data['menu_order'] ?? 0), 'post_date' => $item_data['post_date'] ?? current_time('mysql'),
            'post_author' => intval($item_data['post_author'] ?? (get_current_user_id() ?: 1)),
            'comment_status' => $item_data['comment_status'] ?? 'open', 'ping_status' => $item_data['ping_status'] ?? 'open',
            'post_excerpt' => $item_data['post_excerpt'] ?? '', 'post_password' => $item_data['post_password'] ?? '',
            'post_parent' => 0, 'post_mime_type' => $item_data['post_mime_type'] ?? '',
        ];
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

        if (!empty($item_data['featured_image'])) $this->process_featured_image($processed_id, $item_data['featured_image']);
        if (!empty($item_data['images'])) $this->process_content_images($processed_id, $item_data);
        if (!empty($item_data['categories'])) $this->assign_terms($processed_id, $item_data['categories'], 'category');
        if (!empty($item_data['tags'])) $this->assign_terms($processed_id, $item_data['tags'], 'post_tag');
        if (!empty($item_data['taxonomies'])) {
            foreach ($item_data['taxonomies'] as $tax => $terms) if (taxonomy_exists($tax) && is_array($terms)) $this->assign_terms($processed_id, $terms, $tax);
        }
        if (!empty($item_data['comments'])) $this->import_comments($processed_id, $item_data['comments']);

        return $action === 'insert' ? 'imported' : 'updated';
    }

    /**
     * Importa términos de una taxonomía específica.
     */
    private function import_terms($terms_data, $taxonomy) {
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

        if ($existing) {
            // El término ya existe con el ID original, actualizarlo
            $term_arr = ['name' => $name, 'description' => $desc, 'slug' => $slug];
            $action = 'update';
            $result = wp_update_term($id, $taxonomy, $term_arr);
            $processed_id = $id;
        } else {
            // Verificar si existe un término con el mismo slug
            $term_with_slug = get_term_by('slug', $slug, $taxonomy);
            if ($term_with_slug && $term_with_slug->term_id != $id) {
                MD_Import_Force_Logger::log_message("MD Import Force Terms [CONFLICT/SKIP] Term ID {$id}: Slug '{$slug}' ya existe (ID {$term_with_slug->term_id}) en '{$taxonomy}'.");
                return false;
            }

            // Intentar forzar el ID original usando SQL directo
            global $wpdb;

            // Verificar si hay espacio para el ID (no hay otro término con ese ID)
            $existing_term = $wpdb->get_var($wpdb->prepare(
                "SELECT term_id FROM {$wpdb->terms} WHERE term_id = %d",
                $id
            ));

            if ($existing_term) {
                // Ya existe un término con ese ID (pero no de esta taxonomía)
                MD_Import_Force_Logger::log_message("MD Import Force Terms [WARN] Term ID {$id}: Ya existe un término con este ID. Usando método estándar.");

                // Usar el método estándar de WordPress
                $term_arr = ['description' => $desc, 'slug' => $slug];
                $action = 'insert';
                $result = wp_insert_term($name, $taxonomy, $term_arr);

                if (is_wp_error($result)) {
                    if ($result->get_error_code() === 'term_exists') {
                        // El término ya existe, intentar obtenerlo
                        $existing_term = get_term_by('slug', $slug, $taxonomy);
                        if ($existing_term) {
                            MD_Import_Force_Logger::log_message("MD Import Force Terms [INFO] Term ID {$id}: Término existente encontrado con slug '{$slug}', ID: {$existing_term->term_id}");
                            $processed_id = $existing_term->term_id;
                            $action = 'found';
                        } else {
                            MD_Import_Force_Logger::log_message("MD Import Force Terms [ERROR] Term ID {$id}: No se pudo encontrar término existente: " . $result->get_error_message());
                            return false;
                        }
                    } else {
                        MD_Import_Force_Logger::log_message("MD Import Force Terms [ERROR] Term ID {$id}: Error al insertar término: " . $result->get_error_message());
                        return false;
                    }
                } else {
                    $processed_id = $result['term_id'];
                }
            } else {
                // No existe un término con ese ID, podemos intentar forzarlo
                MD_Import_Force_Logger::log_message("MD Import Force Terms [INFO] Term ID {$id}: Intentando forzar ID original.");

                // Primero, insertar el término en wp_terms
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
        }

        // Actualizar metadatos SEO
        $this->update_term_seo_meta($processed_id, $term_data);
        return ['id' => $processed_id, 'original_id' => $id, 'action' => $action];
    }

    /** Helper para SEO de términos */
     private function update_term_seo_meta($term_id, $term_data) {
         if (!empty($term_data['meta_title'])) { update_term_meta($term_id, '_yoast_wpseo_title', $term_data['meta_title']); update_term_meta($term_id, '_aioseo_title', $term_data['meta_title']); }
         if (!empty($term_data['meta_description'])) { update_term_meta($term_id, '_yoast_wpseo_metadesc', $term_data['meta_description']); update_term_meta($term_id, '_aioseo_description', $term_data['meta_description']); }
     }

    /** Guarda metadatos del post */
    private function save_meta_data($post_id, $post_data) {
        if (!empty($post_data['meta_title'])) { update_post_meta($post_id, '_yoast_wpseo_title', $post_data['meta_title']); update_post_meta($post_id, '_aioseo_title', $post_data['meta_title']); }
        if (!empty($post_data['meta_description'])) { update_post_meta($post_id, '_yoast_wpseo_metadesc', $post_data['meta_description']); update_post_meta($post_id, '_aioseo_description', $post_data['meta_description']); }
        if (!empty($post_data['breadcrumb_title'])) update_post_meta($post_id, 'rank_math_breadcrumb_title', $post_data['breadcrumb_title']);
        if (!empty($post_data['meta_data']) && is_array($post_data['meta_data'])) {
            foreach ($post_data['meta_data'] as $key => $val) {
                if ($key === '_md_original_id' || strpos($key, '_edit_') === 0 || $key === '_wp_page_template' || $key === '_wp_old_slug') continue;
                update_post_meta($post_id, $key, $val);
            }
        }
    }

    /** Procesa imagen destacada */
    private function process_featured_image($post_id, $image_data) {
        $url = is_array($image_data) ? ($image_data['url'] ?? '') : (is_string($image_data) ? $image_data : ''); $alt = is_array($image_data) ? ($image_data['alt'] ?? '') : ''; if (empty($url)) return;
        $att_id = $this->import_external_image($url, $post_id, $alt);
        if ($att_id && !is_wp_error($att_id)) { if (!set_post_thumbnail($post_id, $att_id)) error_log("MD Import Force [WARN] Post ID {$post_id}: No se pudo setear thumbnail {$att_id}."); }
        elseif (is_wp_error($att_id)) error_log("MD Import Force [ERROR] Post ID {$post_id}: Error procesando feat. img '{$url}': " . $att_id->get_error_message());
    }

    /** Procesa imágenes en contenido */
    private function process_content_images($post_id, $post_data) {
        $content = $post_data['post_content'] ?? ''; $images = $post_data['images'] ?? []; if (empty($content) || empty($images) || !is_array($images)) return;
        $src_url = isset($this->source_site_info['site_url']) ? trailingslashit($this->source_site_info['site_url']) : null; $tgt_url = trailingslashit(get_site_url()); if ($src_url && $src_url === $tgt_url) return;
        $new_content = $content; $replacements = [];
        foreach ($images as $img) {
             if (empty($img['url'])) continue; $old = $img['url']; if (isset($replacements[$old])) continue;
             $att_id = $this->import_external_image($old, $post_id);
             if ($att_id && !is_wp_error($att_id)) { $new = wp_get_attachment_url($att_id); if ($new && $new !== $old) $replacements[$old] = $new; }
             elseif (is_wp_error($att_id)) error_log("MD Import Force [ERROR] Post ID {$post_id}: Error importando img content '{$old}': " . $att_id->get_error_message());
        }
        if (!empty($replacements)) {
            uksort($replacements, fn($a, $b) => strlen($b) - strlen($a)); $orig_content = $new_content;
            foreach ($replacements as $old => $new) $new_content = str_replace($old, $new, $new_content);
            if ($new_content !== $orig_content) {
                $upd = wp_update_post(['ID' => $post_id, 'post_content' => $new_content], true);
                if (is_wp_error($upd)) error_log("MD Import Force [ERROR] Post ID {$post_id}: Falló update post_content: " . $upd->get_error_message());
            }
        }
    }

    /** Importa imagen externa */
    private function import_external_image($url, $post_id = 0, $alt = '') {
        static $processed = []; static $skip = false;
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) return new WP_Error('invalid_url', __('URL inválida.', 'md-import-force'));
        $url = esc_url_raw(trim($url)); if (isset($processed[$url])) return $processed[$url];
        $tgt_url = trailingslashit(get_site_url()); if (strpos($url, $tgt_url) === 0) { $att_id = attachment_url_to_postid($url); if ($att_id) { $processed[$url] = $att_id; return $att_id; } $err = new WP_Error('local_not_found', __('URL local no encontrada.', 'md-import-force')); $processed[$url] = $err; return $err; }
        global $wpdb; $existing = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_source_url' AND meta_value = %s LIMIT 1", $url));
        if ($existing && get_post($existing) && get_post_type($existing) === 'attachment') { $processed[$url] = intval($existing); return intval($existing); } elseif ($existing) delete_post_meta(intval($existing), '_source_url', $url);
        if ($skip) { $err = new WP_Error('skip_download', __('Omitiendo descarga.', 'md-import-force')); $processed[$url] = $err; return $err; }
        $limit = $this->get_memory_limit_bytes(); if ($limit > 0 && (memory_get_usage(true) / $limit * 100) > 90) { $skip = true; $err = new WP_Error('high_memory', __('Memoria alta, omitiendo futuras descargas.', 'md-import-force')); error_log("MD Import Force: " . $err->get_error_message()); $processed[$url] = $err; return $err; }
        if (function_exists('set_time_limit')) set_time_limit(120); $tmp = download_url($url, 60);
        if (is_wp_error($tmp)) { error_log("MD Import Force [ERROR] Descarga '{$url}': " . $tmp->get_error_message()); $processed[$url] = $tmp; return $tmp; }
        $file = ['name' => basename(parse_url($url, PHP_URL_PATH)), 'tmp_name' => $tmp]; if (empty($file['name']) || strpos($file['name'], '.') === false) { $info = wp_check_filetype($tmp); $file['name'] = uniqid('imported-') . '.' . ($info['ext'] ?: 'jpg'); }
        $att_id = media_handle_sideload($file, $post_id, null); @unlink($tmp);
        if (is_wp_error($att_id)) { error_log("MD Import Force [ERROR] Sideload '{$url}': " . $att_id->get_error_message()); $processed[$url] = $att_id; return $att_id; }
        if (!empty($alt)) update_post_meta($att_id, '_wp_attachment_image_alt', $alt); update_post_meta($att_id, '_source_url', $url); $processed[$url] = $att_id; return $att_id;
    }

    /** Asigna categorías */
    private function assign_categories($post_id, $category_ids) { $this->assign_terms($post_id, $category_ids, 'category'); }
    /** Asigna etiquetas */
    private function assign_tags($post_id, $tag_ids) { $this->assign_terms($post_id, $tag_ids, 'post_tag'); }
    /** Asigna términos */
    private function assign_terms($post_id, $original_ids, $tax) {
        if (empty($original_ids) || !is_array($original_ids)) return; $new_ids = []; $prefix = $tax . '_';
        foreach ($original_ids as $old) {
            $old = intval($old);
            if ($old <= 0) continue;

            // Intentar obtener el ID mapeado con prefijo
            $new = $this->get_mapped_id($prefix . $old);

            // Si no se encuentra con prefijo, intentar sin prefijo
            if (!$new) $new = $this->get_mapped_id($old);

            if ($new) {
                $new_ids[] = $new;
                MD_Import_Force_Logger::log_message("MD Import Force [DEBUG] Post ID {$post_id}: Mapeando Term ID {$old} a {$new} (Tax: {$tax})");
            } else {
                MD_Import_Force_Logger::log_message("MD Import Force [WARN] Post ID {$post_id}: No map for Term ID {$old} (Tax: {$tax})");
            }
        }

        if (!empty($new_ids)) {
            $res = wp_set_object_terms($post_id, $new_ids, $tax, false);
            if (is_wp_error($res)) {
                MD_Import_Force_Logger::log_message("MD Import Force [ERROR] Post ID {$post_id}: Error setting terms for '{$tax}': " . $res->get_error_message());
            } else {
                MD_Import_Force_Logger::log_message("MD Import Force [INFO] Post ID {$post_id}: Asignados " . count($new_ids) . " términos para '{$tax}'");
            }
        } else {
            MD_Import_Force_Logger::log_message("MD Import Force [WARN] Post ID {$post_id}: No se encontraron términos mapeados para '{$tax}'");
        }
    }

    /** Importa comentarios */
    private function import_comments($post_id, $comments_data) {
        MD_Import_Force_Logger::log_message("MD Import Force: Importando " . count($comments_data) . " comentarios para post ID {$post_id}.");
        foreach ($comments_data as $cdata) {
            $orig_cid = intval($cdata['comment_ID'] ?? 0);
            $c_arr = [
                'comment_post_ID' => $post_id, 'comment_author' => $cdata['comment_author'] ?? '', 'comment_author_email' => $cdata['comment_author_email'] ?? '',
                'comment_author_url' => $cdata['comment_author_url'] ?? '', 'comment_author_IP' => $cdata['comment_author_IP'] ?? '',
                'comment_date' => $cdata['comment_date'] ?? current_time('mysql'), 'comment_date_gmt' => $cdata['comment_date_gmt'] ?? get_gmt_from_date($cdata['comment_date'] ?? current_time('mysql')),
                'comment_content' => $cdata['comment_content'] ?? '', 'comment_karma' => intval($cdata['comment_karma'] ?? 0), 'comment_approved' => $cdata['comment_approved'] ?? 1,
                'comment_agent' => $cdata['comment_agent'] ?? '', 'comment_type' => $cdata['comment_type'] ?? '', 'comment_parent' => 0, 'user_id' => 0
            ];
            $orig_uid = intval($cdata['user_id'] ?? 0); if ($orig_uid > 0 && !empty($c_arr['comment_author_email'])) { $user = get_user_by('email', $c_arr['comment_author_email']); if ($user) $c_arr['user_id'] = $user->ID; }
            $cid = wp_insert_comment($c_arr);
            if ($cid && !is_wp_error($cid)) { if (!empty($cdata['meta_data'])) foreach ($cdata['meta_data'] as $k => $v) update_comment_meta($cid, $k, $v); }
            else { $err = is_wp_error($cid) ? $cid->get_error_message() : 'ID inválido'; MD_Import_Force_Logger::log_message("MD Import Force [ERROR] Post ID {$post_id}: Error insertando comment (Orig ID: {$orig_cid}): {$err}"); }
        }
    }

    /** Obtiene límite de memoria */
    private function get_memory_limit_bytes() {
        $limit = ini_get('memory_limit'); if (empty($limit) || $limit == -1) return 0; $limit = trim($limit); $last = strtolower(substr($limit, -1)); $val = intval($limit);
        switch ($last) { case 'g': $val *= 1024; case 'm': $val *= 1024; case 'k': $val *= 1024; } return $val;
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
