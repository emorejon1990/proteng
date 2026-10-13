# Fase 2: llenado identificado por serial

## Alcance

Rama: `phase-2-filling-by-serial`. Solo se implementa llenado de órdenes de producción. Los nuevos ciclos autorizados por reparación se habilitarán en la fase 6.

El operador abre Filling, escribe o escanea un serial y lo confirma con Enter o «Identificar tubo». No se selecciona ningún producto automáticamente. Se muestra el modelo del tubo, su serial y el intervalo de peso permitido. Al guardar, se limpia la selección, se actualizan los contadores y se devuelve el foco al campo de serial para el siguiente tubo.

Se conservan los roles Admin, Manager y Worker de la estación. El permiso local añadido por el usuario a la exportación de seriales no se modifica.

## Validaciones y guardado

`ProductionFillingService` valida en el servidor el rol, tipo, estado y estación actuales de la orden, serial único asignado, pertenencia a la orden, ensamblado terminado, llenado pendiente, ciclo vigente y ausencia de otra asignación operativa. Los productos previamente aprobados en calidad también se rechazan, aunque su indicador de llenado sea inconsistente. Guardar vuelve a consultar y validar los datos; la identificación inicial no autoriza datos que hayan cambiado después.

El peso debe ser numérico, finito y positivo, con hasta cuatro decimales, dentro del peso nominal ± tolerancia del modelo del producto escaneado. Los límites son inclusivos. Identificar un tubo no escribe datos.

El guardado usa una transacción con bloqueo de orden y producto. Actualiza los campos actuales de llenado, conserva la orden original, registra la asociación operativa y el ciclo, y añade History con serial, peso, usuario y contexto. Un error revierte todos esos cambios. Repetir el guardado no sobrescribe el peso anterior.

Los datos anteriores de productos importados quedan en su snapshot histórico. Al terminar una orden parcialmente procesada con el flujo anterior, se conserva también el origen y el ciclo de los tubos ya llenados sin modificar sus pesos, fechas ni historiales.

La orden pasa a Waiting solo cuando la consulta actual de todos sus productos confirma que no quedan pendientes. El inicio de espera y la disponibilidad cinco días después se registran como fechas de calendario, sin depender de la hora. Se mantiene `work_orders.wc_changed_at` para el comando programado existente.

## Báscula y escáner

Se conserva la conexión Web Serial a 9600 baudios y el botón Connect. La lectura se envía al componente Livewire del tubo seleccionado. Al seleccionar otro tubo u otra orden, se desactiva el destino anterior. Sin un destino único no se aplica el peso. El servidor ignora una lectura tardía dirigida a un producto que dejó de estar seleccionado.

El botón «Usar báscula para este tubo» permite cambiar el destino sin volver a escanear. La entrada manual de peso sigue disponible. La ruta de entrada de peso de calidad permanece compatible. Los escáneres físicos que funcionan como teclado pueden enviar el serial y Enter.

## Preparación antes de habilitar

No se agregan migraciones en esta fase; se requiere el esquema incremental de la fase 1. No se aplicaron migraciones ni backfill a una base real.

Los identificadores se resuelven contra los catálogos de la conexión activa, por coincidencia única de nombre. Si falta un registro o hay ambigüedad, se bloquea la operación con un mensaje para el administrador. No se asumen los IDs de los seeders. `config/production.php` permite definir IDs previamente verificados mediante:

- `PRODUCTION_TYPE_ID`
- `PRODUCTION_IN_PROCESS_STATUS_ID`
- `PRODUCTION_FILLING_CENTER_ID`
- `PRODUCTION_WAITING_CENTER_ID`
- `PRODUCTION_WAITING_LOCATION_ID`

Si se usa configuración cacheada, deberá regenerarse como parte del procedimiento autorizado de puesta en marcha. El recurso JavaScript registrado por Filament también deberá publicarse con el procedimiento de assets habitual. No se realizó despliegue.

El comando existente `workorders:move-after-5days` conserva sus IDs fijos de estación Waiting 3, Quality 4 y ubicación Quality 3. Antes de habilitar en una base real se debe verificar que esos valores coincidan con los catálogos resueltos. Su actualización y las transiciones posteriores se revisarán en las fases correspondientes.

## Verificación y límites

Resultados ejecutados: 32 pruebas PHP de llenado y trazabilidad aprobadas (263 aserciones), cuatro pruebas JavaScript aprobadas, formato Pint y `git diff --check` aprobados. La suite Unit completa produjo 38 aprobadas y los dos fallos preexistentes de exportación descritos abajo.

Las pruebas PHP usan exclusivamente SQLite en memoria, con migraciones existentes y de fase 1. Cubren selección exacta, validaciones, autorización, pesos, repetición de guardado, transición con productos añadidos después de identificar, fechas, datos históricos, asignaciones incompatibles, reversión completa ante fallo de auditoría y el estado del formulario Livewire.

Las pruebas JavaScript usan Node sin dependencias nuevas y verifican el destino de la báscula, selecciones ausentes o ambiguas, pesos no finitos y compatibilidad de la entrada de calidad.

Comandos:

```text
php vendor/bin/pest tests/Unit/ProductionFillingTest.php tests/Unit/ProductionTraceabilityTest.php --compact --do-not-cache-result
php vendor/bin/pest tests/Unit --compact --do-not-cache-result
node --test tests/JavaScript/weightscale.test.mjs
```

Se detectaron antes de los cambios de fase 2 dos fallos en SerialPoolTest: su mock exige el arreglo de roles Admin/Manager, mientras el controlador modificado localmente por el usuario incluye Worker. Se conservan tanto el cambio del usuario como esas pruebas; esta fase no cambia permisos de exportación.

No se pudo comprobar la base MySQL local porque la conexión disponible estaba rechazada. Quedan pendientes las pruebas de bloqueo concurrente en MySQL y la prueba con una báscula física, escáner y navegador reales.

## Archivos de esta fase

Creados:

- `app/Services/ProductionCatalog.php`
- `app/Services/ProductionFillingService.php`
- `config/production.php`
- `tests/Support/ProductionDatabase.php`
- `tests/Unit/ProductionFillingTest.php`
- `tests/JavaScript/weightscale.test.mjs`
- `docs/production-filling-phase-2.md`

Modificados:

- `app/Filament/Pages/Filling.php`
- `app/Livewire/WorkOrderFillingEditor.php`
- `resources/views/filament/pages/filling.blade.php`
- `resources/views/livewire/work-order-filling-editor.blade.php`
- `resources/js/weightscale.js`
- `tests/Unit/ProductionTraceabilityTest.php`: extracción de los helpers compartidos de base aislada, conservando sus casos de prueba.

Los archivos de fase 1 siguen presentes en el árbol de trabajo. El cambio local de `SerialExportController.php` pertenece al usuario y no se modificó en esta fase.
