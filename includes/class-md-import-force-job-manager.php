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
        add_action(self::IMPORT_ACTION, array($this, 'handle_import_job'), 10, 2);
        add_action(self::IMPORT_BATCH_ACTION, array($this, 'handle_import_batch'), 10, 5);
        
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
     * @return bool Éxito al programar la tarea
     */
    public function schedule_import($import_id, $options = array()) {
        if (!$this->is_action_scheduler_available()) {
            MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER ERROR]: Action Scheduler no está disponible. Usando WP Cron como alternativa.");
            // Si Action Scheduler no está disponible, usar WP Cron como fallback
            wp_schedule_single_event(time(), 'md_import_force_run_background_import', array('import_id' => $import_id, 'options' => $options));
            return true;
        }
        
        MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Programando importación con Action Scheduler para import_id: {$import_id}");
        
        // Programar la acción de importación inicial con Action Scheduler
        return as_schedule_single_action(
            time(), // Lo antes posible
            self::IMPORT_ACTION,
            array(
                'import_id' => $import_id,
                'options' => $options
            ),
            'md-import-force' // Grupo para identificar y gestionar tareas
        );
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
     * 
     * @param string $import_id ID de la importación
     * @param array $options Opciones de importación
     */
    public function handle_import_job($import_id, $options) {
        try {
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
            
            // Contar los elementos a procesar
            $total_items = 0;
            if (is_array($import_data) && isset($import_data[0])) {
                // Caso de múltiples archivos (ZIP)
                foreach ($import_data as $single_data) {
                    if (isset($single_data['posts'])) {
                        $total_items += count($single_data['posts']);
                    }
                }
            } elseif (isset($import_data['posts'])) {
                // Caso de archivo único JSON
                $total_items = count($import_data['posts']);
            }
            
            if ($total_items === 0) {
                MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER ERROR]: No se encontraron elementos para importar en: {$import_id}");
                MD_Import_Force_Progress_Tracker::update_status(
                    $import_id, 
                    'failed', 
                    __('Error: No se encontraron elementos para importar.', 'md-import-force')
                );
                return;
            }
            
            // Inicializar el progreso con el total de ítems
            MD_Import_Force_Progress_Tracker::initialize_progress(
                $import_id,
                $total_items,
                __('Importación preparada, procesando...', 'md-import-force')
            );
            
            // Almacenar los datos de importación en un archivo temporal
            $data_id = $file_processor->store_import_data($import_data, $import_id);
            
            // Programar el primer lote
            $this->schedule_next_batch($import_id, $options, 0, $data_id, $total_items);
            
            MD_Import_Force_Logger::log_message("MD Import Force [JOB MANAGER]: Importación iniciada para import_id: {$import_id} con {$total_items} elementos.");
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
     * Procesar un lote de la importación (manejador de Action Scheduler)
     * 
     * @param string $import_id ID de la importación
     * @param array $options Opciones de importación
     * @param int $batch_index Índice del lote actual
     * @param string $data_id Identificador de los datos de importación
     * @param int $total_items Total de ítems a procesar
     */
    public function handle_import_batch($import_id, $options, $batch_index, $data_id, $total_items) {
        try {
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
            $result = $handler->process_batch($import_id, $options, $import_data, $start_index, $batch_size, $current_items_processed, $total_items);
            
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
                $this->schedule_next_batch($import_id, $options, $next_batch_index, $data_id, $total_items);
                
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
     * Programar el siguiente lote de importación
     * 
     * @param string $import_id ID de la importación
     * @param array $options Opciones de importación
     * @param int $next_batch_index Índice del siguiente lote
     * @param string $data_id Identificador de los datos de importación
     * @param int $total_items Total de ítems a procesar
     */
    private function schedule_next_batch($import_id, $options, $next_batch_index, $data_id, $total_items) {
        // Agregar un pequeño retraso para evitar sobrecarga del servidor
        $delay_seconds = 3;
        
        as_schedule_single_action(
            time() + $delay_seconds,
            self::IMPORT_BATCH_ACTION,
            array(
                'import_id' => $import_id,
                'options' => $options,
                'batch_index' => $next_batch_index,
                'data_id' => $data_id,
                'total_items' => $total_items
            ),
            'md-import-force'
        );
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
}

// Inicializar el gestor de trabajos
MD_Import_Force_Job_Manager::get_instance(); 