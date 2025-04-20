/**
 * MD Import Force - Archivo índice
 * 
 * Este archivo carga todos los módulos necesarios en el orden correcto.
 * Debe ser incluido en lugar del antiguo admin.js.
 */

function loadScript(src) {
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = md_import_force.plugin_url + src;
        script.onload = resolve;
        script.onerror = reject;
        document.head.appendChild(script);
    });
}

loadScript('assets/js/admin/core.js')
    .then(() => loadScript('assets/js/admin/ui.js'))
    .then(() => loadScript('assets/js/admin/ajax.js'))
    .then(() => loadScript('assets/js/admin/tabs.js'))
    .then(() => loadScript('assets/js/admin/preview.js'))
    .then(() => loadScript('assets/js/admin/import.js'))
    .then(() => loadScript('assets/js/admin/log.js'))
    .then(() => loadScript('assets/js/admin/cleanup.js'))
    .then(() => loadScript('assets/js/admin/main.js'))
    .catch(error => console.error('Error loading scripts:', error));

