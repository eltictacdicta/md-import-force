/**
 * MD Import Force - Módulo Tabs
 * 
 * Contiene funciones para la navegación por pestañas.
 */

// Asegurar que el namespace principal existe
var MDImportForce = MDImportForce || {};

// Módulo Tabs
MDImportForce.Tabs = (function() {
    // Variables privadas
    var $ = null;

    /**
     * Cambia a la pestaña especificada
     * @param {string} tab - ID de la pestaña
     */
    function switchTab(tab) {
        $('.nav-tab-wrapper a').removeClass('nav-tab-active');
        $('.nav-tab-wrapper a[data-tab="' + tab + '"]').addClass('nav-tab-active');

        $('.tab-content').hide();
        $('#tab-' + tab).show();

        // Si la pestaña del log está activa, cargar el log
        if (tab === 'log') {
            MDImportForce.Log.readErrorLog();
        }
    }

    /**
     * Configura los eventos de las pestañas
     */
    function setupTabEvents() {
        $('.nav-tab-wrapper a').on('click', function(e) {
            e.preventDefault();
            var tab = $(this).data('tab');
            switchTab(tab);
        });
    }

    // Métodos públicos
    return {
        /**
         * Inicializa el módulo Tabs
         * @param {Object} jQuery - Objeto jQuery
         */
        init: function(jQuery) {
            $ = jQuery;
            setupTabEvents();
            console.log('MD Import Force Tabs inicializado');
        },

        // Exponer métodos para uso en otros módulos
        switchTab: switchTab
    };
})();
