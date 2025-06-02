<?php
/**
 * Clase para gestionar la actualización de contenido de posts después de importar medios.
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

class MD_Import_Force_Content_Updater {

    // Tamaño de lote para actualizar contenido
    const CONTENT_UPDATE_BATCH_SIZE = 20; // Aumentado de 10 a 20 para mayor eficiencia

    /**
     * Procesa un lote de posts que necesitan actualización de contenido.
     * Este método es llamado por Action Scheduler.
     *
     * @param string $import_run_guid GUID de la sesión de importación.
     * @param string $import_id ID de la importación (file_path).
     * @param array $options Opciones de importación.
     * @param int $offset Offset para procesar el lote de posts.
     */
    public static function process_content_update_batch($import_run_guid, $import_id, $options = [], $offset = 0) {
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-progress-tracker.php';
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-logger.php';

        MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE]: Iniciando procesamiento de lote de actualización de contenido. GUID: {$import_run_guid}, ImportID: {$import_id}, Offset: {$offset}");

        // Verificar si hay solicitud para detener las importaciones
        if (get_option('md_import_force_stop_all_imports_requested', false) || get_transient('md_import_force_stop_request_' . $import_id)) {
            MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE]: Deteniendo procesamiento de lote por solicitud del usuario. GUID: {$import_run_guid}, Offset: {$offset}");
            MD_Import_Force_Progress_Tracker::update_status(
                $import_id,
                'stopped',
                sprintf(__('Actualización de contenido detenida por solicitud del usuario (procesando desde post %d).', 'md-import-force'), $offset)
            );
            return;
        }

        // Obtener la lista completa de posts que necesitan actualización para este run GUID
        $posts_to_update_all = MD_Import_Force_Progress_Tracker::get_posts_for_content_update($import_run_guid, $import_id);
        
        if (empty($posts_to_update_all)) {
             MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE]: No hay posts pendientes de actualización de contenido para GUID: {$import_run_guid}. Finalizando fase.");
             self::finalize_content_update_phase($import_run_guid, $import_id, $options); // Marcar como completado y limpiar
             return;
        }

        // Seleccionar el lote actual basado en el offset y el tamaño del lote
        $posts_batch_ids = array_slice($posts_to_update_all, $offset, self::CONTENT_UPDATE_BATCH_SIZE);
        
        if (empty($posts_batch_ids)) {
             MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE]: Offset {$offset} es mayor que el total de posts a actualizar. Finalizando fase para GUID: {$import_run_guid}.");
             self::finalize_content_update_phase($import_run_guid, $import_id, $options); // Marcar como completado y limpiar
             return;
        }

        $processed_count_in_batch = 0;
        $successful_updates_in_batch = 0;

        foreach ($posts_batch_ids as $post_id) {
            // Opcional: Verificar tiempo/memoria aquí también si los posts son muy grandes.
             // md_import_force_check_time_memory(); // Necesitaría una función helper para esto

            $processed_count_in_batch++;
            MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE]: Procesando post ID {$post_id} para actualizar contenido. GUID: {$import_run_guid}");

            // === Lógica para actualizar contenido del Post ===
            $post = get_post($post_id);
            if ($post) {
                $old_content = $post->post_content;
                $new_content = $old_content;
                $updated = false;

                // 1. Encontrar todas las URLs de imágenes en el contenido antiguo
                $image_urls = self::extract_image_urls_from_content($old_content); // Implementar este método

                if (!empty($image_urls)) {
                    MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE]: Post ID {$post_id} tiene URLs de imágenes en contenido: " . implode(', ', $image_urls));

                    // 2. Para cada URL, encontrar el medio importado correspondiente por la meta _source_url
                    $replacements = [];
                    foreach ($image_urls as $original_url) {
                        // Buscar el adjunto por la meta _source_url
                        $args = array(
                            'post_type'  => 'attachment',
                            'meta_key'   => '_source_url',
                            'meta_value' => $original_url,
                            'posts_per_page' => 1,
                            'fields' => 'ids' // Solo necesitamos el ID
                        );
                        $attachments = get_posts($args);

                        if (!empty($attachments)) {
                            $attachment_id = $attachments[0];
                            $new_media_url = wp_get_attachment_url($attachment_id);

                            if ($new_media_url) {
                                // Evitar reemplazar si la URL ya es la nueva URL local
                                if ($original_url !== $new_media_url) {
                                     $replacements[$original_url] = $new_media_url;
                                     $updated = true;
                                     MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE]: Found replacement for URL {$original_url} -> {$new_media_url} for post ID {$post_id}");
                                }
                            } else {
                                MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE WARNING]: Could not get new URL for attachment ID {$attachment_id} (original URL: {$original_url}) for post ID {$post_id}");
                            }
                        } else {
                            // Si no se encuentra por URL exacta, intentar buscar por nombre de archivo
                            $filename = basename(parse_url($original_url, PHP_URL_PATH));
                            if (!empty($filename)) {
                                MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE]: Trying fallback search by filename '{$filename}' for original URL {$original_url} for post ID {$post_id}");
                                
                                // Buscar por nombre de archivo en el título del post o en la URL
                                $filename_without_ext = pathinfo($filename, PATHINFO_FILENAME);
                                $args_fallback = array(
                                    'post_type'  => 'attachment',
                                    'post_status' => 'inherit',
                                    'posts_per_page' => -1,
                                    'fields' => 'ids',
                                    'meta_query' => array(
                                        array(
                                            'key' => '_source_url',
                                            'value' => $filename,
                                            'compare' => 'LIKE'
                                        )
                                    )
                                );
                                $fallback_attachments = get_posts($args_fallback);
                                
                                if (!empty($fallback_attachments)) {
                                    // Si encontramos múltiples, tomar el primero
                                    $attachment_id = $fallback_attachments[0];
                                    $new_media_url = wp_get_attachment_url($attachment_id);
                                    $source_url = get_post_meta($attachment_id, '_source_url', true);
                                    
                                    if ($new_media_url) {
                                        $replacements[$original_url] = $new_media_url;
                                        $updated = true;
                                        MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE]: Found fallback replacement by filename for URL {$original_url} -> {$new_media_url} (source: {$source_url}) for post ID {$post_id}");
                                    }
                                } else {
                                    // Último intento: buscar por título del post (nombre del archivo sin extensión)
                                    $args_title = array(
                                        'post_type'  => 'attachment',
                                        'post_status' => 'inherit',
                                        'posts_per_page' => 1,
                                        'fields' => 'ids',
                                        'post_title' => $filename_without_ext
                                    );
                                    $title_attachments = get_posts($args_title);
                                    
                                    if (!empty($title_attachments)) {
                                        $attachment_id = $title_attachments[0];
                                        $new_media_url = wp_get_attachment_url($attachment_id);
                                        
                                        if ($new_media_url) {
                                            $replacements[$original_url] = $new_media_url;
                                            $updated = true;
                                            MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE]: Found replacement by post title for URL {$original_url} -> {$new_media_url} for post ID {$post_id}");
                                        }
                                    } else {
                                        MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE WARNING]: Could not find imported attachment for original URL {$original_url} (filename: {$filename}) for post ID {$post_id}");
                                    }
                                }
                            } else {
                                MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE WARNING]: Could not extract filename from URL {$original_url} for post ID {$post_id}");
                            }
                        }
                    }

                    // 3. Realizar los reemplazos en el contenido
                    if ($updated && !empty($replacements)) {
                         // Sort replacements by length descending to prevent partial replacements of similar URLs
                        uksort($replacements, function($a, $b) { return strlen($b) - strlen($a); });

                        foreach ($replacements as $old_url => $new_url) {
                            $new_content = str_replace($old_url, $new_url, $new_content);
                        }
                        MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE]: Content updated for post ID {$post_id}");

                         // 4. Guardar el post con el contenido actualizado
                        $update_result = wp_update_post(['ID' => $post_id, 'post_content' => $new_content], true);
                        if (is_wp_error($update_result)) {
                             MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE ERROR]: Failed to update post content for ID {$post_id}. Error: " . $update_result->get_error_message());
                             // Handle update error - maybe log and keep post in queue?
                             // For now, we remove from queue to avoid getting stuck.
                        } else {
                            $successful_updates_in_batch++;
                            MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE]: Successfully updated content for post ID {$post_id}");
                        }
                    }
                }
                
                // Siempre removemos el post de la cola de actualización después de intentar procesarlo
                // para evitar reprocesarlo infinitamente si falla la actualización de contenido o no tiene imágenes.
                MD_Import_Force_Progress_Tracker::remove_post_from_content_update_queue($import_run_guid, $import_id, $post_id); // Implementar este método

            } else {
                MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE WARNING]: Post ID {$post_id} not found during content update. Removing from queue.");
                 MD_Import_Force_Progress_Tracker::remove_post_from_content_update_queue($import_run_guid, $import_id, $post_id); // Implementar este método
            }
             // === Fin Lógica para actualizar contenido del Post ===
        }

        // Re-obtener la lista completa de posts pendientes después de procesar el lote actual
        $remaining_posts_to_update = MD_Import_Force_Progress_Tracker::get_posts_for_content_update($import_run_guid, $import_id);
        $remaining_count = count($remaining_posts_to_update);

        MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE]: Lote completado. Procesados en este job: {$processed_count_in_batch}. Actualizaciones exitosas: {$successful_updates_in_batch}. Posts restantes a actualizar: {$remaining_count}. GUID: {$import_run_guid}");

        if ($remaining_count > 0) {
            // Programar el siguiente lote
            // Action Scheduler debería continuar desde el principio de la lista restante
            // en la próxima ejecución del hook. No necesitamos calcular el offset manualmente aquí
            // ya que remove_post_from_content_update_queue elimina los IDs procesados de la lista.
            
             MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE]: Programando siguiente acción de actualización de contenido con delay. Pendientes restantes: {$remaining_count}. GUID: {$import_run_guid}");

             as_schedule_single_action(
                 time(), // Sin delay - procesamiento inmediato para máxima velocidad
                 'md_import_force_update_post_content_media_urls',
                  array(
                      'import_run_guid' => $import_run_guid,
                      'import_id' => $import_id,
                      'options' => $options,
                      'offset' => 0 // Siempre empezamos desde el inicio de la lista restante
                  ), 
                 'md-import-force-content-update' // Grupo específico
             );

        } else {
            MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE]: Todos los posts que necesitaban actualización de contenido han sido procesados para GUID: {$import_run_guid}.");
            self::finalize_content_update_phase($import_run_guid, $import_id, $options);
        }
    }

    /**
     * Extrae todas las URLs de imágenes del contenido de un post.
     *
     * @param string $content El contenido del post.
     * @return array Un array de URLs de imágenes encontradas.
     */
    private static function extract_image_urls_from_content($content) {
        $image_urls = [];
        if (empty($content)) {
            return $image_urls;
        }

        // Usar DOMDocument si está disponible para un parseo más robusto
        if (class_exists('DOMDocument')) {
            $doc = new DOMDocument();
            // Suppress warnings for malformed HTML
            @$doc->loadHTML('<?xml encoding="utf-8" ?><html><body>' . $content . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
            // No clear errors here, as it might clear other important errors

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
      * Finaliza la fase de actualización de contenido. Marca la importación como completa y limpia.
      *
      * @param string $import_run_guid GUID de la sesión de importación.
      * @param string $import_id ID de la importación (file_path).
      * @param array $options Opciones de importación.
      */
     private static function finalize_content_update_phase($import_run_guid, $import_id, $options) {
         require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-progress-tracker.php';
         require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-media-queue-manager.php';
         require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-file-processor.php';

         MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE]: Finalizando fase de actualización de contenido para GUID: {$import_run_guid}");

         // Obtener estadísticas finales del progreso general (posts)
         $overall_progress = MD_Import_Force_Progress_Tracker::get_progress($import_id);
         $final_stats_summary = $overall_progress['stats'] ?? [];

          // Obtener estadísticas finales de medios procesados de la cola de medios
         $total_media_items = MD_Import_Force_Media_Queue_Manager::count_total_items_for_run($import_run_guid);
         $failed_media_items = MD_Import_Force_Media_Queue_Manager::count_failed_items($import_run_guid);
         $completed_media_items = MD_Import_Force_Media_Queue_Manager::count_completed_items($import_run_guid);
         $skipped_media_items = MD_Import_Force_Media_Queue_Manager::count_skipped_items($import_run_guid);

          $final_stats_summary['media'] = [
              'total' => $total_media_items,
              'completed' => $completed_media_items,
              'failed' => $failed_media_items,
              'skipped' => $skipped_media_items
          ];



         // Marcar la importación global como completada solo si no hubo fallos o paradas previas
         if (isset($overall_progress['status']) && $overall_progress['status'] !== 'failed' && $overall_progress['status'] !== 'stopped') {
             $final_status = 'completed';
             $final_message = __('Importación completa (posts, medios y actualización de contenido).', 'md-import-force');
             
             // Verificar si hubo fallos en los medios
             if ($failed_media_items > 0) {
                  $final_status = 'completed_with_errors';
                  $final_message = sprintf(__('Importación de posts y medios completada con %d errores en medios.', 'md-import-force'), $failed_media_items);
             }

              // Verificar si quedan posts en la cola de actualización (no debería ocurrir si llegamos aquí)
              $remaining_content_updates = MD_Import_Force_Progress_Tracker::get_posts_for_content_update($import_run_guid, $import_id);
              if (!empty($remaining_content_updates)) {
                   MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE WARNING]: Finalizando fase de contenido pero aún quedan posts en la cola ({count($remaining_content_updates)}). ImportID: {$import_id}");
                   $final_status = 'completed_with_errors';
                   $final_message = sprintf(__('Importación completada, pero quedaron %d posts pendientes de actualizar contenido.', 'md-import-force'), count($remaining_content_updates));
              }


             MD_Import_Force_Progress_Tracker::update_status(
                 $import_id,
                 $final_status,
                 $final_message,
                 ['final_stats' => $final_stats_summary] // Añadir estadísticas finales detalladas
             );

              MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE]: Importación global marcada como {$final_status} para import_id: {$import_id}");

         } else {
             // Si hubo un fallo o parada anterior, no sobrescribir el estado
              MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE WARNING]: Importación global para import_id: {$import_id} no marcada como completa debido a estado anterior: {$overall_progress['status']}");
         }

         // Limpieza final de datos temporales y colas
         require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-job-manager.php';
         $file_processor = new MD_Import_Force_File_Processor();

         // Limpiar datos temporales de posts (si aún existen)
         // Necesitamos el data_id. Podríamos almacenarlo en el progreso o recuperarlo de otro modo.
         // Por ahora, asumimos que JobManager ya lo limpió o que no es crítico que se quede si falló antes.
         // Si $data_id se almacenara en el progreso:
         // $data_id = $overall_progress['data_id'] ?? null;
         // if ($data_id) $file_processor->delete_import_data($data_id);

         // Limpiar la cola de medios (ya debería estar vacía, pero por si acaso)
         MD_Import_Force_Media_Queue_Manager::delete_items_for_run($import_run_guid);
         MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE]: Cola de medios limpia para GUID: {$import_run_guid}");

         // Limpiar la lista de posts para actualizar contenido del progreso
         // Podríamos tener un método específico en ProgressTracker para esto
         // Por ahora, como la lista es por GUID dentro del progreso, simplemente la vaciamos.
         $progress_file = MD_Import_Force_Progress_Tracker::_get_progress_file_path($import_id); // Asumiendo $import_id es file_path
          if ($progress_file && file_exists($progress_file)) {
              $data = json_decode(file_get_contents($progress_file), true);
              if ($data && isset($data['content_update_queue_by_guid'][$import_run_guid])) {
                  $data['content_update_queue_by_guid'][$import_run_guid] = []; // Vaciar la lista para este GUID
                   @file_put_contents($progress_file, json_encode($data)); // Guardar cambios
                   MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE]: Lista de posts a actualizar en progreso vaciada para GUID: {$import_run_guid}");
              }
          }

         // Podríamos eliminar el archivo de progreso completo si la importación fue un éxito total.
         // Pero mantenerlo ayuda para revisión post-importación.

         MD_Import_Force_Logger::log_message("MD Import Force [CONTENT UPDATE]: Finalización de fase de actualización de contenido completada para GUID: {$import_run_guid}");
     }
}
?> 