# Auditoría operativa de facturas — 2026-10-15

## Alcance y estado

Etapa 1. Revisión del código local, fixtures y pruebas existentes. `git status --short` y `git diff --stat` estaban vacíos antes de comenzar. No se consultó GitHub ni se abrió una conexión a la base existente o a QuickBooks.

La etapa 2 queda pendiente de aprobación de la representación de facturas sin alcance físico: la solicitud exige detenerse antes de introducir estados nuevos. No se modificó código de negocio, esquema ni fixtures.

## Causas demostradas y límites del diagnóstico

El resultado comunicado para 1982 y 11990 (`operational_requirements_valid=false`) significa que `InvoiceRequirements::resolve($invoice, false)` lanzó una `ValidationException` en la simulación. No significa que fallara la importación contable. Tanto el importador como el verificador capturan esa excepción y descartan su detalle.

La ausencia de productos físicos, por sí sola, NO explica ese indicador falso: con `requirePhysical=false`, los requisitos vacíos son válidos. El importador, por separado, asigna `requires_review` también a requisitos vacíos. Son dos caminos distintos.

Los fixtures contienen proyecciones sanitizadas de capturas reales de Invoice (HTTP y SDK), NO respuestas reales de Item ni el catálogo Asset. DocNumber y CustomerRef fueron sustituidos. 1982, 11241 y 11990 son los IDs de QuickBooks conservados; no permiten recuperar el número comercial original.

| Dato solicitado | 1982 | 11990 |
| --- | --- | --- |
| ID QuickBooks | 1982 | 11990 |
| Número comercial real | No disponible; fixture SANITIZED-1982 | No disponible; fixture SANITIZED-11990 |
| Índice y posición de la línea que falla | No determinables | No determinables |
| Tipo de línea problemática | No determinable | No determinable |
| ItemRef problemático | No determinable | No determinable |
| Tipo real de Item | No capturado | No capturado |
| Asset real y correspondencia | No capturados | No capturados |
| Cantidad de la línea problemática | No identificable sin conocer la línea | No identificable sin conocer la línea |
| Regla y mensaje exactos | Descartados por el código actual | Descartados por el código actual |
| Resultado operativo esperado | Depende de tipos y mappings reales | Depende de tipos y mappings reales |

No hay evidencia suficiente para asignar una causa A, B, C, D, E o F concreta a esas dos ejecuciones. No debe atribuirse el error a Items concretos por su nombre supuesto, ID o cantidad.

### Evidencia en las capturas

- 1982: 18 líneas. Índices 0–14 son SalesItemLineDetail con cantidades enteras positivas; índice 15 subtotal; 16 descuento; 17 shipping especial, posición 18, ItemRef SHIPPING_ITEM_ID, Qty ausente. Ese shipping está excluido explícitamente y no necesita Asset.
- Items numéricos de 1982: 30, 16, 31, 17, 12, 35, 19, 37, 32. Item 30 acumula 9000 unidades en el fixture; ninguna suma de cantidades del fixture alcanza 2147483647.
- 11990: 43 líneas. Índices 0–41 son SalesItemLineDetail con cantidades enteras positivas; índice 42 subtotal. Items: 201, 170, 44, 1010000021, 21, 1. Índices 15–41 contienen ItemRef 1. Su tipo real es desconocido; el mock que lo llama Service no demuestra que lo sea en QuickBooks.
- 11241: una línea comercial con ItemRef 199, Qty 1, seguida de subtotal y descuento.
- No hay grupos ni tipos de línea desconocidos en esos fixtures. No hay cantidades comerciales decimales, cero o negativas. Esto describe las capturas disponibles, no garantiza que coincidan con el payload de la última ejecución reportada.

Para cerrar el diagnóstico hacen falta, de la misma ejecución: payload de Invoice o confirmación de su igualdad con las capturas; por línea `line_number`, `detail_type`, `quickbooks_item_id`, `item_type`, `asset_id`, `quantity`; `id` y `quickbooks_id` de los Assets asociados y candidatos (incluidos duplicados); respuestas Item con Id y Type; y errores estructurados de Requirements. Si el payload no cambió, el importador reutiliza líneas almacenadas sin consultar Items: también se necesita esa versión local de las líneas. No hacen falta credenciales, direcciones ni datos personales.

## Todas las rutas actuales a revisión

En `app/Services/InvoiceRequirements.php`, en orden de evaluación, se interrumpe en el primer fallo:

| Condición | Mensaje actual | Efecto |
| --- | --- | --- |
| GroupLineDetail | Grouped items require administrative review. | Revisión operativa; no se expanden componentes |
| Tipo distinto de SalesItem, Discount, SubTotal o DescriptionOnly | Unsupported invoice line requires administrative review. | Revisión si ya existe almacenado; el importador rechaza tipos desconocidos antes de persistir |
| SalesItem cuyo tipo no es Inventory/NonInventory ni una exclusión válida | Unknown or unsupported Item type. Sync again or review its mapping. | Revisión; incluye referencia ausente con tipo nulo |
| Asset ausente o Asset.quickbooks_id diferente de ItemRef | Physical item … needs an Asset mapping. | Revisión; hoy no distingue ausencia de correspondencia incorrecta |
| Qty no numérica, <=0, fraccionaria o >2147483647 | Physical quantities must be positive whole units. | Revisión operativa; valores no numéricos/no finitos en payload son rechazados antes como error de importación |
| Suma por Asset >2147483647 | Quantity exceeds the supported limit. | Revisión |
| Requisitos vacíos y requirePhysical=true | At least one mapped physical product is required. | Bloquea clasificación; no se ejecuta esta regla en importación/verificación |

