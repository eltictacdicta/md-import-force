<?php
/**
 * Clase para manejar la lógica de importación
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

// Incluir el manejador de URLs si no está incluido
if (!class_exists('MD_Import_Force_URL_Handler')) {
    require_once dirname(__FILE__) . '/class-md-import-force-url-handler.php';
}

class MD_Import_Force_Import_Manager {

    /**
     * Mapeo de IDs entre el sitio de origen y el sitio de destino
     */
    private $id_mapping = [];

    /**
     * Información del sitio de origen
     */
    private $source_site_info = [];

    /**
     * Instancia del importador de posts
     */
    private $post_importer;

    /**
     * Instancia del importador de taxonomías
     */
    private $taxonomy_importer;

    /**
     * Instancia del rastreador de progreso
     */
    private $progress_tracker;

    /**
     * Constructor
     */
    public function __construct() {
        $this->id_mapping = [];
        $this->source_site_info = [];

        // Inicializar el rastreador de progreso
        $this->progress_tracker = new MD_Import_Force_Progress_Tracker();

        // Inicializar el importador de taxonomías
        $this->taxonomy_importer = new MD_Import_Force_Taxonomy_Importer($this->id_mapping);

        // Inicializar el manejador de medios
        $media_handler = new MD_Import_Force_Media_Handler($this->source_site_info);

        // Inicializar el importador de comentarios
        $comment_importer = new MD_Import_Force_Comment_Importer();

        // Inicializar el importador de posts
        $this->post_importer = new MD_Import_Force_Post_Importer(
            $this->id_mapping,
            $this->source_site_info,
            $this->taxonomy_importer,
            $media_handler,
            $comment_importer,
            $this->progress_tracker
        );
    }

    /**
     * Realiza la importación de datos
     *
     * @param array $import_data Datos a importar
     * @return array Resultado de la importación
     */
    public function import_data($import_data) {
        try {
            // Verificar permisos
            if (!current_user_can('import')) {
                throw new Exception(__('No tienes permisos para importar.', 'md-import-force'));
            }

            $total_imported = 0;
            $total_new = 0;
            $total_updated = 0;
            $total_skipped = 0;
            $messages = [];

            // Si es un array (viene de un ZIP con múltiples JSONs)
            if (is_array($import_data) && isset($import_data[0])) {
                MD_Import_Force_Logger::log_message("MD Import Force: Procesando múltiples archivos JSON desde ZIP.");
                foreach ($import_data as $single_import_data) {
                    if (!isset($single_import_data['site_info']) || !isset($single_import_data['posts'])) {
                        $messages[] = __('Saltando un archivo JSON en el ZIP: Formato inválido.', 'md-import-force');
                        $total_skipped += count($single_import_data['posts'] ?? []); // Intentar contar posts si existen para el skipped count
                        MD_Import_Force_Logger::log_message("MD Import Force [WARN]: Saltando un archivo JSON en el ZIP: Formato inválido.");
                        continue;
                    }

                    $this->source_site_info = $single_import_data['site_info'];
                    MD_Import_Force_Logger::log_message("MD Import Force: Info sitio origen (desde ZIP): " . ($this->source_site_info['site_url'] ?? 'N/A'));

                    // Detectar automáticamente la URL del sitio de origen si no está disponible
                    if (empty($this->source_site_info['site_url'])) {
                        $detected_url = MD_Import_Force_URL_Handler::detect_source_url($this->source_site_info, $single_import_data['posts'] ?? []);
                        if (!empty($detected_url)) {
                            $this->source_site_info['site_url'] = $detected_url;
                            MD_Import_Force_Logger::log_message("MD Import Force: URL de origen detectada automáticamente: {$detected_url}");
                        }
                    }

                    // Importar términos
                    $this->import_taxonomy_terms($single_import_data);

                    // Importar posts/páginas
                    $result = $this->import_post_items($single_import_data['posts'] ?? []);

                    $total_imported += ($result['new_count'] ?? 0) + ($result['updated_count'] ?? 0);
                    $total_new += $result['new_count'] ?? 0;
                    $total_updated += $result['updated_count'] ?? 0;
                    $total_skipped += $result['skipped_count'] ?? 0;

                    if (!empty($result['message'])) {
                        $messages[] = $result['message'];
                    }



                    if (function_exists('wp_cache_flush')) {
                        wp_cache_flush();
                    }

                    MD_Import_Force_Logger::log_message("MD Import Force: Procesamiento de un archivo JSON en ZIP finalizado.");
                }

                $final_message = sprintf(
                    __('Importación de ZIP finalizada. Total: %d nuevos, %d actualizados, %d omitidos.', 'md-import-force'),
                    $total_new,
                    $total_updated,
                    $total_skipped
                );

                $messages[] = $final_message;
                MD_Import_Force_Logger::log_message("MD Import Force: " . $final_message);

            } else { // Si es un solo conjunto de datos (viene de un JSON individual)
                MD_Import_Force_Logger::log_message("MD Import Force: Procesando archivo JSON individual.");

                if (!isset($import_data['site_info']) || !isset($import_data['posts'])) {
                    throw new Exception(__('Formato JSON inválido.', 'md-import-force'));
                }

                $this->source_site_info = $import_data['site_info'];
                MD_Import_Force_Logger::log_message("MD Import Force: Info sitio origen: " . ($this->source_site_info['site_url'] ?? 'N/A'));

                $this->id_mapping = array();
                MD_Import_Force_Logger::log_message("MD Import Force: Mapeo IDs inicializado.");

                // Detectar automáticamente la URL del sitio de origen si no está disponible
                if (empty($this->source_site_info['site_url'])) {
                    $detected_url = MD_Import_Force_URL_Handler::detect_source_url($this->source_site_info, $import_data['posts'] ?? []);
                    if (!empty($detected_url)) {
                        $this->source_site_info['site_url'] = $detected_url;
                        MD_Import_Force_Logger::log_message("MD Import Force: URL de origen detectada automáticamente: {$detected_url}");
                    }
                }

                // Importar términos
                $this->import_taxonomy_terms($import_data);

                // Importar posts/páginas
                $result = $this->import_post_items($import_data['posts'] ?? []);

                $total_imported = ($result['new_count'] ?? 0) + ($result['updated_count'] ?? 0);
                $total_new = $result['new_count'] ?? 0;
                $total_updated = $result['updated_count'] ?? 0;
                $total_skipped = $result['skipped_count'] ?? 0;


                $final_message = __('La importación se ha realizado con exito', 'md-import-force');
                $messages[] = $final_message;

                if (function_exists('wp_cache_flush')) {
                    wp_cache_flush();
                }

                MD_Import_Force_Logger::log_message("MD Import Force: Importación finalizada.");
            }

            // Marcar la importación como completada
            $this->progress_tracker->mark_as_completed();

            return array(
                'success' => true,
                'imported_count' => $total_imported,
                'new_count' => $total_new,
                'updated_count' => $total_updated,
                'skipped_count' => $total_skipped,
                'message' => implode("\n", $messages)
            );

        } catch (Exception $e) {
            MD_Import_Force_Logger::log_message("MD Import Force [ERROR FATAL]: " . $e->getMessage());
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    /**
     * Importa términos de taxonomías desde los datos de importación
     *
     * @param array $import_data Datos de importación
     */
    private function import_taxonomy_terms($import_data) {
        // Importar categorías
        if (!empty($import_data['categories'])) {
            $this->import_terms($import_data['categories'], 'category');
        }

        // Importar etiquetas
        if (!empty($import_data['tags'])) {
            $this->import_terms($import_data['tags'], 'post_tag');
        }

        // Aquí se podrían importar otras taxonomías si estuvieran en $import_data['taxonomies']
    }

    /**
     * Importa términos de una taxonomía específica
     *
     * @param array $terms_data Datos de los términos
     * @param string $taxonomy Nombre de la taxonomía
     * @return array Mapeo de IDs actualizado
     */
    private function import_terms($terms_data, $taxonomy) {
        $this->taxonomy_importer->set_id_mapping($this->id_mapping);
        $this->id_mapping = $this->taxonomy_importer->import_terms($terms_data, $taxonomy);
        return $this->id_mapping;
    }

    /**
     * Importa posts/páginas
     *
     * @param array $items_data Datos de los posts/páginas
     * @return array Resultado de la importación
     */
    private function import_post_items($items_data) {
        $this->post_importer->set_id_mapping($this->id_mapping);
        $this->post_importer->set_source_site_info($this->source_site_info);
        $result = $this->post_importer->import_posts($items_data);
        $this->id_mapping = $this->post_importer->get_id_mapping();
        return $result;
    }

    /**
     * Obtiene el rastreador de progreso
     *
     * @return MD_Import_Force_Progress_Tracker Instancia del rastreador de progreso
     */
    public function get_progress_tracker() {
        return $this->progress_tracker;
    }
}
