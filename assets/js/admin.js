jQuery(document).ready(function($) {

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

        $.ajax({
            url: md_import_force.ajax_url, // Usar ajax_url localizado
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            xhr: function() {
                var xhr = new window.XMLHttpRequest();
                xhr.upload.addEventListener("progress", function(evt) {
                    if (evt.lengthComputable) {
                        var percentComplete = (evt.loaded / evt.total) * 100;
                        $('#md-import-force-progress .progress-bar').width(percentComplete + '%');
                    }
                }, false);
                return xhr;
            },
            success: function(response) {
                $('#md-import-force-progress').hide(); // Ocultar progreso
                $('#md-import-force-messages').empty();
                if (response.success) {
                    var success_message = md_import_force.i18n.success + '. '; // Usar i18n
                    if (response.data && response.data.stats) {
                        success_message += 'Nuevos: ' + (response.data.stats.new_count || 0) + ', ';
                        success_message += 'Actualizados: ' + (response.data.stats.updated_count || 0) + ', ';
                        success_message += 'Omitidos: ' + (response.data.stats.skipped_count || 0) + '.';
                        if (response.data.message) {
                             success_message += '<br>' + response.data.message;
                        }
                    }
                    $('#md-import-force-messages').html('<p style="color: green;">' + success_message + '</p>');
                } else {
                    $('#md-import-force-messages').html('<p style="color: red;">' + md_import_force.i18n.error + ': ' + (response.data.message || 'Error desconocido') + '</p>'); // Usar i18n y acceder a data.message
                }
            },
            error: function(xhr, status, error) {
                $('#md-import-force-progress').hide(); // Ocultar progreso

                // Verificar si la respuesta es JSON y contiene datos de importación exitosa
                try {
                    var jsonResponse = JSON.parse(xhr.responseText);
                    if (jsonResponse && jsonResponse.success === true) {
                        // La importación fue exitosa a pesar del error AJAX
                        var success_message = md_import_force.i18n.success + '. ';
                        if (jsonResponse.data && jsonResponse.data.stats) {
                            success_message += 'Nuevos: ' + (jsonResponse.data.stats.new_count || 0) + ', ';
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
                }

                $('#md-import-force-messages').html('<p style="color: green;">' + md_import_force.i18n.success + '</p>'); // Usar i18n
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
