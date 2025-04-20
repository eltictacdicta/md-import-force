/**
 * MD Import Force - Módulo UI
 * 
 * Contiene funciones relacionadas con la interfaz de usuario.
 */

// Asegurar que el namespace principal existe
var MDImportForce = MDImportForce || {};

// Módulo UI
MDImportForce.UI = (function() {
    // Variables privadas
    var $ = null;

    // Métodos privados
    /**
     * Muestra un mensaje en la interfaz
     * @param {string} message - Mensaje a mostrar
     * @param {string} type - Tipo de mensaje (success, error, info)
     * @param {string} targetElement - Selector del elemento donde mostrar el mensaje
     */
    function showMessage(message, type, targetElement) {
        var color = type === 'success' ? 'green' : (type === 'error' ? 'red' : 'inherit');
        var target = targetElement || '#md-import-force-messages';
        $(target).html('<p style="color: ' + color + '">' + message + '</p>');
    }

    /**
     * Muestra un mensaje de éxito con estadísticas
     * @param {Object} data - Datos de la respuesta
     */
    function showSuccessMessage(data) {
        var success_message = md_import_force.i18n.success;
        if (data && data.stats) {
            success_message += '<br>Nuevos: ' + (data.stats.new_count || 0) + ', ';
            success_message += 'Actualizados: ' + (data.stats.updated_count || 0) + ', ';
            success_message += 'Omitidos: ' + (data.stats.skipped_count || 0) + '.';
            if (data.message) {
                success_message += '<br>' + data.message;
            }
        }
        showMessage(success_message, 'success');
    }

    /**
     * Muestra un mensaje de error
     * @param {Object} data - Datos de la respuesta
     */
    function showErrorMessage(data) {
        var message = (data && data.message) ? data.message : 'Error desconocido';
        showMessage(md_import_force.i18n.error + ': ' + message, 'error');
    }

    /**
     * Muestra u oculta los elementos de progreso
     * @param {boolean} show - Indica si mostrar u ocultar
     */
    function toggleProgressElements(show) {
        if (show) {
            $('#md-import-force-progress').show();
            $('#md-import-force-current-item').show();
        } else {
            $('#md-import-force-progress').hide();
            $('#md-import-force-current-item').hide();
        }
    }

    // Métodos públicos
    return {
        /**
         * Inicializa el módulo UI
         * @param {Object} jQuery - Objeto jQuery
         */
        init: function(jQuery) {
            $ = jQuery;
            console.log('MD Import Force UI inicializado');
        },

        // Exponer métodos para uso en otros módulos
        showMessage: showMessage,
        showSuccessMessage: showSuccessMessage,
        showErrorMessage: showErrorMessage,
        toggleProgressElements: toggleProgressElements
    };
})();
