/**
 * MD Import Force - Módulo Preview
 *
 * Contiene funciones para la previsualización de archivos.
 */

// Asegurar que el namespace principal existe
var MDImportForce = MDImportForce || {};

// Módulo Preview
MDImportForce.Preview = (function() {
    // Variables privadas
    var $ = null;
    var previewFilePath = ''; // Variable para almacenar la ruta del archivo de previsualización

    /**
     * Muestra la previsualización en la interfaz
     * @param {Object} previewData - Datos de la previsualización
     */
    function displayPreview(previewData) {
        // Guardar la ruta del archivo para usarla en la importación
        previewFilePath = previewData.file_path;

        $('#md-import-force-preview-area').show();
        var preview_content = '<h4>Información del sitio de origen:</h4>';

        // Mostrar URL del sitio de origen (detectada o proporcionada)
        var sourceUrl = previewData.site_info.site_url || 'No disponible - Se detectará automáticamente durante la importación';
        var urlClass = previewData.site_info.site_url ? '' : 'style="color: orange;"';
        preview_content += '<p><strong>URL:</strong> <span ' + urlClass + '>' + sourceUrl + '</span></p>';

        // Mostrar URL de destino
        preview_content += '<p><strong>URL de destino:</strong> ' + previewData.target_url + '</p>';

        // Mostrar nombre del sitio
        preview_content += '<p><strong>Nombre:</strong> ' + (previewData.site_info.site_name || 'No disponible') + '</p>';

        // Mostrar información sobre la sustitución de URLs
        preview_content += '<div style="margin: 15px 0; padding: 10px; background-color: #f8f8f8; border-left: 4px solid #46b450;">';
        preview_content += '<p><strong>Sustitución de URLs:</strong> Durante la importación, se reemplazarán automáticamente todas las URLs del sitio de origen por las URLs del sitio de destino en:</p>';
        preview_content += '<ul style="list-style-type: disc; margin-left: 20px;">';
        preview_content += '<li>Enlaces internos</li>';
        preview_content += '<li>Shortcodes de WordPress</li>';
        preview_content += '<li>Imágenes y otros medios</li>';
        preview_content += '</ul>';
        preview_content += '</div>';

        preview_content += '<h4>Primeros ' + previewData.preview_records.length + ' de ' + previewData.total_records + ' registros:</h4>';
        preview_content += '<ul>';
        previewData.preview_records.forEach(function(record) {
            preview_content += '<li>ID: ' + record.ID + ', Título: ' + record.post_title + ', Tipo: ' + record.post_type + '</li>';
        });
        preview_content += '</ul>';
        $('#md-import-force-preview-content').html(preview_content);
    }

    /**
     * Realiza la previsualización del archivo
     * @param {File} file - Archivo a previsualizar
     */
    function previewImportFile(file) {
        var formData = new FormData();
        formData.append('action', 'md_import_force_preview');
        formData.append('nonce', md_import_force.nonce);
        formData.append('import_file', file);

        // Mostrar indicador de carga
        MDImportForce.UI.showMessage(md_import_force.i18n.uploading, 'info');
        $('#md-import-force-preview-area').hide();
        $('#md-import-force-progress').hide();

        // Usar la función ajaxRequest del módulo Ajax
        MDImportForce.Ajax.ajaxRequest({
            url: md_import_force.ajax_url,
            type: 'POST',
            data: formData
        })
        .then(function(response) {
            $('#md-import-force-messages').empty();
            if (response.success) {
                displayPreview(response.data);
            } else {
                MDImportForce.UI.showErrorMessage(response.data);
            }
        })
        .catch(function(error) {
            console.error('Error en la previsualización:', error);
            MDImportForce.UI.showMessage(md_import_force.i18n.error + ': ' + (error.statusText || 'Error al procesar la respuesta del servidor'), 'error');
        });
    }

    /**
     * Configura los eventos de previsualización
     */
    function setupPreviewEvents() {
        $('#md-import-force-preview-button').on('click', function(e) {
            e.preventDefault();

            var file_input = $('#import_file')[0];
            if (file_input.files.length === 0) {
                alert('Por favor, selecciona un archivo JSON o ZIP para previsualizar.');
                return;
            }

            previewImportFile(file_input.files[0]);
        });
    }

    // Métodos públicos
    return {
        /**
         * Inicializa el módulo Preview
         * @param {Object} jQuery - Objeto jQuery
         */
        init: function(jQuery) {
            $ = jQuery;
            setupPreviewEvents();
            console.log('MD Import Force Preview inicializado');
        },

        // Exponer métodos para uso en otros módulos
        getPreviewFilePath: function() {
            return previewFilePath;
        }
    };
})();
