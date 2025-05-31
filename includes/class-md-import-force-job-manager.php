<?php
/**
 * Clase para gestionar tareas de importación en segundo plano usando Action Scheduler
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

class MD_Import_Force_Job_Manager {
    
    // Constantes para las acciones de Action Scheduler
    const IMPORT_ACTION = 'md_import_force_process_import';
    const IMPORT_BATCH_ACTION = 'md_import_force_process_import_batch';
    
    // Tamaño de lote predeterminado - REDUCIDO para evitar timeouts 504
    const DEFAULT_BATCH_SIZE = 5; // Aumentado de 3 a 5 según solicitud del usuario
    
    // Configuraciones adicionales para optimización
    const MIN_MEMORY_THRESHOLD = 0.8; // 80% de memoria máxima antes de parar
    const BATCH_DELAY_SECONDS = 1; // Reducido de 5 a 1 segundo para acelerar procesamiento de posts
    const MAX_EXECUTION_TIME_RATIO = 0.7; // Usar solo 70% del tiempo máximo de ejecución
    
    /**
     * Singleton instance
     * 
     * @var MD_Import_Force_Job_Manager
     */
    private static $instance = null;
    
    /**
     * Constructor privado para el patrón singleton
     */
    private function __construct() {
        // Registrar los hooks para Action Scheduler si está disponible
        $this->register_hooks();
    }
    
    /**
     * Obtener la instancia singleton
     * 
     * @return MD_Import_Force_Job_Manager
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Registrar los hooks necesarios para Action Scheduler
     */
    private function register_hooks() {
        // Registrar handlers para las acciones de Action Scheduler
        add_action(self::IMPORT_ACTION, array($this, 'handle_import_job'), 10, 3);
        add_action(self::IMPORT_BATCH_ACTION, array($this, 'handle_import_batch'), 10, 7);
        
        // Registrar acción para limpiar archivos temporales antiguos (diariamente)
        if (!wp_next_scheduled('md_import_force_cleanup_temp_files')) {
            wp_schedule_event(time(), 'daily', 'md_import_force_cleanup_temp_files');
        }
        add_action('md_import_force_cleanup_temp_files', array($this, 'cleanup_old_temp_files'));
    }
    
    /**
     * Comprobar si Action Scheduler está disponible
     * 
     * @return bool
     */
    public function is_action_scheduler_available() {
        return class_exists('ActionScheduler') && function_exists('as_schedule_single_action');
    }
    
    /**
     * Programar una nueva importación usando Action Scheduler
     * 
     * @param string $import_id ID de la importación (ruta al archivo)
     * @param array $options Opciones de importación
     * @param string $import_run_guid GUID único para esta ejecución de importación
     * @return bool Éxito al programar la tarea
     */
    public function schedule_import($import_id, $options = array(), $import_run_guid = '') {
        if (!$this->is_action_scheduler_available()) {
            MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER ERROR]: Action Scheduler no está disponible. Usando WP Cron como alternativa.");
            // Si Action Scheduler no está disponible, usar WP Cron como fallback
            // Asegurarse de que el GUID se pasa también al fallback de WP Cron
            $cron_args = array(
                'import_id' => $import_id,
                'options' => $options,
                'import_run_guid' => $import_run_guid // Pasar GUID a WP Cron
            );
            wp_schedule_single_event(time(), 'md_import_force_run_background_import', $cron_args);
            return true;
        }
        
        MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Programando importación con Action Scheduler para import_id: {$import_id}, GUID: {$import_run_guid}");
        
        // Programar la acción de importación inicial con Action Scheduler
        $action_id = as_schedule_single_action(
            time(), // Lo antes posible
            self::IMPORT_ACTION, // Hook: md_import_force_process_import
            array(
                'import_id' => $import_id,
                'options' => $options,
                'import_run_guid' => $import_run_guid // Pasar GUID
            ),
            'md-import-force' // Grupo para identificar y gestionar tareas
        );
        return $action_id !== null && $action_id > 0;
    }
    
    /**
     * Cancelar todas las importaciones programadas
     * 
     * @param string $import_id ID específico para cancelar, o null para todas
     * @return int Número de acciones canceladas
     */
    public function cancel_imports($import_id = null) {
        if (!$this->is_action_scheduler_available()) {
            MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Action Scheduler no disponible para cancelar tareas.");
            return 0;
        }
        
        $args = array();
        if ($import_id) {
            MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Cancelando importación específica: {$import_id}");
            $args['import_id'] = $import_id;
        } else {
            MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Cancelando todas las importaciones programadas");
        }
        
        // Cancelar acción principal de importación
        $main_jobs_cancelled = as_unschedule_all_actions(self::IMPORT_ACTION, $args, 'md-import-force');
        
        // Cancelar acciones de lote
        $batch_jobs_cancelled = as_unschedule_all_actions(self::IMPORT_BATCH_ACTION, $args, 'md-import-force');
        
        MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Canceladas {$main_jobs_cancelled} acciones principales y {$batch_jobs_cancelled} acciones de lote.");
        
        return $main_jobs_cancelled + $batch_jobs_cancelled;
    }
    
    /**
     * Procesar la preparación inicial de una importación (manejador de Action Scheduler)
     * Hooked to: self::IMPORT_ACTION ('md_import_force_process_import')
     * 
     * @param string $import_id ID de la importación
     * @param array $options Opciones de importación
     * @param string $import_run_guid GUID de la ejecución de importación
     */
    public function handle_import_job($import_id, $options, $import_run_guid) {
        try {
            MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER - handle_import_job]: Iniciando para import_id: {$import_id}, GUID: {$import_run_guid}");

            // Verificar si hay solicitud para detener las importaciones
            if (get_option('md_import_force_stop_all_imports_requested', false) || get_transient('md_import_force_stop_request_' . $import_id)) {
                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Importación detenida por solicitud del usuario antes de comenzar para import_id: {$import_id}");
                MD_Import_Force_Progress_Tracker::update_status(
                    $import_id, 
                    'stopped', 
                    __('Importación detenida por solicitud del usuario.', 'md-import-force')
                );
                return;
            }
            
            if (!file_exists($import_id)) {
                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER ERROR]: Archivo no encontrado: {$import_id}");
                MD_Import_Force_Progress_Tracker::update_status( $import_id, 'failed', __('Error: Archivo de importación no encontrado.', 'md-import-force'));
                return;
            }
            
            require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-file-processor.php';
            $file_processor = new MD_Import_Force_File_Processor();
            $import_data_content = $file_processor->read_file($import_id); // Lee el contenido del archivo JSON o ZIP
            
            // Pre-filtrado de posts si la opción 'import_only_missing' está activa
            $filtered_import_data_content = $import_data_content;
            $original_total_items = 0;
            // Calcular $original_total_items basado en $import_data_content
            if (is_array($import_data_content) && isset($import_data_content[0]['posts'])) { // ZIP
                foreach ($import_data_content as $single_data_orig) {
                    if (isset($single_data_orig['posts'])) {
                        $original_total_items += count($single_data_orig['posts']);
                    }
                }
            } elseif (isset($import_data_content['posts'])) { // JSON único
                $original_total_items = count($import_data_content['posts']);
            }

            if (isset($options['import_only_missing']) && $options['import_only_missing']) {
                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Opción 'import_only_missing' activada. Iniciando pre-filtrado de posts para import_id: {$import_id}");
                $check_batch_size = apply_filters('md_import_force_pre_filter_batch_size', 50); // Aumentado para pre-filtrado
                
                if (is_array($import_data_content) && isset($import_data_content[0]['posts'])) { 
                    $temp_filtered_data_multi = []; 
                    foreach ($import_data_content as $index => $single_data_content_item) {
                        if (isset($single_data_content_item['posts']) && !empty($single_data_content_item['posts'])) {
                            $filtered_posts_for_this_file = $this->filter_posts_by_existence($single_data_content_item['posts'], $check_batch_size, $options, $import_id);
                            $current_file_data = $single_data_content_item; 
                            $current_file_data['posts'] = $filtered_posts_for_this_file;
                            $temp_filtered_data_multi[] = $current_file_data;
                        } else {
                             $temp_filtered_data_multi[] = $single_data_content_item; // Mantener archivos sin posts (ej. solo site_info)
                        }
                    }
                    $filtered_import_data_content = $temp_filtered_data_multi;
                } elseif (isset($import_data_content['posts']) && !empty($import_data_content['posts'])) { 
                    $filtered_posts = $this->filter_posts_by_existence($import_data_content['posts'], $check_batch_size, $options, $import_id);
                    $current_data = $import_data_content; 
                    $current_data['posts'] = $filtered_posts;
                    $filtered_import_data_content = $current_data;
                }
                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Pre-filtrado completado para import_id: {$import_id}.");
            }
            
            // Almacenar los datos (potencialmente filtrados) en un archivo temporal
            $data_id = $file_processor->store_import_data($filtered_import_data_content, $import_id);
            MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Datos de importación (filtrados si aplica) almacenados con data_id: {$data_id} para import_id: {$import_id}");

            $overall_total_items = 0;
            if (is_array($filtered_import_data_content) && isset($filtered_import_data_content[0]['posts'])) { // ZIP
                foreach ($filtered_import_data_content as $single_data) {
                    if (isset($single_data['posts'])) {
                        $overall_total_items += count($single_data['posts']);
                    }
                }
            } elseif (isset($filtered_import_data_content['posts'])) { // JSON único
                $overall_total_items = count($filtered_import_data_content['posts']);
            }
            
            MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Posts originales: {$original_total_items}, Posts después de filtrar: {$overall_total_items} para import_id: {$import_id}");

            if ($overall_total_items === 0) {
                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: No se encontraron elementos para importar en: {$import_id} (o todos fueron filtrados/ya existen).");
                MD_Import_Force_Progress_Tracker::update_status(
                    $import_id, 
                    'completed', 
                    __('No hay nuevos elementos para importar o todos los elementos ya existen y fueron omitidos.', 'md-import-force')
                );
                $file_processor->delete_import_data($data_id); // Limpiar datos temporales
                return;
            }
            
            MD_Import_Force_Progress_Tracker::initialize_progress($import_id, $overall_total_items, __('Importación en cola...', 'md-import-force'));
            MD_Import_Force_Progress_Tracker::update_overall_processed_count($import_id, 0); // Inicializar contador global de procesados

            // Programar el primer lote
            $num_items_for_first_job = min(self::DEFAULT_BATCH_SIZE, $overall_total_items);
            $this->schedule_batch_action(
                $import_id, 
                $options, 
                $data_id, // ID de los datos temporales (ahora contiene los datos filtrados)
                0,         // current_job_start_offset
                $num_items_for_first_job, // num_items_for_this_job
                $overall_total_items, 
                $import_run_guid,
                time() // Programar inmediatamente
            );
            
            MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Primer lote programado para import_id: {$import_id}. Offset: 0, Num Items: {$num_items_for_first_job}, Total Global: {$overall_total_items}. DataID: {$data_id}");

        } catch (Exception $e) {
            MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER ERROR - handle_import_job]: Excepción: " . $e->getMessage());
            MD_Import_Force_Progress_Tracker::update_status($import_id, 'failed', __('Error al preparar la importación: ', 'md-import-force') . $e->getMessage());
            // Considerar limpiar $data_id si se creó antes de la excepción
             if (isset($data_id) && !empty($data_id) && isset($file_processor)) {
                $file_processor->delete_import_data($data_id);
            }
        }
    }
    
    /**
     * Procesa un lote de importación (manejador de Action Scheduler)
     * Hooked to: self::IMPORT_BATCH_ACTION
     * 
     * @param string $import_id ID de la importación
     * @param array $options Opciones de importación
     * @param string $data_id ID de los datos temporales (nombre de archivo que contiene todos los items filtrados)
     * @param int $current_job_start_offset Índice global de inicio para los items que este job debe procesar
     * @param int $num_items_for_this_job Número de items que este job debe intentar procesar
     * @param int $overall_total_items Total de items en la importación global (después de filtrar)
     * @param string $import_run_guid GUID de la ejecución de importación
     */
    public function handle_import_batch($import_id, $options, $data_id, $current_job_start_offset, $num_items_for_this_job, $overall_total_items, $import_run_guid) {
        $batch_action_start_time = time();
        
        // Implementar monitoreo de memoria y tiempo más estricto
        $memory_limit = ini_get('memory_limit');
        $memory_limit_bytes = $this->convert_memory_limit_to_bytes($memory_limit);
        $memory_usage_start = memory_get_usage(true);
        $memory_peak_start = memory_get_peak_usage(true);
        
        // Definir un límite de tiempo más conservador para evitar 504s
        $php_max_exec_time = (int) ini_get('max_execution_time');
        $time_limit_for_action = ($php_max_exec_time > 15) 
            ? floor($php_max_exec_time * self::MAX_EXECUTION_TIME_RATIO) 
            : 15; // Mínimo 15s usando solo 70% del tiempo disponible

        MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER - handle_import_batch]: ImportID: {$import_id}, GUID: {$import_run_guid}, DataID: {$data_id}. Job Start Offset: {$current_job_start_offset}, Num Items for Job: {$num_items_for_this_job}, Overall Total: {$overall_total_items}. Time Limit for Action: {$time_limit_for_action}s. Memory start: " . size_format($memory_usage_start));

        try {
            // Limpieza de memoria al inicio del lote
            $this->cleanup_memory();
            
            if (get_option('md_import_force_stop_all_imports_requested', false) || get_transient('md_import_force_stop_request_' . $import_id)) {
                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Lote detenido (Offset {$current_job_start_offset}) por solicitud del usuario para import_id: {$import_id}.");
                MD_Import_Force_Progress_Tracker::update_status(
                    $import_id, 
                    'stopped', 
                    sprintf(__('Importación detenida por solicitud del usuario (procesando desde ítem %d).', 'md-import-force'), $current_job_start_offset)
                );
                return;
            }
            
            // Verificar memoria disponible antes de continuar
            $current_memory_usage = memory_get_usage(true);
            $memory_usage_ratio = $current_memory_usage / $memory_limit_bytes;
            
            if ($memory_usage_ratio > self::MIN_MEMORY_THRESHOLD) {
                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER WARNING]: Uso de memoria alto (" . round($memory_usage_ratio * 100, 2) . "%) para import_id: {$import_id}. Reduciendo tamaño del lote.");
                // Reducir el tamaño del lote si la memoria está alta
                $num_items_for_this_job = max(1, floor($num_items_for_this_job / 2));
            }
            
            require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-file-processor.php';
            $file_processor = new MD_Import_Force_File_Processor();
            $import_data_content = $file_processor->retrieve_import_data($data_id); // Recupera todos los datos filtrados

            if (!$import_data_content) {
                 MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER ERROR]: No se pudieron recuperar los datos de importación para data_id: {$data_id}. ImportID: {$import_id}");
                 MD_Import_Force_Progress_Tracker::update_status($import_id, 'failed', __('Error crítico: Datos de importación no encontrados para procesar el lote.', 'md-import-force'));
                 return;
            }
            
            require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-handler.php';
            $handler = new MD_Import_Force_Handler();
            
            // El contador $overall_processed_count se recupera y se pasa por referencia
            $overall_processed_count = MD_Import_Force_Progress_Tracker::get_overall_processed_count($import_id);
            
            MD_Import_Force_Progress_Tracker::update_status(
                $import_id, 
                'processing', 
                sprintf(__('Procesando lote (desde ítem %d, hasta %d de %d). Avance: %d/%d. Memoria: %s', 'md-import-force'), 
                        $current_job_start_offset + 1, 
                        min($current_job_start_offset + $num_items_for_this_job, $overall_total_items), 
                        $overall_total_items,
                        $overall_processed_count,
                        $overall_total_items,
                        size_format($current_memory_usage))
            );
            
            $handler_result = $handler->process_batch(
                $import_id, 
                $options, 
                $import_data_content,         // Todos los datos filtrados
                $current_job_start_offset,    // El offset global desde donde el handler debe empezar a buscar items para este job
                $num_items_for_this_job,      // Cuántos items este job es responsable de procesar
                $overall_processed_count,     // Referencia al contador global
                $overall_total_items, 
                $import_run_guid,
                $batch_action_start_time,   // Hora de inicio de este job de Action Scheduler
                $time_limit_for_action      // Límite de tiempo para este job
            );

            // Limpieza de memoria después del procesamiento
            $this->cleanup_memory();
            
            // Guardar el contador global actualizado
            MD_Import_Force_Progress_Tracker::update_overall_processed_count($import_id, $overall_processed_count);
            
            // Monitoreo de memoria post-procesamiento
            $memory_usage_end = memory_get_usage(true);
            $memory_peak_end = memory_get_peak_usage(true);
            
            MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER - handle_import_batch]: Handler procesó. Avance global: {$overall_processed_count}/{$overall_total_items}. Items procesados en esta corrida del handler: " . ($handler_result['items_actually_processed_this_run'] ?? 0) . ". Memoria final: " . size_format($memory_usage_end) . ", Pico: " . size_format($memory_peak_end));

            if (isset($handler_result['stopped_manually']) && $handler_result['stopped_manually']) {
                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Handler indicó detención manual para import_id: {$import_id}. Offset: {$current_job_start_offset}.");
                return;
            }

            $items_processed_by_handler_this_run = $handler_result['items_actually_processed_this_run'] ?? 0;
            
            if (isset($handler_result['time_exceeded']) && $handler_result['time_exceeded'] && $items_processed_by_handler_this_run < $num_items_for_this_job) {
                $remaining_items_in_this_job_chunk = $num_items_for_this_job - $items_processed_by_handler_this_run;
                $next_start_offset_for_remainder = $current_job_start_offset + $items_processed_by_handler_this_run;

                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Tiempo excedido en lote para import_id: {$import_id}. Offset actual: {$current_job_start_offset}. Items procesados por handler: {$items_processed_by_handler_this_run}. Items restantes en este job: {$remaining_items_in_this_job_chunk}. Reprogramando remanente.");
                
                $this->schedule_batch_action(
                    $import_id, 
                    $options, 
                    $data_id, 
                    $next_start_offset_for_remainder, 
                    $remaining_items_in_this_job_chunk, 
                    $overall_total_items, 
                    $import_run_guid,
                    time() + self::BATCH_DELAY_SECONDS // Delay más largo para timeout
                );
                return; // Terminar este job, el remanente se procesará en un nuevo job.
            }

            // Si no hubo time_exceeded o si se procesaron todos los items asignados a este job
            // Proceder a programar el siguiente lote conceptual o finalizar.
            $next_conceptual_batch_start_offset = $current_job_start_offset + $num_items_for_this_job;

            if ($next_conceptual_batch_start_offset < $overall_total_items) {
                $num_items_for_next_job = min(self::DEFAULT_BATCH_SIZE, $overall_total_items - $next_conceptual_batch_start_offset);
                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Lote (Offset {$current_job_start_offset}, {$num_items_for_this_job} items) completado sin timeout (o todos procesados). Programando siguiente lote conceptual. ImportID: {$import_id}. Próximo Offset: {$next_conceptual_batch_start_offset}, Num Items: {$num_items_for_next_job}");
                
                $this->schedule_batch_action(
                    $import_id, 
                    $options, 
                    $data_id, 
                    $next_conceptual_batch_start_offset, 
                    $num_items_for_next_job, 
                    $overall_total_items, 
                    $import_run_guid,
                    time() + self::BATCH_DELAY_SECONDS // Delay consistente entre lotes
                );
            } else {
                // Todos los items procesados
                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Todos los items procesados para import_id: {$import_id}. GUID: {$import_run_guid}. Total global procesado: {$overall_processed_count} de {$overall_total_items}.");
                
                $final_stats_from_progress = MD_Import_Force_Progress_Tracker::get_progress($import_id); // Obtener el estado completo
                $final_stats_summary = [
                    'new_count' => $final_stats_from_progress['stats']['new_count'] ?? 0, // Asumiendo que el tracker acumula esto
                    'updated_count' => $final_stats_from_progress['stats']['updated_count'] ?? 0,
                    'skipped_count' => $final_stats_from_progress['stats']['skipped_count'] ?? 0,
                    'processed_count' => $overall_processed_count,
                    'total_count' => $overall_total_items
                ];

                MD_Import_Force_Progress_Tracker::update_status(
                    $import_id,
                    'processing_media',
                    __('Importación de posts completada. Procesando medios...', 'md-import-force'),
                    ['final_stats' => $final_stats_summary]
                );
                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Importación de posts marcada como COMPLETA para import_id: {$import_id}. Iniciando procesamiento de medios.");
                
                // Limpieza final
                $this->cleanup_memory();
                $file_processor->delete_import_data($data_id); // Limpiar datos temporales de posts
                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Datos temporales {$data_id} eliminados para import_id: {$import_id}.");

                // TODO: Aquí se podría iniciar la Fase 2 (ej. Medios), si existe.
                // Por ahora, la importación (de posts) se considera completa.
                MD_Import_Force_Media_Queue_Manager::schedule_media_processing_start($import_run_guid, $import_id, $options);
            }

        } catch (Exception $e) {
            // Limpieza de memoria en caso de error
            $this->cleanup_memory();
            
            MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER ERROR - handle_import_batch]: Excepción para ImportID {$import_id}, Offset {$current_job_start_offset}: " . $e->getMessage() . " En archivo: " . $e->getFile() . " Línea: " . $e->getLine());
            $current_overall_processed_count = MD_Import_Force_Progress_Tracker::get_overall_processed_count($import_id); // Obtener el último conteo
            MD_Import_Force_Progress_Tracker::update_status(
                $import_id, 
                'failed', 
                sprintf(__('Error en lote (desde ítem %d): %s', 'md-import-force'), $current_job_start_offset, $e->getMessage()),
                ['overall_processed_count' => $current_overall_processed_count]
            );
            // No limpiar $data_id aquí en caso de error, podría ser útil para depuración o reintentos manuales.
        }
    }
    
    /**
     * Helper para programar una acción de lote.
     */
    private function schedule_batch_action($import_id, $options, $data_id, $current_job_start_offset, $num_items_for_this_job, $overall_total_items, $import_run_guid, $timestamp) {
        // Calcular delay más conservador basado en la carga del servidor
        $current_memory_usage = memory_get_usage(true);
        $memory_limit = ini_get('memory_limit');
        $memory_limit_bytes = $this->convert_memory_limit_to_bytes($memory_limit);
        $memory_usage_ratio = $current_memory_usage / $memory_limit_bytes;
        
        // Ajustar delay basado en uso de memoria
        $base_delay = self::BATCH_DELAY_SECONDS;
        if ($memory_usage_ratio > 0.7) {
            $base_delay = self::BATCH_DELAY_SECONDS * 2; // Doblar el delay si memoria alta
        } elseif ($memory_usage_ratio > 0.5) {
            $base_delay = self::BATCH_DELAY_SECONDS * 1.5; // 1.5x delay si memoria moderada
        }
        
        // Usar el timestamp proporcionado o calcular uno más conservador
        $final_timestamp = max($timestamp, time() + $base_delay);
        
        MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Programando lote con delay de " . ($final_timestamp - time()) . " segundos. Memoria actual: " . round($memory_usage_ratio * 100, 2) . "%. ImportID: {$import_id}");
        
        $action_id = as_schedule_single_action(
            $final_timestamp, 
            self::IMPORT_BATCH_ACTION,
            array(
                'import_id' => $import_id,
                'options' => $options,
                'data_id' => $data_id,
                'current_job_start_offset' => $current_job_start_offset,
                'num_items_for_this_job' => $num_items_for_this_job,
                'overall_total_items' => $overall_total_items,
                'import_run_guid' => $import_run_guid
            ),
            'md-import-force' // Grupo
        );
        
        if ($action_id) {
            MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Lote programado exitosamente. ActionID: {$action_id}, ImportID: {$import_id}, Offset: {$current_job_start_offset}, NumItems: {$num_items_for_this_job}, TotalGlobal: {$overall_total_items}, DataID: {$data_id}, GUID: {$import_run_guid}, Programado para: " . date('Y-m-d H:i:s', $final_timestamp));
        } else {
            MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER ERROR]: Error al programar lote. ImportID: {$import_id}, Offset: {$current_job_start_offset}");
        }
        
        return $action_id;
    }

    /**
     * Limpia archivos temporales antiguos (ejecutado diariamente por WP Cron)
     */
    public function cleanup_old_temp_files() {
        MD_Import_Force_Logger::log_message("MD Import Force [SCHEDULED CLEANUP]: Iniciando limpieza programada de archivos temporales antiguos.");
        
        // Cargar el procesador de archivos
        require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-file-processor.php';
        $file_processor = new MD_Import_Force_File_Processor();
        
        // Limpiar archivos temporales más antiguos que 48 horas
        $result = $file_processor->cleanup_old_temp_files(48);
        
        MD_Import_Force_Logger::log_message("MD Import Force [SCHEDULED CLEANUP]: Limpieza programada completada.");
    }

    /**
     * Filtra una lista de posts candidatos, devolviendo solo aquellos que no existen.
     * Comprueba por título y tipo de post, según la lógica de "import_only_missing".
     *
     * @param array $posts_to_check Array de posts candidatos del archivo JSON.
     * @param int $batch_size Tamaño del lote para iterar internamente (actualmente no usado para consultas BD agrupadas aquí).
     * @param array $options Opciones de importación.
     * @param string $import_id ID de la importación (para logueo).
     * @return array Array de posts que realmente necesitan ser importados.
     */
    protected function filter_posts_by_existence($posts_to_check, $batch_size = 10, $options = [], $import_id = 'N/A') {
        if (empty($posts_to_check)) {
            return [];
        }

        $posts_that_need_import = [];
        // La variable $batch_size recibida no se usa directamente para agrupar consultas a BD aquí,
        // ya que get_page_by_title es una consulta por ítem. Se mantiene por si se refactoriza en el futuro.
        // El procesamiento es ítem por ítem para la consulta.

        $total_candidates = count($posts_to_check);
        $processed_count = 0;

        foreach ($posts_to_check as $key => $post_data) {
            $processed_count++;
            if ($processed_count % $batch_size === 0 || $processed_count === $total_candidates) {
                 MD_Import_Force_Logger::log_message("MD Import Force [FILTER]: Revisando existencia para import_id: {$import_id}. Progreso del lote de candidatos: {$processed_count}/{$total_candidates}");
            }

            if (!isset($post_data['post_title']) || !isset($post_data['post_type'])) {
                $posts_that_need_import[] = $post_data; 
                MD_Import_Force_Logger::log_message("MD Import Force [FILTER]: Post con datos incompletos (título/tipo) para import_id: {$import_id}, se incluirá por defecto: " . substr(json_encode($post_data), 0, 200) . "...");
                continue;
            }

            $title = sanitize_text_field($post_data['post_title']);
            $post_type = sanitize_text_field($post_data['post_type']);
            
            // Esta función es la forma estándar de WordPress para buscar un post por título exacto y tipo.
            // Es sensible a mayúsculas/minúsculas según la colación de la BD.
            $existing_post = get_page_by_title($title, OBJECT, $post_type); 

            if ($existing_post === null) {
                $posts_that_need_import[] = $post_data;
            } else {
                MD_Import_Force_Logger::log_message("MD Import Force [FILTER]: Post '{$title}' (tipo: {$post_type}) ya existe para import_id: {$import_id}. Omitiendo.");
            }
        }
        MD_Import_Force_Logger::log_message("MD Import Force [FILTER]: Finalizado pre-filtrado para un conjunto de " . count($posts_to_check) . " posts candidatos (import_id: {$import_id}). Necesitan importación: " . count($posts_that_need_import));
        return $posts_that_need_import;
    }

    /**
     * Limpia la memoria antes de procesar un lote
     */
    private function cleanup_memory() {
        // Forzar liberación de memoria
        if (function_exists('gc_collect_cycles')) {
            $cycles_freed = gc_collect_cycles();
            if ($cycles_freed > 0) {
                MD_Import_Force_Logger::log_message("MD Import Force [MEMORY CLEANUP]: Liberados {$cycles_freed} ciclos de memoria.");
            }
        }
        
        // Forzar limpieza de cache de WordPress
        wp_cache_flush();
        
        // Limpiar cache de objetos si está disponible
        if (function_exists('wp_cache_delete_group')) {
            wp_cache_delete_group('posts');
            wp_cache_delete_group('post_meta');
            wp_cache_delete_group('terms');
            wp_cache_delete_group('term_relationships');
        }
        
        // Limpiar variables globales de WordPress que podrían estar cargadas
        global $wp_object_cache;
        if (isset($wp_object_cache) && is_object($wp_object_cache) && method_exists($wp_object_cache, 'flush')) {
            $wp_object_cache->flush();
        }
        
        MD_Import_Force_Logger::log_message("MD Import Force [MEMORY CLEANUP]: Limpieza de memoria completada. Memoria actual: " . size_format(memory_get_usage(true)));
    }
    
    /**
     * Convierte un límite de memoria de formato string a bytes
     */
    public function convert_memory_limit_to_bytes($memory_limit) {
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
}

// Inicializar el gestor de trabajos
MD_Import_Force_Job_Manager::get_instance(); 