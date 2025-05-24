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
    
    // Tamaño de lote predeterminado
    const DEFAULT_BATCH_SIZE = 10;
    
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
        add_action(self::IMPORT_BATCH_ACTION, array($this, 'handle_import_batch'), 10, 6);
        
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
            
            // Verificar que el archivo exista
            if (!file_exists($import_id)) {
                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER ERROR]: Archivo no encontrado: {$import_id}");
                MD_Import_Force_Progress_Tracker::update_status(
                    $import_id, 
                    'failed', 
                    __('Error: Archivo de importación no encontrado.', 'md-import-force')
                );
                return;
            }
            
            // Cargar el procesador de archivos
            require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-file-processor.php';
            $file_processor = new MD_Import_Force_File_Processor();
            
            // Leer el archivo de importación
            $import_data = $file_processor->read_file($import_id);
            
            // ---- INICIO DE MODIFICACIÓN: Pre-filtrado de posts ----
            $filtered_import_data = $import_data; 
            $original_total_items = 0; 

            if (is_array($import_data) && isset($import_data[0])) {
                foreach ($import_data as $single_data_orig) {
                    if (isset($single_data_orig['posts'])) {
                        $original_total_items += count($single_data_orig['posts']);
                    }
                }
            } elseif (isset($import_data['posts'])) {
                $original_total_items = count($import_data['posts']);
            }

            if (isset($options['import_only_missing']) && $options['import_only_missing']) {
                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Opción 'import_only_missing' activada. Iniciando pre-filtrado de posts para import_id: {$import_id}");
                
                $check_batch_size = apply_filters('md_import_force_pre_filter_batch_size', 10);

                if (is_array($import_data) && isset($import_data[0])) { 
                    $temp_filtered_data_multi = []; 
                    foreach ($import_data as $index => $single_data_content) {
                        if (isset($single_data_content['posts']) && !empty($single_data_content['posts'])) {
                            $filtered_posts_for_this_file = $this->filter_posts_by_existence($single_data_content['posts'], $check_batch_size, $options, $import_id);
                            
                            $current_file_data = $single_data_content; 
                            $current_file_data['posts'] = $filtered_posts_for_this_file;
                            $temp_filtered_data_multi[] = $current_file_data;
                            
                        } else {
                             $temp_filtered_data_multi[] = $single_data_content;
                        }
                    }
                    $filtered_import_data = $temp_filtered_data_multi;
                } elseif (isset($import_data['posts']) && !empty($import_data['posts'])) { 
                    $filtered_posts = $this->filter_posts_by_existence($import_data['posts'], $check_batch_size, $options, $import_id);
                    $current_data = $import_data; 
                    $current_data['posts'] = $filtered_posts;
                    $filtered_import_data = $current_data;
                }
                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Pre-filtrado completado para import_id: {$import_id}.");
            }
            // ---- FIN DE MODIFICACIÓN: Pre-filtrado de posts ----

            // Contar los elementos a procesar DESPUÉS del filtrado
            $total_items = 0;
            if (is_array($filtered_import_data) && isset($filtered_import_data[0])) { // ZIP
                foreach ($filtered_import_data as $single_data) {
                    if (isset($single_data['posts'])) {
                        $total_items += count($single_data['posts']);
                    }
                }
            } elseif (isset($filtered_import_data['posts'])) { // JSON único
                $total_items = count($filtered_import_data['posts']);
            }
            
            // ---- INICIO DE MODIFICACIÓN: Logueo de conteo original y filtrado ----
            MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Posts originales: {$original_total_items}, Posts después de filtrar: {$total_items} para import_id: {$import_id}");
            // ---- FIN DE MODIFICACIÓN ----

            if ($total_items === 0) {
                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER ERROR]: No se encontraron elementos para importar en: {$import_id} (o todos fueron filtrados).");
                MD_Import_Force_Progress_Tracker::update_status(
                    $import_id, 
                    'completed', // Considerar 'skipped' o un nuevo estado 'nothing_to_import'
                    __('No hay nuevos elementos para importar o todos los elementos ya existen.', 'md-import-force')
                );
                return;
            }
            
            // Inicializar el progreso con el total de ítems (ya es el filtrado)
            MD_Import_Force_Progress_Tracker::initialize_progress(
                $import_id,
                $total_items,
                __('Importación preparada, procesando posts filtrados...', 'md-import-force')
            );
            
            // Almacenar los datos de importación FILTRADOS en un archivo temporal
            $data_id = $file_processor->store_import_data($filtered_import_data, $import_id . '_filtered_' . $import_run_guid);
            
            // Programar el primer lote
            $this->schedule_next_batch($import_id, $options, 0, $data_id, $total_items, $import_run_guid);
            
            MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Importación (fase de posts) iniciada para import_id: {$import_id}, GUID: {$import_run_guid} con {$total_items} elementos.");
        } catch (Exception $e) {
            MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER ERROR]: Excepción al iniciar importación: " . $e->getMessage());
            MD_Import_Force_Progress_Tracker::update_status(
                $import_id, 
                'failed', 
                sprintf(__('Error al iniciar la importación: %s', 'md-import-force'), $e->getMessage())
            );
        }
    }
    
    /**
     * Procesar un lote de importación (manejador de Action Scheduler)
     * Hooked to: self::IMPORT_BATCH_ACTION ('md_import_force_process_import_batch')
     * 
     * @param string $import_id 
     * @param array $options 
     * @param int $batch_index 
     * @param string $data_id ID de los datos temporales (nombre de archivo)
     * @param int $total_items 
     * @param string $import_run_guid GUID de la ejecución de importación
     */
    public function handle_import_batch($import_id, $options, $batch_index, $data_id, $total_items, $import_run_guid) {
        try {
            MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER - handle_import_batch]: Lote {$batch_index} para import_id: {$import_id}, GUID: {$import_run_guid}, DataID: {$data_id}");

            // Verificar si hay solicitud para detener las importaciones
            if (get_option('md_import_force_stop_all_imports_requested', false) || get_transient('md_import_force_stop_request_' . $import_id)) {
                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Lote detenido por solicitud del usuario para import_id: {$import_id}, lote: {$batch_index}");
                MD_Import_Force_Progress_Tracker::update_status(
                    $import_id, 
                    'stopped', 
                    sprintf(__('Importación detenida por solicitud del usuario (lote %d).', 'md-import-force'), $batch_index)
                );
                return;
            }
            
            // Cargar el procesador de archivos
            require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-file-processor.php';
            $file_processor = new MD_Import_Force_File_Processor();
            
            // Recuperar los datos de importación
            $import_data = $file_processor->retrieve_import_data($data_id);
            
            // Calcular el rango de ítems para este lote
            $batch_size = self::DEFAULT_BATCH_SIZE;
            $start_index = $batch_index * $batch_size;
            $current_items_processed = $start_index;
            
            // Cargar las clases necesarias
            require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-handler.php';
            $handler = new MD_Import_Force_Handler();
            
            // Actualizar estado
            MD_Import_Force_Progress_Tracker::update_status(
                $import_id, 
                'processing', 
                sprintf(__('Procesando lote %d (ítems %d-%d de %d)...', 'md-import-force'), 
                        $batch_index + 1, $start_index + 1, 
                        min($start_index + $batch_size, $total_items), 
                        $total_items)
            );
            
            // Procesar el lote actual
            $result = $handler->process_batch(
                $import_id, 
                $options, 
                $import_data, // Datos completos (ya filtrados si aplica)
                $start_index, 
                $batch_size, 
                $current_items_processed, // Esto es el conteo global de items de posts, no de lotes
                $total_items,             // Total de items de posts para esta fase
                $import_run_guid          // Pasar GUID
            );
            
            // Si se solicitó detener durante el procesamiento del lote
            if (isset($result['stopped_manually']) && $result['stopped_manually']) {
                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Importación detenida durante el lote {$batch_index}.");
                // Limpiar datos temporales
                $file_processor->delete_import_data($data_id);
                return;
            }
            
            // Calcular si hay más lotes
            $has_more_items = ($start_index + $batch_size) < $total_items;
            
            if ($has_more_items) {
                // Programar el siguiente lote
                $next_batch_index = $batch_index + 1;
                $this->schedule_next_batch($import_id, $options, $next_batch_index, $data_id, $total_items, $import_run_guid);
                
                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Lote {$batch_index} completado. Programando lote {$next_batch_index}.");
            } else {
                // Finalizar la importación
                $final_stats = array(
                    'success' => true,
                    'new_count' => $result['new_count'] ?? 0,
                    'updated_count' => $result['updated_count'] ?? 0,
                    'skipped_count' => $result['skipped_count'] ?? 0,
                    'processed_count' => $current_items_processed,
                    'total_count' => $total_items,
                    'message' => __('Importación completada exitosamente.', 'md-import-force')
                );
                
                MD_Import_Force_Progress_Tracker::mark_complete($import_id, $final_stats);
                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Importación completada para import_id: {$import_id}. Estadísticas: " . json_encode($final_stats));
                
                // Limpiar datos temporales
                $file_processor->delete_import_data($data_id);
                
                // Limpieza opcional del archivo de importación
                if (isset($options['cleanup_after_import']) && $options['cleanup_after_import']) {
                    $handler->cleanup_import_file($import_id);
                }

                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Todos los lotes de posts completados para import_id: {$import_id}, GUID: {$import_run_guid}. Total procesados: {$current_items_processed} de {$total_items}");
                // Aquí es donde, después de que TODOS los lotes de posts se completen, se podría disparar la Fase 2 (procesamiento de medios)
                // Por ahora, marcamos como completada la fase de posts.
                // El estado final de la importación (considerando todas las fases) se manejará más adelante.

                // TODO: Transición a la Fase 2: Procesamiento de Medios.
                // Por ahora, asumimos que la importación de posts está completa.
                // Si no hay errores en los lotes, el progreso debería estar al 100% para posts.
                // Una revisión final del estado:
                $final_status_check = MD_Import_Force_Progress_Tracker::get_progress($import_id);
                if ($final_status_check && $final_status_check['status'] !== 'stopped' && $final_status_check['status'] !== 'failed') {
                     MD_Import_Force_Progress_Tracker::update_status($import_id, 'posts_completed', __('Procesamiento de posts completado. Pendiente procesamiento de medios.', 'md-import-force'));
                     MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Fase de POSTS completada para GUID: {$import_run_guid}. Preparando para Fase de MEDIOS (a implementar).");
                     // Aquí se encolaría la primera tarea para la Fase 2 (procesar la media_queue)
                } else {
                    MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Fase de posts para GUID: {$import_run_guid} finalizada, pero el estado es {$final_status_check['status']}. No se iniciará fase de medios.");
                }
            }
        } catch (Exception $e) {
            MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER ERROR]: Excepción en lote {$batch_index}: " . $e->getMessage());
            MD_Import_Force_Progress_Tracker::update_status(
                $import_id, 
                'failed', 
                sprintf(__('Error en lote %d: %s', 'md-import-force'), $batch_index, $e->getMessage())
            );
            
            // Intentar limpiar datos temporales
            require_once MD_IMPORT_FORCE_PLUGIN_DIR . 'includes/class-md-import-force-file-processor.php';
            $file_processor = new MD_Import_Force_File_Processor();
            $file_processor->delete_import_data($data_id);
        }
    }
    
    /**
     * Programa el siguiente lote de importación.
     */
    private function schedule_next_batch($import_id, $options, $next_batch_index, $data_id, $total_items, $import_run_guid) {
        $action_id = as_schedule_single_action(
            time() + 5, // Un pequeño retraso para permitir que la transacción actual termine
            self::IMPORT_BATCH_ACTION, // Hook: md_import_force_process_import_batch
            array(
                'import_id' => $import_id,
                'options' => $options,
                'batch_index' => $next_batch_index,
                'data_id' => $data_id,
                'total_items' => $total_items,
                'import_run_guid' => $import_run_guid // Pasar GUID
            ),
            'md-import-force'
        );
        MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Programado siguiente lote {$next_batch_index} para import_id: {$import_id}, GUID: {$import_run_guid}, ActionID: {$action_id}");
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
}

// Inicializar el gestor de trabajos
MD_Import_Force_Job_Manager::get_instance(); 