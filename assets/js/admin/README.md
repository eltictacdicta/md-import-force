# MD Import Force - Estructura Modular de JavaScript

Este directorio contiene la estructura modular del JavaScript para el panel de administración del plugin MD Import Force.

## Estructura de archivos

- **index.js**: Archivo principal que carga todos los módulos en el orden correcto.
- **main.js**: Inicializa todos los módulos cuando el documento está listo.
- **core.js**: Contiene la configuración principal y el namespace para todos los módulos.
- **ui.js**: Funciones relacionadas con la interfaz de usuario (mensajes, elementos de progreso).
- **ajax.js**: Funciones para manejar solicitudes AJAX.
- **tabs.js**: Funciones para la navegación por pestañas.
- **preview.js**: Funciones para la previsualización de archivos.
- **import.js**: Funciones para el proceso de importación.
- **log.js**: Funciones para el manejo de logs.
- **cleanup.js**: Funciones para la limpieza de archivos.

## Namespace

Todos los módulos utilizan el namespace `MDImportForce` para evitar conflictos con otros scripts.

## Dependencias

Los módulos tienen las siguientes dependencias:

- Todos los módulos dependen de **core.js**
- **tabs.js** depende de **log.js** para cargar el log cuando se cambia a la pestaña correspondiente
- **preview.js** depende de **ui.js** y **ajax.js**
- **import.js** depende de **ui.js**, **ajax.js** y **preview.js**
- **log.js** depende de **ajax.js**
- **cleanup.js** depende de **ui.js** y **ajax.js**

## Cómo añadir nuevas funcionalidades

Para añadir nuevas funcionalidades:

1. Identifica el módulo adecuado para la nueva funcionalidad
2. Si la funcionalidad no encaja en ningún módulo existente, crea un nuevo módulo
3. Añade el nuevo módulo a **index.js** y **main.js**
4. Asegúrate de inicializar correctamente el módulo en **main.js**

## Convenciones de código

- Usar patrón de módulo revelador (IIFE) para encapsular el código
- Exponer solo los métodos necesarios para otros módulos
- Inicializar cada módulo con el objeto jQuery para evitar conflictos
- Documentar todas las funciones con comentarios JSDoc