Adicionalmente, `InvoiceSyncService` asigna `requires_review` a una factura sin Work Order si el resultado es nulo (excepción) O vacío (sin productos físicos). Este último caso es un falso positivo demostrado para servicios válidos y para facturas únicamente con líneas no físicas admitidas.

Inventory y NonInventory se consideran físicos. Service se omite sin exigir Asset ni validar su cantidad como unidad física. Shipping sólo se omite con tipo Shipping y referencia especial exacta. Descuentos, subtotales y descripciones se omiten operativamente y se conservan contablemente. Tipos desconocidos no se convierten en servicios.

La búsqueda de Asset en importación exige exactamente una coincidencia por quickbooks_id: cero o múltiples coincidencias dejan asset_id nulo. Hay una limitación adicional: se utiliza Item.Type, pero no se compara Item.Id devuelto con el ItemRef solicitado; debe cubrirse en los ajustes.

## Clasificación e integridad

`InvoiceClassificationService` exige requisitos físicos antes de crear Work Order; por eso hoy una factura sólo de servicios no puede crear una orden, aunque aparezca el botón. `unclassified` corresponde a una factura pendiente de clasificación Distribution/Installation, por lo que no representa correctamente servicios fuera de alcance. `created` implica operación creada y `sync_conflict` una operación vinculada inconsistente; tampoco sirven.

Con Work Order existente, la sincronización compara requisitos, cliente y estado void contra el snapshot; una inconsistencia produce sync_conflict y conserva la relación y snapshot. No resuelve automáticamente conflictos previos. Si el payload es idéntico, conserva identidades de líneas y no reevalúa tipos/mappings. Los ajustes no deben introducir reclasificación masiva ni alterar esa ruta silenciosamente.

## Propuesta de etapa 2 (sin implementar)

Introducir `operation_status=out_of_scope`, etiqueta «Fuera del alcance operativo», sólo cuando todas las líneas sean reconocidas como no físicas y no haya inconsistencias. Unknown, grupos y productos inválidos siguen en requires_review. Productos válidos siguen unclassified; con Work Order se mantiene la lógica de conflictos antes que cualquier exclusión.

Impacto: `operation_status` ya es string, por lo que no requiere migración. Añadir el valor al filtro/etiquetas de Filament; ocultar clasificación para ese estado y mantener validación de requisitos en servidor. No cambiar operation_type ni estado contable. No actualizar filas históricas automáticamente; la ruta de payload idéntico debe mantener su protección hasta acordar una reevaluación explícita.

Diagnóstico propuesto: excepción compatible con ValidationException con código, mensaje, regla, índice cero, posición uno, tipo de línea, ItemRef, Item.Type, asset_id, Asset.quickbooks_id y Qty. Distinguir missing_asset, mismatched_asset, unknown_item_type, invalid_physical_quantity, physical_quantity_limit, accumulated_quantity_limit, grouped_line, unsupported_line y missing_physical_products. Evitar nombres, descripciones y payloads completos. Incluir en informe descargable y vista del Dry Run; conservar por separado verificaciones contables e integridad. Para resultados vacíos válidos, informar alcance excluido sin declararlos error operativo. Mantener el fallo del Dry Run ante errores reales.

Pruebas nuevas pendientes: productos solos; servicios solos; mixtas con cantidades de servicio fraccionarias; Asset incorrecto/duplicado; tipo desconocido; referencia Item devuelta incorrecta; acumulación fuera de límite; diagnóstico exacto por línea; alcance vacío legítimo; estados y bloqueo de clasificación. Reutilizar regresiones existentes de cantidades, grupos, shipping, contabilidad, idempotencia, Work Orders, snapshots y relaciones, y las seis variantes raw/SDK de los tres fixtures. Los tipos y mappings simulados deben seguir identificados como tales.

## Verificación ejecutada

Comando: `php vendor/bin/pest tests/Unit/InvoiceIntegrationTest.php tests/Unit/InvoiceRealPayloadTest.php tests/Unit/InvoiceSelectiveSyncTest.php tests/Unit/QuickBooksInvoiceDebugTest.php --compact`.

Resultado: **110 pruebas aprobadas, 1131 aserciones, 0 fallidas**, 91.73 segundos. Se forzó APP_ENV=testing, configuración sin caché existente, DB_CONNECTION=sqlite, DB_DATABASE=:memory:, DB_URL=null y caché array. El soporte de fixtures reafirma conexión en memoria antes de crear sus tablas. El subproceso de simulación tiene su propio aislamiento y prohíbe red.

Son pruebas de la implementación actual; no demuestran una corrección todavía no implementada ni la causa de los dos fallos reales. Incluyen las tres facturas en raw/SDK con tipos y Assets simulados, importación contable, cantidades inválidas, grupos, shipping, idempotencia, protección de operaciones, snapshots, rollback y flujo selectivo. No se ejecutó una suite de concurrencia MariaDB ni una comprobación sobre datos reales. No se añadieron aún las regresiones de la etapa 2.

## Seguridad

No se modificaron datos de producción, ni Assets, Work Orders, instalaciones o snapshots existentes. No se ejecutaron Sync All ni Apply contra producción. No se realizaron escrituras ni lecturas contra QuickBooks real. Las pruebas existentes ejercitan importación, sincronización y Apply exclusivamente con mocks y SQLite aislado; crean/modifican sus propios datos sintéticos. No hubo despliegues, commits, pushes ni migraciones contra producción.
