<?php
/**
 * Clase para gestionar la cola de procesamiento de medios en la base de datos.
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

class MD_Import_Force_Media_Queue_Manager {

    /**
     * Obtiene el nombre de la tabla de la cola de medios.
     */
    private static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'md_import_force_media_queue';
    }

    /**
     * Añade un ítem a la cola de procesamiento de medios.
     *
     * @param string $import_run_guid GUID de la sesión de importación.
     * @param int $post_id ID del post en WordPress.
     * @param string $original_post_id_from_file ID original del post en el archivo de importación.
     * @param string $media_type Tipo de medio ('featured_image', 'content_image').
     * @param string $original_url URL original del medio.
     * @return int|false El ID de la fila insertada o false en caso de error.
     */
    public static function add_item($import_run_guid, $post_id, $original_post_id_from_file, $media_type, $original_url) {
        global $wpdb;
        $table_name = self::get_table_name();

        $data = [
            'import_run_guid' => $import_run_guid,
            'post_id' => $post_id,
            'original_post_id_from_file' => $original_post_id_from_file,
            'media_type' => $media_type,
            'original_url' => $original_url,
            'status' => 'pending', // Estado inicial
            // created_at y updated_at usarán los defaults de la DB
        ];

        $format = ['%s', '%d', '%s', '%s', '%s', '%s'];

        $result = $wpdb->insert($table_name, $data, $format);

        if ($result === false) {
            MD_Import_Force_Logger::log_message("MD Import Force [DB ERROR MediaQueue]: No se pudo insertar item en la cola. GUID: {$import_run_guid}, PostID: {$post_id}, URL: {$original_url}. Error: " . $wpdb->last_error);
            return false;
        }
        return $wpdb->insert_id;
    }

    /**
     * Obtiene un lote de items pendientes de la cola para un import_run_guid específico.
     *
     * @param string $import_run_guid GUID de la sesión de importación.
     * @param int $limit Número máximo de items a obtener.
     * @param int $offset Número de items a saltar (para paginación).
     * @return array Array de items de la cola.
     */
    public static function get_pending_batch($import_run_guid, $limit = 10, $offset = 0) {
        global $wpdb;
        $table_name = self::get_table_name();

        $sql = $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE import_run_guid = %s AND status = 'pending' ORDER BY id ASC LIMIT %d OFFSET %d",
            $import_run_guid,
            $limit,
            $offset
        );
        return $wpdb->get_results($sql, ARRAY_A);
    }
    
    /**
     * Obtiene todos los items pendientes de la cola para un import_run_guid específico.
     * ¡PRECAUCIÓN: Usar con cuidado en imports muy grandes, podría devolver muchos datos!
     *
     * @param string $import_run_guid GUID de la sesión de importación.
     * @return array Array de items de la cola.
     */
    public static function get_all_pending_items_for_run($import_run_guid) {
        global $wpdb;
        $table_name = self::get_table_name();
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE import_run_guid = %s AND status = 'pending' ORDER BY id ASC",
            $import_run_guid
        );
        return $wpdb->get_results($sql, ARRAY_A);
    }

    /**
     * Actualiza el estado y los detalles de un ítem en la cola.
     *
     * @param int $item_id ID del ítem en la cola.
     * @param string $new_status Nuevo estado.
     * @param int|null $new_attachment_id (Opcional) Nuevo ID de adjunto.
     * @param string|null $new_media_url (Opcional) Nueva URL del medio.
     * @param string|null $message (Opcional) Mensaje (ej. error).
     * @return bool True si la actualización fue exitosa, false en caso contrario.
     */
    public static function update_item_status($item_id, $new_status, $new_attachment_id = null, $new_media_url = null, $message = null) {
        global $wpdb;
        $table_name = self::get_table_name();

        $data = ['status' => $new_status];
        $format = ['%s'];

        if ($new_attachment_id !== null) {
            $data['new_attachment_id'] = $new_attachment_id;
            $format[] = '%d';
        }
        if ($new_media_url !== null) {
            $data['new_media_url'] = $new_media_url;
            $format[] = '%s';
        }
        if ($message !== null) {
            $data['last_attempt_message'] = $message;
            $format[] = '%s';
        }
        
        // Incrementar contador de intentos
        // $data['attempts'] = new \stdClass(); // Esto no funciona para $wpdb->update
        // $data['attempts'] = 'attempts + 1'; // Esto tampoco es seguro ni estándar para $wpdb->update
        // Para incrementar, es mejor hacer una query directa o leer y luego escribir.
        // Por ahora, lo simple es actualizar los datos. El incremento de intentos se puede hacer en el worker.
        // O, si es crítico aquí, primero leer `attempts`, luego $data['attempts'] = $current_attempts + 1.

        $where = ['id' => $item_id];
        $where_format = ['%d'];

        $result = $wpdb->update($table_name, $data, $where, $format, $where_format);
        
        if ($result === false) {
            MD_Import_Force_Logger::log_message("MD Import Force [DB ERROR MediaQueue]: No se pudo actualizar item ID {$item_id} a estado {$new_status}. Error: " . $wpdb->last_error);
            return false;
        }
        return true;
    }

    /**
     * Incrementa el contador de intentos para un ítem.
     */
    public static function increment_item_attempts($item_id) {
        global $wpdb;
        $table_name = self::get_table_name();
        $sql = $wpdb->prepare("UPDATE {$table_name} SET attempts = attempts + 1 WHERE id = %d", $item_id);
        $result = $wpdb->query($sql);
        if ($result === false) {
            MD_Import_Force_Logger::log_message("MD Import Force [DB ERROR MediaQueue]: No se pudo incrementar intentos para item ID {$item_id}. Error: " . $wpdb->last_error);
            return false;
        }
        return true;
    }

    /**
     * Cuenta el número de items pendientes para un import_run_guid.
     *
     * @param string $import_run_guid GUID de la sesión de importación.
     * @return int Número de items pendientes.
     */
    public static function count_pending_items($import_run_guid) {
        global $wpdb;
        $table_name = self::get_table_name();
        $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE import_run_guid = %s AND status = 'pending'", $import_run_guid);
        return (int) $wpdb->get_var($sql);
    }

    /**
     * Cuenta el número total de items para un import_run_guid.
     *
     * @param string $import_run_guid GUID de la sesión de importación.
     * @return int Número total de items.
     */
    public static function count_total_items_for_run($import_run_guid) {
        global $wpdb;
        $table_name = self::get_table_name();
        $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE import_run_guid = %s", $import_run_guid);
        return (int) $wpdb->get_var($sql);
    }
    
    /**
     * Elimina todos los items de la cola para un import_run_guid específico.
     * Usar después de que una importación se complete o falle catastróficamente y no se vaya a reintentar.
     *
     * @param string $import_run_guid GUID de la sesión de importación.
     * @return int|false Número de filas eliminadas o false en error.
     */
    public static function delete_items_for_run($import_run_guid) {
        global $wpdb;
        $table_name = self::get_table_name();
        $result = $wpdb->delete($table_name, ['import_run_guid' => $import_run_guid], ['%s']);
        if ($result === false) {
            MD_Import_Force_Logger::log_message("MD Import Force [DB ERROR MediaQueue]: No se pudieron eliminar items para GUID {$import_run_guid}. Error: " . $wpdb->last_error);
        }
        return $result;
    }

    /**
     * Programa el inicio del procesamiento de medios para un import_run_guid específico.
     * Se ejecuta después de que todos los posts hayan sido importados.
     *
     * @param string $import_run_guid GUID de la sesión de importación.
     * @param string $import_id ID de la importación.
     * @param array $options Opciones de importación.
     * @return bool True si se programó exitosamente, false en caso contrario.
     */
    public static function schedule_media_processing_start($import_run_guid, $import_id, $options = []) {
        // Verificar si hay items pendientes para procesar
        $pending_count = self::count_pending_items($import_run_guid);
        
        if ($pending_count === 0) {
            MD_Import_Force_Logger::log_message("MD Import Force [MEDIA QUEUE]: No hay medios pendientes para procesar en GUID: {$import_run_guid}");
            return true; // No hay error, simplemente no hay nada que procesar
        }

        MD_Import_Force_Logger::log_message("MD Import Force [MEDIA QUEUE]: Programando procesamiento de {$pending_count} medios para GUID: {$import_run_guid}");

        // Verificar si Action Scheduler está disponible
        if (!class_exists('ActionScheduler') || !function_exists('as_schedule_single_action')) {
            MD_Import_Force_Logger::log_message("MD Import Force [MEDIA QUEUE ERROR]: Action Scheduler no está disponible para procesar medios.");
            return false;
        }

        // Programar la acción de procesamiento de medios
        $action_id = as_schedule_single_action(
            time() + 1, // Reducido de 5 a 1 segundo para acelerar inicio
            'md_import_force_process_media_batch',
            array(
                'import_run_guid' => $import_run_guid,
                'import_id' => $import_id,
                'options' => $options,
                'batch_offset' => 0 // Comenzar desde el primer lote
            ),
            'md-import-force-media' // Grupo específico para medios
        );

        if ($action_id) {
            MD_Import_Force_Logger::log_message("MD Import Force [MEDIA QUEUE]: Procesamiento de medios programado exitosamente. ActionID: {$action_id}, GUID: {$import_run_guid}");
            return true;
        } else {
            MD_Import_Force_Logger::log_message("MD Import Force [MEDIA QUEUE ERROR]: Error al programar procesamiento de medios para GUID: {$import_run_guid}");
            return false;
        }
    }

    /**
     * Procesa un lote de medios pendientes.
     * Este método es llamado por Action Scheduler.
     *
     * @param string $import_run_guid GUID de la sesión de importación.
     * @param string $import_id ID de la importación.
     * @param array $options Opciones de importación.
     * @param int $batch_offset Offset del lote actual.
     */
    public static function process_media_batch($import_run_guid, $import_id, $options = [], $batch_offset = 0) {
        $batch_size = apply_filters('md_import_force_media_batch_size', 10); // Aumentado de 5 a 10 para acelerar procesamiento
        
        MD_Import_Force_Logger::log_message("MD Import Force [MEDIA PROCESSING]: Iniciando procesamiento de lote. GUID: {$import_run_guid}, Offset: {$batch_offset}, Tamaño Lote: {$batch_size}");

        // Obtener lote de medios pendientes
        $media_items = self::get_pending_batch($import_run_guid, $batch_size, $batch_offset);
        
        if (empty($media_items)) {
            MD_Import_Force_Logger::log_message("MD Import Force [MEDIA PROCESSING]: No hay más medios pendientes. Procesamiento completado para GUID: {$import_run_guid}");
            
            // Todos los medios han sido procesados. Marcar la importación global como completada.
            require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-progress-tracker.php';
            MD_Import_Force_Progress_Tracker::update_status(
                $import_id,
                'completed',
                __('Importación completada incluyendo medios.', 'md-import-force')
            );
            
            // Limpiar la cola de medios para esta importación
            self::delete_items_for_run($import_run_guid);
            MD_Import_Force_Logger::log_message("MD Import Force [MEDIA PROCESSING]: Cola de medios limpia para GUID: {$import_run_guid}");

            return;
        }

        // Cargar el Media Handler y las funciones necesarias de WordPress
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-media-handler.php';
        if (!function_exists('download_url')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        if (!function_exists('wp_read_image_metadata')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        if (!function_exists('media_handle_sideload')) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
        }
        
        $media_handler = new MD_Import_Force_Media_Handler();

        $processed_count_in_this_batch = 0;
        $success_count_in_this_batch = 0;
        $failed_count_in_this_batch = 0;

        $batch_action_start_time = time();

        foreach ($media_items as $media_item) {
            // Verificar tiempo transcurrido y limites antes de procesar cada ítem
            $elapsed_time = time() - $batch_action_start_time;
            $php_max_exec_time = (int) ini_get('max_execution_time');
            $time_limit_for_action = ($php_max_exec_time > 15) 
                 ? floor($php_max_exec_time * 0.8) // Usar 80% del tiempo límite
                 : 15;
            
            // Salir del bucle si se excede el tiempo de ejecución o si hay solicitud de detención global.
            if ($elapsed_time >= $time_limit_for_action || get_option('md_import_force_stop_all_imports_requested', false) || get_transient('md_import_force_stop_request_' . $import_id)) {
                 MD_Import_Force_Logger::log_message("MD Import Force [MEDIA PROCESSING]: Deteniendo procesamiento de lote por tiempo excedido o solicitud de detención. GUID: {$import_run_guid}, Offset: {$batch_offset}, Procesados en este job: {$processed_count_in_this_batch}");
                 break; // Salir del foreach para permitir que el scheduler reprograme
            }

            $processed_count_in_this_batch++;
            
            try {
                // Incrementar intentos antes de procesar
                self::increment_item_attempts($media_item['id']);
                
                $post_id = intval($media_item['post_id']);
                $media_type = $media_item['media_type'];
                $original_url = $media_item['original_url'];
                
                MD_Import_Force_Logger::log_message("MD Import Force [MEDIA PROCESSING]: Procesando ítem {$media_item['id']} ({$media_type}) para post {$post_id}: {$original_url}");

                // Validar que la URL no esté vacía antes de procesar
                if (empty($original_url) || trim($original_url) === '') {
                    $message = 'URL vacía o inválida para ' . $media_type;
                    MD_Import_Force_Logger::log_message("MD Import Force [MEDIA PROCESSING]: Omitiendo ítem {$media_item['id']} - {$message}");
                    self::update_item_status($media_item['id'], 'skipped', null, null, $message);
                    continue; // Saltar al siguiente ítem
                }

                $result = null;
                $message = '';
                $new_attachment_id = null;
                $new_media_url = null;

                // Verificar si la opción handle_attachments está activa para esta importación
                if (isset($options['handle_attachments']) && $options['handle_attachments']) {
                    if ($media_type === 'featured_image') {
                        $result = $media_handler->process_featured_image($post_id, ['url' => $original_url]);
                        if (!is_wp_error($result)) { // $result es el attachment ID o null
                             $thumbnail_id = get_post_thumbnail_id($post_id);
                             if ($thumbnail_id) {
                                $new_attachment_id = $thumbnail_id;
                                $new_media_url = wp_get_attachment_url($thumbnail_id);
                                $message = 'Imagen destacada procesada exitosamente';
                                $success_count_in_this_batch++;
                                self::update_item_status($media_item['id'], 'completed', $new_attachment_id, $new_media_url, $message);
                             } else {
                                $message = 'No se pudo establecer la imagen destacada después de procesar';
                                $failed_count_in_this_batch++;
                                self::update_item_status($media_item['id'], 'failed', null, null, $message);
                             }
                        } else {
                            $message = 'Error procesando imagen destacada: ' . $result->get_error_message();
                            $failed_count_in_this_batch++;
                            self::update_item_status($media_item['id'], 'failed', null, null, $message);
                        }
                        
                    } elseif ($media_type === 'content_image') {
                         // --- Implementación temporal para content_image llamando a import_external_image --- 
                         MD_Import_Force_Logger::log_message("MD Import Force [MEDIA PROCESSING]: Procesando content_image directamente vía import_external_image. URL: {$original_url}");
                         $att_id_or_error = $media_handler->import_external_image($original_url, $post_id);
                         
                         if (!is_wp_error($att_id_or_error) && $att_id_or_error > 0) {
                              $new_attachment_id = $att_id_or_error;
                              $new_media_url = wp_get_attachment_url($new_attachment_id);
                              $message = 'Imagen de contenido importada exitosamente. URL: ' . $new_media_url;
                              $success_count_in_this_batch++;
                              self::update_item_status($media_item['id'], 'completed', $new_attachment_id, $new_media_url, $message);
                              // Marcar este post_id para actualización de contenido posterior con la nueva URL
                              require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-progress-tracker.php';
                              MD_Import_Force_Progress_Tracker::add_post_to_content_update_queue($import_run_guid, $post_id, $import_id);

                         } else {
                             $message = 'Error importando imagen de contenido: ';
                             if (is_wp_error($att_id_or_error)) $message .= $att_id_or_error->get_error_message();
                             else $message .= 'Resultado inesperado';

                             $failed_count_in_this_batch++;
                             self::update_item_status($media_item['id'], 'failed', null, null, $message);
                         }
                         // --- Fin Implementación temporal para content_image --- 

                    } else {
                         $message = 'Tipo de medio desconocido: ' . $media_type;
                         $failed_count_in_this_batch++;
                         self::update_item_status($media_item['id'], 'failed', null, null, $message);
                    }
                } else {
                    // handle_attachments option is false, skip media processing for this run GUID
                    $message = 'Procesamiento de adjuntos desactivado en opciones de importación.';
                    MD_Import_Force_Logger::log_message("MD Import Force [MEDIA PROCESSING]: Omitiendo ítem {$media_item['id']} ({$media_type}) para post {$post_id}: {$original_url}. Opción handle_attachments desactivada.");
                    self::update_item_status($media_item['id'], 'skipped', null, null, $message);
                     // No contar como éxito o fallo en este caso, es un skip intencional.
                }
                
            } catch (Exception $e) {
                $message = 'Excepción procesando media ID ' . $media_item['id'] . ': ' . $e->getMessage();
                MD_Import_Force_Logger::log_message("MD Import Force [MEDIA PROCESSING ERROR]: " . $message);
                $failed_count_in_this_batch++;
                self::update_item_status($media_item['id'], 'failed', null, null, $message);
            }

             // Verificar uso de memoria
            $current_memory_usage = memory_get_usage(true);
            $memory_limit = ini_get('memory_limit');
            require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-job-manager.php';
            $job_manager = MD_Import_Force_Job_Manager::get_instance();
            $memory_limit_bytes = $job_manager->convert_memory_limit_to_bytes($memory_limit);
            $memory_usage_ratio = $current_memory_usage / $memory_limit_bytes;

             if ($memory_limit_bytes > 0 && $memory_usage_ratio > 0.85 && $processed_count_in_this_batch < count($media_items)) {
                 MD_Import_Force_Logger::log_message("MD Import Force [MEDIA PROCESSING]: Umbral de memoria (85%) superado (" . round($memory_usage_ratio*100, 2) . "%). Procesados en este job: {$processed_count_in_this_batch}. Saliendo del lote para reprogramar. GUID: {$import_run_guid}, Offset: {$batch_offset}");
                 break; // Salir del bucle
             }

        }

        // Calcular el próximo offset basado en cuántos ítems *realmente* se procesaron en este job
        $next_batch_offset = $batch_offset + $processed_count_in_this_batch;

        $processed_total_for_run = self::count_total_items_for_run($import_run_guid);
        // Re-contar los pendientes para asegurar precisión después del procesamiento del lote
        $pending_total_for_run = self::count_pending_items($import_run_guid);
        $completed_total_for_run = $processed_total_for_run - $pending_total_for_run;

        MD_Import_Force_Logger::log_message("MD Import Force [MEDIA PROCESSING]: Lote completado. Procesados en este job: {$processed_count_in_this_batch}. Exitosos en este job: {$success_count_in_this_batch}. Fallidos en este job: {$failed_count_in_this_batch}. Progreso total medios: {$completed_total_for_run}/{$processed_total_for_run} pendientes: {$pending_total_for_run}. GUID: {$import_run_guid}");

        // Verificar si hay más medios pendientes para este import_run_guid
        if ($pending_total_for_run > 0) {
             MD_Import_Force_Logger::log_message("MD Import Force [MEDIA PROCESSING]: Programando siguiente acción para medios con delay. Pendientes restantes: {$pending_total_for_run}. GUID: {$import_run_guid}");
             
             // Programar la misma acción para que se ejecute más tarde
             as_schedule_single_action(
                time(), // Sin delay - procesamiento inmediato para máxima velocidad
                'md_import_force_process_media_batch',
                 array(
                     'import_run_guid' => $import_run_guid,
                     'import_id' => $import_id,
                     'options' => $options,
                     'batch_offset' => $next_batch_offset // Pasar el offset calculado
                 ), 
                'md-import-force-media' // Grupo específico
            );

        } else {
            MD_Import_Force_Logger::log_message("MD Import Force [MEDIA PROCESSING]: Todos los medios para GUID: {$import_run_guid} han sido procesados o fallaron tras varios intentos.");
            
            // --- INICIO: Programar la fase de actualización de contenido --- 
            MD_Import_Force_Logger::log_message("MD Import Force [MEDIA PROCESSING]: Cola de medios vacía. Programando fase de actualización de contenido para GUID: {$import_run_guid}");
             as_schedule_single_action(
                time() + 1, // Reducido de 5 a 1 segundo para transición rápida
                'md_import_force_update_post_content_media_urls',
                 array(
                     'import_run_guid' => $import_run_guid,
                     'import_id' => $import_id,
                     'options' => $options,
                     'offset' => 0 // Comenzar desde el primer post
                 ), 
                'md-import-force-content-update' // Nuevo grupo
            );
            // --- FIN: Programar la fase de actualización de contenido --- 

            // NO marcar como completado ni limpiar la cola de medios aquí.
            // Esto se hará DESPUÉS de la fase de actualización de contenido.
        }
    }

    /**
     * Cuenta el número de items fallidos para un import_run_guid.
     *
     * @param string $import_run_guid GUID de la sesión de importación.
     * @return int Número de items fallidos.
     */
    public static function count_failed_items($import_run_guid) {
        global $wpdb;
        $table_name = self::get_table_name();
        $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE import_run_guid = %s AND status = 'failed'", $import_run_guid);
        return (int) $wpdb->get_var($sql);
    }

    /**
     * Cuenta el número de items completados para un import_run_guid.
     *
     * @param string $import_run_guid GUID de la sesión de importación.
     * @return int Número de items completados.
     */
    public static function count_completed_items($import_run_guid) {
        global $wpdb;
        $table_name = self::get_table_name();
        $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE import_run_guid = %s AND status = 'completed'", $import_run_guid);
        return (int) $wpdb->get_var($sql);
    }

    /**
     * Cuenta el número de items omitidos para un import_run_guid.
     *
     * @param string $import_run_guid GUID de la sesión de importación.
     * @return int Número de items omitidos.
     */
    public static function count_skipped_items($import_run_guid) {
        global $wpdb;
        $table_name = self::get_table_name();
        $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE import_run_guid = %s AND status = 'skipped'", $import_run_guid);
        return (int) $wpdb->get_var($sql);
    }
}
?> 