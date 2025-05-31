# Mejoras de Rendimiento - MD Import Force

## Problema Original
El plugin experimentaba errores 504 (Gateway Timeout) durante la importación de archivos grandes debido a:
- Lotes de importación demasiado grandes (10 posts por lote)
- Falta de limpieza de memoria entre lotes
- Tiempos de espera muy cortos
- Verificaciones de progreso demasiado frecuentes

## Soluciones Implementadas

### 1. Optimización del Tamaño de Lotes
**Archivo:** `includes/class-md-import-force-job-manager.php`
- **Inicial:** `DEFAULT_BATCH_SIZE = 10`
- **Primera optimización:** `DEFAULT_BATCH_SIZE = 3` (para evitar timeouts)
- **Optimización actual:** `DEFAULT_BATCH_SIZE = 5` (balance entre velocidad y estabilidad)
- **Beneficio:** Reduce la carga por operación manteniendo velocidad razonable

### 2. Sistema de Procesamiento de Medios Activado
**Archivos modificados:**
- `includes/class-md-import-force-media-queue-manager.php` - Nuevos métodos de procesamiento
- `includes/class-md-import-force-job-manager.php` - Activación del procesamiento de medios
- `md-import-force.php` - Registro de hooks para medios

**Características:**
- Procesamiento de medios en segundo plano después de importar posts
- Cola de medios en base de datos para seguimiento
- Procesamiento por lotes de 5 medios por vez
- Manejo separado de imágenes destacadas y de contenido
- Logging detallado del progreso de medios

### 3. Nuevas Configuraciones de Optimización
**Archivo:** `includes/class-md-import-force-job-manager.php`
```php
const MIN_MEMORY_THRESHOLD = 0.8; // 80% de memoria máxima antes de parar
const BATCH_DELAY_SECONDS = 5; // Delay entre lotes para aliviar la carga del servidor
const MAX_EXECUTION_TIME_RATIO = 0.7; // Usar solo 70% del tiempo máximo de ejecución
```

### 4. Sistema de Limpieza de Memoria
**Nuevas Funciones:**
- `cleanup_memory()` en Job Manager
- `cleanup_handler_memory()` en Handler

**Acciones de Limpieza:**
- Liberación de ciclos de memoria PHP (`gc_collect_cycles()`)
- Limpieza de cache de WordPress (`wp_cache_flush()`)
- Limpieza de cache de objetos
- Limpieza de cache de base de datos
- Liberación de variables globales

### 5. Monitoreo de Memoria y Tiempo
**Implementaciones:**
- Monitoreo en tiempo real del uso de memoria
- Reducción dinámica del tamaño de lotes cuando memoria > 80%
- Tiempo límite más conservador (70% del máximo)
- Logging detallado de memoria antes/después de cada operación

### 6. Delays Inteligentes Entre Lotes
**Características:**
- Delay base: 5 segundos entre lotes
- Delay adaptativo basado en uso de memoria:
  - Memoria > 70%: Delay x2
  - Memoria > 50%: Delay x1.5
- Previene sobrecarga del servidor

### 7. Manejo Mejorado de Errores 504 en Frontend
**Archivo:** `assets/js/admin.js`
**Mejoras:**
- Detección específica de errores 504
- Sistema de reintentos con backoff exponencial
- Máximo 5 reintentos antes de cambiar a verificaciones menos frecuentes
- Delays progresivos: 2s → 3s → 4.5s → 6.75s → 10s
- Fallback a verificaciones cada 15 segundos si persisten errores

### 8. Optimizaciones de Programación
**Características:**
- Mejor logging de programación de lotes
- Timestamps más conservadores
- Verificación de éxito en programación de Action Scheduler

## Resultados Esperados

### Antes de las Mejoras:
- ❌ Errores 504 frecuentes
- ❌ Importación se volvía más lenta con el tiempo
- ❌ Sobrecarga del servidor
- ❌ Verificaciones de progreso fallaban

### Después de las Mejoras:
- ✅ Lotes más pequeños y manejables
- ✅ Limpieza automática de memoria
- ✅ Delays inteligentes entre operaciones
- ✅ Recuperación automática de errores 504
- ✅ Monitoreo en tiempo real de recursos
- ✅ Mejor estabilidad general

## Configuraciones Recomendadas del Servidor

Para mejores resultados, se recomienda:

```php
// php.ini
max_execution_time = 300
memory_limit = 512M
post_max_size = 64M
upload_max_filesize = 64M
```

## Monitoreo y Logs

Todos los cambios incluyen logging detallado en el sistema de logs del plugin:
- Uso de memoria antes/después de cada operación
- Tiempo de ejecución por lote
- Delays aplicados entre lotes
- Errores y reintentos de conectividad

## Mantenimiento

El sistema incluye limpieza automática:
- Archivos temporales: cada 48 horas
- Cache de memoria: cada lote
- Mapeos de términos: al finalizar importación 