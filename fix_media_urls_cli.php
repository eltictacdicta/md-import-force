<?php
/**
 * Script CLI para corregir URLs de medios incorrectas en el contenido de posts
 * 
 * Uso:
 * ddev exec php wp-content/plugins/md-import-force/fix_media_urls_cli.php --dry-run
 * ddev exec php wp-content/plugins/md-import-force/fix_media_urls_cli.php --execute
 */

// Cargar WordPress
require_once dirname(__FILE__) . '/../../../wp-load.php';

if (!defined('ABSPATH')) {
    die('No se puede acceder directamente a este archivo.');
}

// Incluir la clase del fixer
require_once dirname(__FILE__) . '/fix_media_urls.php';

// Función para mostrar ayuda
function show_help() {
    echo "Corrector de URLs de Medios - Línea de Comandos\n";
    echo "==============================================\n\n";
    echo "Uso:\n";
    echo "  php fix_media_urls_cli.php [opciones]\n\n";
    echo "Opciones:\n";
    echo "  --dry-run    Ejecutar simulación (no hace cambios reales)\n";
    echo "  --execute    Ejecutar corrección real\n";
    echo "  --help       Mostrar esta ayuda\n\n";
    echo "Ejemplos:\n";
    echo "  ddev exec php wp-content/plugins/md-import-force/fix_media_urls_cli.php --dry-run\n";
    echo "  ddev exec php wp-content/plugins/md-import-force/fix_media_urls_cli.php --execute\n\n";
}

// Procesar argumentos de línea de comandos
$options = getopt('', ['dry-run', 'execute', 'help']);

if (isset($options['help'])) {
    show_help();
    exit(0);
}

$dry_run = true;
if (isset($options['execute'])) {
    $dry_run = false;
} elseif (isset($options['dry-run'])) {
    $dry_run = true;
} else {
    echo "Error: Debes especificar --dry-run o --execute\n\n";
    show_help();
    exit(1);
}

// Confirmar ejecución real
if (!$dry_run) {
    echo "⚠️  ADVERTENCIA: Vas a ejecutar la corrección REAL de URLs de medios.\n";
    echo "   Esto modificará el contenido de los posts en la base de datos.\n";
    echo "   ¿Estás seguro? (escribe 'SI' para continuar): ";
    
    $handle = fopen("php://stdin", "r");
    $confirmation = trim(fgets($handle));
    fclose($handle);
    
    if ($confirmation !== 'SI') {
        echo "Operación cancelada.\n";
        exit(0);
    }
}

echo "\n";
echo "=== Iniciando Corrector de URLs de Medios ===\n";
echo "Modo: " . ($dry_run ? "SIMULACIÓN" : "EJECUCIÓN REAL") . "\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n";
echo "==========================================\n\n";

// Ejecutar el corrector
$fixer = new MD_Import_Force_Media_URL_Fixer($dry_run);
$result = $fixer->run();

echo "\n";
echo "=== RESULTADO FINAL ===\n";
echo "Posts procesados: " . $result['processed_posts'] . "\n";
echo "URLs corregidas: " . $result['fixed_urls'] . "\n";
echo "========================\n";

// Guardar log en archivo
$log_content = implode("\n", $result['log_messages']);
$log_file = dirname(__FILE__) . '/logs/media_url_fix_' . date('Y-m-d_H-i-s') . '.log';
file_put_contents($log_file, $log_content);
echo "Log guardado en: " . $log_file . "\n";

exit(0);
?> 