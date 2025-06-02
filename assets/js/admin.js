document.addEventListener('DOMContentLoaded', function() {
    // Define escapeHtml at a higher scope
    const escapeHtml = (unsafe) => {
        if (typeof unsafe !== 'string') {
            if (typeof unsafe === 'number' || typeof unsafe === 'boolean') {
                return String(unsafe);
            }
            return '';
        }
        return unsafe
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    };

    // ===== FUNCIONES AUXILIARES =====

    // Función para mostrar mensajes en la interfaz
    function showMessage(message, type, targetElement) {
        var color = type === 'success' ? 'green' : (type === 'error' ? 'red' : 'inherit');
        var targetSelector = targetElement || '#md-import-force-messages'; // Default target
        const messageContainer = document.querySelector(targetSelector);
        if (messageContainer) {
            // Sanitize message before inserting as HTML if it can contain user-generated content not intended as HTML
            // For simple text messages, this is okay. If 'message' could contain HTML, consider textContent or a sanitizer.
            messageContainer.innerHTML = '<p style="color: ' + color + '">' + message + '</p>';
        } else {
            console.error('Error: Target element ' + targetSelector + ' not found for showMessage. Message: ' + message);
            // As a fallback, you could use alert or log to a more generic console area
            // if the specific message area isn't found.
        }
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
        const progressElement = document.getElementById('md-import-force-progress');
        const currentItemElement = document.getElementById('md-import-force-current-item');
        
        if (show) {
            if (progressElement) progressElement.style.display = 'block';
            if (currentItemElement) currentItemElement.style.display = 'block';
        } else {
            if (progressElement) progressElement.style.display = 'none';
            if (currentItemElement) currentItemElement.style.display = 'none';
        }
    }

    // ===== FUNCIONES DE NAVEGACIÓN POR PESTAÑAS =====

    // Función para cambiar de pestaña
    function switchTab(tab) {
        // Desactivar todas las pestañas
        document.querySelectorAll('.nav-tab-wrapper a').forEach(function(el) {
            el.classList.remove('nav-tab-active');
        });
        
        // Activar la pestaña seleccionada
        const activeTab = document.querySelector('.nav-tab-wrapper a[data-tab="' + tab + '"]');
        if (activeTab) activeTab.classList.add('nav-tab-active');

        // Ocultar todos los contenidos
        document.querySelectorAll('.tab-content').forEach(function(el) {
            el.style.display = 'none';
        });
        
        // Mostrar el contenido seleccionado
        const tabContent = document.getElementById('tab-' + tab);
        if (tabContent) tabContent.style.display = 'block';

        // Si la pestaña del log está activa, cargar el log
        if (tab === 'log') {
            readErrorLog();
        }
    }

    // Manejo de pestañas
    document.querySelectorAll('.nav-tab-wrapper a').forEach(function(tabLink) {
        tabLink.addEventListener('click', function(e) {
            e.preventDefault();
            const tab = this.getAttribute('data-tab');
            switchTab(tab);
        });
    });

    // ===== FUNCIONES DE API FETCH =====

    // Función para realizar peticiones Fetch a la REST API
    function apiFetch(endpoint, options = {}) {
        // Asegurarse de que options.headers existe
        options.headers = options.headers || {};
        
        // Añadir el nonce para seguridad de la API REST
        options.headers['X-WP-Nonce'] = md_import_force.rest_nonce;
        
        // URL completa
        const url = md_import_force.rest_url + endpoint;
        
        // Realizar la petición
        return fetch(url, options)
            .then(response => {
                if (!response.ok) {
                    // Intentar obtener el mensaje de error del cuerpo JSON
                    return response.json()
                        .then(errorData => {
                            throw new Error(errorData.message || `Error HTTP: ${response.status}`);
                        })
                        .catch(err => {
                            // Si no es JSON o falla al parsear, usar el status
                            throw new Error(`Error HTTP: ${response.status} ${response.statusText}`);
                        });
                }
                return response.json();
            });
    }

    // ===== FUNCIONES DE PREVISUALIZACIÓN =====

    // Variable global para almacenar la ruta del archivo de previsualización
    var previewFilePath = '';

    // Función para mostrar la previsualización
    function displayPreview(previewData) {
        // Guardar la ruta del archivo para usarla en la importación
        previewFilePath = previewData.file_path;

        const previewArea = document.getElementById('md-import-force-preview-area');
        const previewContent = document.getElementById('md-import-force-preview-content');
        
        if (previewArea) previewArea.style.display = 'block';
        
        if (previewContent) {
            let content = '<h4>Información del sitio de origen:</h4>';
            content += '<p>URL: ' + escapeHtml(previewData.site_info.site_url) + '</p>';
            content += '<p>Nombre: ' + escapeHtml(previewData.site_info.site_name) + '</p>';
            
            // Mostrar información del sitio actual
            if (previewData.current_site_info) {
                content += '<h4>Información del sitio de destino (actual):</h4>';
                content += '<p>URL: ' + escapeHtml(previewData.current_site_info.site_url) + '</p>';
                content += '<p>Nombre: ' + escapeHtml(previewData.current_site_info.site_name) + '</p>';
            }
            
            // Mostrar información de reemplazo de URLs
            if (previewData.url_replacement_info) {
                content += '<h4>Reemplazo de URLs:</h4>';
                if (previewData.url_replacement_info.will_replace) {
                    content += '<p style="color: blue;">✓ Se reemplazarán las URLs del contenido:</p>';
                    content += '<p><strong>Desde:</strong> ' + escapeHtml(previewData.url_replacement_info.source_url) + '</p>';
                    content += '<p><strong>Hacia:</strong> ' + escapeHtml(previewData.url_replacement_info.target_url) + '</p>';
                } else if (previewData.url_replacement_info.source_url === previewData.url_replacement_info.target_url) {
                    content += '<p style="color: green;">✓ Las URLs del sitio de origen y destino son iguales, no se requiere reemplazo.</p>';
                } else {
                    content += '<p style="color: orange;">⚠ No se detectó URL del sitio de origen, no se realizará reemplazo de URLs.</p>';
                }
            }
            
            // Generar mensaje resumen usando los nuevos datos del backend
            let summaryMessage = '';
            const totalInFile = previewData.total_records_in_file;
            const totalMissing = previewData.total_missing_in_file;
            const totalExisting = previewData.total_existing_in_file;
            const countInPreview = previewData.preview_records.length;

            if (totalInFile === 0) {
                summaryMessage = '<p style="color: orange;">El archivo no contiene posts para importar.</p>';
            } else if (totalMissing === 0) {
                summaryMessage = '<p style="color: green;">¡Excelente! Parece que los ' + totalInFile + ' post(s) del archivo ya han sido importados previamente.</p>';
            } else {
                summaryMessage = '<p style="color: blue;">El archivo contiene un total de ' + totalInFile + ' post(s).</p>';
                summaryMessage += '<p style="color: orange;">De estos, ' + totalExisting + ' post(s) ya existen en la base de datos.</p>';
                summaryMessage += '<p style="color: green;">Quedan ' + totalMissing + ' post(s) por importar.</p>';
                if (countInPreview > 0) {
                    summaryMessage += '<p>A continuación se muestran los primeros ' + countInPreview + ' de los posts que faltan por importar:</p>';
                } else {
                    summaryMessage += '<p style="color: orange;">No hay más posts que falten por importar para mostrar en esta previsualización (aunque se detectaron ' + totalMissing + ' faltantes en total).</p>';
                }
            }
            content += summaryMessage; // Añadir el mensaje resumen al contenido

            if (countInPreview > 0) {
                content += '<h4>Primeros ' + escapeHtml(String(countInPreview)) + ' de ' + escapeHtml(String(totalMissing)) + ' posts que faltan por importar:</h4>';
                content += '<ul>';
                previewData.preview_records.forEach(function(record) {
                    // Como ahora solo mostramos los que faltan, no es necesario el `existingIndicator`
                    content += '<li>ID: ' + escapeHtml(String(record.ID)) +
                               ', Título: ' + escapeHtml(record.post_title) +
                               ', Tipo: ' + escapeHtml(record.post_type) + '</li>';
                });
                content += '</ul>';
            }

            const optionsWrapper = document.getElementById('md-import-force-options-wrapper');
            const onlyMissingCheckbox = document.getElementById('import_only_missing');
            const handleAttachmentsCheckbox = document.getElementById('handle_attachments');
            const generateThumbnailsCheckbox = document.getElementById('generate_thumbnails');
            const forceIdsCheckbox = document.getElementById('force_ids');
            const forceAuthorCheckbox = document.getElementById('force_author');

            if (optionsWrapper) {
                if (previewData.total_missing_in_file > 0) {
                    // Mostrar opciones cuando hay posts para importar
                    optionsWrapper.style.display = 'block';
                    
                    // Configurar opciones según si existen posts o no
                    if (previewData.total_existing_in_file === 0) {
                        // No hay posts existentes - importación nueva
                        // Marcar: handle_attachments, generate_thumbnails, force_ids
                        // Ocultar: import_only_missing
                        if (handleAttachmentsCheckbox) handleAttachmentsCheckbox.checked = true;
                        if (generateThumbnailsCheckbox) generateThumbnailsCheckbox.checked = true;
                        if (forceIdsCheckbox) forceIdsCheckbox.checked = true;
                        if (forceAuthorCheckbox) forceAuthorCheckbox.checked = false;
                        
                        // Ocultar la opción import_only_missing
                        if (onlyMissingCheckbox) {
                            onlyMissingCheckbox.checked = false;
                            const onlyMissingLabel = onlyMissingCheckbox.closest('label');
                            if (onlyMissingLabel) {
                                onlyMissingLabel.style.display = 'none';
                                // También ocultar la descripción siguiente si existe
                                const nextP = onlyMissingLabel.nextElementSibling;
                                if (nextP && nextP.tagName === 'P' && nextP.classList.contains('description')) {
                                    nextP.style.display = 'none';
                                }
                            }
                        }
                    } else {
                        // Hay posts existentes - importación parcial
                        // Marcar: handle_attachments, generate_thumbnails, force_ids, import_only_missing
                        // Mostrar: import_only_missing (marcada por defecto)
                        if (handleAttachmentsCheckbox) handleAttachmentsCheckbox.checked = true;
                        if (generateThumbnailsCheckbox) generateThumbnailsCheckbox.checked = true;
                        if (forceIdsCheckbox) forceIdsCheckbox.checked = true;
                        if (forceAuthorCheckbox) forceAuthorCheckbox.checked = false;
                        
                        // Mostrar y marcar import_only_missing por defecto
                        if (onlyMissingCheckbox) {
                            onlyMissingCheckbox.checked = true; // Marcada por defecto en importación parcial
                            const onlyMissingLabel = onlyMissingCheckbox.closest('label');
                            if (onlyMissingLabel) {
                                onlyMissingLabel.style.display = 'block'; // Mostrar la opción
                                // También mostrar la descripción siguiente si existe
                                const nextP = onlyMissingLabel.nextElementSibling;
                                if (nextP && nextP.tagName === 'P' && nextP.classList.contains('description')) {
                                    nextP.style.display = 'block';
                                }
                            }
                        }
                    }
                } else {
                    // No hay posts para importar
                    optionsWrapper.style.display = 'none';
                    if (onlyMissingCheckbox) onlyMissingCheckbox.checked = false;
                }
            }

            previewContent.innerHTML = content;
        }
    }

    // Función para realizar la previsualización
    function previewImportFile(file) {
        const formData = new FormData();
        formData.append('import_file', file);

        // Mostrar indicador de carga
        showMessage(md_import_force.i18n.uploading, 'info');
        
        const previewArea = document.getElementById('md-import-force-preview-area');
        const progressArea = document.getElementById('md-import-force-progress');
        
        if (previewArea) previewArea.style.display = 'none';
        if (progressArea) progressArea.style.display = 'none';

        // Usar Fetch API para la previsualización
        apiFetch('preview', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            const messagesElement = document.getElementById('md-import-force-messages');
            if (messagesElement) messagesElement.innerHTML = '';
            
            if (response.success) {
                displayPreview(response.data || response);
            } else {
                showErrorMessage(response.data || response);
            }
        })
        .catch(error => {
            console.error('Error en la previsualización:', error.message);
            showMessage(md_import_force.i18n.error + ': ' + error.message, 'error');
        });
    }

    // Manejo del botón de previsualización
    const previewButton = document.getElementById('md-import-force-preview-button');
    if (previewButton) {
        previewButton.addEventListener('click', function(e) {
            e.preventDefault();

            const fileInput = document.getElementById('import_file');
            if (fileInput && fileInput.files.length === 0) {
                alert('Por favor, selecciona un archivo JSON o ZIP para previsualizar.');
                return;
            }

            previewImportFile(fileInput.files[0]);
        });
    }

    // Manejo del formulario de importación
    const importForm = document.getElementById('md-import-force-form');
    if (importForm) {
        importForm.addEventListener('submit', function(e) {
            e.preventDefault();

            // Guardar el tiempo de inicio de la importación
            this.dataset.startTime = new Date().getTime() / 1000;
            window.currentImportId = null; // Variable global para almacenar el ID de la importación actual

            // Verificar si tenemos un archivo para importar
            if (!previewFilePath) {
                alert('Por favor, primero previsualiza un archivo antes de importar.');
                return;
            }

            // Construct a JavaScript object for the JSON payload
            const payload = {
                file_path: previewFilePath
            };

            // Añadir la opción de importar solo faltantes si el checkbox está presente y marcado
            const onlyMissingCheckboxForm = document.getElementById('import_only_missing');
            if (onlyMissingCheckboxForm) { // Check if element exists
                payload.import_only_missing = onlyMissingCheckboxForm.checked ? '1' : '0';
            } else {
                payload.import_only_missing = '0'; // Default if checkbox not found
            }

            // Obtener otras opciones del formulario si es necesario
            // Ejemplo: const otherOption = document.getElementById('other_option_id').value;
            // if (otherOption) payload.other_option = otherOption;
            
            // >>> OBTENER TODAS LAS OPCIONES DEL FORMULARIO (checkboxes) <<<
            const optionsCheckboxes = document.querySelectorAll('#md-import-force-options-wrapper input[type="checkbox"]');
            optionsCheckboxes.forEach(checkbox => {
                // Usa el 'name' del checkbox como clave en el payload
                // y su estado 'checked' (true/false) como valor, convertido a '1' o '0'.
                // Asegúrate de que 'import_only_missing' se maneje como arriba si tiene lógica especial
                // o si su ID no coincide con su 'name' property.
                if (checkbox.id !== 'import_only_missing') { // ya manejado
                    payload[checkbox.name] = checkbox.checked ? '1' : '0';
                }
            });

            // Ocultar previsualización y mostrar mensajes/progreso
            const previewArea = document.getElementById('md-import-force-preview-area');
            const messagesArea = document.getElementById('md-import-force-messages');
            
            if (previewArea) previewArea.style.display = 'none';
            if (messagesArea) messagesArea.innerHTML = '<p>' + md_import_force.i18n.importing + '</p>';
            
            initializeProgressUI();

            let progressCheckInterval = null;
            let importCompletedProcessed = false;

            // Variables para manejar reintentos en caso de errores 504
            var progressCheckRetryCount = 0;
            var maxProgressCheckRetries = 5;
            var progressCheckRetryDelay = 2000; // 2 segundos inicial
            var maxProgressCheckDelay = 30000; // Máximo 30 segundos entre verificaciones

            apiFetch('import', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    // X-WP-Nonce is already added by apiFetch function
                },
                body: JSON.stringify(payload) // Send data as JSON
            })
            .then(response => {
                if (response.success && response.import_id && response.status === 'queued') {
                    window.currentImportId = response.import_id;
                    showMessage(response.message, 'info');
                    progressCheckInterval = setInterval(checkImportProgress, 2000);
                } else {
                    showErrorMessage(response.message || md_import_force.i18n.error + ": No se pudo programar la importación.");
                    toggleProgressElements(false);
                }
            })
            .catch(error => {
                console.error('Error al iniciar importación (programación):', error.message);
                showMessage(md_import_force.i18n.error + ': ' + error.message, 'error');
                toggleProgressElements(false);
            });

            function initializeProgressUI() {
                toggleProgressElements(true);
                document.getElementById('current-item-info').textContent = md_import_force.i18n.importing;
                document.getElementById('progress-count').textContent = '0';
                document.getElementById('progress-total').textContent = '0';
                document.getElementById('progress-percent').textContent = '0%';
            }

            function checkImportProgress() {
                if (!window.currentImportId) {
                    console.warn('checkImportProgress llamado sin currentImportId');
                    return;
                }

                apiFetch(`progress?import_id=${encodeURIComponent(window.currentImportId)}`, {
                    method: 'GET'
                })
                .then(progressData => {
                    // Resetear contador de reintentos en caso de éxito
                    progressCheckRetryCount = 0;
                    progressCheckRetryDelay = 2000;
                    
                    if (importCompletedProcessed && progressData.status === 'completed' && progressData.percent === 100) {
                        return;
                    }
                    
                    updateProgressUI(progressData);

                    if (progressData.status === 'completed' || progressData.status === 'failed' || progressData.status === 'completed_with_errors' || progressData.status === 'stopped') {
                        importCompletedProcessed = true;
                        clearInterval(progressCheckInterval);
                        finalizeImport(progressData);
                    }
                })
                .catch(error => {
                    console.error('Error al verificar progreso:', error.message);
                    
                    // Manejar errores 504 específicamente
                    if (error.message.includes('504') || error.message.includes('Gateway Timeout')) {
                        progressCheckRetryCount++;
                        
                        if (progressCheckRetryCount <= maxProgressCheckRetries) {
                            console.log(`Error 504 detectado. Reintento ${progressCheckRetryCount}/${maxProgressCheckRetries} en ${progressCheckRetryDelay}ms`);
                            
                            // Mostrar mensaje informativo al usuario
                            const currentItemElement = document.getElementById('md-import-force-current-item');
                            if (currentItemElement) {
                                currentItemElement.textContent = `El servidor está ocupado (error 504). Reintentando verificación en ${progressCheckRetryDelay/1000}s... (${progressCheckRetryCount}/${maxProgressCheckRetries})`;
                            }
                            
                            // Programar reintento con delay progresivo
                            setTimeout(() => {
                                checkImportProgress();
                            }, progressCheckRetryDelay);
                            
                            // Aumentar el delay para el próximo reintento (backoff exponencial)
                            progressCheckRetryDelay = Math.min(progressCheckRetryDelay * 1.5, maxProgressCheckDelay);
                        } else {
                            // Demasiados reintentos, mostrar error pero continuar verificando más lentamente
                            console.warn('Demasiados errores 504 consecutivos. Continuando con verificaciones menos frecuentes.');
                            
                            const currentItemElement = document.getElementById('md-import-force-current-item');
                            if (currentItemElement) {
                                currentItemElement.textContent = 'El servidor está sobrecargado. Verificando progreso con menos frecuencia...';
                            }
                            
                            // Cambiar a verificaciones menos frecuentes
                            clearInterval(progressCheckInterval);
                            progressCheckInterval = setInterval(checkImportProgress, 15000); // Cada 15 segundos
                            progressCheckRetryCount = 0; // Resetear para el próximo ciclo
                            progressCheckRetryDelay = 5000; // Delay más largo para cuando recomience
                        }
                    } else {
                        // Para otros tipos de errores, manejar normalmente
                        const currentItemElement = document.getElementById('md-import-force-current-item');
                        if (currentItemElement) {
                            currentItemElement.textContent = `Error verificando progreso: ${error.message}`;
                        }
                    }
                });
            }

            function updateProgressUI(progressData) {
                if (!progressData) return;
                
                const progressElement = document.getElementById('md-import-force-progress');
                const progressBar = progressElement ? progressElement.querySelector('.progress-bar') : null;
                
                const progressCountElement = document.getElementById('progress-count');
                const progressTotalElement = document.getElementById('progress-total');
                const progressPercentElement = document.getElementById('progress-percent');
                const currentItemElement = document.getElementById('current-item-info');
                const overallMessageElement = document.getElementById('md-import-force-current-item');
                
                // Mostrar los elementos de progreso
                toggleProgressElements(true);
                
                let percentComplete = 0;
                const totalCount = parseInt(progressData.total_count, 10) || 0;
                const processedCount = parseInt(progressData.processed_count, 10) || 0;

                if (totalCount > 0) {
                    percentComplete = Math.round((processedCount / totalCount) * 100);
                }
                
                // Actualizar barra de progreso
                if (progressBar) {
                    progressBar.style.width = percentComplete + '%';
                }
                
                // Actualizar textos de progreso
                if (progressCountElement) progressCountElement.textContent = processedCount;
                if (progressTotalElement) progressTotalElement.textContent = totalCount;
                if (progressPercentElement) progressPercentElement.textContent = percentComplete + '%';
                
                // Actualizar el ID de importación global para que otros scripts puedan acceder a él
                window.currentImportId = progressData.import_id || null;
                
                // Determinar estado de la importación
                const status = progressData.status || '';

                // Actualizar el mensaje principal del progreso
                if (status === 'stopped') {
                    if (currentItemElement) {
                        currentItemElement.textContent = md_import_force.i18n.import_process_stopped_message || "Proceso de importación detenido.";
                    }
                } else {
                    const defaultMessage = progressData.status === 'queued' ? (md_import_force.i18n.import_queued_message || 'Importación en cola...') : (md_import_force.i18n.processing_message || 'Procesando...');
                    const itemMessage = progressData.current_item_message || defaultMessage;
                    if (currentItemElement) {
                         currentItemElement.textContent = itemMessage;
                    }
                }
                
                // Dispatch custom event for other scripts to respond to progress updates
                const progressEvent = new CustomEvent('md_import_force_progress_update', {
                    detail: progressData
                });
                document.dispatchEvent(progressEvent);
                
                // Handle completion states
                if (status === 'completed' || status === 'failed' || status === 'stopped') {
                    finalizeImport(progressData);
                }
            }

            function finalizeImport(progressData) {
                const elapsedTime = getImportElapsedTime();
                const elapsedMinutes = Math.floor(elapsedTime / 60);
                const elapsedSeconds = Math.floor(elapsedTime % 60);
                
                let finalMessageText = '';
                let messageType = 'success'; // Default to success

                if (progressData.status === 'stopped') {
                    finalMessageText = md_import_force.i18n.import_stopped_by_user || 'Importación detenida por el usuario.';
                    messageType = 'warning'; 
                } else if (progressData.status === 'failed') {
                    finalMessageText = md_import_force.i18n.import_failed || 'La importación falló.';
                    messageType = 'error';
                } else if (progressData.status === 'completed_with_errors') {
                    finalMessageText = md_import_force.i18n.import_completed_with_errors || 'Importación completada con errores.';
                    messageType = 'warning';
                } else { // Assumed 'completed' status
                    finalMessageText = md_import_force.i18n.completed || 'Importación completada.';
                    // messageType remains 'success'
                }

                let fullMessage = finalMessageText;
                fullMessage += `<br>Tiempo total: ${elapsedMinutes}m ${elapsedSeconds}s<br>`;
                fullMessage += `Elementos procesados: ${progressData.processed_count} de ${progressData.total_count}`;
                
                if (progressData.stats) {
                    fullMessage += `<br>Nuevos: ${progressData.stats.new_count || 0}`;
                    fullMessage += `, Actualizados: ${progressData.stats.updated_count || 0}`;
                    fullMessage += `, Omitidos: ${progressData.stats.skipped_count || 0}`;
                }
                
                // Append specific error/details message from backend if available and relevant
                if (progressData.message && (progressData.status === 'failed' || progressData.status === 'completed_with_errors' || progressData.status === 'stopped')) {
                    // Using the globally scoped escapeHtml function
                    fullMessage += `<br>${md_import_force.i18n.details || 'Detalles'}: ${escapeHtml(progressData.message)}`;
                }

                showMessage(fullMessage, messageType);
            }
        });
    }

    // ===== FUNCIONES DE LOG =====

    // Función para actualizar el contenido del log
    function updateLogContent(content) {
        const logContainer = document.getElementById('md-import-force-log-content');
        if (logContainer) {
            // Assuming log content should be displayed as plain text within a pre tag
            const preElement = document.createElement('pre');
            preElement.textContent = content; // Safely sets text content
            logContainer.innerHTML = ''; // Clear previous content
            logContainer.appendChild(preElement);
        }
    }

    // Función para leer el log de errores
    function readErrorLog() {
        apiFetch('log', {
            method: 'GET'
        })
        .then(response => {
            // Clear previous messages in the log-specific message area
            const logMessagesContainer = document.querySelector('#md-import-force-log-messages');
            if (logMessagesContainer) logMessagesContainer.innerHTML = '';

            if (response.success && typeof response.log_content === 'string') {
                updateLogContent(response.log_content);
                if (response.log_content.trim() === '' || response.log_content.trim() === md_import_force.i18n.empty_log_message) { // Check against localized empty message too
                    // md_import_force.i18n.empty_log_message should be defined in wp_localize_script if you use a specific message from PHP for empty log
                    // For now, let's assume md_import_force.i18n.empty_log_message is 'El log está vacío.'
                    showMessage(md_import_force.i18n.empty_log_message || 'El log está vacío.', 'info', '#md-import-force-log-messages');
                }
            } else if (!response.success && typeof response.log_content === 'string') {
                // Handle cases where success is false but log_content might contain an error message from the server
                updateLogContent(response.log_content); // Display the server's message (e.g., "permission denied") in the log area
                showMessage(response.log_content, 'error', '#md-import-force-log-messages');
            } else {
                // Generic fallback for other unexpected structures or if response.success is false without a specific message in log_content
                const message = (response.data && response.data.message) || response.message || 'El log está vacío o no se pudo leer.';
                updateLogContent(message); // Show message in log area
                showMessage(message, 'error', '#md-import-force-log-messages'); 
            }
        })
        .catch(error => {
            console.error('Error al leer log:', error.message);
            const errorMessage = 'Error al leer el log: ' + error.message;
            updateLogContent(errorMessage);
            showMessage(errorMessage, 'error', '#md-import-force-log-messages');
        });
    }

    // Función para limpiar el log de errores
    function clearErrorLog() {
        apiFetch('log', {
            method: 'DELETE'
        })
        .then(response => {
            if (response.success) {
                updateLogContent(''); // Clear the visual log display
                // response.message should come from the PHP backend (handle_rest_clear_log)
                showMessage(response.message || 'Log limpiado correctamente.', 'success', '#md-import-force-log-messages');
            } else {
                const errorMessage = 'Error al limpiar el log: ' + (response.message || (response.data && (response.data.message || response.data.log_content)) || 'Error desconocido');
                showMessage(errorMessage, 'error', '#md-import-force-log-messages');
            }
        })
        .catch(error => {
            const errorMessage = 'Error al limpiar el log: ' + error.message;
            showMessage(errorMessage, 'error', '#md-import-force-log-messages');
        });
    }

    // Botón de limpiar log
    const clearLogButton = document.getElementById('md-import-force-clear-log');
    if (clearLogButton) {
        clearLogButton.addEventListener('click', function(e) {
            e.preventDefault();
            clearErrorLog();
        });
    }

    // ===== FUNCIONES DE LIMPIEZA =====

    // Función para actualizar el resultado de limpieza
    function updateCleanupResult(message, type) {
        showMessage(message, type, '#md-import-force-cleanup-message');
    }

    // Función para limpiar archivos
    function cleanupFiles(hours) {
        const formData = new FormData();
        formData.append('hours', hours);

        apiFetch('cleanup', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            if (response.success) {
                updateCleanupResult(response.message, 'success');
            } else {
                updateCleanupResult(response.message || 'Error al limpiar archivos', 'error');
            }
        })
        .catch(error => {
            console.error('Error al limpiar archivos:', error.message);
            updateCleanupResult('Error al limpiar archivos: ' + error.message, 'error');
        });
    }

    // Botón de limpieza
    const cleanupButton = document.getElementById('md-import-force-cleanup-button');
    if (cleanupButton) {
        cleanupButton.addEventListener('click', function(e) {
            e.preventDefault();
            const hoursInput = document.getElementById('md-import-force-cleanup-hours');
            const hours = hoursInput ? parseInt(hoursInput.value) : 24;
            
            if (isNaN(hours) || hours < 1) {
                alert('Por favor, introduce un número válido de horas (mínimo 1).');
                return;
            }
            
            cleanupFiles(hours);
        });
    }

    // ===== UTILIDADES =====

    // Función para obtener el tiempo transcurrido desde el inicio de la importación
    function getImportElapsedTime() {
        const importForm = document.getElementById('md-import-force-form');
        if (!importForm || !importForm.dataset.startTime) {
            return 0;
        }
        
        const startTime = parseFloat(importForm.dataset.startTime);
        const currentTime = new Date().getTime() / 1000;
        return currentTime - startTime;
    }

    // Activar la primera pestaña por defecto
    const defaultTab = document.querySelector('.nav-tab-wrapper a');
    if (defaultTab) {
        const tabName = defaultTab.getAttribute('data-tab');
        switchTab(tabName);
    }
});
