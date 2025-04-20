/**
 * MD Import Force - Archivo principal de administración
 * 
 * Este archivo carga todos los módulos necesarios para el funcionamiento
 * del panel de administración del plugin MD Import Force.
 */

jQuery(document).ready(function($) {
    // Inicializar todos los módulos cuando el documento esté listo
    MDImportForce.Core.init($);
    MDImportForce.UI.init($);
    MDImportForce.Ajax.init($);
    MDImportForce.Tabs.init($);
    MDImportForce.Preview.init($);
    MDImportForce.Import.init($);
    MDImportForce.Log.init($);
    MDImportForce.Cleanup.init($);
});
