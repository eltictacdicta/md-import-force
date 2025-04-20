<?php
/**
 * Clase para rastrear elementos omitidos durante la importación
 */

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente.
}

class MD_Import_Force_Skipped_Items_Tracker {
    
    /**
     * Almacena los elementos omitidos durante la importación
     * @var array
     */
    private $skipped_items = [];
    
    /**
     * Instancia única (singleton)
     * @var MD_Import_Force_Skipped_Items_Tracker
     */
    private static $instance = null;
    
    /**
     * Obtener instancia única
     * @return MD_Import_Force_Skipped_Items_Tracker
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor privado (singleton)
     */
    private function __construct() {
        $this->skipped_items = [];
    }
    
    /**
     * Añade un elemento omitido al registro
     * 
     * @param int $id ID del elemento
     * @param string $title Título del elemento
     * @param string $type Tipo de elemento (post, page, etc.)
     * @param string $reason Razón por la que se omitió
     */
    public function add_skipped_item($id, $title, $type, $reason) {
        $this->skipped_items[] = [
            'id' => $id,
            'title' => $title,
            'type' => $type,
            'reason' => $reason
        ];
        
        // Registrar en el log para depuración
        if (class_exists('MD_Import_Force_Logger')) {
            MD_Import_Force_Logger::log_message("MD Import Force [SKIP TRACKED] {$type} ID {$id} ('{$title}'): {$reason}");
        }
    }
    
    /**
     * Obtiene todos los elementos omitidos
     * 
     * @return array Lista de elementos omitidos
     */
    public function get_skipped_items() {
        return $this->skipped_items;
    }
    
    /**
     * Obtiene el número de elementos omitidos
     * 
     * @return int Número de elementos omitidos
     */
    public function get_skipped_count() {
        return count($this->skipped_items);
    }
    
    /**
     * Limpia la lista de elementos omitidos
     */
    public function clear() {
        $this->skipped_items = [];
    }
}
