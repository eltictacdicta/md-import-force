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
