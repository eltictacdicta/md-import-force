/**
 * MD Import Force - Módulo Log
 * 
 * Contiene funciones para el manejo de logs.
 */

// Asegurar que el namespace principal existe
var MDImportForce = MDImportForce || {};

// Módulo Log
MDImportForce.Log = (function() {
    // Variables privadas
    var $ = null;

    /**
     * Actualiza el contenido del log en la interfaz
     * @param {string} content - Contenido del log
     */
    function updateLogContent(content) {
        $('#md-import-force-log-content').text(content);
    }

    /**
     * Lee el log de errores
     */
    function readErrorLog() {
        updateLogContent('Cargando log...'); // Mensaje de carga

        MDImportForce.Ajax.ajaxRequest({
            url: md_import_force.ajax_url,
            type: 'POST',
            data: {
                action: 'md_import_force_read_log',
                nonce: md_import_force.nonce
            }
        })
        .then(function(response) {
            if (response.success) {
                updateLogContent(response.data.log_content || 'El log está vacío.');
            } else {
                updateLogContent('Error al cargar el log: ' + (response.data.message || 'Error desconocido'));
            }
        })
        .catch(function(error) {
            updateLogContent('Error en la solicitud AJAX para leer el log: ' + error.statusText);
        });
    }

    /**
     * Limpia el log de errores
     */
    function clearErrorLog() {
        updateLogContent('Limpiando log...'); // Mensaje de limpieza

        MDImportForce.Ajax.ajaxRequest({
            url: md_import_force.ajax_url,
            type: 'POST',
            data: {
                action: 'md_import_force_clear_log',
                nonce: md_import_force.nonce
            }
        })
        .then(function(response) {
            if (response.success) {
                updateLogContent('Log limpiado con éxito.');
            } else {
                updateLogContent('Error al limpiar el log: ' + (response.data.message || 'Error desconocido'));
            }
        })
        .catch(function(error) {
            updateLogContent('Error en la solicitud AJAX para limpiar el log: ' + error.statusText);
        });
    }

    /**
     * Configura los eventos relacionados con el log
     */
    function setupLogEvents() {
        // Manejo del botón Actualizar Log
        $('#md-import-force-refresh-log').on('click', function() {
            readErrorLog();
        });

        // Manejo del botón Limpiar Log
        $('#md-import-force-clear-log').on('click', function() {
            if (confirm('¿Estás seguro de que quieres limpiar el log de errores? Esta acción no se puede deshacer.')) {
                clearErrorLog();
            }
        });
    }

    // Métodos públicos
    return {
        /**
         * Inicializa el módulo Log
         * @param {Object} jQuery - Objeto jQuery
         */
        init: function(jQuery) {
            $ = jQuery;
            setupLogEvents();
            console.log('MD Import Force Log inicializado');
        },

        // Exponer métodos para uso en otros módulos
        readErrorLog: readErrorLog,
        clearErrorLog: clearErrorLog
    };
})();
