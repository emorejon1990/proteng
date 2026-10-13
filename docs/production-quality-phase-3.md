# Fase 3: calidad por serial

## A. Resumen

Rama independiente: `phase-3-quality-by-serial`. Calidad solicita un serial y no selecciona automáticamente el siguiente tubo. El formulario muestra modelo, serial, peso de llenado, peso actual, diferencia y tolerancia ±5 g. Los escáneres de teclado funcionan con Enter; la cámara existente se abre para una orden concreta y valida el serial en esa orden.

Evaluar no escribe datos. Una medición aceptable permite guardar aprobación. Una medición fuera de tolerancia ofrece volver a pesar o solicitar envío a revisión; se requiere una segunda acción de confirmación. Cancelar, repetir el pesaje o cambiar el peso invalida la confirmación y no modifica el producto.

## B. Archivos

Creados:

- `app/Services/ProductionQualityService.php`
- `tests/Unit/ProductionQualityTest.php`
- `tests/JavaScript/quality-scanner.test.mjs`
- `docs/production-quality-phase-3.md`

Modificados:

- `app/Livewire/WorkOrderQualityEditor.php`
- `app/Filament/Pages/Quality.php`
- `app/Models/Products.php`
- `app/Filament/Shared/Resources/ProductsResource.php`
- `app/Services/ProductionCatalog.php`
- `config/production.php`
- `resources/views/livewire/work-order-quality-editor.blade.php`
- `resources/views/filament/pages/quality.blade.php`
- `resources/views/livewire/qr-scanner-modal.blade.php`
- `resources/js/weightscale.js`
- `tests/JavaScript/weightscale.test.mjs`
- `tests/Support/ProductionDatabase.php`: añade al esquema de pruebas la migración existente de `work_orders.for`.

Los cambios locales anteriores del usuario y las fases 1 y 2 se conservan. No se modificó el permiso local de exportación.

## C. Base de datos

No se crean migraciones nuevas. Se utiliza el esquema incremental de fase 1: origen de producción, asociaciones, ciclos, asignaciones activas y contexto de History.

Se corrigió `qualifiled` por `qualified` en fillable/casts del modelo y en el formulario administrativo, conforme a la columna real de la migración existente. En ProductsResource el indicador queda solo de lectura y no se envía al guardar, para no introducir una aprobación mediante checkbox. No se renombra ninguna columna ni se transforma ningún dato real. El backfill conserva sus referencias defensivas al nombre antiguo para diagnosticar información histórica.

En una transacción con bloqueo de orden y producto se registra el resultado definitivo en el ciclo, peso de calidad, usuario y fecha; se actualizan los campos actuales del producto y se añade History con pesos, diferencia, tolerancia, serial y resultado. Los ciclos finalizados siguen siendo inmutables. Para un producto legado sin ciclo se conserva un snapshot de sus valores anteriores antes del nuevo resultado.

## D. Compatibilidad

Se mantienen Admin, Manager y Worker para calidad. Todas las acciones se autorizan en el servidor. Se comprueban tipo, estado y estación de la orden; identidad y unicidad del serial; pertenencia; ensamblado y llenado; ubicación; ciclo vigente; asignaciones incompatibles y calidad pendiente. Guardar repite las comprobaciones.

La comparación usa gramos y aritmética decimal de Brick Math, ya instalada como dependencia del proyecto: `abs(calidad - llenado) <= 5`. Ambos extremos son inclusivos. Calidad se compara con el llenado real del ciclo, no con el nominal del Asset. El peso debe ser positivo, finito y tener hasta cuatro decimales; las validaciones nominales de llenado permanecen en la fase 2.

La espera se verifica por fechas de calendario. Si el ciclo contiene fechas de espera, se exige al menos cinco días desde el inicio. Para registros legados sin esas fechas se toma la última fecha de llenado conocida de la orden; si falta alguna, se bloquea para revisión administrativa. No se interpreta como inicio de espera `wc_changed_at` de Quality, porque el comando legado lo sobrescribe al cambiar de estación.

