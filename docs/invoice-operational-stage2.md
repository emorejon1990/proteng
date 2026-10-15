# PROTENG LAB — Clasificación operativa y diagnóstico, etapa 2

Fecha: 2026-10-15. Implementación local; sin despliegue, commit, push ni acceso a QuickBooks real o a datos de producción.

## Estado inicial y alcance

Se revisó el código local y el diff antes de editar. El único cambio inicial era `docs/invoice-operational-audit.md`, ya añadido al índice por el usuario; se conserva intacto. Los hallazgos del informe coinciden con los servicios actuales. Aclaración de esquema: la migración de Assets ya declara `quickbooks_id` único; el diagnóstico de duplicados es defensivo frente a catálogos inconsistentes. La regresión de duplicados elimina ese índice exclusivamente dentro de su SQLite en memoria, sin cambiar migraciones.

No se modifica esquema, OAuth, tokens, realm ni reglas de producción, distribución o instalaciones. Se implementa el estado aprobado `out_of_scope` en el campo string existente.

## Archivos y cambios

| Archivo | Cambio |
| --- | --- |
| `app/Services/InvoiceOperationalException.php` | Subclase compatible con ValidationException; conserva errores de validación y contexto estructurado. Incluye adaptación de ValidationException genéricas. |
| `app/Services/InvoiceRequirements.php` | Códigos diferenciados para errores reales; contexto de línea; detección de mappings duplicados; mantiene exclusiones explícitas y cantidades físicas estrictas. Valida Qty del payload conservado antes del redondeo decimal de la línea. Cachea conteos por Item durante cada resolución. |
| `app/Services/InvoiceSyncService.php` | Verifica identidad de cada Item leído antes de persistir la factura. Requisitos válidos vacíos producen out_of_scope; conserva diagnósticos operativos en metadata del evento existente de importación/actualización. |
| `app/Services/InvoiceClassificationService.php` | Rechaza explícitamente out_of_scope en servidor, después de autorización y antes de crear cualquier operación. |
| `app/Services/InvoiceSelectiveVerification.php` | Devuelve operational_diagnostics, import_diagnostics y operational_scope; alcance vacío válido supera validación operativa. |
| `app/Services/InvoiceSelectiveSandbox.php` | Conserva contexto seguro de InvoiceDataException como diagnóstico de importación, separado de fallos operativos. |
| `app/Services/InvoiceSelectiveSyncService.php` | Conserva también contexto de importación si el flujo Apply autorizado externamente falla; no se ejecutó Apply sobre producción. |
| `app/Filament/Manager/Resources/InvoiceResource.php` | Etiqueta y filtro Fuera del alcance operativo; badge neutral y acción de clasificación oculta para ese estado. |
| `resources/views/filament/admin/pages/quick-books-sync.blade.php` | Etiqueta de estado y diagnósticos visibles separados por importación/operación. Blade escapa el contenido. El JSON descargable existente incluye los nuevos campos automáticamente. |
| `resources/views/filament/invoices/details.blade.php` | Explica el estado fuera de alcance en el detalle. |
| `tests/Unit/InvoiceIntegrationTest.php` | Regresiones de estados, cantidades, mappings, identidad Item, clasificación servidor/Filament, historial sin cambios, conflictos, snapshots e instalaciones. |
| `tests/Unit/InvoiceSelectiveSyncTest.php` | Regresiones del subproceso SQLite, datos preservados, diagnósticos visibles/descargables y facturas fuera de alcance válidas. |
| `docs/invoice-operational-stage2.md` | Este informe y procedimiento de revisión posterior. |

## Matriz final

| Situación | Resultado operativo |
| --- | --- |
| Productos Inventory/NonInventory válidos, sin Work Order | unclassified |
| Sólo Service o conceptos no físicos reconocidos y válidos | out_of_scope — Fuera del alcance operativo |
| Productos válidos + servicios | unclassified; requisitos sólo de productos |
| Producto sin Asset, mapping incorrecto/duplicado, tipo desconocido, cantidad inválida, grupo sin resolver | requires_review |
| Item devuelto con Id ausente, inválido o distinto del solicitado | Error de importación; se detiene esa factura antes de cambiar sus datos contables |
| Tipo de línea no admitido en payload entrante | Error de importación; datos anteriores preservados |
| Tipo de línea no admitido ya almacenado | Diagnóstico operativo unsupported_line |
| Factura vinculada con cambios incompatibles con snapshot, cliente distinto o void | sync_conflict; vínculos y snapshot conservados |
| Factura vinculada con cambio contable compatible | Conserva estado actual; un conflicto previo no se resuelve automáticamente |
| Payload idéntico | Conserva clasificación y IDs de líneas, sin nueva consulta Item ni reclasificación automática |

