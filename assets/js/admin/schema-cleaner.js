/**
 * Funcionalidad para limpiar schema de Rank Math
 */
(function($) {
    'use strict';

    // Botón para limpiar schema
    $('#md-import-force-clean-schema').on('click', function() {
        var button = $(this);
        var resultArea = $('#md-import-force-schema-result');
        var postId = $('#schema_post_id').val();

        // Mostrar mensaje de carga
        resultArea.html('<div class="notice notice-info"><p>Limpiando schema de Rank Math...</p></div>');
        button.prop('disabled', true);

        // Realizar la solicitud AJAX
        $.ajax({
            url: md_import_force.ajax_url,
            type: 'POST',
            data: {
                action: 'md_import_force_clean_schema',
                nonce: md_import_force.nonce,
                post_id: postId
            },
            success: function(response) {
                if (response.success) {
                    resultArea.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                } else {
                    resultArea.html('<div class="notice notice-error"><p>Error: ' + (response.data.message || 'Error desconocido') + '</p></div>');
                }
            },
            error: function(xhr, status, error) {
                resultArea.html('<div class="notice notice-error"><p>Error en la solicitud AJAX: ' + error + '</p></div>');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });

})(jQuery);