Los aprobados pasan a Stock con estado Ready, conservan la orden original y liberan su asignación operativa. La orden termina calidad cuando todos los productos actuales tienen resultado, incluidos los rechazados confirmados. Una distribución vinculada solo se habilita cuando no hay rechazos pendientes.

La conexión Web Serial a 9600 baudios se conserva. Las lecturas se dirigen a un único tubo seleccionado y las lecturas tardías de otro tubo se ignoran. Evaluar congela nuevas lecturas hasta volver a pesar, para mantener estable lo que se confirma. El lector de cámara cierra la sesión al leer o cancelar y evita emitir el mismo resultado repetidamente.

## E. Pruebas

Pruebas PHP con SQLite exclusivamente en memoria. Los 21 casos de calidad pasaron con 183 aserciones: selección exacta, permisos, pertenencia y etapa, validación de pesos, límites ±5 g y exceso por 0.0001 g, espera por fecha, aprobación, confirmación/cancelación/repetición, lecturas tardías, estado bloqueado de Livewire, rechazo repetido, snapshot histórico, reversión ante fallo de auditoría, cámara por orden y distribución vinculada.

Las nueve pruebas JavaScript de báscula y cámara pasaron. La suite Unit de regresión produjo 59 aprobadas y dos fallos preexistentes (680 aserciones). Pint y `git diff --check` pasaron; ProductsResource pasó la comprobación de sintaxis PHP.

Comandos reproducibles:

```text
php vendor/bin/pest tests/Unit/ProductionQualityTest.php --compact --do-not-cache-result
php vendor/bin/pest tests/Unit --compact --do-not-cache-result
node --test tests/JavaScript/weightscale.test.mjs tests/JavaScript/quality-scanner.test.mjs
```

## F. Problemas y límites

La incorporación automática a órdenes Review pertenece a la fase 4. En esta fase, confirmar registra `quality_result=rejected`, `quality_completed=true`, `completed_at` y un History con `review_pending=true`. El tubo permanece en Quality, fuera de Stock, ligado a su orden original y con la asignación activa de producción hasta el traspaso transaccional a Review. No se crea todavía una orden Review ni se presenta como realizada esa incorporación.

Los dos fallos preexistentes de SerialPoolTest permanecen: el mock espera los roles Admin/Manager, pero el controlador editado localmente por el usuario incluye Worker. No se cambió esa autorización ni el mock.

No se aplicaron migraciones ni backfill en una base real. La conexión MySQL disponible estaba rechazada en el análisis anterior. No se han probado bloqueos concurrentes en MySQL ni una cámara, escáner o báscula físicos. Las pruebas JavaScript simulan sus interfaces.

## G. Riesgos antes de habilitar

Verificar los catálogos reales. Los nuevos IDs opcionales de `config/production.php` son `PRODUCTION_QUALITY_CENTER_ID`, `PRODUCTION_QUALITY_LOCATION_ID`, `PRODUCTION_STOCK_LOCATION_ID`, `PRODUCTION_READY_ORDER_STATUS_ID`, `PRODUCTION_READY_PRODUCT_STATUS_ID` y `PRODUCTION_IN_PROCESS_PRODUCT_STATUS_ID`. Sin IDs configurados se exige una coincidencia única del nombre en la conexión activa; no se utilizan IDs supuestos de seeders.

El comando de espera conserva sus IDs fijos de estación Waiting 3, Quality 4 y ubicación Quality 3. Deben coincidir con los catálogos reales antes de activar. Los registros legados con fechas ausentes requieren diagnóstico; no se inventa un período ya cumplido.

Antes de habilitar debe estar aplicado el esquema de fase 1, publicarse el JavaScript con el procedimiento de assets de Filament y regenerarse la configuración cacheada si corresponde. Se mantiene el proveedor externo existente del lector de cámara. No se instalaron paquetes, se hizo push, merge ni despliegue.

## H. Próxima fase

Fase 4: tipo Review, agrupación automática de hasta 50 tubos de distintos modelos, cierre manual y aprobación. Debe incorporar los rechazos ya registrados, transferir sus asignaciones activas y garantizar transacciones, idempotencia y límite concurrente. El servicio de calidad se integrará con ese traspaso dentro de la misma transacción de confirmación. Requiere aprobación expresa del usuario.
