<?php
/**
 * Clase para manejar la importación de medios
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

class MD_Import_Force_Media_Handler {

    private $source_site_info = [];

    public function __construct($source_site_info = []) {
        $this->source_site_info = $source_site_info;
    }

    /**
     * Establece la información del sitio de origen
     */
    public function set_source_site_info($source_site_info) {
        $this->source_site_info = $source_site_info;
    }

    /**
     * Procesa imagen destacada
     */
    public function process_featured_image($post_id, $image_data) {
        $url = is_array($image_data) ? ($image_data['url'] ?? '') : (is_string($image_data) ? $image_data : ''); 
        $alt = is_array($image_data) ? ($image_data['alt'] ?? '') : ''; 
        
        if (empty($url)) return;
        
        $att_id = $this->import_external_image($url, $post_id, $alt);
        if ($att_id && !is_wp_error($att_id)) { 
            if (!set_post_thumbnail($post_id, $att_id)) {
                error_log("MD Import Force [WARN] Post ID {$post_id}: No se pudo setear thumbnail {$att_id}.");
            }
        }
        elseif (is_wp_error($att_id)) {
            error_log("MD Import Force [ERROR] Post ID {$post_id}: Error procesando feat. img '{$url}': " . $att_id->get_error_message());
        }
    }

    /**
     * Procesa imágenes en contenido
     */
    public function process_content_images($post_id, $post_data) {
        $content = $post_data['post_content'] ?? ''; 
        $images = $post_data['images'] ?? []; 
        
        if (empty($content) || empty($images) || !is_array($images)) return;
        
        $src_url = isset($this->source_site_info['site_url']) ? trailingslashit($this->source_site_info['site_url']) : null; 
        $tgt_url = trailingslashit(get_site_url()); 
        
        if ($src_url && $src_url === $tgt_url) return;
        
        $new_content = $content; 
        $replacements = [];
        
        foreach ($images as $img) {
            if (empty($img['url'])) continue; 
            $old = $img['url']; 
            if (isset($replacements[$old])) continue;
            
            $att_id = $this->import_external_image($old, $post_id);
            if ($att_id && !is_wp_error($att_id)) {
                $new_url = wp_get_attachment_url($att_id);
                if ($new_url) {
                    $replacements[$old] = $new_url;
                    // Si hay un src_url, también reemplazar URLs relativas
                    if ($src_url) {
                        $rel_url = str_replace($src_url, '', $old);
                        if ($rel_url !== $old) $replacements[$rel_url] = $new_url;
                    }
                }
            }
        }
        
        if (!empty($replacements)) {
            uksort($replacements, function($a, $b) { return strlen($b) - strlen($a); }); 
            $orig_content = $new_content;
            
            foreach ($replacements as $old => $new) {
                $new_content = str_replace($old, $new, $new_content);
            }
            
            if ($new_content !== $orig_content) {
                $upd = wp_update_post(['ID' => $post_id, 'post_content' => $new_content], true);
                if (is_wp_error($upd)) {
                    error_log("MD Import Force [ERROR] Post ID {$post_id}: Falló update post_content: " . $upd->get_error_message());
                }
            }
        }
    }

    /**
     * Importa imagen externa
     */
    public function import_external_image($url, $post_id = 0, $alt = '') {
        static $processed = []; 
        static $skip = false;
        
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', __('URL inválida.', 'md-import-force'));
        }
        
        $url = esc_url_raw(trim($url)); 
        
        if (isset($processed[$url])) {
            return $processed[$url];
        }
        
        $tgt_url = trailingslashit(get_site_url()); 
        
        if (strpos($url, $tgt_url) === 0) { 
            $att_id = attachment_url_to_postid($url); 
            if ($att_id) { 
                $processed[$url] = $att_id; 
                return $att_id; 
            } 
            $err = new WP_Error('local_not_found', __('URL local no encontrada.', 'md-import-force')); 
            $processed[$url] = $err; 
            return $err; 
        }
        
        if ($skip) {
            $err = new WP_Error('skipped', __('Omitiendo descargas de imágenes.', 'md-import-force'));
            $processed[$url] = $err;
            return $err;
        }
        
        // Verificar memoria disponible
        $limit = $this->get_memory_limit_bytes(); 
        if ($limit > 0 && (memory_get_usage(true) / $limit * 100) > 90) { 
            $skip = true; 
            $err = new WP_Error('high_memory', __('Memoria alta, omitiendo futuras descargas.', 'md-import-force')); 
            error_log("MD Import Force: " . $err->get_error_message()); 
            $processed[$url] = $err; 
            return $err; 
        }
        
        if (function_exists('set_time_limit')) {
            set_time_limit(120);
        }
        
        $tmp = download_url($url, 60);
        
        if (is_wp_error($tmp)) { 
            error_log("MD Import Force [ERROR] Descarga '{$url}': " . $tmp->get_error_message()); 
            $processed[$url] = $tmp; 
            return $tmp; 
        }
        
        $file = [
            'name' => basename(parse_url($url, PHP_URL_PATH)), 
            'tmp_name' => $tmp
        ]; 
        
        if (empty($file['name']) || strpos($file['name'], '.') === false) { 
            $info = wp_check_filetype($tmp); 
            $file['name'] = uniqid('imported-') . '.' . ($info['ext'] ?: 'jpg'); 
        }
        
        $att_id = media_handle_sideload($file, $post_id, null); 
        @unlink($tmp);
        
        if (is_wp_error($att_id)) { 
            error_log("MD Import Force [ERROR] Sideload '{$url}': " . $att_id->get_error_message()); 
            $processed[$url] = $att_id; 
            return $att_id; 
        }
        
        if (!empty($alt)) {
            update_post_meta($att_id, '_wp_attachment_image_alt', $alt);
        }
        
        update_post_meta($att_id, '_source_url', $url); 
        $processed[$url] = $att_id; 
        
        return $att_id;
    }

    /**
     * Obtiene el límite de memoria en bytes
     */
    private function get_memory_limit_bytes() {
        $memory_limit = ini_get('memory_limit');
        if (!$memory_limit || $memory_limit == -1) return 0;
        
        $unit = strtolower(substr($memory_limit, -1));
        $bytes = intval(substr($memory_limit, 0, -1));
        
        switch ($unit) {
            case 'g': $bytes *= 1024;
            case 'm': $bytes *= 1024;
            case 'k': $bytes *= 1024;
        }
        
        return $bytes;
    }
}
