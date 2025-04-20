/**
 * MD Import Force - Módulo Ajax
 * 
 * Contiene funciones para manejar solicitudes AJAX.
 */

// Asegurar que el namespace principal existe
var MDImportForce = MDImportForce || {};

// Módulo Ajax
MDImportForce.Ajax = (function() {
    // Variables privadas
    var $ = null;

    /**
     * Realiza una solicitud AJAX usando XMLHttpRequest
     * @param {Object} options - Opciones de la solicitud
     * @returns {Promise} Promesa con la respuesta
     */
    function ajaxRequest(options) {
        return new Promise(function(resolve, reject) {
            var xhr = new XMLHttpRequest();

            // Configurar la solicitud
            xhr.open(options.type || 'GET', options.url, true);

            // Configurar el manejo de la respuesta
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        var response;
                        try {
                            response = JSON.parse(xhr.responseText);
                        } catch (e) {
                            response = xhr.responseText;
                        }
                        resolve(response);
                    } else {
                        reject({
                            status: xhr.status,
                            statusText: xhr.statusText,
                            responseText: xhr.responseText
                        });
                    }
                }
            };

            // Manejar errores de red
            xhr.onerror = function() {
                reject({
                    status: 0,
                    statusText: 'Error de red',
                    responseText: ''
                });
            };

            // Enviar los datos
            if (options.data instanceof FormData) {
                xhr.send(options.data);
            } else if (typeof options.data === 'object' && options.data !== null) {
                // Convertir objeto a cadena de consulta
                var params = [];
                for (var key in options.data) {
                    if (options.data.hasOwnProperty(key)) {
                        params.push(encodeURIComponent(key) + '=' + encodeURIComponent(options.data[key]));
                    }
                }
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.send(params.join('&'));
            } else {
                xhr.send();
            }
        });
    }

    // Métodos públicos
    return {
        /**
         * Inicializa el módulo Ajax
         * @param {Object} jQuery - Objeto jQuery
         */
        init: function(jQuery) {
            $ = jQuery;
            console.log('MD Import Force Ajax inicializado');
        },

        // Exponer métodos para uso en otros módulos
        ajaxRequest: ajaxRequest
    };
})();
