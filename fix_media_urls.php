<?php
/**
 * Script para corregir URLs de medios incorrectas en el contenido de posts
 * 
 * Este script busca posts que tienen URLs de imágenes que no coinciden con las URLs reales
 * de los medios importados y las corrige.
 */

// Cargar WordPress
require_once dirname(__FILE__) . '/../../../wp-load.php';

if (!defined('ABSPATH')) {
    die('No se puede acceder directamente a este archivo.');
}

// Verificar permisos
if (!current_user_can('manage_options')) {
    die('No tienes permisos para ejecutar este script.');
}

class MD_Import_Force_Media_URL_Fixer {
    
    private $dry_run = true;
    private $processed_posts = 0;
    private $fixed_urls = 0;
    private $log_messages = [];
    
    public function __construct($dry_run = true) {
        $this->dry_run = $dry_run;
    }
    
    /**
     * Ejecuta la corrección de URLs
     */
    public function run() {
        $this->log("=== Iniciando corrección de URLs de medios ===");
        $this->log("Modo: " . ($this->dry_run ? "SIMULACIÓN (no se harán cambios)" : "EJECUCIÓN REAL"));
        
        // Obtener todos los posts que contienen imágenes
        $posts = $this->get_posts_with_images();
        $this->log("Encontrados " . count($posts) . " posts con imágenes para revisar");
        
        foreach ($posts as $post) {
            $this->process_post($post);
        }
        
        $this->log("=== Resumen ===");
        $this->log("Posts procesados: " . $this->processed_posts);
        $this->log("URLs corregidas: " . $this->fixed_urls);
        
        return [
            'processed_posts' => $this->processed_posts,
            'fixed_urls' => $this->fixed_urls,
            'log_messages' => $this->log_messages
        ];
    }
    
    /**
     * Obtiene posts que contienen imágenes
     */
    private function get_posts_with_images() {
        global $wpdb;
        
        $query = "
            SELECT ID, post_title, post_content 
            FROM {$wpdb->posts} 
            WHERE post_status = 'publish' 
            AND post_type = 'post' 
            AND post_content LIKE '%<img%'
            ORDER BY ID DESC
        ";
        
        return $wpdb->get_results($query);
    }
    
    /**
     * Procesa un post individual
     */
    private function process_post($post) {
        $this->processed_posts++;
        $content = $post->post_content;
        $original_content = $content;
        
        // Extraer URLs de imágenes del contenido
        $image_urls = $this->extract_image_urls_from_content($content);
        
        if (empty($image_urls)) {
            return;
        }
        
        $this->log("Procesando post ID {$post->ID}: '{$post->post_title}' - " . count($image_urls) . " imágenes encontradas");
        
        $replacements = [];
        
        foreach ($image_urls as $original_url) {
            // Verificar si la URL existe como attachment
            $attachment_id = attachment_url_to_postid($original_url);
            
            if ($attachment_id) {
                // La URL ya es correcta
                continue;
            }
            
            // Buscar el attachment correcto
            $correct_attachment_id = $this->find_correct_attachment($original_url);
            
            if ($correct_attachment_id) {
                $correct_url = wp_get_attachment_url($correct_attachment_id);
                if ($correct_url && $correct_url !== $original_url) {
                    $replacements[$original_url] = $correct_url;
                    $this->log("  - Encontrado reemplazo: {$original_url} -> {$correct_url}");
                }
            } else {
                $this->log("  - No se encontró attachment para: {$original_url}");
            }
        }
        
        // Aplicar reemplazos
        if (!empty($replacements)) {
            // Ordenar por longitud descendente para evitar reemplazos parciales
            uksort($replacements, function($a, $b) { 
                return strlen($b) - strlen($a); 
            });
            
            foreach ($replacements as $old_url => $new_url) {
                $content = str_replace($old_url, $new_url, $content);
                $this->fixed_urls++;
            }
            
            // Actualizar el post si no es simulación
            if (!$this->dry_run) {
                $result = wp_update_post([
                    'ID' => $post->ID,
                    'post_content' => $content
                ], true);
                
                if (is_wp_error($result)) {
                    $this->log("  - ERROR al actualizar post: " . $result->get_error_message());
                } else {
                    $this->log("  - Post actualizado exitosamente");
                }
            } else {
                $this->log("  - SIMULACIÓN: Se habrían aplicado " . count($replacements) . " reemplazos");
            }
        }
    }
    
