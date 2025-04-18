jQuery(document).ready(function($) {
    // Add a prefilter to handle FormData objects properly and fix the settings.data.split error
    $.ajaxPrefilter(function(options, originalOptions, jqXHR) {
        // If data is FormData, ensure processData and contentType are set correctly
        if (options.data instanceof FormData) {
            options.processData = false;
            options.contentType = false;
        }

        // Fix for the settings.data.split error
        var oldBeforeSend = options.beforeSend;
        options.beforeSend = function(xhr, settings) {
            // Ensure settings.data is a string if it's not FormData
            if (settings.data && typeof settings.data !== 'string' && !(settings.data instanceof FormData)) {
                settings.data = $.param(settings.data);
            }

            // Call the original beforeSend if it exists
            if (oldBeforeSend) {
                return oldBeforeSend(xhr, settings);
            }
        };
    });

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

        $.ajax({
            url: md_import_force.ajax_url, // Usar ajax_url localizado
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
    });

    // Manejo del botón de importar (mantener lógica existente, añadir nonce)
    $('#md-import-force-form').on('submit', function(e) {
        e.preventDefault();

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

        // Función para actualizar la interfaz con los datos de progreso
        function updateProgressUI(progressData) {
            if (!progressData) return;

            console.log('Actualización de progreso recibida:', progressData);

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
            if (progressData.status === 'completed' || percent >= 100) {
                if (progressCheckInterval) {
                    clearInterval(progressCheckInterval);
                    progressCheckInterval = null;
                }
            }
        }

        // Función para consultar el progreso de la importación
        function checkImportProgress() {
            $.ajax({
                url: md_import_force.ajax_url,
                type: 'POST',
                data: {
                    action: 'md_import_force_check_progress',
                    nonce: md_import_force.nonce
                },
                success: function(response) {
                    if (response.success && response.data) {
                        updateProgressUI(response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error al consultar progreso:', error);
                }
            });
        }

        // Iniciar el intervalo de consulta de progreso (cada 1 segundo)
        progressCheckInterval = setInterval(checkImportProgress, 1000);

        $.ajax({
            url: md_import_force.ajax_url, // Usar ajax_url localizado
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            cache: false, // Evitar caché
            timeout: 0, // Sin tiempo límite
            beforeSend: function(xhr) {
                // Configurar cabeceras para evitar caché
                xhr.setRequestHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
                xhr.setRequestHeader('Pragma', 'no-cache');
                xhr.setRequestHeader('Expires', '0');
            },
            success: function(response) {
                // Detener el intervalo de consulta de progreso
                if (progressCheckInterval) {
                    clearInterval(progressCheckInterval);
                    progressCheckInterval = null;
                }

                // Limpiar la respuesta para obtener solo el JSON
                var cleanResponse = response;
                if (typeof response === 'string') {
                    try {
                        cleanResponse = JSON.parse(response);
                    } catch (e) {
                        console.error('Error al parsear respuesta JSON:', e);
                        cleanResponse = { success: false, message: 'Error al procesar la respuesta del servidor' };
                    }
                }

                // Ocultar elementos de progreso
                $('#md-import-force-progress').hide();
                $('#md-import-force-current-item').hide();
                $('#md-import-force-messages').empty();

                if (cleanResponse.success) {
                    var success_message = md_import_force.i18n.success; // Usar i18n
                    if (cleanResponse.data && cleanResponse.data.stats) {
                        success_message += '<br>Nuevos: ' + (cleanResponse.data.stats.new_count || 0) + ', ';
                        success_message += 'Actualizados: ' + (cleanResponse.data.stats.updated_count || 0) + ', ';
                        success_message += 'Omitidos: ' + (cleanResponse.data.stats.skipped_count || 0) + '.';
                        if (cleanResponse.data.message) {
                             success_message += '<br>' + cleanResponse.data.message;
                        }
                    }
                    $('#md-import-force-messages').html('<p style="color: green;">' + success_message + '</p>');
                } else {
                    $('#md-import-force-messages').html('<p style="color: red;">' + md_import_force.i18n.error + ': ' + ((cleanResponse.data && cleanResponse.data.message) || 'Error desconocido') + '</p>');
                }
            },
            error: function(xhr, status, error) {
                // Detener el intervalo de consulta de progreso
                if (progressCheckInterval) {
                    clearInterval(progressCheckInterval);
                    progressCheckInterval = null;
                }

                // Ocultar elementos de progreso
                $('#md-import-force-progress').hide();
                $('#md-import-force-current-item').hide();

                // Verificar si la respuesta es JSON y contiene datos de importación exitosa
                try {
                    var jsonResponse = JSON.parse(xhr.responseText.replace(/<progress-update>[\s\S]*?<\/progress-update>/g, ''));
                    if (jsonResponse && jsonResponse.success === true) {
                        // La importación fue exitosa a pesar del error AJAX
                        var success_message = md_import_force.i18n.success;
                        if (jsonResponse.data && jsonResponse.data.stats) {
                            success_message += '<br>Nuevos: ' + (jsonResponse.data.stats.new_count || 0) + ', ';
                            success_message += 'Actualizados: ' + (jsonResponse.data.stats.updated_count || 0) + ', ';
                            success_message += 'Omitidos: ' + (jsonResponse.data.stats.skipped_count || 0) + '.';
                            if (jsonResponse.data.message) {
                                success_message += '<br>' + jsonResponse.data.message;
                            }
                        } else if (jsonResponse.data && jsonResponse.data.message) {
                            success_message += '<br>' + jsonResponse.data.message;
                        }
                        $('#md-import-force-messages').html('<p style="color: green;">' + success_message + '</p>');
                        return;
                    }
                } catch (e) {
                    // No es JSON o no se puede analizar, continuar con el manejo de error normal
                    console.error('Error al parsear respuesta JSON en error handler:', e);
                }

                $('#md-import-force-messages').html('<p style="color: red;">' + md_import_force.i18n.error + ': ' + error + '</p>');
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

});
