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
}
?> 