Los estados contables y sync_status mantienen su significado. Una validación operativa fallida no convierte una importación contable correcta en un error contable. El Dry Run sigue fallando ante errores operativos reales y no habilita Apply en ese caso.

## Diagnósticos

`operational_diagnostics` contiene el primer fallo encontrado, con orden de evaluación estable; no se promete enumerar todos los fallos de una factura en una sola ejecución. Cada diagnóstico propio incluye code, message, rule, line_index (cero), line_position (uno), detail_type, item_ref, item_type, asset_id, asset_quickbooks_id y quantity. Los campos no disponibles son null.

Códigos: `missing_item_reference`, `missing_asset`, `mismatched_asset`, `duplicate_asset`, `unknown_item_type`, `invalid_physical_quantity`, `physical_quantity_limit`, `accumulated_quantity_limit`, `grouped_line`, `unsupported_line`, `missing_physical_products`. Este último sigue bloqueando clasificación sin productos; no se usa para rechazar el alcance vacío válido en sincronización. Las ValidationException genéricas conservan sus mensajes con código `operational_validation_failed`.

Ejemplo sintético, no diagnóstico de una factura real:

```json
{
  "code": "missing_asset",
  "message": "Physical item 300 needs an Asset mapping.",
  "rule": "missing_asset",
  "line_index": 0,
  "line_position": 1,
  "detail_type": "SalesItemLineDetail",
  "item_ref": "300",
  "item_type": "NonInventory",
  "asset_id": null,
  "asset_quickbooks_id": null,
  "quantity": "2.000000"
}
```

`import_diagnostics` conserva mensaje y contexto de InvoiceDataException. Una identidad Item incorrecta utiliza `item_response_mismatch`, regla `item_response_identity`, referencia solicitada y posición de línea; no crea mappings ni cambia la factura anterior. Otros errores contables conservan el contexto que ya suministra el importador; no todos incluyen los mismos campos o un código nuevo.

`operational_scope` distingue physical, out_of_scope, invalid y not_evaluated. Para un resultado fuera de alcance válido, operational_diagnostics está vacío y operational_requirements_valid es true. Los informes no añaden credenciales, direcciones, descripciones comerciales ni payloads completos.

## Servicios, integridad y datos históricos

Service no exige Asset ni cantidad física. Sus cantidades fraccionarias se conservan contablemente y no se suman a requisitos. Shipping sólo se excluye con la combinación explícita Shipping + SHIPPING_ITEM_ID; el importador reconoce esa referencia especial sin consultar Item. Descuentos, subtotales y descripciones admitidas tampoco producen requisitos. Los grupos sin resolver y tipos desconocidos siguen bloqueados.

Los requisitos físicos exigen Asset del mismo QuickBooks ID y sin duplicados, Qty numérica finita, entera positiva, límite individual y acumulado 2147483647. El valor original de Qty evita aceptar fracciones pequeñas que el cast decimal:6 redondearía a enteros.

No hay backfill ni reclasificación histórica. Una factura histórica requires_review con payload idéntico puede conservar ese estado aunque sus requisitos actuales sean vacíos válidos; el informe distingue alcance actual de estado preservado. No se inventa la causa del estado histórico ni se vuelve a consultar el tipo Item en esa ruta. Las protecciones de Work Orders tienen prioridad sobre out_of_scope y no se modifican snapshots, instalaciones ni cantidades vinculadas.

## Pruebas

Las suites usan `setupTraceabilityDatabase()`, que configura SQLite :memory:, purga la conexión SQLite y comprueba el nombre efectivo antes de crear tablas. Se fuerza también APP_ENV=testing, un APP_CONFIG_CACHE inexistente, DB_CONNECTION=sqlite, DB_DATABASE=:memory:, DB_URL=null, CACHE_STORE=array y LOG_CHANNEL=null al ejecutar. El subproceso del Dry Run configura su propia base en memoria y prohíbe red.

Comando de regresión:

