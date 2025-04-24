<?php
/**
 * Clase para manejar la detección y sustitución de URLs
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

class MD_Import_Force_URL_Handler {

    /**
     * Detecta automáticamente la URL base del sitio de origen
     * 
     * @param array $source_site_info Información del sitio de origen
     * @param array $posts_data Datos de los posts
     * @return string URL base detectada o vacío si no se pudo detectar
     */
    public static function detect_source_url($source_site_info, $posts_data) {
        try {
            // Primero intentar obtener la URL del site_info
            if (!empty($source_site_info['site_url'])) {
                $url = trailingslashit($source_site_info['site_url']);
                MD_Import_Force_Logger::log_message("MD Import Force: Usando URL de origen desde site_info: {$url}");
                return $url;
            }

            MD_Import_Force_Logger::log_message("MD Import Force: Intentando detectar URL de origen automáticamente...");
            
            // Si no está en site_info, intentar detectarla de los posts
            $detected_urls = [];
            $processed_posts = 0;
            $max_posts_to_process = 10; // Limitar el número de posts a procesar para evitar timeouts
            
            // Buscar en imágenes y enlaces en el contenido
            foreach ($posts_data as $post) {
                // Limitar el número de posts procesados
                if ($processed_posts >= $max_posts_to_process) break;
                $processed_posts++;
                
                // Buscar en imágenes
                if (!empty($post['images']) && is_array($post['images'])) {
                    foreach ($post['images'] as $image) {
                        if (!empty($image['url'])) {
                            $url_parts = parse_url($image['url']);
                            if (!empty($url_parts['scheme']) && !empty($url_parts['host'])) {
                                $base_url = $url_parts['scheme'] . '://' . $url_parts['host'];
                                if (!empty($url_parts['port'])) {
                                    $base_url .= ':' . $url_parts['port'];
                                }
                                $detected_urls[$base_url] = isset($detected_urls[$base_url]) ? $detected_urls[$base_url] + 1 : 1;
                            }
                        }
                    }
                }
                
                // Buscar en el contenido
                if (!empty($post['post_content'])) {
                    $content = $post['post_content'];
                    
                    // Limitar el tamaño del contenido para evitar problemas de memoria
                    if (strlen($content) > 50000) {
                        $content = substr($content, 0, 50000);
                    }
                    
                    // Extraer todas las URLs de una sola vez con una expresión regular más eficiente
                    if (preg_match_all('/https?:\/\/[^\s\"\')\]}]+/', $content, $matches)) {
                        foreach ($matches[0] as $url) {
                            // Limpiar la URL (eliminar caracteres no deseados al final)
                            $url = rtrim($url, '.,;:\'\"!?)');
                            $url_parts = parse_url($url);
                            if (!empty($url_parts['scheme']) && !empty($url_parts['host'])) {
                                $base_url = $url_parts['scheme'] . '://' . $url_parts['host'];
                                if (!empty($url_parts['port'])) {
                                    $base_url .= ':' . $url_parts['port'];
                                }
                                $detected_urls[$base_url] = isset($detected_urls[$base_url]) ? $detected_urls[$base_url] + 1 : 1;
                            }
                        }
                    }
                }
            }
            
            // Si se detectaron URLs, devolver la más frecuente
            if (!empty($detected_urls)) {
                arsort($detected_urls);
                $most_frequent_url = key($detected_urls);
                $url = trailingslashit($most_frequent_url);
                MD_Import_Force_Logger::log_message("MD Import Force: URL de origen detectada automáticamente: {$url}");
                return $url;
            }
            
            MD_Import_Force_Logger::log_message("MD Import Force: No se pudo detectar automáticamente la URL de origen");
            // Si no se pudo detectar, devolver vacío
            return '';
        } catch (Exception $e) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR]: Error al detectar URL de origen: " . $e->getMessage());
            return '';
        }
    }
    
    /**
     * Reemplaza URLs en el contenido
     * 
     * @param string $content Contenido a procesar
     * @param string $source_url URL base del sitio de origen
     * @param string $target_url URL base del sitio de destino
     * @param array $replacements Reemplazos adicionales (opcional)
     * @return string Contenido con URLs reemplazadas
     */
    public static function replace_urls_in_content($content, $source_url, $target_url, $replacements = []) {
        try {
            if (empty($content) || empty($source_url)) {
                return $content;
            }
            
            // Si las URLs son iguales, no hacer nada
            if ($source_url === $target_url) {
                return $content;
            }
            
            // Registrar información de reemplazo
            MD_Import_Force_Logger::log_message("MD Import Force: Reemplazando URLs de '{$source_url}' a '{$target_url}'");
            
            $new_content = $content;
            
            // Combinar reemplazos adicionales con el reemplazo principal
            $all_replacements = $replacements;
            $all_replacements[$source_url] = $target_url;
            
            // Añadir variantes sin www. y con www.
            $source_with_www = preg_replace('/^(https?:\/\/)/', '$1www.', $source_url);
            $source_without_www = preg_replace('/^(https?:\/\/)www\./', '$1', $source_url);
            
            if ($source_with_www !== $source_url) {
                $all_replacements[$source_with_www] = $target_url;
            }
            
            if ($source_without_www !== $source_url) {
                $all_replacements[$source_without_www] = $target_url;
            }
            
            // Ordenar reemplazos por longitud (más largos primero)
            uksort($all_replacements, function($a, $b) {
                return strlen($b) - strlen($a);
            });
            
            // Realizar reemplazos simples primero (más eficiente)
            foreach ($all_replacements as $old => $new) {
                // Reemplazar URLs completas, pero evitar duplicar dominios
                // Primero verificar si hay URLs que ya contienen el dominio de destino
                if (strpos($new_content, $target_url) !== false) {
                    // Usar un enfoque más cuidadoso para evitar duplicar dominios
                    $parts = explode($old, $new_content);
                    $result = $parts[0];
                    
                    for ($i = 1; $i < count($parts); $i++) {
                        $prev_char = substr($result, -1);
                        $next_char = substr($parts[$i], 0, 1);
                        
                        // Verificar si esta ocurrencia es parte de una URL que ya contiene el dominio de destino
                        $is_in_target_url = (
                            strpos(substr($result, -30), $target_url) !== false || 
                            strpos(substr($parts[$i], 0, 30), $target_url) !== false
                        );
                        
                        // Solo reemplazar si no es parte de una URL que ya contiene el dominio de destino
                        if (!$is_in_target_url) {
                            $result .= $new . $parts[$i];
                        } else {
                            $result .= $old . $parts[$i];
                        }
                    }
                    
                    $new_content = $result;
                } else {
                    // Si no hay riesgo de duplicación, usar el reemplazo simple
                    $new_content = str_replace($old, $new, $new_content);
                }
            }
            
            // Limitar el número de reemplazos con expresiones regulares para evitar problemas de rendimiento
            $max_regex_replacements = 3; // Solo aplicar regex a los reemplazos más importantes
            $count = 0;
            
            foreach ($all_replacements as $old => $new) {
                if ($count >= $max_regex_replacements) break;
                $count++;
                
                // Reemplazar URLs en atributos href y src, pero solo si no contienen ya el dominio de destino
                $pattern = '/(href|src)=(["\'])('. preg_quote($old, '/') .')([^"\']*?)(["\'])/i';
                
                // Usar callback para verificar si la URL ya contiene el dominio de destino
                $new_content = preg_replace_callback($pattern, function($matches) use ($new, $target_url) {
                    // Si la URL ya contiene el dominio de destino, no reemplazar para evitar duplicación
                    if (strpos($matches[0], $target_url) !== false) {
                        // Verificar si hay una duplicación de dominio
                        $double_domain = $target_url . '/' . $target_url;
                        if (strpos($matches[0], $double_domain) !== false) {
                            // Corregir la duplicación de dominio
                            return str_replace($double_domain, $target_url, $matches[0]);
                        }
                        return $matches[0];
                    }
                    
                    // Si es una imagen que ya ha sido importada (tiene el dominio de destino), no reemplazar
                    if ($matches[1] == 'src' && strpos($matches[3] . $matches[4], $target_url) !== false) {
                        return $matches[0];
                    }
                    
                    return $matches[1] . '=' . $matches[2] . $new . $matches[4] . $matches[5];
                }, $new_content);
                
                // Reemplazar URLs en shortcodes
                $pattern = '/(\[[^\]]*(?:url|link)=(["\']))'. preg_quote($old, '/') .'([^"\']*?)(["\'])/i';
                $replacement = '$1'. $new .'$3$4';
                $new_content = @preg_replace($pattern, $replacement, $new_content);
            }
            
            // Corregir cualquier URL duplicada que pueda haber quedado
            $double_domain_pattern = '/(href|src)=(["\'])'. preg_quote($target_url . '/' . $target_url, '/') .'([^"\']*?)(["\'])/i';
            $double_domain_replacement = '$1=$2'. $target_url .'$3$4';
            $new_content = @preg_replace($double_domain_pattern, $double_domain_replacement, $new_content);
            
            return $new_content;
        } catch (Exception $e) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR]: Error al reemplazar URLs: " . $e->getMessage());
            return $content; // Devolver el contenido original en caso de error
        }
    }
}