    /**
     * Busca el attachment correcto para una URL
     */
    private function find_correct_attachment($original_url) {
        global $wpdb;
        
        // 1. Buscar por URL exacta en _source_url
        $attachment_id = $wpdb->get_var($wpdb->prepare("
            SELECT post_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_source_url' 
            AND meta_value = %s
        ", $original_url));
        
        if ($attachment_id) {
            return $attachment_id;
        }
        
        // 2. Buscar por nombre de archivo
        $filename = basename(parse_url($original_url, PHP_URL_PATH));
        if (!empty($filename)) {
            $attachment_id = $wpdb->get_var($wpdb->prepare("
                SELECT post_id 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = '_source_url' 
                AND meta_value LIKE %s
            ", '%' . $filename));
            
            if ($attachment_id) {
                return $attachment_id;
            }
            
            // 3. Buscar por título del post (nombre sin extensión)
            $filename_without_ext = pathinfo($filename, PATHINFO_FILENAME);
            $attachment_id = $wpdb->get_var($wpdb->prepare("
                SELECT ID 
                FROM {$wpdb->posts} 
                WHERE post_type = 'attachment' 
                AND post_title = %s
            ", $filename_without_ext));
            
            if ($attachment_id) {
                return $attachment_id;
            }
        }
        
        return false;
    }
    
    /**
     * Extrae URLs de imágenes del contenido
     */
    private function extract_image_urls_from_content($content) {
        $image_urls = [];
        
        if (class_exists('DOMDocument')) {
            $doc = new DOMDocument();
            @$doc->loadHTML('<?xml encoding="utf-8" ?><html><body>' . $content . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
            
            $img_tags = $doc->getElementsByTagName('img');
            foreach ($img_tags as $img) {
                $src = $img->getAttribute('src');
                if (!empty($src)) {
                    $image_urls[] = trim($src);
                }
            }
        } else {
            preg_match_all('/<img[^>]+src\s*=\s*([\'"])(.*?)\1[^>]*>/i', $content, $matches);
            if (!empty($matches[2])) {
                foreach ($matches[2] as $src) {
                    $image_urls[] = trim($src);
                }
            }
        }
        
        return array_unique($image_urls);
    }
    
    /**
     * Registra un mensaje de log
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] {$message}";
        $this->log_messages[] = $log_message;
        echo $log_message . "\n";
    }
}

// Ejecutar el script
if (isset($_GET['run'])) {
    $dry_run = !isset($_GET['execute']); // Por defecto es simulación
    $fixer = new MD_Import_Force_Media_URL_Fixer($dry_run);
    $result = $fixer->run();
    
    echo "\n\n=== RESULTADO FINAL ===\n";
    echo "Posts procesados: " . $result['processed_posts'] . "\n";
    echo "URLs corregidas: " . $result['fixed_urls'] . "\n";
    
} else {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Corrector de URLs de Medios</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; }
            .button { padding: 10px 20px; margin: 10px; text-decoration: none; background: #0073aa; color: white; border-radius: 3px; }
            .button.danger { background: #dc3232; }
            .warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 20px 0; border-radius: 4px; }
        </style>
    </head>
    <body>
        <h1>Corrector de URLs de Medios</h1>
        
        <div class="warning">
            <strong>⚠️ Importante:</strong> Este script corregirá las URLs de imágenes en el contenido de los posts.
            Se recomienda hacer una copia de seguridad de la base de datos antes de ejecutar.
        </div>
        
        <p>Este script busca y corrige URLs de imágenes incorrectas en el contenido de los posts.</p>
        
        <a href="?run=1" class="button">🔍 Ejecutar Simulación (sin cambios)</a>
        <a href="?run=1&execute=1" class="button danger">⚡ Ejecutar Corrección Real</a>
        
        <h3>¿Qué hace este script?</h3>
        <ul>
            <li>Busca posts que contienen imágenes</li>
            <li>Identifica URLs de imágenes que no existen</li>
            <li>Busca el attachment correcto por nombre de archivo</li>
            <li>Reemplaza las URLs incorrectas con las correctas</li>
        </ul>
    </body>
    </html>
    <?php
}
?> 