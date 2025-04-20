/**
 * MD Import Force - Módulo Cleanup
 * 
 * Contiene funciones para la limpieza de archivos.
 */

// Asegurar que el namespace principal existe
var MDImportForce = MDImportForce || {};

// Módulo Cleanup
MDImportForce.Cleanup = (function() {
    // Variables privadas
    var $ = null;

    /**
     * Actualiza el resultado de la limpieza en la interfaz
     * @param {string} message - Mensaje a mostrar
     * @param {string} type - Tipo de mensaje (success, error, info)
     */
    function updateCleanupResult(message, type) {
        MDImportForce.UI.showMessage(message, type, '#md-import-force-cleanup-result');
    }

    /**
     * Realiza la limpieza de archivos
     * @param {number} hours - Horas de antigüedad para los archivos a limpiar
     */
    function cleanupFiles(hours) {
        updateCleanupResult('Limpiando archivos antiguos...', 'info');

        MDImportForce.Ajax.ajaxRequest({
            url: md_import_force.ajax_url,
            type: 'POST',
            data: {
                action: 'md_import_force_cleanup_all',
                nonce: md_import_force.nonce,
                hours: hours
            }
        })
        .then(function(response) {
            if (response.success) {
                updateCleanupResult(response.data.message, 'success');
            } else {
                updateCleanupResult('Error: ' + (response.data.message || 'Error desconocido'), 'error');
            }
        })
        .catch(function(error) {
            updateCleanupResult('Error en la solicitud AJAX: ' + error.statusText, 'error');
        });
    }

    /**
     * Configura los eventos de limpieza
     */
    function setupCleanupEvents() {
        // Manejo del botón de limpieza de archivos
        $('#md-import-force-cleanup-all').on('click', function() {
            if (confirm('¿Estás seguro de que quieres eliminar los archivos de importación antiguos? Esta acción no se puede deshacer.')) {
                var hours = $('#cleanup_hours').val();
                cleanupFiles(hours);
            }
        });
    }

    // Métodos públicos
    return {
        /**
         * Inicializa el módulo Cleanup
         * @param {Object} jQuery - Objeto jQuery
         */
        init: function(jQuery) {
            $ = jQuery;
            setupCleanupEvents();
            console.log('MD Import Force Cleanup inicializado');
        }
    };
})();