```powershell
$env:APP_ENV='testing'
$env:APP_CONFIG_CACHE='D:\Work\proteng\storage\framework\testing-no-config.php'
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=':memory:'
$env:DB_URL='null'
$env:CACHE_STORE='array'
$env:LOG_CHANNEL='null'
php vendor/bin/pest tests/Unit/InvoiceIntegrationTest.php tests/Unit/InvoiceRealPayloadTest.php tests/Unit/InvoiceSelectiveSyncTest.php tests/Unit/QuickBooksInvoiceDebugTest.php --compact
```

Resultado final: **134 pruebas aprobadas, 0 fallidas, 1311 aserciones**, duración 59.04 segundos. Incluye el subproceso real de simulación aislada, renderizado de diagnósticos y descarga del informe. `php vendor/bin/pint --test --dirty` aprobado y `git diff --check` sin errores. No se ejecutó la suite general ajena a facturas.

La primera ejecución parcial detectó dos errores en los tests nuevos: un conteo inicial de Assets supuesto incorrectamente y la construcción de duplicados impedida por el índice único. Se corrigieron los tests usando el conteo inicial real y una anomalía de esquema limitada a la base de prueba. No se relajó ninguna validación para resolverlos.

Se conservan las seis variantes raw/SDK de 1982, 11241 y 11990. Las respuestas Item y Assets de esas pruebas siguen siendo simuladas. Superar las pruebas no demuestra por qué las facturas reales 1982 y 11990 requieren revisión: sus respuestas Item y mappings reales no están en los fixtures.

## Limitaciones y revisión de despliegue

- No se ejecutó MariaDB ni suite de concurrencia; SQLite no reproduce todos sus bloqueos, límites y redondeos. Revisar primero en staging con MariaDB aislada.
- Los diagnósticos señalan el primer fallo; corregirlo puede revelar otro. No se realizan correcciones automáticas de datos.
- Las facturas sin cambios conservan tipos/mappings almacenados y estado histórico. La reevaluación histórica requiere un procedimiento aprobado aparte.
- La unicidad Asset ya está impuesta por migración; la comprobación adicional produce un mensaje útil si existe un catálogo inconsistente. No repara ese catálogo.
- Antes de desplegar, revisar diff, ejecutar regresiones en staging, comprobar permisos Admin/Manager y etiquetas Filament. No hay migraciones nuevas ni comandos de datos que ejecutar. Si se usa caché de vistas/configuración, regenerarla mediante el procedimiento habitual de despliegue. Coordinar reinicio de procesos persistentes según el entorno. Nada de esto se ejecutó aquí.
- Un rollback de código anterior no borraría el string out_of_scope de futuras filas; revisar ese comportamiento antes de un rollback. No hay script automático de conversión.

## Repetir sólo el Dry Run real, después de revisión/despliegue

1. Entrar como Admin sin rol Customer a QuickBooks Sync, comprobando previamente la compañía conectada mediante el procedimiento habitual. No cambiar OAuth, tokens ni realm.
2. Elegir únicamente **Test 3 Invoices** y confirmar **Dry Run: 1982, 11241, 11990**. No pulsar Sync Invoices, Sync All ni Apply.
3. El flujo existente lee esas tres facturas y las referencias necesarias; simula importación con copias locales en un proceso SQLite aislado. No modifica facturas ni operaciones existentes; utiliza el lock/caché temporal del flujo habitual.
4. Revisar final_state, operational_scope, checks contables y operativos. Si falla operación, revisar operational_diagnostics; si falla importación, revisar import_diagnostics. Registrar índice cero y posición uno junto con ItemRef, tipo y Asset.
5. Usar **Download Test Report** para obtener quickbooks_selective_test.json. Compartir sólo lo necesario para el diagnóstico. No utilizar el ticket generado para Apply.
6. Si payload no cambió, recordar que el informe analiza líneas locales conservadas, no una nueva respuesta Item. No forzar cambios en facturas para provocar reevaluación.

## Confirmación de seguridad

No se modificó producción. No se ejecutó Sync All ni sincronización real; tampoco Apply contra producción. No se modificaron Assets, Work Orders, instalaciones, cantidades ni snapshots reales. Las pruebas crean y modifican únicamente sus datos sintéticos, y ejercitan Apply sólo con mocks en base aislada. No se realizaron escrituras en QuickBooks, cambios OAuth/tokens/realm, migraciones contra bases existentes, despliegues, commits ni pushes.
