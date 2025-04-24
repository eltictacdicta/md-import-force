<?php
/**
 * Clase para manejar la importación de medios
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

// Incluir el manejador de URLs si no está incluido
if (!class_exists('MD_Import_Force_URL_Handler')) {
    require_once dirname(__FILE__) . '/class-md-import-force-url-handler.php';
}

class MD_Import_Force_Media_Handler {

    private $source_site_info = [];
    private $source_url = null;
    private $target_url = null;
    private $memory_limit_reached = false;

    public function __construct($source_site_info = []) {
        $this->source_site_info = $source_site_info;

        // Obtener la URL completa del sitio actual directamente de la opción de WordPress
        $home_url = get_option('home');
        if (empty($home_url)) {
            $home_url = get_option('siteurl');
        }

        // Asegurar que la URL termina con una barra
        $this->target_url = rtrim($home_url, '/') . '/';

        // Registrar información detallada para depuración
        MD_Import_Force_Logger::log_message("MD Import Force: URL de destino detectada (directa): {$this->target_url}");
        MD_Import_Force_Logger::log_message("MD Import Force: URL desde get_option('home'): {$home_url}");
        MD_Import_Force_Logger::log_message("MD Import Force: URL desde get_option('siteurl'): " . get_option('siteurl'));
        MD_Import_Force_Logger::log_message("MD Import Force: URL desde home_url(): " . home_url());
        MD_Import_Force_Logger::log_message("MD Import Force: URL desde site_url(): " . site_url());

        // Inicializar la URL de origen si está disponible
        if (!empty($source_site_info['site_url'])) {
            $this->source_url = rtrim($source_site_info['site_url'], '/') . '/';
        }
    }

    /**
     * Establece la información del sitio de origen
     */
    public function set_source_site_info($source_site_info) {
        $this->source_site_info = $source_site_info;

        // Actualizar la URL de origen si está disponible
        if (!empty($source_site_info['site_url'])) {
            $this->source_url = rtrim($source_site_info['site_url'], '/') . '/';
        }
    }

    /**
     * Establece la URL del sitio de origen
     */
    public function set_source_url($url) {
        if (!empty($url)) {
            $this->source_url = rtrim($url, '/') . '/';
            MD_Import_Force_Logger::log_message("MD Import Force: URL de origen establecida manualmente: {$this->source_url}");
        }
    }

    /**
     * Procesa imagen destacada
     */
    public function process_featured_image($post_id, $image_data) {
        try {
            $url = is_array($image_data) ? ($image_data['url'] ?? '') : (is_string($image_data) ? $image_data : '');
            $alt = is_array($image_data) ? ($image_data['alt'] ?? '') : '';

            if (empty($url)) return;

            // Si ya hemos alcanzado el límite de memoria, no procesar más imágenes
            if ($this->memory_limit_reached) {
                MD_Import_Force_Logger::log_message("MD Import Force [WARN]: Omitiendo imagen destacada debido a límite de memoria: {$url}");
                return;
            }

            $att_id = $this->import_external_image($url, $post_id, $alt);
            if ($att_id && !is_wp_error($att_id)) {
                if (!set_post_thumbnail($post_id, $att_id)) {
                    MD_Import_Force_Logger::log_message("MD Import Force [WARN] Post ID {$post_id}: No se pudo setear thumbnail {$att_id}.");
                }
            }
            elseif (is_wp_error($att_id)) {
                MD_Import_Force_Logger::log_message("MD Import Force [ERROR] Post ID {$post_id}: Error procesando feat. img '{$url}': " . $att_id->get_error_message());
            }
        } catch (Exception $e) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR]: Error al procesar imagen destacada: " . $e->getMessage());
        }
    }

    /**
     * Procesa imágenes en contenido y reemplaza URLs
     */
    public function process_content_images($post_id, $post_data) {
        try {
            $content = $post_data['post_content'] ?? '';
            $images = $post_data['images'] ?? [];

            if (empty($content)) return;

            // Si ya hemos alcanzado el límite de memoria, solo hacer reemplazo de URLs sin procesar imágenes
            if ($this->memory_limit_reached) {
                MD_Import_Force_Logger::log_message("MD Import Force [WARN]: Omitiendo procesamiento de imágenes debido a límite de memoria para Post ID {$post_id}");
                $this->replace_urls_only($post_id, $post_data, $content);
                return;
            }

            // Usar la URL de origen almacenada o intentar obtenerla de source_site_info
            $src_url = $this->source_url;
            $tgt_url = $this->target_url;

            // Si no tenemos URL de origen, intentar detectarla automáticamente
            if (empty($src_url) && !empty($images) && is_array($images)) {
                // Intentar detectar la URL base a partir de las imágenes
                $detected_urls = [];
                foreach ($images as $img) {
                    if (!empty($img['url'])) {
                        $url_parts = parse_url($img['url']);
                        if (!empty($url_parts['scheme']) && !empty($url_parts['host'])) {
                            $base_url = $url_parts['scheme'] . '://' . $url_parts['host'];
                            if (!empty($url_parts['port'])) {
                                $base_url .= ':' . $url_parts['port'];
                            }

                            // Si hay un path, verificar si es un subdirectorio de WordPress
                            if (!empty($url_parts['path'])) {
                                // Extraer el posible subdirectorio (primer nivel del path)
                                $path_parts = explode('/', trim($url_parts['path'], '/'));
                                if (!empty($path_parts[0]) &&
                                    !in_array($path_parts[0], ['wp-content', 'wp-includes', 'wp-admin'])) {
                                    // Si el primer segmento no es una carpeta de WordPress, podría ser un subdirectorio de instalación
                                    $base_url .= '/' . $path_parts[0];
                                }
                            }
                            $detected_urls[$base_url] = isset($detected_urls[$base_url]) ? $detected_urls[$base_url] + 1 : 1;
                        }
                    }
                }

                // Si se detectaron URLs, usar la más frecuente
                if (!empty($detected_urls)) {
                    arsort($detected_urls);
                    $src_url = rtrim(key($detected_urls), '/') . '/';
                    $this->source_url = $src_url; // Guardar para uso futuro

                    // Registrar la URL detectada
                    MD_Import_Force_Logger::log_message("MD Import Force: URL de origen detectada automáticamente: {$src_url}");
                }
            }

            // Si las URLs son iguales o no tenemos URL de origen, no hacer reemplazos globales
            if (empty($src_url) || $src_url === $tgt_url) {
                // Aún así, procesar las imágenes individuales si hay
                if (!empty($images) && is_array($images)) {
                    $this->process_images_only($post_id, $post_data, $content);
                }
                return;
            }

            // Procesar imágenes y obtener reemplazos
            $replacements = [];

            if (!empty($images) && is_array($images)) {
                $processed_count = 0;
                $max_images = 20; // Limitar el número de imágenes a procesar para evitar timeouts

                foreach ($images as $img) {
                    // Limitar el número de imágenes procesadas
                    if ($processed_count >= $max_images || $this->memory_limit_reached) break;
                    $processed_count++;

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
            }

            // Usar el manejador de URLs para reemplazar todas las URLs en el contenido
            $new_content = MD_Import_Force_URL_Handler::replace_urls_in_content($content, $src_url, $tgt_url, $replacements);

            // Actualizar el contenido si ha cambiado
            if ($new_content !== $content) {
                $upd = wp_update_post(['ID' => $post_id, 'post_content' => $new_content], true);
                if (is_wp_error($upd)) {
                    MD_Import_Force_Logger::log_message("MD Import Force [ERROR] Post ID {$post_id}: Falló update post_content: " . $upd->get_error_message());
                } else {
                    MD_Import_Force_Logger::log_message("MD Import Force: Contenido actualizado con URLs reemplazadas para Post ID {$post_id}");
                }
            }
        } catch (Exception $e) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR]: Error al procesar imágenes en contenido: " . $e->getMessage());
        }
    }

    /**
     * Reemplaza solo las URLs sin procesar imágenes
     * (Usado cuando se ha alcanzado el límite de memoria)
     */
    private function replace_urls_only($post_id, $post_data, $content) {
        try {
            $src_url = $this->source_url;
            $tgt_url = $this->target_url;

            if (empty($src_url) || $src_url === $tgt_url || empty($content)) {
                return;
            }

            // Usar el manejador de URLs para reemplazar todas las URLs en el contenido
            $new_content = MD_Import_Force_URL_Handler::replace_urls_in_content($content, $src_url, $tgt_url, []);

            // Actualizar el contenido si ha cambiado
            if ($new_content !== $content) {
                $upd = wp_update_post(['ID' => $post_id, 'post_content' => $new_content], true);
                if (is_wp_error($upd)) {
                    MD_Import_Force_Logger::log_message("MD Import Force [ERROR] Post ID {$post_id}: Falló update post_content (solo URLs): " . $upd->get_error_message());
                } else {
                    MD_Import_Force_Logger::log_message("MD Import Force: Contenido actualizado con URLs reemplazadas (sin imágenes) para Post ID {$post_id}");
                }
            }
        } catch (Exception $e) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR]: Error al reemplazar URLs: " . $e->getMessage());
        }
    }

    /**
     * Procesa solo las imágenes sin reemplazar URLs globalmente
     * (Usado cuando no se puede detectar la URL de origen o es igual a la de destino)
     */
    private function process_images_only($post_id, $post_data, $content) {
        try {
            $images = $post_data['images'] ?? [];
            if (empty($images) || !is_array($images) || $this->memory_limit_reached) return;

            $replacements = [];
            $processed_count = 0;
            $max_images = 20; // Limitar el número de imágenes a procesar

            foreach ($images as $img) {
                // Limitar el número de imágenes procesadas
                if ($processed_count >= $max_images || $this->memory_limit_reached) break;
                $processed_count++;

                if (empty($img['url'])) continue;
                $old = $img['url'];
                if (isset($replacements[$old])) continue;

                $att_id = $this->import_external_image($old, $post_id);
                if ($att_id && !is_wp_error($att_id)) {
                    $new_url = wp_get_attachment_url($att_id);
                    if ($new_url) {
                        $replacements[$old] = $new_url;
                    }
                }
            }

            if (!empty($replacements)) {
                uksort($replacements, function($a, $b) { return strlen($b) - strlen($a); });
                $new_content = $content;

                foreach ($replacements as $old => $new) {
                    $new_content = str_replace($old, $new, $new_content);
                }

                if ($new_content !== $content) {
                    $upd = wp_update_post(['ID' => $post_id, 'post_content' => $new_content], true);
                    if (is_wp_error($upd)) {
                        MD_Import_Force_Logger::log_message("MD Import Force [ERROR] Post ID {$post_id}: Falló update post_content: " . $upd->get_error_message());
                    } else {
                        MD_Import_Force_Logger::log_message("MD Import Force: Contenido actualizado con imágenes reemplazadas para Post ID {$post_id}");
                    }
                }
            }
        } catch (Exception $e) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR]: Error al procesar solo imágenes: " . $e->getMessage());
        }
    }

    /**
     * Importa imagen externa
     */
    public function import_external_image($url, $post_id = 0, $alt = '') {
        static $processed = [];

        try {
            // Si ya hemos alcanzado el límite de memoria, no procesar más imágenes
            if ($this->memory_limit_reached) {
                return new WP_Error('memory_limit', __('Límite de memoria alcanzado, omitiendo descarga de imágenes.', 'md-import-force'));
            }

            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                return new WP_Error('invalid_url', __('URL inválida.', 'md-import-force'));
            }

            $url = esc_url_raw(trim($url));

            if (isset($processed[$url])) {
                return $processed[$url];
            }

            $tgt_url = $this->target_url;

            // Si la URL ya es local, intentar obtener su ID
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

            // Verificar memoria disponible
            $limit = $this->get_memory_limit_bytes();
            if ($limit > 0 && (memory_get_usage(true) / $limit * 100) > 80) {
                $this->memory_limit_reached = true;
                $err = new WP_Error('high_memory', __('Memoria alta, omitiendo futuras descargas.', 'md-import-force'));
                MD_Import_Force_Logger::log_message("MD Import Force [WARN]: " . $err->get_error_message());
                $processed[$url] = $err;
                return $err;
            }

            // Aumentar el tiempo límite para la descarga
            if (function_exists('set_time_limit')) {
                @set_time_limit(120);
            }

            // Descargar la imagen con un timeout de 30 segundos
            $tmp = download_url($url, 30);

            if (is_wp_error($tmp)) {
                MD_Import_Force_Logger::log_message("MD Import Force [ERROR] Descarga '{$url}': " . $tmp->get_error_message());
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
                MD_Import_Force_Logger::log_message("MD Import Force [ERROR] Sideload '{$url}': " . $att_id->get_error_message());
                $processed[$url] = $att_id;
                return $att_id;
            }

            if (!empty($alt)) {
                update_post_meta($att_id, '_wp_attachment_image_alt', $alt);
            }

            update_post_meta($att_id, '_source_url', $url);
            $processed[$url] = $att_id;

            return $att_id;
        } catch (Exception $e) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR]: Error al importar imagen externa: " . $e->getMessage());
            return new WP_Error('exception', $e->getMessage());
        }
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
