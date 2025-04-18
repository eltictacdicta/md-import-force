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
}
