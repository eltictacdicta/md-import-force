<?php
/**
 * Clase para manejar errores y excepciones del plugin
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

class MD_Import_Force_Error_Handler {
    
    /**
     * Tipos de errores
     */
    const ERROR_PERMISSION = 'permission';
    const ERROR_FILE = 'file';
    const ERROR_FORMAT = 'format';
    const ERROR_IMPORT = 'import';
    const ERROR_SYSTEM = 'system';
    
    /**
     * Maneja un error y devuelve una respuesta formateada
     * 
     * @param string $message Mensaje de error
     * @param string $type Tipo de error
     * @param Exception|null $exception Excepción original (opcional)
     * @return array Respuesta de error formateada
     */
    public static function handle_error($message, $type = self::ERROR_SYSTEM, $exception = null) {
        // Registrar el error en el log
        $log_message = "MD Import Force [ERROR {$type}]: {$message}";
        
        if ($exception) {
            $log_message .= " | Exception: " . $exception->getMessage();
            if (method_exists($exception, 'getTraceAsString')) {
                $log_message .= " | Trace: " . $exception->getTraceAsString();
            }
        }
        
        MD_Import_Force_Logger::log_message($log_message);
        
        // Devolver respuesta formateada
        return array(
            'success' => false,
            'error_type' => $type,
            'message' => $message,
            'data' => array(
                'message' => $message
            )
        );
    }
    
    /**
     * Verifica permisos de usuario
     * 
     * @param string $capability Capacidad requerida
     * @param string $action Acción que se está intentando realizar
     * @return bool|array True si tiene permisos, array con error si no
     */
    public static function check_permission($capability, $action) {
        if (!current_user_can($capability)) {
            return self::handle_error(
                sprintf(__('No tienes permisos para %s.', 'md-import-force'), $action),
                self::ERROR_PERMISSION
            );
        }
        
        return true;
    }
    
    /**
     * Verifica que un archivo existe
     * 
     * @param string $file_path Ruta al archivo
     * @param string $action Acción que se está intentando realizar
     * @return bool|array True si el archivo existe, array con error si no
     */
    public static function check_file_exists($file_path, $action) {
        if (empty($file_path) || !file_exists($file_path)) {
            return self::handle_error(
                sprintf(__('El archivo para %s no existe o no es accesible.', 'md-import-force'), $action),
                self::ERROR_FILE
            );
        }
        
        return true;
    }
    
    /**
     * Verifica que los datos tienen el formato correcto
     * 
     * @param array $data Datos a verificar
     * @param array $required_keys Claves requeridas
     * @param string $action Acción que se está intentando realizar
     * @return bool|array True si los datos son válidos, array con error si no
     */
    public static function check_data_format($data, $required_keys, $action) {
        foreach ($required_keys as $key) {
            if (!isset($data[$key])) {
                return self::handle_error(
                    sprintf(__('Formato de datos inválido para %s. Falta la clave "%s".', 'md-import-force'), $action, $key),
                    self::ERROR_FORMAT
                );
            }
        }
        
        return true;
    }
}
