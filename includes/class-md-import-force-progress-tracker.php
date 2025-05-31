<?php
/**
 * Clase para rastrear el progreso de importación
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

class MD_Import_Force_Progress_Tracker {

    // private $import_session_id = ''; // Commented out: Old instance property
    // private $temp_dir = ''; // Commented out: Old instance property

    /**
     * Obtiene el directorio temporal para los archivos de progreso.
     * Crea el directorio si no existe.
     *
     * @return string Ruta al directorio temporal.
     */
    private static function _get_temp_dir() {
        $temp_dir = '';
        if (function_exists('wp_upload_dir')) {
            $upload_dir = wp_upload_dir();
            // Changed directory name to be more specific for progress files
            $temp_dir = $upload_dir['basedir'] . '/md-import-force-progress/';
        } else {
            // Fallback a directorio del plugin (menos ideal)
            $temp_dir = dirname(dirname(__FILE__)) . '/progress-temp/';
        }

        if (!file_exists($temp_dir)) {
            if (function_exists('wp_mkdir_p')) {
                wp_mkdir_p($temp_dir);
            } else {
                @mkdir($temp_dir, 0755, true);
            }
        }
        return $temp_dir;
    }

    /**
     * Obtiene la ruta completa al archivo de progreso para un ID de importación dado.
     *
     * @param string $import_id Identificador único de la importación (ej. file_path).
     * @return string Ruta completa al archivo JSON de progreso.
     */
    private static function _get_progress_file_path($import_id) {
        if (empty($import_id)) {
            // Log error or handle empty import_id
            if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS ERROR]: _get_progress_file_path recibió un import_id vacío.");
            }
            return null; // Or throw an exception
        }
        // Usar md5 del import_id para asegurar un nombre de archivo válido y único
        return self::_get_temp_dir() . md5($import_id) . '_progress.json';
    }

    /**
     * Inicializa o resetea el seguimiento de progreso para una importación específica.
     *
     * @param string $import_id Identificador único de la importación.
     * @param int $total_items Número total de elementos a procesar.
     * @param string $message Mensaje inicial.
     */
    public static function initialize_progress($import_id, $total_items = 0, $message = '') {
        if (empty($import_id)) {
             if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS ERROR]: initialize_progress recibió un import_id vacío.");
            }
            return;
        }
        $progress_file = self::_get_progress_file_path($import_id);
        if (!$progress_file) return;

        $initial_message = !empty($message) ? $message : __('Importación inicializada...', 'md-import-force');
        $data = [
            'import_id' => $import_id,
            'status' => 'queued', // O 'initializing'
            'processed_count' => 0,
            'total_count' => (int)$total_items,
            'percent' => 0,
            'current_item_message' => $initial_message,
            'details' => [],
            'stats' => [],
            'timestamp' => time(),
            'start_time' => time(),
        ];

        if (@file_put_contents($progress_file, json_encode($data)) === false) {
            if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS ERROR]: No se pudo escribir el archivo de progreso: {$progress_file}");
            }
        } else {
            if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS INFO]: Progreso inicializado para {$import_id} en {$progress_file}. Total: {$total_items}");
            }
        }
    }

    /**
     * Actualiza el progreso de una importación.
     *
     * @param string $import_id Identificador único de la importación.
     * @param int $processed_items Número de elementos procesados hasta ahora.
     * @param int $total_items Número total de elementos (puede actualizarse si se descubre más tarde).
     * @param string $current_item_message Mensaje sobre el elemento actual.
     */
    public static function update_progress($import_id, $processed_items, $total_items, $current_item_message = '') {
        if (empty($import_id)) return;
        $progress_file = self::_get_progress_file_path($import_id);
        if (!$progress_file || !file_exists($progress_file)) {
             if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS WARN]: Archivo de progreso no encontrado para {$import_id} al actualizar. Se creará uno nuevo.");
            }
            // Podríamos llamar a initialize_progress o simplemente crear un archivo con el estado actual
            self::initialize_progress($import_id, $total_items, $current_item_message);
            $data = json_decode(file_get_contents($progress_file), true); // Recargar después de inicializar
        } else {
            $data = json_decode(file_get_contents($progress_file), true);
            if (!$data) {
                if (class_exists('MD_Import_Force_Logger')) {
                    MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS ERROR]: No se pudo decodificar el archivo de progreso: {$progress_file}. Re-inicializando.");
                }
                self::initialize_progress($import_id, $total_items, $current_item_message);
                $data = json_decode(file_get_contents($progress_file), true);
            }
        }


        $data['processed_count'] = (int)$processed_items;
        $data['total_count'] = (int)$total_items; // Permitir que el total se actualice
        $data['percent'] = ($data['total_count'] > 0) ? round(((int)$processed_items / (int)$total_items) * 100) : 0;
        if (!empty($current_item_message)) {
            $data['current_item_message'] = $current_item_message;
        }
        // Solo cambiar a 'processing' si no es un estado final
        if ($data['status'] !== 'completed' && $data['status'] !== 'failed') {
            $data['status'] = 'processing';
        }
        $data['timestamp'] = time();

        if (@file_put_contents($progress_file, json_encode($data)) === false) {
             if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS ERROR]: No se pudo escribir la actualización de progreso en: {$progress_file}");
            }
        }
    }

    /**
     * Actualiza el estado general y/o mensaje de una importación.
     *
     * @param string $import_id Identificador único de la importación.
     * @param string $status Nuevo estado (ej. 'queued', 'processing', 'failed', 'completed_with_errors').
     * @param string $message Mensaje descriptivo del estado.
     * @param array $details Datos adicionales (ej. errores específicos, estadísticas parciales).
     */
    public static function update_status($import_id, $status, $message = '', $details = []) {
        if (empty($import_id)) return;
        $progress_file = self::_get_progress_file_path($import_id);
         if (!$progress_file || !file_exists($progress_file)) {
            if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS WARN]: Archivo de progreso no encontrado para {$import_id} al actualizar estado. Se creará uno nuevo.");
            }
            // Inicializar con 0 items si no existe, ya que no sabemos el total aún
            self::initialize_progress($import_id, 0, $message);
            $data = json_decode(file_get_contents($progress_file), true);
        } else {
            $data = json_decode(file_get_contents($progress_file), true);
            if (!$data) {
                 if (class_exists('MD_Import_Force_Logger')) {
                    MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS ERROR]: No se pudo decodificar el archivo de progreso: {$progress_file} para update_status. Re-inicializando.");
                }
                self::initialize_progress($import_id, 0, $message);
                $data = json_decode(file_get_contents($progress_file), true);
            }
        }

        $data['status'] = $status;
        if (!empty($message)) {
            $data['current_item_message'] = $message;
        }
        if (!empty($details)) {
            $data['details'] = array_merge($data['details'] ?? [], $details);
        }
        $data['timestamp'] = time();

        if (@file_put_contents($progress_file, json_encode($data)) === false) {
            if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS ERROR]: No se pudo escribir la actualización de estado en: {$progress_file}");
            }
        } else {
             if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS INFO]: Estado actualizado para {$import_id} a {$status}. Mensaje: {$message}");
            }
        }
    }

    /**
     * Marca una importación como completada.
     *
     * @param string $import_id Identificador único de la importación.
     * @param array $stats Estadísticas finales de la importación (ej. new_count, updated_count, skipped_count, etc.).
     * @param string $message Mensaje final opcional.
     */
    public static function mark_complete($import_id, $stats, $message = '') {
        if (empty($import_id)) return;
        $progress_file = self::_get_progress_file_path($import_id);
        if (!$progress_file || !file_exists($progress_file)) {
            if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS WARN]: Archivo de progreso no encontrado para {$import_id} al marcar como completado. Se creará uno nuevo.");
            }
            // No se debería llegar aquí si el proceso ha estado actualizando
            self::initialize_progress($import_id, ($stats['processed_count'] ?? ($stats['new_count'] ?? 0 + $stats['updated_count'] ?? 0)), $message);
             $data = json_decode(file_get_contents($progress_file), true);
        } else {
            $data = json_decode(file_get_contents($progress_file), true);
             if (!$data) {
                 if (class_exists('MD_Import_Force_Logger')) {
                    MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS ERROR]: No se pudo decodificar el archivo de progreso: {$progress_file} para mark_complete. Re-inicializando.");
                }
                self::initialize_progress($import_id, ($stats['processed_count'] ?? ($stats['new_count'] ?? 0 + $stats['updated_count'] ?? 0)), $message);
                $data = json_decode(file_get_contents($progress_file), true);
            }
        }
        

        $data['status'] = 'completed';
        $data['percent'] = 100;
        $data['stats'] = $stats; // Guardar todas las estadísticas recibidas
        
        // Asegurar que processed_count y total_count reflejen el final si están en stats
        if (isset($stats['imported_count']) && isset($stats['total_items_in_file'])) { // Suponiendo estos nombres de $result
            $data['processed_count'] = $stats['imported_count'];
            $data['total_count'] = $stats['total_items_in_file'];
        } elseif (isset($stats['processed_count']) && isset($stats['total_count'])) {
             $data['processed_count'] = $stats['processed_count'];
             $data['total_count'] = $stats['total_count'];
        } else { // fallback si no vienen esos datos exactos
            $data['processed_count'] = $data['total_count']; // Asumir que todo se procesó
        }

        $final_message = !empty($message) ? $message : __('Importación completada.', 'md-import-force');
        if (isset($stats['new_count'], $stats['updated_count'], $stats['skipped_count'])) {
            $final_message .= sprintf(
                __(' Resumen: %d nuevos, %d actualizados, %d omitidos.', 'md-import-force'),
                $stats['new_count'], $stats['updated_count'], $stats['skipped_count']
            );
        }
        $data['current_item_message'] = $final_message;
        $data['timestamp'] = time();
        $data['end_time'] = time();


        if (@file_put_contents($progress_file, json_encode($data)) === false) {
             if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS ERROR]: No se pudo escribir la finalización de progreso en: {$progress_file}");
            }
        } else {
            if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS INFO]: Importación {$import_id} marcada como COMPLETA. Stats: " . json_encode($stats));
            }
        }
    }

    /**
     * Obtiene los datos de progreso actuales para una importación específica.
     *
     * @param string $import_id Identificador único de la importación.
     * @return array|null Datos de progreso actuales o null si no se encuentran.
     */
    public static function get_progress($import_id) {
        if (empty($import_id)) {
             if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS ERROR]: get_progress recibió un import_id vacío.");
            }
            return self::_default_progress_data($import_id, 'error_no_id', __('ID de importación no proporcionado.', 'md-import-force'));
        }

        $progress_file = self::_get_progress_file_path($import_id);

        if (!$progress_file || !file_exists($progress_file)) {
            if (class_exists('MD_Import_Force_Logger')) {
                 MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS INFO]: Archivo de progreso no encontrado para {$import_id} en get_progress. Devolviendo predeterminado.");
            }
            // Devolver un estado que indique que no se ha iniciado o no se encuentra
            return self::_default_progress_data($import_id, 'not_found', __('No se encontró progreso para esta importación o aún no ha comenzado.', 'md-import-force'));
        }

        $raw_data = @file_get_contents($progress_file);
        if ($raw_data === false) {
            if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS ERROR]: No se pudo leer el archivo de progreso: {$progress_file}");
            }
             return self::_default_progress_data($import_id, 'error_reading_file', __('Error al leer datos de progreso.', 'md-import-force'));
        }

        $progress_data = json_decode($raw_data, true);

        if (!$progress_data) {
            if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS ERROR]: No se pudo decodificar JSON del archivo de progreso: {$progress_file}");
            }
            return self::_default_progress_data($import_id, 'error_parsing_json', __('Error al parsear datos de progreso.', 'md-import-force'));
        }
        
        // Asegurar que los datos tienen el formato esperado por el cliente JS
        // El JS espera 'processed_count' y 'total_count' directamente en el objeto.
        // El JS espera 'current_item' en lugar de 'current_item_message'
        // El JS espera 'percent'
        // $progress_data['current_item'] = $progress_data['current_item_message'] ?? ''; // Adaptar nombre de campo - REMOVED FOR CLEANUP
        // 'processed_count', 'total_count', 'percent', 'status' ya deberían estar bien.

        return $progress_data;
    }

    /**
     * Devuelve una estructura de datos de progreso por defecto.
     */
    private static function _default_progress_data($import_id = '', $status = 'unknown', $message = '') {
        return [
            'import_id' => $import_id,
            'status' => $status,
            'processed_count' => 0,
            'total_count' => 0,
            'percent' => 0,
            'current_item_message' => $message ?: __('Esperando información...', 'md-import-force'),
            // 'current_item' => $message ?: __('Esperando información...', 'md-import-force'), // Para compatibilidad JS - REMOVED FOR CLEANUP
            'details' => [],
            'stats' => [],
            'timestamp' => time()
        ];
    }

    /**
     * Crea la tabla de progreso si no existe o la actualiza si es necesario.
     */
    public static function create_progress_table_if_needed() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'mdif_progress';
        
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            import_id varchar(255) NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'queued',
            processed_count int(11) NOT NULL DEFAULT 0,
            total_count int(11) NOT NULL DEFAULT 0,
            percent int(3) NOT NULL DEFAULT 0,
            current_item_message text NOT NULL,
            details longtext,
            stats longtext,
            timestamp datetime NOT NULL,
            start_time datetime NOT NULL,
            end_time datetime,
            PRIMARY KEY  (id),
            KEY import_id (import_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Obtiene el contador de elementos procesados globalmente para una importación.
     *
     * @param string $import_id Identificador único de la importación.
     * @return int Número de elementos procesados globalmente.
     */
    public static function get_overall_processed_count($import_id) {
        if (empty($import_id)) return 0;
        $progress_data = self::get_progress($import_id);
        return $progress_data['processed_count'] ?? 0;
    }

    /**
     * Actualiza el contador de elementos procesados globalmente y el timestamp.
     * Este método es más específico que update_progress y solo toca el contador y el timestamp.
     *
     * @param string $import_id Identificador único de la importación.
     * @param int $new_count Nuevo contador de elementos procesados.
     */
    public static function update_overall_processed_count($import_id, $new_count) {
        if (empty($import_id)) return;
        $progress_file = self::_get_progress_file_path($import_id);
        if (!$progress_file) return;

        $data = [];
        if (file_exists($progress_file)) {
            $data = json_decode(file_get_contents($progress_file), true);
        }

        // Si no hay datos o no se pudieron decodificar, inicializar con valores básicos.
        // Esto es un resguardo, idealmente el archivo ya existe y es válido.
        if (empty($data)) {
            MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS WARN]: Archivo de progreso no encontrado o corrupto en update_overall_processed_count para {$import_id}. Se intentará crear/resetear.");
            // No llamar a initialize_progress aquí para no sobrescribir el total_count si ya existe.
            // Simplemente estableceremos los campos necesarios.
            $data = self::_default_progress_data($import_id, 'processing', __('Actualizando contador...', 'md-import-force'));
            // Si tenemos un total_count previo de alguna manera, deberíamos intentar preservarlo.
            // Por ahora, _default_progress_data no lo hace. Se necesitaría un get_progress() más robusto.
        }

        $data['processed_count'] = (int)$new_count;
        $data['timestamp'] = time();

        // Recalcular porcentaje si total_count está disponible
        if (isset($data['total_count']) && $data['total_count'] > 0) {
            $data['percent'] = round(((int)$new_count / (int)$data['total_count']) * 100);
        } else {
            $data['percent'] = 0; // No se puede calcular si no hay total
        }
        
        // Asegurar que el estado sea 'processing' si no es un estado final
        if (isset($data['status']) && $data['status'] !== 'completed' && $data['status'] !== 'failed' && $data['status'] !== 'stopped') {
            $data['status'] = 'processing';
        }

        if (@file_put_contents($progress_file, json_encode($data)) === false) {
            if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS ERROR]: No se pudo escribir la actualización de contador en: {$progress_file}");
            }
        }
    }

    /**
     * Agrega un Post ID a la cola de posts que necesitan actualización de contenido.
     *
     * @param string $import_run_guid GUID de la sesión de importación.
     * @param int $post_id ID del post en WordPress.
     * @param string $import_id ID de la importación (file_path) - opcional, se puede inferir del contexto
     */
    public static function add_post_to_content_update_queue($import_run_guid, $post_id, $import_id = null) {
        if (empty($import_run_guid) || empty($post_id)) return;

        // Si no se proporciona import_id, intentamos obtenerlo del contexto
        // o simplemente logueamos el error sin procesar
        if (empty($import_id)) {
            MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS WARNING]: add_post_to_content_update_queue llamado sin import_id para GUID: {$import_run_guid}, Post: {$post_id}");
            return;
        }

        $progress_file = self::_get_progress_file_path($import_id);
         if (!$progress_file || !file_exists($progress_file)) {
              if (class_exists('MD_Import_Force_Logger')) {
                  MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS ERROR]: Archivo de progreso no encontrado para import_id {$import_id} al añadir post a cola de actualización de contenido.");
              }
              return;
         }

         $data = json_decode(file_get_contents($progress_file), true);
         if (!$data) {
               if (class_exists('MD_Import_Force_Logger')) {
                  MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS ERROR]: No se pudo decodificar el archivo de progreso: {$progress_file} al añadir post a cola de actualización de contenido.");
              }
              return;
         }

         // Asegurarse de que la estructura existe
         if (!isset($data['posts_to_update_content'])) {
             $data['posts_to_update_content'] = [];
         }

         // Añadir el post_id si no está ya en la lista
         if (!in_array($post_id, $data['posts_to_update_content'])) {
             $data['posts_to_update_content'][] = $post_id;
         }
         
         // Opcional: almacenar también por GUID para referencia, aunque la clave principal es import_id
         if (!isset($data['content_update_queue_by_guid'])) {
              $data['content_update_queue_by_guid'] = [];
         }
         if (!isset($data['content_update_queue_by_guid'][$import_run_guid])) {
             $data['content_update_queue_by_guid'][$import_run_guid] = [];
         }
         if (!in_array($post_id, $data['content_update_queue_by_guid'][$import_run_guid])) {
             $data['content_update_queue_by_guid'][$import_run_guid][] = $post_id;
         }

         // Guardar los cambios
         if (@file_put_contents($progress_file, json_encode($data)) === false) {
             if (class_exists('MD_Import_Force_Logger')) {
                 MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS ERROR]: No se pudo escribir en el archivo de progreso ({$progress_file}) al añadir post a cola de actualización de contenido.");
             }
         }
          if (class_exists('MD_Import_Force_Logger')) {
              MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS INFO]: Post ID {$post_id} añadido a cola de actualización de contenido para import_id {$import_id} (GUID: {$import_run_guid}).");
          }
    }

    /**
     * Obtiene la lista de Post IDs que necesitan actualización de contenido para un import_run_guid específico.
     *
     * @param string $import_run_guid GUID de la sesión de importación.
     * @param string $import_id ID de la importación (file_path).
     * @return array Lista de Post IDs.
     */
    public static function get_posts_for_content_update($import_run_guid, $import_id) {
        if (empty($import_run_guid) || empty($import_id)) return [];
        
        $progress_file = self::_get_progress_file_path($import_id);
        if (!$progress_file || !file_exists($progress_file)) {
            if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS ERROR]: Archivo de progreso no encontrado para import_id {$import_id} al obtener posts para actualización de contenido.");
            }
            return [];
        }

        $data = json_decode(file_get_contents($progress_file), true);
        if (!$data) {
             if (class_exists('MD_Import_Force_Logger')) {
                MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS ERROR]: No se pudo decodificar el archivo de progreso: {$progress_file} al obtener posts para actualización de contenido.");
            }
            return [];
        }

        // Recuperar la lista asociada al GUID si existe, o la lista global si no
        // Usamos la lista por GUID para ser más específicos si el progreso rastrea múltiples importaciones.
        // Si no encontramos por GUID, quizás la lista global (si existiera) podría ser un fallback,
        // pero para este plugin, la lista por GUID es lo que esperamos usar.
        if (isset($data['content_update_queue_by_guid'][$import_run_guid]) && is_array($data['content_update_queue_by_guid'][$import_run_guid])) {
            return $data['content_update_queue_by_guid'][$import_run_guid];
        } elseif (isset($data['posts_to_update_content']) && is_array($data['posts_to_update_content'])) {
             // Fallback a la lista global si no hay lista por GUID (compatibilidad o si se decide no usar por GUID)
             // PERO: Si hay múltiples imports rastreados en el mismo archivo, esto podría devolver Posts de otro import.
             // Es más seguro depender del rastreo por GUID.
             // Vamos a requerir el rastreo por GUID para evitar mezclar posts de diferentes imports.
            if (class_exists('MD_Import_Force_Logger')) {
                 MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS WARN]: No se encontró lista de posts para actualización de contenido por GUID {$import_run_guid}, ni lista global en archivo de progreso {$import_id}.");
            }
             return [];
        }

        if (class_exists('MD_Import_Force_Logger')) {
            MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS INFO]: No hay Post IDs registrados para actualización de contenido para GUID {$import_run_guid} en archivo de progreso {$import_id}.");
        }
        return []; // No hay posts para actualizar
    }

     /**
      * Elimina un Post ID de la cola de posts que necesitan actualización de contenido.
      *
      * @param string $import_run_guid GUID de la sesión de importación.
      * @param string $import_id ID de la importación (file_path).
      * @param int $post_id ID del post en WordPress a eliminar.
      */
    public static function remove_post_from_content_update_queue($import_run_guid, $import_id, $post_id) {
         if (empty($import_run_guid) || empty($import_id) || empty($post_id)) return;

         $progress_file = self::_get_progress_file_path($import_id);
         if (!$progress_file || !file_exists($progress_file)) {
              if (class_exists('MD_Import_Force_Logger')) {
                  MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS ERROR]: Archivo de progreso no encontrado para import_id {$import_id} al eliminar post de cola de actualización de contenido.");
              }
              return;
         }

         $data = json_decode(file_get_contents($progress_file), true);
         if (!$data) {
               if (class_exists('MD_Import_Force_Logger')) {
                  MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS ERROR]: No se pudo decodificar el archivo de progreso: {$progress_file} al eliminar post de cola de actualización de contenido.");
              }
              return;
         }

         $updated = false;

         // Eliminar de la lista por GUID
         if (isset($data['content_update_queue_by_guid'][$import_run_guid]) && is_array($data['content_update_queue_by_guid'][$import_run_guid])) {
             $key = array_search($post_id, $data['content_update_queue_by_guid'][$import_run_guid]);
             if ($key !== false) {
                 unset($data['content_update_queue_by_guid'][$import_run_guid][$key]);
                 $data['content_update_queue_by_guid'][$import_run_guid] = array_values($data['content_update_queue_by_guid'][$import_run_guid]); // Reindexar
                 $updated = true;
             }
         }

         // Eliminar de la lista global (si la estamos usando o soportando como fallback)
          if (isset($data['posts_to_update_content']) && is_array($data['posts_to_update_content'])) {
              $key = array_search($post_id, $data['posts_to_update_content']);
              if ($key !== false) {
                  unset($data['posts_to_update_content'][$key]);
                  $data['posts_to_update_content'] = array_values($data['posts_to_update_content']); // Reindexar
                   $updated = true;
              }
          }

         if ($updated) {
              // Guardar los cambios
              if (@file_put_contents($progress_file, json_encode($data)) === false) {
                  if (class_exists('MD_Import_Force_Logger')) {
                      MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS ERROR]: No se pudo escribir en el archivo de progreso ({$progress_file}) al eliminar post de cola de actualización de contenido.");
                  }
              } else {
                   if (class_exists('MD_Import_Force_Logger')) {
                       MD_Import_Force_Logger::log_message("MD Import Force [PROGRESS INFO]: Post ID {$post_id} eliminado de cola de actualización de contenido para import_id {$import_id} (GUID: {$import_run_guid}).");
                   }
              }
         }
    }

    /*
    // ---- Old instance-based methods and constructor ----
    // ---- Commented out for replacement with static methods ----

    public function __construct() {
        // Generar un ID único para la sesión de importación
        // $this->import_session_id = uniqid('import_');

        // Crear directorio temporal si no existe
        // $this->init_temp_directory();

        // Limpiar datos de progreso anteriores
        // $this->clear_previous_progress_data();

        // Guardar el ID de sesión en una opción temporal inmediatamente
        // if (function_exists('update_option')) {
        //     update_option('md_import_force_current_session', $this->import_session_id, false);
        // }

        // Guardar también en un archivo para mayor seguridad
        // $session_file = $this->temp_dir . '/current_session.txt';
        // @file_put_contents($session_file, $this->import_session_id);

        // Inicializar datos de progreso con valores por defecto
        // $this->initialize_progress_data();

        // Registrar en el log para depuración
        // if (class_exists('MD_Import_Force_Logger')) {
        //     MD_Import_Force_Logger::log_message("MD Import Force: Iniciando seguimiento de progreso con ID: {$this->import_session_id}");
        // }
    }

    private function init_temp_directory() {
        // Intentar usar wp_upload_dir si está disponible
        // if (function_exists('wp_upload_dir')) {
        //     $upload_dir = wp_upload_dir();
        //     $this->temp_dir = $upload_dir['basedir'] . '/md-import-force-temp';
        // } else {
        //     // Fallback a directorio del plugin
        //     $this->temp_dir = dirname(dirname(__FILE__)) . '/temp';
        // }

        // Crear directorio si no existe
        // if (!file_exists($this->temp_dir)) {
        //     if (function_exists('wp_mkdir_p')) {
        //         wp_mkdir_p($this->temp_dir);
        //     } else {
        //         @mkdir($this->temp_dir, 0755, true);
        //     }
        // }
    }

    public function send_progress_update($current, $total, $current_item = null) {
        // ... old logic ...
    }

    private function save_progress_data($data) {
        // ... old logic ...
    }
    
    private function clear_previous_progress_data() {
        // ... old logic ...
    }

    private function initialize_progress_data() {
        // ... old logic ...
    }

    public function mark_as_completed() {
        // ... old logic ...
    }

    // Old static get_progress method, replaced by new one above
    // public static function get_progress() {
    //      // ... old logic ...
    // }
    */
}

@ini_set('max_execution_time', '300');  // 5 minutes
@ini_set('max_input_time', '300');

?>
