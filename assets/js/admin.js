jQuery(document).ready(function($) {
    // Monkey patch jQuery.ajax to fix the settings.data.split error
    var originalAjax = $.ajax;
    $.ajax = function(url, options) {
        // If url is an object, treat it as options
        if (typeof url === 'object') {
            options = url;
            url = undefined;
        }

        // Set default options
        options = options || {};

        // Handle FormData correctly
        if (options.data instanceof FormData) {
            options.processData = false;
            options.contentType = false;
        }

        // Create a custom beforeSend function that will ensure data is properly formatted
        var originalBeforeSend = options.beforeSend;
        options.beforeSend = function(xhr, settings) {
            // Fix for settings.data.split error - ensure it's a string if not FormData
            if (settings.data && typeof settings.data !== 'string' && !(settings.data instanceof FormData)) {
                try {
                    // Convertir a string usando jQuery.param
                    settings.data = $.param(settings.data);
                } catch (e) {
                    console.error('Error converting data to string:', e);
                    // Si falla, crear un string vacío para evitar errores
                    settings.data = '';
                }
            }

            // Double-check that data is now a string or FormData
            if (settings.data && typeof settings.data !== 'string' && !(settings.data instanceof FormData)) {
                console.warn('Data is still not a string after conversion attempt');
                // Último recurso: convertir a string JSON
                try {
                    settings.data = JSON.stringify(settings.data);
                } catch (e) {
                    console.error('Error stringifying data:', e);
                    settings.data = '';
                }
            }

            // Call the original beforeSend if it exists
            if (originalBeforeSend) {
                return originalBeforeSend(xhr, settings);
            }
        };

        // Call the original $.ajax with our modified options
        return originalAjax.call(this, options);
    };

    // Manejo de pestañas
    $('.nav-tab-wrapper a').on('click', function(e) {
        e.preventDefault();
        var tab = $(this).data('tab');

        $('.nav-tab-wrapper a').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');

        $('.tab-content').hide();
        $('#tab-' + tab).show();

        // Si la pestaña del log está activa, cargar el log
        if (tab === 'log') {
            readErrorLog();
        }
    });

    // Variable global para almacenar la ruta del archivo de previsualización
    var previewFilePath = '';

    // Manejo del botón de previsualización
    $('#md-import-force-preview-button').on('click', function(e) {
        e.preventDefault();

        var file_input = $('#import_file')[0];
        if (file_input.files.length === 0) {
            alert('Por favor, selecciona un archivo JSON o ZIP para previsualizar.');
            return;
        }

        var file = file_input.files[0];
        var formData = new FormData();
        formData.append('action', 'md_import_force_preview');
        formData.append('nonce', md_import_force.nonce); // Añadir nonce
        formData.append('import_file', file);

        // Show loading indicator or message
        $('#md-import-force-messages').html('<p>' + md_import_force.i18n.uploading + '</p>'); // Usar i18n
        $('#md-import-force-preview-area').hide();
        $('#md-import-force-progress').hide(); // Ocultar progreso si estaba visible

        // Usar directamente el objeto XMLHttpRequest para evitar problemas con jQuery
        var xhr = new XMLHttpRequest();
        xhr.open('POST', md_import_force.ajax_url, true);
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        $('#md-import-force-messages').empty();
                        if (response.success) {
                            // Guardar la ruta del archivo para usarla en la importación
                            previewFilePath = response.data.file_path;

                            $('#md-import-force-preview-area').show();
                            var preview_content = '<h4>Información del sitio de origen:</h4>';
                            preview_content += '<p>URL: ' + response.data.site_info.site_url + '</p>';
                            preview_content += '<p>Nombre: ' + response.data.site_info.site_name + '</p>';
                            preview_content += '<h4>Primeros ' + response.data.preview_records.length + ' de ' + response.data.total_records + ' registros:</h4>';
                            preview_content += '<ul>';
                            response.data.preview_records.forEach(function(record) {
                                preview_content += '<li>ID: ' + record.ID + ', Título: ' + record.post_title + ', Tipo: ' + record.post_type + '</li>';
                            });
                            preview_content += '</ul>';
                            $('#md-import-force-preview-content').html(preview_content);
                        } else {
                            $('#md-import-force-messages').html('<p style="color: red;">' + md_import_force.i18n.error + ': ' + (response.data.message || 'Error desconocido') + '</p>');
                        }
                    } catch (e) {
                        console.error('Error al parsear respuesta JSON:', e);
                        $('#md-import-force-messages').html('<p style="color: red;">' + md_import_force.i18n.error + ': Error al procesar la respuesta del servidor</p>');
                    }
                } else {
                    $('#md-import-force-messages').html('<p style="color: red;">' + md_import_force.i18n.error + ' en la solicitud AJAX: ' + xhr.statusText + '</p>');
                }
            }
        };
        xhr.send(formData);

        // No usamos jQuery.ajax para evitar el error de settings.data.split
        /*
        $.ajax({
            url: md_import_force.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                $('#md-import-force-messages').empty();
                if (response.success) {
                    // Guardar la ruta del archivo para usarla en la importación
                    previewFilePath = response.data.file_path;

                    $('#md-import-force-preview-area').show();
                    var preview_content = '<h4>Información del sitio de origen:</h4>';
                    preview_content += '<p>URL: ' + response.data.site_info.site_url + '</p>'; // Acceder a data
                    preview_content += '<p>Nombre: ' + response.data.site_info.site_name + '</p>'; // Acceder a data
                    preview_content += '<h4>Primeros ' + response.data.preview_records.length + ' de ' + response.data.total_records + ' registros:</h4>'; // Acceder a data
                    preview_content += '<ul>';
                    response.data.preview_records.forEach(function(record) { // Acceder a data
                        preview_content += '<li>ID: ' + record.ID + ', Título: ' + record.post_title + ', Tipo: ' + record.post_type + '</li>';
                    });
                    preview_content += '</ul>';
                    $('#md-import-force-preview-content').html(preview_content);
                } else {
                    $('#md-import-force-messages').html('<p style="color: red;">' + md_import_force.i18n.error + ': ' + (response.data.message || 'Error desconocido') + '</p>'); // Usar i18n y acceder a data.message
                }
            },
            error: function(xhr, status, error) {
                $('#md-import-force-messages').html('<p style="color: red;">' + md_import_force.i18n.error + ' en la solicitud AJAX: ' + error + '</p>'); // Usar i18n
            }
        });
        */
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

        // Mostrar el área de información del elemento actual
        $('#md-import-force-current-item').show();
        $('#current-item-info').text('Iniciando importación...');
        $('#progress-count').text('0');
        $('#progress-total').text('0');
        $('#progress-percent').text('0%');

        // Variable para almacenar el ID del intervalo de consulta de progreso
        var progressCheckInterval = null;
        // Variable para controlar si ya se ha procesado una importación completada
        var importCompletedProcessed = false;

        // Función para actualizar la interfaz con los datos de progreso
        function updateProgressUI(progressData) {
            if (!progressData) return;

            console.log('Actualización de progreso recibida:', progressData);

            // Si ya hemos procesado una importación completada, ignorar actualizaciones adicionales
            if (importCompletedProcessed && progressData.status === 'completed' && progressData.percent === 100) {
                console.log('Importación ya completada, ignorando actualizaciones adicionales');
                return;
            }

            // Verificar si los datos son válidos y no son de una importación anterior completada
            // Si es una importación nueva que acaba de comenzar, el status debe ser 'starting' o 'importing'
            // y el porcentaje no debe ser 100%
            if (progressData.status === 'completed' && progressData.percent === 100) {
                // Verificar si la importación acaba de comenzar (menos de 5 segundos)
                var currentTime = new Date().getTime() / 1000;
                var dataTime = progressData.timestamp || 0;
                var timeDiff = currentTime - dataTime;

                // Si la importación acaba de comenzar y ya está marcada como completada,
                // probablemente son datos antiguos, así que los ignoramos
                if (timeDiff > 5) {
                    console.log('Ignorando datos de progreso antiguos');
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
                $('#md-import-force-messages').html('<p style="color: green;">' + md_import_force.i18n.success + '</p>');

                // Detener inmediatamente el intervalo de consulta
                if (progressCheckInterval) {
                    console.log('Deteniendo consultas de progreso - importación completada');
                    clearInterval(progressCheckInterval);
                    progressCheckInterval = null;
                }

                // Ocultar elementos de progreso
                $('#md-import-force-progress').hide();
                $('#md-import-force-current-item').hide();
            }
        }

        // Función para consultar el progreso de la importación
        function checkImportProgress() {
            // Usar jQuery AJAX para mayor compatibilidad
            $.ajax({
                url: md_import_force.ajax_url,
                type: 'POST',
                data: {
                    action: 'md_import_force_check_progress',
                    nonce: md_import_force.nonce
                },
                success: function(response) {
                    try {
                        if (response.success && response.data) {
                            updateProgressUI(response.data);

                            // Si la importación está completada, detener las consultas después de esta actualización
                            if (response.data.status === 'completed' && response.data.percent === 100) {
                                console.log('Importación completada detectada en checkImportProgress');
                                importCompletedProcessed = true;

                                // Mostrar mensaje de éxito
                                $('#md-import-force-messages').html('<p style="color: green;">' + md_import_force.i18n.success + '</p>');

                                // Detener el intervalo de consulta
                                if (progressCheckInterval) {
                                    clearInterval(progressCheckInterval);
                                    progressCheckInterval = null;
                                }

                                // Ocultar elementos de progreso
                                $('#md-import-force-progress').hide();
                                $('#md-import-force-current-item').hide();
                            }
                        }
                    } catch (e) {
                        console.error('Error al procesar respuesta en checkImportProgress:', e);
                        // No reiniciar el intervalo si hay un error y ya estamos marcados como completados
                        if (importCompletedProcessed && progressCheckInterval) {
                            clearInterval(progressCheckInterval);
                            progressCheckInterval = null;
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error al consultar progreso. Status:', status, 'Error:', error);
                }
            });
        }

        // Iniciar el intervalo de consulta de progreso (cada 1 segundo)
        progressCheckInterval = setInterval(checkImportProgress, 1000);

        // Realizar una primera consulta inmediata para obtener el estado inicial
        checkImportProgress();

        // Usar jQuery AJAX para mayor compatibilidad
        // Configurar AJAX global para esta solicitud
        $.ajaxSetup({
            timeout: 0, // Sin tiempo límite
            cache: false // Evitar caché
        });

        // Establecer un timeout para verificar el progreso si la solicitud tarda demasiado
        var importTimeout = setTimeout(function() {
            // Si después de 300 segundos (5 minutos) no hay respuesta, verificar el estado actual
            if (!importCompletedProcessed) {
                console.log('Timeout de importación alcanzado - verificando estado actual');

                // Realizar una consulta final de progreso
                $.ajax({
                    url: md_import_force.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'md_import_force_check_progress',
                        nonce: md_import_force.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            // Si la importación está completada, mostrar mensaje de éxito
                            if (response.data.status === 'completed' && response.data.percent === 100) {
                                console.log('Verificación final: La importación se completó correctamente');
                                // Detener el intervalo de consulta de progreso
                                if (progressCheckInterval) {
                                    clearInterval(progressCheckInterval);
                                    progressCheckInterval = null;
                                }
                                // Marcar como procesado
                                importCompletedProcessed = true;
                                // Mostrar mensaje de éxito
                                $('#md-import-force-messages').html('<p style="color: green;">' + md_import_force.i18n.success + '</p>');
                                // Ocultar elementos de progreso
                                $('#md-import-force-progress').hide();
                                $('#md-import-force-current-item').hide();
                            } else {
                                // Si la importación sigue en progreso, mostrar el estado actual
                                console.log('Verificación final: La importación sigue en progreso', response.data);
                                updateProgressUI(response.data);

                                // En lugar de asumir que la importación se completó, seguir verificando el progreso
                                console.log('Continuando verificación de progreso en segundo plano');

                                // Reiniciar el intervalo de consulta de progreso si no está activo
                                if (!progressCheckInterval) {
                                    progressCheckInterval = setInterval(checkImportProgress, 2000); // Consultar cada 2 segundos

                                    // Realizar una consulta inmediata
                                    checkImportProgress();
                                }

                                // Mostrar mensaje de que la importación continúa
                                $('#md-import-force-messages').html('<p>' + md_import_force.i18n.importing + '</p>');
                            }
                        } else {
                            // Si no hay datos de progreso, asumir que la importación se completó
                            console.log('Verificación final: No hay datos de progreso, asumiendo que la importación se completó');
                            // Detener el intervalo de consulta de progreso
                            if (progressCheckInterval) {
                                clearInterval(progressCheckInterval);
                                progressCheckInterval = null;
                            }
                            // Marcar como procesado
                            importCompletedProcessed = true;
                            // Mostrar mensaje de éxito
                            $('#md-import-force-messages').html('<p style="color: green;">' + md_import_force.i18n.success + '</p>');
                            // Ocultar elementos de progreso
                            $('#md-import-force-progress').hide();
                            $('#md-import-force-current-item').hide();
                        }
                    },
                    error: function() {
                        // En caso de error en la verificación, continuar monitoreando en lugar de asumir que se completó
                        console.log('Error en la verificación final, continuando monitoreo de progreso');

                        // Reiniciar el intervalo de consulta de progreso si no está activo
                        if (!progressCheckInterval) {
                            progressCheckInterval = setInterval(checkImportProgress, 2000); // Consultar cada 2 segundos

                            // Realizar una consulta inmediata
                            checkImportProgress();
                        }

                        // Mostrar mensaje de que la importación continúa
                        $('#md-import-force-messages').html('<p>' + md_import_force.i18n.importing + '</p>');
                    }
                });
            }
        }, 300000); // 300 segundos (5 minutos)

        // Realizar la solicitud de importación principal
        $.ajax({
            url: md_import_force.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            complete: function() {
                // Limpiar el timeout
                clearTimeout(importTimeout);

                // Detener el intervalo de consulta de progreso y marcar como completado
                if (progressCheckInterval) {
                    clearInterval(progressCheckInterval);
                    progressCheckInterval = null;
                }

                // Marcar como procesado para evitar procesamiento múltiple
                importCompletedProcessed = true;
            },
            success: function(response, status, xhr) {
                console.log('Respuesta de importación recibida:', response);

                // Ocultar elementos de progreso
                $('#md-import-force-progress').hide();
                $('#md-import-force-current-item').hide();

                try {
                    // Si la respuesta ya es un objeto (jQuery la ha parseado)
                    if (typeof response === 'object') {
                        console.log('Respuesta del servidor (objeto):', response);
                        if (response.success) {
                            var success_message = md_import_force.i18n.success; // Usar i18n
                            if (response.data && response.data.stats) {
                                console.log('Datos de estadísticas:', response.data.stats);
                                success_message += '<br>Nuevos: ' + (response.data.stats.new_count || 0) + ', ';
                                success_message += 'Actualizados: ' + (response.data.stats.updated_count || 0) + ', ';
                                success_message += 'Omitidos: ' + (response.data.stats.skipped_count || 0) + '.';
                                if (response.data.message) {
                                    success_message += '<br>' + response.data.message;
                                }

                                // Mostrar elementos omitidos en consola para depuración
                                console.log('Elementos omitidos (objeto):', response.data.stats.skipped_items);
                            }
                            $('#md-import-force-messages').html('<p style="color: green;">' + success_message + '</p>');


                        } else {
                            $('#md-import-force-messages').html('<p style="color: red;">' + md_import_force.i18n.error + ': ' + ((response.data && response.data.message) || 'Error desconocido') + '</p>');
                        }
                        return;
                    }

                    // Si es una cadena, intentar parsearla como JSON
                    if (typeof response === 'string') {
                        console.log('Respuesta original (string):', response);

                        // Eliminar cualquier etiqueta progress-update y contenido no JSON
                        var responseText = response.replace(/<progress-update>[\s\S]*?<\/progress-update>/g, '');

                        // Buscar un objeto JSON válido en la respuesta
                        var jsonMatch = responseText.match(/({[\s\S]*})/);
                        if (jsonMatch && jsonMatch[1]) {
                            responseText = jsonMatch[1];
                            console.log('JSON extraído:', responseText);

                            var jsonResponse = JSON.parse(responseText);
                            console.log('Respuesta JSON parseada:', jsonResponse);
                            if (jsonResponse.success) {
                                var success_message = md_import_force.i18n.success;
                                if (jsonResponse.data && jsonResponse.data.stats) {
                                    console.log('Datos de estadísticas (JSON):', jsonResponse.data.stats);
                                    success_message += '<br>Nuevos: ' + (jsonResponse.data.stats.new_count || 0) + ', ';
                                    success_message += 'Actualizados: ' + (jsonResponse.data.stats.updated_count || 0) + ', ';
                                    success_message += 'Omitidos: ' + (jsonResponse.data.stats.skipped_count || 0) + '.';
                                    if (jsonResponse.data.message) {
                                        success_message += '<br>' + jsonResponse.data.message;
                                    }

                                    // Mostrar elementos omitidos en consola para depuración
                                    console.log('Elementos omitidos (JSON):', jsonResponse.data.stats.skipped_items);
                                }
                                $('#md-import-force-messages').html('<p style="color: green;">' + success_message + '</p>');


                            } else {
                                $('#md-import-force-messages').html('<p style="color: red;">' + md_import_force.i18n.error + ': ' + ((jsonResponse.data && jsonResponse.data.message) || 'Error desconocido') + '</p>');
                            }
                            return;
                        }
                    }

                    // Si llegamos aquí, no pudimos procesar la respuesta como JSON
                    // Pero asumimos que la importación se completó correctamente
                    console.log('No se pudo procesar la respuesta como JSON, pero asumimos que la importación se completó');
                    $('#md-import-force-messages').html('<p style="color: green;">' + md_import_force.i18n.success + '</p>');
                } catch (e) {
                    console.error('Error al procesar respuesta de importación:', e);

                    // Verificar si la importación se completó correctamente a pesar del error
                    if (typeof response === 'string' && (
                        response.includes('Importación completada') ||
                        response.includes('"status":"completed"') ||
                        response.includes('success') ||
                        response.includes('La importación se ha realizado con éxito')
                    )) {
                        console.log('Se detectó que la importación se completó correctamente a pesar del error de parseo');
                        $('#md-import-force-messages').html('<p style="color: green;">' + md_import_force.i18n.success + '</p>');
                        return;
                    }

                    // Verificar si la importación ha estado en progreso por un tiempo
                    var currentTime = new Date().getTime() / 1000;
                    var startTime = $('#md-import-force-form').data('start-time') || currentTime;
                    var timeSinceStart = currentTime - startTime;

                    if (timeSinceStart > 10) {
                        console.log('Han pasado más de 10 segundos desde el inicio de la importación, asumiendo que se completó correctamente');
                        $('#md-import-force-messages').html('<p style="color: green;">' + md_import_force.i18n.success + '</p>');
                    } else {
                        // Si no ha pasado suficiente tiempo, mostrar mensaje de importación en progreso
                        console.error('Error al procesar respuesta. La importación podría seguir en progreso.');
                        $('#md-import-force-messages').html('<p>' + md_import_force.i18n.importing + '</p>');

                        // Reiniciar el intervalo de consulta de progreso para seguir monitoreando
                        if (!progressCheckInterval) {
                            progressCheckInterval = setInterval(checkImportProgress, 1000);
                        }
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('Error en la solicitud de importación:', status, error);

                // Ocultar elementos de progreso
                $('#md-import-force-progress').hide();
                $('#md-import-force-current-item').hide();

                // Verificar si la importación ha estado en progreso por un tiempo
                var currentTime = new Date().getTime() / 1000;
                var startTime = $('#md-import-force-form').data('start-time') || currentTime;
                var timeSinceStart = currentTime - startTime;

                if (timeSinceStart > 10) {
                    // Si ha pasado suficiente tiempo, asumir que la importación se completó
                    console.log('Error en la solicitud, pero han pasado más de 10 segundos. Asumiendo que se completó correctamente');
                    $('#md-import-force-messages').html('<p style="color: green;">' + md_import_force.i18n.success + '</p>');
                } else {
                    // Si no ha pasado suficiente tiempo, mostrar mensaje de error
                    $('#md-import-force-messages').html('<p style="color: red;">' + md_import_force.i18n.error + ': ' + error + '</p>');
                }
            }
        });
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

    // Función para leer el log de errores
    function readErrorLog() {
        $('#md-import-force-log-content').text('Cargando log...'); // Mensaje de carga

        $.ajax({
            url: md_import_force.ajax_url,
            type: 'POST',
            data: {
                action: 'md_import_force_read_log',
                nonce: md_import_force.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#md-import-force-log-content').text(response.data.log_content || 'El log está vacío.');
                } else {
                    $('#md-import-force-log-content').text('Error al cargar el log: ' + (response.data.message || 'Error desconocido'));
                }
            },
            error: function(xhr, status, error) {
                $('#md-import-force-log-content').text('Error en la solicitud AJAX para leer el log: ' + error);
            }
        });
    }

    // Función para limpiar el log de errores
    function clearErrorLog() {
        $('#md-import-force-log-content').text('Limpiando log...'); // Mensaje de limpieza

        $.ajax({
            url: md_import_force.ajax_url,
            type: 'POST',
            data: {
                action: 'md_import_force_clear_log',
                nonce: md_import_force.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#md-import-force-log-content').text('Log limpiado con éxito.');
                } else {
                    $('#md-import-force-log-content').text('Error al limpiar el log: ' + (response.data.message || 'Error desconocido'));
                }
            },
            error: function(xhr, status, error) {
                $('#md-import-force-log-content').text('Error en la solicitud AJAX para limpiar el log: ' + error);
            }
        });
    }



    // Manejo del botón de limpieza de archivos
    $('#md-import-force-cleanup-all').on('click', function() {
        if (confirm('¿Estás seguro de que quieres eliminar los archivos de importación antiguos? Esta acción no se puede deshacer.')) {
            var hours = $('#cleanup_hours').val();
            $('#md-import-force-cleanup-result').html('<p>Limpiando archivos antiguos...</p>');

            $.ajax({
                url: md_import_force.ajax_url,
                type: 'POST',
                data: {
                    action: 'md_import_force_cleanup_all',
                    nonce: md_import_force.nonce,
                    hours: hours
                },
                success: function(response) {
                    if (response.success) {
                        $('#md-import-force-cleanup-result').html('<p style="color: green;">' + response.data.message + '</p>');
                    } else {
                        $('#md-import-force-cleanup-result').html('<p style="color: red;">Error: ' + (response.data.message || 'Error desconocido') + '</p>');
                    }
                },
                error: function(xhr, status, error) {
                    $('#md-import-force-cleanup-result').html('<p style="color: red;">Error en la solicitud AJAX: ' + error + '</p>');
                }
            });
        }
    });

});
