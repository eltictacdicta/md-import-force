/**
 * MD Import Force - Script de detención de importaciones
 */

document.addEventListener('DOMContentLoaded', function() {
    console.log('MD Import Force Stop Script: Initializing...'); // Added debug logging
    
    // Crear e insertar el botón de detener importaciones en el contenedor
    const stopButtonContainer = document.getElementById('md-import-controls-container');
    if (stopButtonContainer) {
        const stopButton = document.createElement('button');
        stopButton.type = 'button';
        stopButton.id = 'md-import-force-stop-button';
        stopButton.className = 'button button-secondary button-large';
        stopButton.textContent = mdImportForceSettings.i18n.stop_button_text;
        stopButton.style.display = 'none'; // Oculto por defecto
        
        // Insertar el botón en el DOM
        stopButtonContainer.appendChild(stopButton);
        
        // Asignar el evento click al botón
        stopButton.addEventListener('click', function() {
            if (confirm(mdImportForceSettings.i18n.confirm_stop)) {
                stopCurrentImport();
            }
        });
        
        console.log('MD Import Force Stop Script: Stop button created and added to container');
    } else {
        console.warn('MD Import Force Stop Script: Stop button container not found!');
    }

    /**
     * Muestra u oculta el botón de detener importación según el estado
     * @param {boolean} show - Si debe mostrarse el botón
     */
    function toggleStopButton(show) {
        const stopButton = document.getElementById('md-import-force-stop-button');
        if (stopButton) {
            stopButton.style.display = show ? 'inline-block' : 'none';
            console.log('MD Import Force Stop Script: Button visibility set to ' + (show ? 'visible' : 'hidden'));
        } else {
            console.warn('MD Import Force Stop Script: Stop button not found when trying to toggle visibility');
        }
    }

    /**
     * Envía la solicitud para detener todas las importaciones en curso
     */
    function stopCurrentImport() {
        // Mostrar mensaje de estado
        const messagesContainer = document.getElementById('md-import-force-messages');
        if (messagesContainer) {
            messagesContainer.innerHTML = '<div class="md-import-force-message info">' + 
                                         mdImportForceSettings.i18n.stopping + 
                                         '</div>';
        }

        // Desactivar el botón durante la solicitud
        const stopButton = document.getElementById('md-import-force-stop-button');
        if (stopButton) {
            stopButton.disabled = true;
        }

        // Intentar obtener el currentImportId desde window o el admin.js
        const currentImportId = window.currentImportId || '';
        console.log('MD Import Force Stop Script: Stopping import with ID:', currentImportId);
        console.log('MD Import Force Stop Script: REST URL endpoint:', mdImportForceSettings.rest_url_stop_imports);

        // Realizar la petición al endpoint REST API para detener importaciones
        wp.apiFetch({
            path: mdImportForceSettings.rest_url_stop_imports,  // Changed to use localized variable
            method: 'POST',
            data: currentImportId ? { import_id: currentImportId } : {}  // Always send at least an empty object
        }).then(response => {
            console.log('MD Import Force Stop Script: Stop request successful', response);
            if (response && response.success) {
                // Mostrar mensaje de éxito
                if (messagesContainer) {
                    messagesContainer.innerHTML = '<div class="md-import-force-message success">' + 
                                                response.message + 
                                                '</div>';
                }
                
                // Si existe un elemento de información de ítem actual, mostrar allí también
                const currentItemInfo = document.getElementById('current-item-info');
                if (currentItemInfo) {
                    currentItemInfo.textContent = response.message || mdImportForceSettings.i18n.success;
                }
            } else {
                // Mostrar mensaje de error
                if (messagesContainer) {
                    messagesContainer.innerHTML = '<div class="md-import-force-message error">' + 
                                                (response.message || mdImportForceSettings.i18n.error) + 
                                                '</div>';
                }
            }
        }).catch(error => {
            console.error('Error al detener importación:', error);
            
            // Mostrar mensaje de error
            if (messagesContainer) {
                messagesContainer.innerHTML = '<div class="md-import-force-message error">' + 
                                            mdImportForceSettings.i18n.error + 
                                            '</div>';
            }
        }).finally(() => {
            // Restaurar el botón
            if (stopButton) {
                stopButton.disabled = false;
            }
        });
    }

    // Evento personalizado para monitorear cambios en el progreso y mostrar/ocultar el botón
    document.addEventListener('md_import_force_progress_update', function(e) {
        console.log('MD Import Force Stop Script: Progress update event received', e.detail);
        const progressData = e.detail;
        
        // Mostrar el botón solo si hay una importación en progreso
        if (progressData && (progressData.status === 'processing' || 
                           progressData.status === 'queued' || 
                           progressData.status === 'starting')) {
            toggleStopButton(true);
        } else {
            toggleStopButton(false);
        }
    });

    // Observar cambios en el elemento de progreso para activar el botón
    const progressElement = document.getElementById('md-import-force-progress');
    if (progressElement) {
        const progressObserver = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                console.log('MD Import Force Stop Script: Progress element mutation observed', 
                           mutation.target.style.display);
                if (mutation.target.style.display !== 'none') {
                    toggleStopButton(true);
                } else {
                    toggleStopButton(false);
                }
            });
        });
        
        progressObserver.observe(progressElement, { attributes: true, attributeFilter: ['style'] });
        console.log('MD Import Force Stop Script: Progress observer attached');
    } else {
        console.warn('MD Import Force Stop Script: Progress element not found!');
    }
    
    // Force show the button when importing starts - bind to the import button
    const importButton = document.getElementById('md-import-force-import-button');
    if (importButton) {
        importButton.addEventListener('click', function() {
            // Show the stop button when import is clicked
            setTimeout(function() {
                toggleStopButton(true);
                console.log('MD Import Force Stop Script: Showing button after import click');
            }, 500); // Short delay to allow other processes to start
        });
        console.log('MD Import Force Stop Script: Import button event listener attached');
    }
}); 