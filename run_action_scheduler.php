<?php
/**
 * Script para ejecutar manualmente Action Scheduler
 */

// Cargar WordPress
require_once(dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-config.php');

echo "Ejecutando Action Scheduler manualmente...\n";

// Verificar que Action Scheduler esté disponible
if (!class_exists('ActionScheduler')) {
    die('ActionScheduler no está disponible');
}

// Ejecutar el runner de Action Scheduler
if (class_exists('ActionScheduler_QueueRunner')) {
    $runner = new ActionScheduler_QueueRunner();
    echo "Iniciando procesamiento de cola...\n";
    
    // Procesar hasta 5 acciones
    $processed = $runner->run();
    echo "Acciones procesadas: $processed\n";
} else {
    echo "ActionScheduler_QueueRunner no está disponible\n";
}

// Verificar el estado de nuestra tarea específica
global $wpdb;
$task = $wpdb->get_row("SELECT hook, status, last_attempt_gmt, attempts FROM wp_actionscheduler_actions WHERE action_id = 3520", ARRAY_A);

if ($task) {
    echo "Estado de la tarea 3520:\n";
    print_r($task);
} else {
    echo "No se encontró la tarea 3520\n";
}

// Mostrar logs recientes
$logs = $wpdb->get_results("
    SELECT al.message, al.log_date_gmt 
    FROM wp_actionscheduler_logs al 
    JOIN wp_actionscheduler_actions aa ON al.action_id = aa.action_id 
    WHERE aa.action_id = 3520 
    ORDER BY al.log_date_gmt DESC 
    LIMIT 5
", ARRAY_A);

if ($logs) {
    echo "Logs de la tarea:\n";
    foreach ($logs as $log) {
        echo "- [{$log['log_date_gmt']}] {$log['message']}\n";
    }
} else {
    echo "No hay logs para la tarea 3520\n";
}
?> 