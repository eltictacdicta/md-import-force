<?php
/**
 * Clase para procesar archivos de importación (JSON/ZIP)
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

class MD_Import_Force_File_Processor {

    /**
     * Lee el archivo de importación (JSON o ZIP).
     * Si es ZIP, devuelve un array de datos de importación (uno por JSON encontrado).
     * Si es JSON, devuelve un solo conjunto de datos de importación.
     * 
     * @param string $file_path Ruta al archivo de importación
     * @return array Datos de importación
     * @throws Exception Si hay errores en el proceso
     */
    public function read_file($file_path) {
        return $this->read_import_file($file_path);
    }

    /**
     * Almacena los datos de importación en un archivo temporal.
     * 
     * @param array $import_data Datos de importación
     * @param string $import_id ID de la importación
     * @return string Identificador para recuperar los datos
     */
    public function store_import_data($import_data, $import_id) {
        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/md-import-force/temp/';
        
        // Crear directorio si no existe
        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
        }
        
        // Generar un identificador único basado en el import_id
        $data_id = md5($import_id . time());
        $file_path = $target_dir . $data_id . '.json';
        
        // Guardar los datos en un archivo temporal
        $json_data = json_encode($import_data);
        if ($json_data === false) {
            throw new Exception(__('Error al serializar los datos de importación.', 'md-import-force'));
        }
        
        $result = file_put_contents($file_path, $json_data);
        if ($result === false) {
            throw new Exception(__('Error al guardar los datos temporales.', 'md-import-force'));
        }
        
        MD_Import_Force_Logger::log_message("MD Import Force [STORAGE]: Datos de importación guardados temporalmente con ID: {$data_id}");
        
        return $data_id;
    }
    
    /**
     * Recupera los datos de importación a partir de su identificador.
     * 
     * @param string $data_id Identificador de los datos
     * @return array Datos de importación
     * @throws Exception Si no se pueden recuperar los datos
     */
    public function retrieve_import_data($data_id) {
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/md-import-force/temp/' . $data_id . '.json';
        
        if (!file_exists($file_path)) {
            throw new Exception(__('No se encontraron los datos temporales de importación.', 'md-import-force'));
        }
        
        $json_data = file_get_contents($file_path);
        if ($json_data === false) {
            throw new Exception(__('Error al leer los datos temporales.', 'md-import-force'));
        }
        
        $import_data = json_decode($json_data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception(__('Error al decodificar los datos temporales:', 'md-import-force') . json_last_error_msg());
        }
        
        return $import_data;
    }
    
    /**
     * Elimina los datos temporales de importación.
     * 
     * @param string $data_id Identificador de los datos
     * @return bool True si se eliminó correctamente, False en caso contrario
     */
    public function delete_import_data($data_id) {
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/md-import-force/temp/' . $data_id . '.json';
        
        if (!file_exists($file_path)) {
            return false;
        }
        
        $result = @unlink($file_path);
        if ($result) {
            MD_Import_Force_Logger::log_message("MD Import Force [STORAGE]: Datos temporales eliminados con ID: {$data_id}");
        } else {
            MD_Import_Force_Logger::log_message("MD Import Force [STORAGE ERROR]: No se pudieron eliminar los datos temporales con ID: {$data_id}");
        }
        
        return $result;
    }

    /**
     * Lee el archivo de importación (JSON o ZIP).
     * Si es ZIP, devuelve un array de datos de importación (uno por JSON encontrado).
     * Si es JSON, devuelve un solo conjunto de datos de importación.
     */
    public function read_import_file($file_path) {
        $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        $import_data_array = [];

        if ($ext === 'zip') {
            if (!class_exists('ZipArchive')) throw new Exception(__('ZipArchive no habilitada.', 'md-import-force'));
            $zip = new ZipArchive;
            $res = $zip->open($file_path);

            if ($res === TRUE) {
                // Primero, buscar manifest.json o export_report.json para obtener site_info
                $site_info = null;
                $posts_files = [];
                $manifest_content = null;
                $report_content = null;

                // Buscar archivos importantes primero
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    // Ignorar directorios y archivos dentro de __MACOSX
                    if (substr($name, -1) === '/' || strpos($name, '__MACOSX/') === 0) continue;

                    $basename = basename($name);
                    if ($basename === 'manifest.json') {
                        $manifest_content = $zip->getFromIndex($i);
                    } elseif ($basename === 'export_report.json') {
                        $report_content = $zip->getFromIndex($i);
                    } elseif (preg_match('/^posts-\d+\.json$/', $basename)) {
                        $posts_files[] = ['index' => $i, 'name' => $name];
                    } elseif (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'json') {
                        // Otros archivos JSON que podrían contener datos completos
                        $json_content = $zip->getFromIndex($i);
                        if (!empty($json_content)) {
                            $data = json_decode($json_content, true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                // Si tiene site_info y posts, es un archivo válido completo
                                if (isset($data['site_info']) && isset($data['posts'])) {
                                    $import_data_array[] = $data;
                                }
                            }
                        }
                    }
                }

                // Si no encontramos ningún archivo JSON completo, pero tenemos archivos de posts y manifest/report
                if (empty($import_data_array) && !empty($posts_files)) {
                    // Intentar construir un conjunto de datos combinado
                    $combined_data = [
                        'site_info' => null,
                        'posts' => [],
                        'categories' => [],
                        'tags' => []
                    ];

                    // Obtener site_info del manifest o report
                    if (!empty($manifest_content)) {
                        $manifest = json_decode($manifest_content, true);
                        if (json_last_error() === JSON_ERROR_NONE && isset($manifest['site_info'])) {
                            $combined_data['site_info'] = $manifest['site_info'];
                        }
                    } elseif (!empty($report_content)) {
                        $report = json_decode($report_content, true);
                        if (json_last_error() === JSON_ERROR_NONE && isset($report['site_info'])) {
                            $combined_data['site_info'] = $report['site_info'];
                        }
                    }

                    // Si tenemos site_info, procesar los archivos de posts
                    if (!empty($combined_data['site_info'])) {
                        // Procesar cada archivo de posts
                        foreach ($posts_files as $file_info) {
                            $json_content = $zip->getFromIndex($file_info['index']);
                            if (!empty($json_content)) {
                                $data = json_decode($json_content, true);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                    // Añadir posts
                                    if (isset($data['posts']) && is_array($data['posts'])) {
                                        $combined_data['posts'] = array_merge($combined_data['posts'], $data['posts']);
                                    }

                                    // Añadir categorías si no se han añadido antes
                                    if (isset($data['categories']) && is_array($data['categories']) && empty($combined_data['categories'])) {
                                        $combined_data['categories'] = $data['categories'];
                                    }

                                    // Añadir tags si no se han añadido antes
                                    if (isset($data['tags']) && is_array($data['tags']) && empty($combined_data['tags'])) {
                                        $combined_data['tags'] = $data['tags'];
                                    }
                                } else {
                                    MD_Import_Force_Logger::log_message("MD Import Force [WARN]: Error JSON en archivo '{$file_info['name']}' dentro del ZIP: " . json_last_error_msg());
                                }
                            }
                        }

                        // Si tenemos posts, añadir el conjunto combinado
                        if (!empty($combined_data['posts'])) {
                            $import_data_array[] = $combined_data;
                            MD_Import_Force_Logger::log_message("MD Import Force [INFO]: Datos combinados con éxito. Total posts: " . count($combined_data['posts']));
                        }
                    }
                }

                $zip->close();

                if (empty($import_data_array)) {
                    throw new Exception(__('No se encontraron datos válidos en el archivo ZIP.', 'md-import-force'));
                }

                return $import_data_array;
            } else {
                throw new Exception(__('Error al abrir el archivo ZIP.', 'md-import-force'));
            }
        } elseif ($ext === 'json') {
            $json_content = file_get_contents($file_path);
            if (empty($json_content)) throw new Exception(__('Contenido JSON vacío.', 'md-import-force'));
            $data = json_decode($json_content, true);
            if (json_last_error() !== JSON_ERROR_NONE) throw new Exception(__('Error JSON: ', 'md-import-force') . json_last_error_msg());
            return $data;
        } else {
            throw new Exception(__('Formato no soportado (.json o .zip).', 'md-import-force'));
        }
    }

    /**
     * Limpia archivos temporales antiguos.
     * 
     * @param int $hours_old Eliminar archivos más antiguos que estas horas (por defecto 24 horas)
     * @return array Información sobre la limpieza
     */
    public function cleanup_old_temp_files($hours_old = 24) {
        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/md-import-force/temp/';
        
        $result = [
            'success' => true,
            'deleted' => 0,
            'failed' => 0,
            'skipped' => 0
        ];
        
        if (!file_exists($target_dir) || !is_dir($target_dir)) {
            MD_Import_Force_Logger::log_message("MD Import Force [STORAGE CLEANUP]: El directorio temporal no existe.");
            return $result;
        }
        
        $time_threshold = time() - ($hours_old * 3600); // Convertir horas a segundos
        $files = glob($target_dir . '*.json');
        
        foreach ($files as $file) {
            // Verificar la antigüedad del archivo
            $file_time = filemtime($file);
            if ($file_time > $time_threshold) {
                $result['skipped']++;
                continue;
            }
            
            // Intentar eliminar el archivo
            if (@unlink($file)) {
                MD_Import_Force_Logger::log_message("MD Import Force [STORAGE CLEANUP]: Archivo temporal antiguo eliminado: " . basename($file));
                $result['deleted']++;
            } else {
                MD_Import_Force_Logger::log_message("MD Import Force [STORAGE CLEANUP ERROR]: No se pudo eliminar archivo temporal antiguo: " . basename($file));
                $result['failed']++;
            }
        }
        
        MD_Import_Force_Logger::log_message("MD Import Force [STORAGE CLEANUP]: Limpieza de archivos temporales completada. Eliminados: {$result['deleted']}, Fallidos: {$result['failed']}, Omitidos: {$result['skipped']}");
        return $result;
    }
}
