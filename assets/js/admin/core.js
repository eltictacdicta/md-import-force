/**
 * MD Import Force - Módulo Core
 * 
 * Contiene la configuración principal y el namespace para todos los módulos.
 */

// Crear el namespace principal
var MDImportForce = MDImportForce || {};

// Módulo Core
MDImportForce.Core = (function() {
    // Variables privadas
    var $ = null;

    // Métodos públicos
    return {
        /**
         * Inicializa el módulo Core
         * @param {Object} jQuery - Objeto jQuery
         */
        init: function(jQuery) {
            $ = jQuery;
            console.log('MD Import Force Core inicializado');
        },

        /**
         * Obtiene el objeto jQuery
         * @returns {Object} Objeto jQuery
         */
        getJQuery: function() {
            return $;
        }
    };
})();
