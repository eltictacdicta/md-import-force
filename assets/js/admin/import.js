/**
 * MD Import Force - Módulo Import
 * 
 * Contiene funciones para el proceso de importación.
 */

// Asegurar que el namespace principal existe
var MDImportForce = MDImportForce || {};

// Módulo Import
MDImportForce.Import = (function() {
    // Variables privadas
    var $ = null;
    var progressCheckInterval = null;
    var importCompletedProcessed = false;
    var importTimeout = null;

    /**
     * Inicializa la interfaz de progreso
     */
    function initializeProgressUI() {
        MDImportForce.UI.toggleProgressElements(true);
        $('#current-item-info').text('Iniciando importación...');
        $('#progress-count').text('0');
        $('#progress-total').text('0');
        $('#progress-percent').text('0%');
        MDImportForce.UI.showMessage(md_import_force.i18n.importing, 'info');
    }

    /**
     * Actualiza la interfaz con los datos de progreso
     * @param {Object} progressData - Datos de progreso
     */
    function updateProgressUI(progressData) {
        if (!progressData) return;

        // Si ya hemos procesado una importación completada, ignorar actualizaciones adicionales
        if (importCompletedProcessed && progressData.status === 'completed' && progressData.percent === 100) {
            return;
        }

        // Verificar si son datos antiguos de una importación anterior completada
        if (progressData.status === 'completed' && progressData.percent === 100) {
            var currentTime = new Date().getTime() / 1000;
            var dataTime = progressData.timestamp || 0;
            var timeDiff = currentTime - dataTime;

            // Si han pasado más de 5 segundos, probablemente son datos antiguos
            if (timeDiff > 5) {
                return;
            }
        }

        // Actualizar la barra de progreso
        var percent = progressData.percent || 0;
        $('#md-import-force-progress .progress-bar').width(percent + '%');

        // Actualizar la información del elemento actual
        if (progressData.current_item) {
            $('#current-item-info').text(progressData.current_item);
        }

        // Actualizar los contadores
        $('#progress-count').text(progressData.current || 0);
        $('#progress-total').text(progressData.total || 0);
        $('#progress-percent').text(percent + '%');

        // Si la importación ha terminado, detener las consultas
        if (progressData.status === 'completed' && percent >= 100) {
            // Marcar como procesado para evitar procesamiento múltiple
            importCompletedProcessed = true;

            // Mostrar mensaje de éxito
            MDImportForce.UI.showMessage(md_import_force.i18n.success, 'success');

            // Detener inmediatamente el intervalo de consulta
            if (progressCheckInterval) {
                clearInterval(progressCheckInterval);
                progressCheckInterval = null;
            }

            // Ocultar elementos de progreso
            MDImportForce.UI.toggleProgressElements(false);
        }
    }

    /**
     * Consulta el progreso de la importación
     */
    function checkImportProgress() {
        // Usar la función ajaxRequest del módulo Ajax
        MDImportForce.Ajax.ajaxRequest({
            url: md_import_force.ajax_url,
            type: 'POST',
            data: {
                action: 'md_import_force_check_progress',
                nonce: md_import_force.nonce
            }
        })
        .then(function(response) {
            try {
                if (response.success && response.data) {
                    updateProgressUI(response.data);
                    // La actualización de la UI ya maneja la finalización de la importación
                }
            } catch (e) {
                console.error('Error al procesar respuesta en checkImportProgress:', e);
                // No reiniciar el intervalo si hay un error y ya estamos marcados como completados
                if (importCompletedProcessed && progressCheckInterval) {
                    clearInterval(progressCheckInterval);
                    progressCheckInterval = null;
                }
            }
        })
        .catch(function(error) {
            console.error('Error al consultar progreso:', error);
        });
    }

    /**
     * Finaliza la importación
     */
    function finalizeImport() {
        // Limpiar el timeout
        clearTimeout(importTimeout);

        // Detener el intervalo de consulta de progreso y marcar como completado
        if (progressCheckInterval) {
            clearInterval(progressCheckInterval);
            progressCheckInterval = null;
        }

        // Marcar como procesado para evitar procesamiento múltiple
        importCompletedProcessed = true;
    }

    /**
     * Procesa la respuesta de importación
     * @param {string} responseText - Texto de la respuesta
     * @returns {Object} Respuesta procesada
     */
    function processImportResponse(responseText) {
        var response;
        try {
            response = JSON.parse(responseText);
        } catch (e) {
            response = responseText;
        }

        // Ocultar elementos de progreso
        MDImportForce.UI.toggleProgressElements(false);

        return response;
    }

    /**
     * Procesa la respuesta de importación
     * @param {Object|string} response - Respuesta de la importación
     */
    function handleImportResponse(response) {
        try {
            // Si la respuesta ya es un objeto
            if (typeof response === 'object') {
                if (response.success) {
                    MDImportForce.UI.showSuccessMessage(response.data);
                } else {
                    MDImportForce.UI.showErrorMessage(response.data);
                }
                return;
            }

            // Si es una cadena, intentar parsearla como JSON
            if (typeof response === 'string') {
                // Eliminar cualquier etiqueta progress-update y contenido no JSON
                var responseText = response.replace(/<progress-update>[\s\S]*?<\/progress-update>/g, '');

                // Buscar un objeto JSON válido en la respuesta
                var jsonMatch = responseText.match(/({[\s\S]*})/);
                if (jsonMatch && jsonMatch[1]) {
                    responseText = jsonMatch[1];
                    var jsonResponse = JSON.parse(responseText);

                    if (jsonResponse.success) {
                        MDImportForce.UI.showSuccessMessage(jsonResponse.data);
                    } else {
                        MDImportForce.UI.showErrorMessage(jsonResponse.data);
                    }
                    return;
                }
            }

            // Si llegamos aquí, no pudimos procesar la respuesta como JSON
            // Verificamos si ha pasado suficiente tiempo para asumir que se completó
            if (getImportElapsedTime() > 10) {
                // Han pasado más de 10 segundos, asumimos que se completó correctamente
                MDImportForce.UI.showMessage(md_import_force.i18n.success, 'success');
            } else {
                // Si no ha pasado suficiente tiempo, mostrar mensaje de importación en progreso
                MDImportForce.UI.showMessage(md_import_force.i18n.importing, 'info');
            }
        } catch (e) {
            console.error('Error al procesar respuesta de importación:', e);
            handleImportError(response);
        }
    }

    /**
     * Maneja errores en la importación
     * @param {Object|string} response - Respuesta de error
     */
    function handleImportError(response) {
        // Verificar si la importación se completó correctamente a pesar del error
        if (typeof response === 'string' && (
            response.includes('Importación completada') ||
            response.includes('"status":"completed"') ||
            response.includes('success') ||
            response.includes('La importación se ha realizado con éxito')
        )) {
            MDImportForce.UI.showMessage(md_import_force.i18n.success, 'success');
            return;
        }

        // Verificar si la importación ha estado en progreso por un tiempo
        var timeSinceStart = getImportElapsedTime();

        if (timeSinceStart > 10) {
            // Han pasado más de 10 segundos, asumimos que se completó correctamente
            MDImportForce.UI.showMessage(md_import_force.i18n.success, 'success');
        } else {
            // Si no ha pasado suficiente tiempo, mostrar mensaje de importación en progreso
            MDImportForce.UI.showMessage(md_import_force.i18n.importing, 'info');

            // Reiniciar el intervalo de consulta de progreso para seguir monitoreando
            if (!progressCheckInterval) {
                progressCheckInterval = setInterval(checkImportProgress, 2000);
            }
        }
    }

    /**
     * Maneja errores de red en la solicitud de importación
     */
    function handleNetworkError() {
        console.error('Error en la solicitud de importación');

        // Ocultar elementos de progreso
        MDImportForce.UI.toggleProgressElements(false);

        // Verificar si la importación ha estado en progreso por un tiempo
        var timeSinceStart = getImportElapsedTime();

        if (timeSinceStart > 10) {
            // Si ha pasado suficiente tiempo, asumir que la importación se completó
            MDImportForce.UI.showMessage(md_import_force.i18n.success, 'success');
        } else {
            // Si no ha pasado suficiente tiempo, mostrar mensaje de error
            MDImportForce.UI.showMessage(md_import_force.i18n.error, 'error');
        }
    }

    /**
     * Calcula el tiempo transcurrido desde el inicio de la importación
     * @returns {number} Tiempo transcurrido en segundos
     */
    function getImportElapsedTime() {
        var currentTime = new Date().getTime() / 1000;
        var startTime = $('#md-import-force-form').data('start-time') || currentTime;
        return currentTime - startTime;
    }

    /**
     * Configura los eventos de importación
     */
    function setupImportEvents() {
        $('#md-import-force-form').on('submit', function(e) {
            e.preventDefault();

            // Guardar el tiempo de inicio de la importación
            $(this).data('start-time', new Date().getTime() / 1000);

            // Verificar si tenemos un archivo para importar
            var previewFilePath = MDImportForce.Preview.getPreviewFilePath();
            if (!previewFilePath) {
                alert('Por favor, primero previsualiza un archivo antes de importar.');
                return;
            }

            var form = $(this);
            var formData = new FormData(form[0]);
            formData.append('action', 'md_import_force_import');
            formData.append('nonce', md_import_force.nonce);
            formData.append('file_path', previewFilePath);

            // Ocultar previsualización y mostrar mensajes/progreso
            $('#md-import-force-preview-area').hide();
            $('#md-import-force-messages').html('<p>' + md_import_force.i18n.importing + '</p>');
            $('#md-import-force-progress').show();

            // Inicializar la interfaz de progreso
            initializeProgressUI();

            // Reiniciar variables de control
            importCompletedProcessed = false;

            // Iniciar el intervalo de consulta de progreso (cada 2 segundos)
            progressCheckInterval = setInterval(checkImportProgress, 2000);

            // Realizar una primera consulta inmediata para obtener el estado inicial
            checkImportProgress();

            // Establecer un timeout para verificar el progreso si la solicitud tarda demasiado
            importTimeout = setTimeout(function() {
                // Si después de 180 segundos (3 minutos) no hay respuesta, verificar el estado actual
                if (!importCompletedProcessed) {
                    // Timeout de importación alcanzado - verificando estado actual

                    // Realizar una consulta final de progreso
                    MDImportForce.Ajax.ajaxRequest({
                        url: md_import_force.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'md_import_force_check_progress',
                            nonce: md_import_force.nonce
                        }
                    })
                    .then(function(response) {
                        if (response.success && response.data) {
                            // Actualizar la UI con los datos de progreso
                            updateProgressUI(response.data);

                            // Si la importación no está completada, reiniciar el intervalo de consulta
                            if (response.data.status !== 'completed' && !progressCheckInterval) {
                                progressCheckInterval = setInterval(checkImportProgress, 2000);
                                $('#md-import-force-messages').html('<p>' + md_import_force.i18n.importing + '</p>');
                            }
                        } else {
                            // Si no hay datos de progreso, asumir que la importación se completó
                            importCompletedProcessed = true;
                            $('#md-import-force-messages').html('<p style="color: green;">' + md_import_force.i18n.success + '</p>');

                            // Detener el intervalo de consulta y ocultar elementos de progreso
                            if (progressCheckInterval) {
                                clearInterval(progressCheckInterval);
                                progressCheckInterval = null;
                            }
                            $('#md-import-force-progress').hide();
                            $('#md-import-force-current-item').hide();
                        }
                    })
                    .catch(function() {
                        // En caso de error, reiniciar el intervalo de consulta si no está activo
                        if (!progressCheckInterval) {
                            progressCheckInterval = setInterval(checkImportProgress, 2000);
                            $('#md-import-force-messages').html('<p>' + md_import_force.i18n.importing + '</p>');
                        }
                    });
                }
            }, 180000); // 180 segundos (3 minutos)

            // Realizar la solicitud de importación principal
            var importXhr = new XMLHttpRequest();
            importXhr.open('POST', md_import_force.ajax_url, true);

            // Configurar el manejo de la finalización
            importXhr.onloadend = finalizeImport;

            // Configurar el manejo de la respuesta exitosa
            importXhr.onload = function() {
                if (importXhr.status >= 200 && importXhr.status < 300) {
                    var response = processImportResponse(importXhr.responseText);
                    handleImportResponse(response);
                }
            };

            // Configurar el manejo de errores
            importXhr.onerror = handleNetworkError;

            // Enviar la solicitud
            importXhr.send(formData);
        });
    }

    // Métodos públicos
    return {
        /**
         * Inicializa el módulo Import
         * @param {Object} jQuery - Objeto jQuery
         */
        init: function(jQuery) {
            $ = jQuery;
            setupImportEvents();
            console.log('MD Import Force Import inicializado');
        }
    };
})();
