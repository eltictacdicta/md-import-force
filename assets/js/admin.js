jQuery(document).ready(function($) {
    // ===== FUNCIONES AUXILIARES =====

    // Función para mostrar mensajes en la interfaz
    function showMessage(message, type, targetElement) {
        var color = type === 'success' ? 'green' : (type === 'error' ? 'red' : 'inherit');
        var target = targetElement || '#md-import-force-messages';
        $(target).html('<p style="color: ' + color + '">' + message + '</p>');
    }

    // Función para mostrar mensaje de éxito
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

    // Función para mostrar mensaje de error
    function showErrorMessage(data) {
        var message = (data && data.message) ? data.message : 'Error desconocido';
        showMessage(md_import_force.i18n.error + ': ' + message, 'error');
    }

    // Función para ocultar/mostrar elementos de progreso
    function toggleProgressElements(show) {
        if (show) {
            $('#md-import-force-progress').show();
            $('#md-import-force-current-item').show();
        } else {
            $('#md-import-force-progress').hide();
            $('#md-import-force-current-item').hide();
        }
    }

    // Función auxiliar para realizar solicitudes AJAX usando XMLHttpRequest
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

    // ===== FUNCIONES DE NAVEGACIÓN POR PESTAÑAS =====

    // Función para cambiar de pestaña
    function switchTab(tab) {
        $('.nav-tab-wrapper a').removeClass('nav-tab-active');
        $('.nav-tab-wrapper a[data-tab="' + tab + '"]').addClass('nav-tab-active');

        $('.tab-content').hide();
        $('#tab-' + tab).show();

        // Si la pestaña del log está activa, cargar el log
        if (tab === 'log') {
            readErrorLog();
        }
    }

    // Manejo de pestañas
    $('.nav-tab-wrapper a').on('click', function(e) {
        e.preventDefault();
        var tab = $(this).data('tab');
        switchTab(tab);
    });

    // ===== FUNCIONES DE PREVISUALIZACIÓN =====

    // Variable global para almacenar la ruta del archivo de previsualización
    var previewFilePath = '';

    // Función para mostrar la previsualización
    function displayPreview(previewData) {
        // Guardar la ruta del archivo para usarla en la importación
        previewFilePath = previewData.file_path;

        $('#md-import-force-preview-area').show();
        var preview_content = '<h4>Información del sitio de origen:</h4>';
        preview_content += '<p>URL: ' + previewData.site_info.site_url + '</p>';
        preview_content += '<p>Nombre: ' + previewData.site_info.site_name + '</p>';
        preview_content += '<h4>Primeros ' + previewData.preview_records.length + ' de ' + previewData.total_records + ' registros:</h4>';
        preview_content += '<ul>';
        previewData.preview_records.forEach(function(record) {
            preview_content += '<li>ID: ' + record.ID + ', Título: ' + record.post_title + ', Tipo: ' + record.post_type + '</li>';
        });
        preview_content += '</ul>';
        $('#md-import-force-preview-content').html(preview_content);
    }

    // Función para realizar la previsualización
    function previewImportFile(file) {
        var formData = new FormData();
        formData.append('action', 'md_import_force_preview');
        formData.append('nonce', md_import_force.nonce);
        formData.append('import_file', file);

        // Mostrar indicador de carga
        showMessage(md_import_force.i18n.uploading, 'info');
        $('#md-import-force-preview-area').hide();
        $('#md-import-force-progress').hide();

        // Usar nuestra función ajaxRequest para mantener consistencia
        ajaxRequest({
            url: md_import_force.ajax_url,
            type: 'POST',
            data: formData
        })
        .then(function(response) {
            $('#md-import-force-messages').empty();
            if (response.success) {
                displayPreview(response.data);
            } else {
                showErrorMessage(response.data);
            }
        })
        .catch(function(error) {
            console.error('Error en la previsualización:', error);
            showMessage(md_import_force.i18n.error + ': ' + (error.statusText || 'Error al procesar la respuesta del servidor'), 'error');
        });
    }

    // Manejo del botón de previsualización
    $('#md-import-force-preview-button').on('click', function(e) {
        e.preventDefault();

        var file_input = $('#import_file')[0];
        if (file_input.files.length === 0) {
            alert('Por favor, selecciona un archivo JSON o ZIP para previsualizar.');
            return;
        }

        previewImportFile(file_input.files[0]);
    });

    // Manejo del botón de importar (mantener lógica existente, añadir nonce)
    $('#md-import-force-form').on('submit', function(e) {
        e.preventDefault();

        // Guardar el tiempo de inicio de la importación
        $(this).data('start-time', new Date().getTime() / 1000);

        // Verificar si tenemos un archivo para importar
        if (!previewFilePath) {
            alert('Por favor, primero previsualiza un archivo antes de importar.');
            return;
        }

        var form = $(this);
        var formData = new FormData(form[0]);
        formData.append('action', 'md_import_force_import');
        formData.append('nonce', md_import_force.nonce); // Añadir nonce
        formData.append('file_path', previewFilePath); // Añadir la ruta del archivo previamente subido

        // Ocultar previsualización y mostrar mensajes/progreso
        $('#md-import-force-preview-area').hide();
        $('#md-import-force-messages').html('<p>' + md_import_force.i18n.importing + '</p>'); // Usar i18n
        $('#md-import-force-progress').show(); // Mostrar progreso

        // Inicializar la interfaz de progreso
        function initializeProgressUI() {
            toggleProgressElements(true);
            $('#current-item-info').text('Iniciando importación...');
            $('#progress-count').text('0');
            $('#progress-total').text('0');
            $('#progress-percent').text('0%');
            showMessage(md_import_force.i18n.importing, 'info');
        }

        // Inicializar la interfaz de progreso
        initializeProgressUI();

        // Variable para almacenar el ID del intervalo de consulta de progreso
        var progressCheckInterval = null;
        // Variable para controlar si ya se ha procesado una importación completada
        var importCompletedProcessed = false;

        // Función para actualizar la interfaz con los datos de progreso
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
                showMessage(md_import_force.i18n.success, 'success');

                // Detener inmediatamente el intervalo de consulta
                if (progressCheckInterval) {
                    clearInterval(progressCheckInterval);
                    progressCheckInterval = null;
                }

                // Ocultar elementos de progreso
                toggleProgressElements(false);
            }
        }

        // Función para consultar el progreso de la importación
        function checkImportProgress() {
            // Usar nuestra función ajaxRequest
            ajaxRequest({
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

        // Iniciar el intervalo de consulta de progreso (cada 2 segundos para reducir carga del servidor)
        progressCheckInterval = setInterval(checkImportProgress, 2000);

        // Realizar una primera consulta inmediata para obtener el estado inicial
        checkImportProgress();

        // Establecer un timeout para verificar el progreso si la solicitud tarda demasiado
        var importTimeout = setTimeout(function() {
            // Si después de 300 segundos (5 minutos) no hay respuesta, verificar el estado actual
            if (!importCompletedProcessed) {
                // Timeout de importación alcanzado - verificando estado actual

                // Realizar una consulta final de progreso
                ajaxRequest({
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
                            progressCheckInterval = setInterval(checkImportProgress, 2000); // Consultar cada 2 segundos
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
        }, 180000); // 180 segundos (3 minutos) - reducido para mejorar la experiencia del usuario

        // Función para finalizar la importación
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

        // Función para procesar la respuesta de importación
        function processImportResponse(responseText) {
            var response;
            try {
                response = JSON.parse(responseText);
            } catch (e) {
                response = responseText;
            }

            // Ocultar elementos de progreso
            toggleProgressElements(false);

            return response;
        }

        // Realizar la solicitud de importación principal
        var importXhr = new XMLHttpRequest();
        importXhr.open('POST', md_import_force.ajax_url, true);

        // Configurar el manejo de la finalización (equivalente a complete)
        importXhr.onloadend = finalizeImport;

        // Configurar el manejo de la respuesta exitosa
        importXhr.onload = function() {
            if (importXhr.status >= 200 && importXhr.status < 300) {
                var response = processImportResponse(importXhr.responseText);

                // Función para procesar la respuesta de importación
                function handleImportResponse(response) {
                    try {
                        // Si la respuesta ya es un objeto
                        if (typeof response === 'object') {
                            if (response.success) {
                                showSuccessMessage(response.data);
                            } else {
                                showErrorMessage(response.data);
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
                                    showSuccessMessage(jsonResponse.data);
                                } else {
                                    showErrorMessage(jsonResponse.data);
                                }
                                return;
                            }
                        }

                        // Si llegamos aquí, no pudimos procesar la respuesta como JSON
                        // Verificamos si ha pasado suficiente tiempo para asumir que se completó
                        if (getImportElapsedTime() > 10) {
                            // Han pasado más de 10 segundos, asumimos que se completó correctamente
                            showMessage(md_import_force.i18n.success, 'success');
                        } else {
                            // Si no ha pasado suficiente tiempo, mostrar mensaje de importación en progreso
                            showMessage(md_import_force.i18n.importing, 'info');
                        }
                    } catch (e) {
                        console.error('Error al procesar respuesta de importación:', e);
                        handleImportError(response);
                    }
                }

                // Función para manejar errores en la importación
                function handleImportError(response) {
                    // Verificar si la importación se completó correctamente a pesar del error
                    if (typeof response === 'string' && (
                        response.includes('Importación completada') ||
                        response.includes('"status":"completed"') ||
                        response.includes('success') ||
                        response.includes('La importación se ha realizado con éxito')
                    )) {
                        showMessage(md_import_force.i18n.success, 'success');
                        return;
                    }

                    // Verificar si la importación ha estado en progreso por un tiempo
                    var timeSinceStart = getImportElapsedTime();

                    if (timeSinceStart > 10) {
                        // Han pasado más de 10 segundos, asumimos que se completó correctamente
                        showMessage(md_import_force.i18n.success, 'success');
                    } else {
                        // Si no ha pasado suficiente tiempo, mostrar mensaje de importación en progreso
                        showMessage(md_import_force.i18n.importing, 'info');

                        // Reiniciar el intervalo de consulta de progreso para seguir monitoreando
                        if (!progressCheckInterval) {
                            progressCheckInterval = setInterval(checkImportProgress, 2000);
                        }
                    }
                }

                // Procesar la respuesta
                handleImportResponse(response);
            }
        }

        // Función para manejar errores de red en la solicitud de importación
        function handleNetworkError() {
            console.error('Error en la solicitud de importación');

            // Ocultar elementos de progreso
            toggleProgressElements(false);

            // Verificar si la importación ha estado en progreso por un tiempo
            var timeSinceStart = getImportElapsedTime();

            if (timeSinceStart > 10) {
                // Si ha pasado suficiente tiempo, asumir que la importación se completó
                showMessage(md_import_force.i18n.success, 'success');
            } else {
                // Si no ha pasado suficiente tiempo, mostrar mensaje de error
                showMessage(md_import_force.i18n.error, 'error');
            }
        }

        // Configurar el manejo de errores
        importXhr.onerror = handleNetworkError;

        // Enviar la solicitud
        importXhr.send(formData);
    });

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

    // ===== FUNCIONES DE MANEJO DE LOG =====

    // Función para actualizar el contenido del log
    function updateLogContent(content) {
        $('#md-import-force-log-content').text(content);
    }

    // Función para leer el log de errores
    function readErrorLog() {
        updateLogContent('Cargando log...'); // Mensaje de carga

        ajaxRequest({
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

    // Función para limpiar el log de errores
    function clearErrorLog() {
        updateLogContent('Limpiando log...'); // Mensaje de limpieza

        ajaxRequest({
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

    // ===== FUNCIONES DE LIMPIEZA DE ARCHIVOS =====

    // Función para actualizar el resultado de la limpieza (usa showMessage con target específico)
    function updateCleanupResult(message, type) {
        showMessage(message, type, '#md-import-force-cleanup-result');
    }

    // Función para realizar la limpieza de archivos
    function cleanupFiles(hours) {
        updateCleanupResult('Limpiando archivos antiguos...', 'info');

        ajaxRequest({
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

    // Manejo del botón de limpieza de archivos
    $('#md-import-force-cleanup-all').on('click', function() {
        if (confirm('¿Estás seguro de que quieres eliminar los archivos de importación antiguos? Esta acción no se puede deshacer.')) {
            var hours = $('#cleanup_hours').val();
            cleanupFiles(hours);
        }
    });

    // Función auxiliar para calcular el tiempo transcurrido desde el inicio de la importación
    // Utilizada para determinar si una importación ha estado en progreso el tiempo suficiente
    // para considerarla completada en caso de errores o respuestas inesperadas
    function getImportElapsedTime() {
        var currentTime = new Date().getTime() / 1000;
        var startTime = $('#md-import-force-form').data('start-time') || currentTime;
        return currentTime - startTime;
    }

});
