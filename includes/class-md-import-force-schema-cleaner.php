<?php
/**
 * Clase para limpiar metadatos de schema de Rank Math
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

class MD_Import_Force_Schema_Cleaner {

    /**
     * Constructor
     */
    public function __construct() {
        // Registrar acción AJAX para limpiar schema
        add_action('wp_ajax_md_import_force_clean_schema', array($this, 'handle_clean_schema'));
    }

    /**
     * Manejador AJAX para limpiar schema
     */
    public function handle_clean_schema() {
        try {
            // Verificar nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'md_import_force_nonce')) {
                wp_send_json_error(array('message' => __('Error de seguridad. Por favor, recarga la página.', 'md-import-force')));
                return;
            }

            // Verificar permisos
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => __('No tienes permisos para realizar esta acción.', 'md-import-force')));
                return;
            }

            // Obtener el ID del post si se especifica
            $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
            
            // Limpiar schema
            $result = $this->clean_schema($post_id);
            
            // Devolver resultado
            wp_send_json_success(array(
                'message' => $result['message'],
                'count' => $result['count']
            ));
        } catch (Exception $e) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR]: Error en handle_clean_schema: " . $e->getMessage());
            wp_send_json_error(array('message' => __('Error al limpiar schema: ', 'md-import-force') . $e->getMessage()));
        }
    }

    /**
     * Limpia los metadatos de schema de Rank Math
     * 
     * @param int $post_id ID del post a limpiar (0 para todos)
     * @return array Resultado de la operación
     */
    public function clean_schema($post_id = 0) {
        global $wpdb;
        
        // Lista de prefijos de metadatos a eliminar
        $meta_prefixes = array(
            '_rank_math_schema_',
            'rank_math_schema_',
            'rank_math_snippet_'
        );
        
        $count = 0;
        $where_post = '';
        
        // Si se especifica un post_id, limitar la limpieza a ese post
        if ($post_id > 0) {
            $where_post = $wpdb->prepare("AND post_id = %d", $post_id);
        }
        
        // Eliminar metadatos para cada prefijo
        foreach ($meta_prefixes as $prefix) {
            $like_query = $wpdb->prepare("meta_key LIKE %s", $prefix . '%');
            
            // Registrar los metadatos que se van a eliminar (solo para depuración)
            $meta_keys = $wpdb->get_col("
                SELECT DISTINCT meta_key 
                FROM {$wpdb->postmeta} 
                WHERE {$like_query} {$where_post}
            ");
            
            if (!empty($meta_keys)) {
                foreach ($meta_keys as $meta_key) {
                    MD_Import_Force_Logger::log_message("MD Import Force [INFO]: Eliminando metadato: {$meta_key}" . ($post_id > 0 ? " para Post ID {$post_id}" : ""));
                }
            }
            
            // Eliminar los metadatos
            $deleted = $wpdb->query("
                DELETE FROM {$wpdb->postmeta} 
                WHERE {$like_query} {$where_post}
            ");
            
            if ($deleted !== false) {
                $count += $deleted;
            }
        }
        
        // Limpiar caché
        if ($post_id > 0) {
            clean_post_cache($post_id);
        } else {
            wp_cache_flush();
        }
        
        // Registrar resultado
        $message = sprintf(
            __('Se han eliminado %d metadatos de schema de Rank Math%s.', 'md-import-force'),
            $count,
            $post_id > 0 ? sprintf(__(' para el post ID %d', 'md-import-force'), $post_id) : ''
        );
        
        MD_Import_Force_Logger::log_message("MD Import Force: {$message}");
        
        return array(
            'message' => $message,
            'count' => $count
        );
    }
}
