document.addEventListener('DOMContentLoaded', function() {
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
            // Helper for escaping HTML
            const escapeHtml = (unsafe) => {
                if (typeof unsafe !== 'string') {
                    // Attempt to convert to string if not already, e.g. for numbers like record.ID
                    if (typeof unsafe === 'number' || typeof unsafe === 'boolean') {
                        return String(unsafe);
                    }
                    return ''; // Or handle other types as needed, returning empty for undefined/null
                }
                return unsafe
                     .replace(/&/g, "&amp;")
                     .replace(/</g, "&lt;")
                     .replace(/>/g, "&gt;")
                     .replace(/"/g, "&quot;")
                     .replace(/'/g, "&#039;");
            }

            let content = '<h4>Información del sitio de origen:</h4>';
            content += '<p>URL: ' + escapeHtml(previewData.site_info.site_url) + '</p>';
            content += '<p>Nombre: ' + escapeHtml(previewData.site_info.site_name) + '</p>';
            content += '<h4>Primeros ' + escapeHtml(String(previewData.preview_records.length)) + ' de ' + escapeHtml(String(previewData.total_records)) + ' registros:</h4>';
            content += '<ul>';
            
            previewData.preview_records.forEach(function(record) {
                content += '<li>ID: ' + escapeHtml(String(record.ID)) + 
                           ', Título: ' + escapeHtml(record.post_title) + 
                           ', Tipo: ' + escapeHtml(record.post_type) + '</li>';
            });
            
            content += '</ul>';
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

            const formData = new FormData(this);
            formData.append('file_path', previewFilePath);

            // Ocultar previsualización y mostrar mensajes/progreso
            const previewArea = document.getElementById('md-import-force-preview-area');
            const messagesArea = document.getElementById('md-import-force-messages');
            
            if (previewArea) previewArea.style.display = 'none';
            if (messagesArea) messagesArea.innerHTML = '<p>' + md_import_force.i18n.importing + '</p>';
            
            initializeProgressUI();

            let progressCheckInterval = null;
            let importCompletedProcessed = false;

            apiFetch('import', {
                method: 'POST',
                body: formData
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
                    if (importCompletedProcessed && progressData.status === 'completed' && progressData.percent === 100) {
                        return;
                    }
                    
                    updateProgressUI(progressData);

                    if (progressData.status === 'completed' || progressData.status === 'failed' || progressData.status === 'completed_with_errors') {
                        importCompletedProcessed = true;
                        clearInterval(progressCheckInterval);
                        finalizeImport(progressData);
                    }
                })
                .catch(error => {
                    console.error('Error al verificar progreso:', error.message);
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
                
                // Mostrar los elementos de progreso
                toggleProgressElements(true);
                
                let percentComplete = 0;
                
                if (progressData && progressData.total && progressData.total > 0) {
                    percentComplete = Math.round((progressData.current / progressData.total) * 100);
                }
                
                // Actualizar barra de progreso
                if (progressBar) {
                    progressBar.style.width = percentComplete + '%';
                }
                
                // Actualizar textos de progreso
                if (progressCountElement) progressCountElement.textContent = progressData.current || '0';
                if (progressTotalElement) progressTotalElement.textContent = progressData.total || '0';
                if (progressPercentElement) progressPercentElement.textContent = percentComplete + '%';
                if (currentItemElement) currentItemElement.textContent = progressData.current_item || 'Procesando...';
                
                // Actualizar el ID de importación global para que otros scripts puedan acceder a él
                window.currentImportId = progressData.import_id || null;
                
                // Determinar estado de la importación
                const status = progressData.status || '';
                
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
                
                let successMessage = md_import_force.i18n.completed + '<br>';
                successMessage += 'Tiempo total: ' + elapsedMinutes + 'm ' + elapsedSeconds + 's<br>';
                successMessage += 'Elementos procesados: ' + progressData.processed_count + ' de ' + progressData.total_count;
                
                if (progressData.stats) {
                    successMessage += '<br>Nuevos: ' + (progressData.stats.new_count || 0);
                    successMessage += ', Actualizados: ' + (progressData.stats.updated_count || 0);
                    successMessage += ', Omitidos: ' + (progressData.stats.skipped_count || 0);
                }
                
                showMessage(successMessage, 'success');
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
