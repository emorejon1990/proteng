# Invoice Sync: causas confirmadas con capturas reales

Auditoría del 14 de octubre de 2026. Este informe actualiza la conclusión previa de causa no verificada en `invoice-sync-reference-audit.md`.

## Evidencia y causas

El ZIP aportado está disponible en Downloads, fuera del repositorio. Se inspeccionaron `quickbooks_raw.json` y `quickbooks_sdk.json`; ambos corresponden a los tres IDs solicitados, al mismo realm, a production y a minorversion 75. No se hicieron solicitudes reales a QuickBooks.

| Factura | Causa comprobada |
| --- | --- |
| 1982 | La línea 17 (índice desde cero) es SalesItemLineDetail con `SHIPPING_ITEM_ID` y monto 800. Es una referencia especial de envío; tratarla como un ID normal para FindById rechaza sus caracteres no numéricos. También contiene un subtotal en el índice 15 cuyo detalle SDK es una cadena vacía; con la validación actual ese subtotal podía fallar antes de llegar al envío. |
| 11241 | La línea 1 es un subtotal con monto 6490. El JSON original contiene `{}`; el SDK entrega `SubTotalLineDetail = ""` y Amount `"6490.00"`. `is_array($detail)` era false; el monto era numérico válido. |
| 11990 | La línea 42 presenta la misma conversión de subtotal vacío: `{}` en JSON y `""` en SDK, con monto 238649 / `"238649.00"`. La causa es el tipo del detalle, no el importe. |

La [referencia oficial de Invoice de Intuit](https://developer.intuit.com/docs/api/accounting/invoice) incluye `SHIPPING_ITEM_ID` como ItemRef de una línea SalesItem de envío. La excepción se admite exclusivamente en ese contexto; no se eliminó la validación numérica de entidades del gateway ni se habilitaron otras referencias simbólicas.

## Correcciones generales

`InvoiceSyncService::lineDetail()` valida cada línea antes de consultar Items o escribir datos. Solo los tipos soportados se aceptan; un tipo o estructura desconocidos producen `InvoiceDataException` y utilizan el diagnóstico individual existente en la sincronización general.

- SalesItem: mantiene los Items normales con referencias estrictas y sus mappings existentes. Services continúan sin requisitos físicos.
- Envío: conserva el ItemRef exacto `SHIPPING_ITEM_ID` y su monto; se identifica localmente como Shipping, sin FindById de Item, sin inventar producto y sin Asset. `InvoiceRequirements` excluye únicamente esa combinación explícita de los requisitos físicos.
- Subtotal: únicamente la cadena vacía del SDK se interpreta como un detalle vacío para procesarlo. El payload almacenado conserva su representación original; no se reemplaza el monto ni se crea producto.
- Descuento: conserva detalle y monto, contribuye a `discount_total` y no recibe Asset. No se suman subtotales como ingresos ni se recalculan los totales que proporciona QuickBooks.
- Montos cero y strings numéricos: se admiten. Se rechazan valores no numéricos/no finitos; Qty y UnitPrice presentes deben ser numéricos finitos. Las reglas de cantidades físicas positivas y enteras de operaciones siguen en InvoiceRequirements.
- Grupos y DescriptionOnly: conservan el tratamiento existente; los grupos siguen requiriendo revisión administrativa.

No hay condiciones especiales basadas en IDs de factura. El código de producción no contiene los números 1982, 11241 o 11990 para decidir cómo procesar líneas.

## Comportamiento de facturas existentes

Antes de la corrección, la importación creaba nuevas facturas y actualizaba las modificadas. También guardaba nuevamente las facturas sin cambios y borraba/recreaba sus líneas, aunque el evento indicara Invoice Unchanged.

Ahora valida cabecera/referencias/estructuras y compara el payload con la representación JSON que almacena Eloquent, bajo bloqueo transaccional. Una factura sin cambios conserva sus atributos contables, timestamps, IDs de líneas, Assets, operación y snapshot; solo se añade el evento Invoice Unchanged. Si tenía un diagnóstico de sincronización fallida, se limpia `sync_status`/`sync_error` sin reemplazar líneas. `last_synced_at` conserva la fecha de la última importación que escribió datos; el evento aporta la fecha de la revisión sin cambios.

Las facturas nuevas siguen creándose por su ID externo único. Las modificadas reemplazan cabecera/líneas dentro de la transacción existente. Se vuelve a comparar bajo bloqueo al guardar para evitar reescribir si otro importador ya guardó el mismo payload durante las consultas de Items. Los cambios físicos, de cliente o anulación mantienen las reglas sync_conflict, sin resolver conflictos automáticamente.

La importación no crea ni modifica Work Orders, instalaciones, sus tipos o cantidades. Los snapshots no se sobrescriben. Los fallos de datos siguen siendo recuperables por factura; los de conexión, realm, API o base de datos siguen deteniendo la ejecución. No cambió el manejo de reconciliación.

## Archivos

| Archivo | Cambio |
| --- | --- |
| `app/Services/InvoiceSyncService.php` | Validación previa por tipo, subtotal vacío del SDK, referencia de envío, comparación para conservar facturas/líneas sin cambios. |
| `app/Services/InvoiceRequirements.php` | Exclusión explícita de Shipping con SHIPPING_ITEM_ID de requisitos físicos. |
| `tests/Fixtures/QuickBooks/invoices-raw.sanitized.json` | Datos relevantes derivados de la captura HTTP real. |
| `tests/Fixtures/QuickBooks/invoices-sdk.sanitized.json` | Tipos y estructuras derivados de la captura SDK real. |
| `tests/Fixtures/QuickBooks/README.md` | Procedencia, sanitización, supuestos de Items y comando selectivo. |
| `tests/Support/QuickBooksCapturedFixtures.php` | Reconstrucción controlada de objetos SDK para probar la conversión real. |
| `tests/Unit/InvoiceRealPayloadTest.php` | Regresiones de las tres facturas, ambas representaciones e integridad operativa. |
| `tests/Unit/InvoiceIntegrationTest.php` | Ajusta la prueba de caché: la segunda ejecución ahora incluye una factura nueva para verificar limpieza de caché, porque las sin cambios ya no consultan Items. |
| `docs/invoice-sync-real-payload-audit.md` | Informe técnico. |

No se modificaron OAuth, tokens, credenciales, validaciones del gateway, rutas, interfaz, estructura de base de datos ni dependencias.

## Pruebas selectivas y regresiones

La prueba selectiva es reproducible, exclusivamente con fixtures:

```shell
php vendor/bin/pest tests/Unit/InvoiceRealPayloadTest.php --compact
```

Utiliza SQLite `:memory:`, comprueba el nombre de la base antes de crear el esquema y prohíbe por mocks tanto `QuickBooksService::ds()` como consultas de listados. Prueba el mismo importador que la sincronización general, sin ejecutar esta última ni reconciliar facturas ausentes.

Los 20 casos selectivos incluyen las tres facturas en formato raw y SDK, todos sus montos/líneas, igualdad de total con ventas menos descuentos más impuestos, cero en líneas de servicios, ausencia de duplicados, repetición con atributos e IDs de líneas intactos, modificaciones contables, Work Orders/snapshots protegidos, sync_conflict ante cambios físicos, rollback ante un trigger SQL que rechaza líneas y rechazo de estructuras/referencias desconocidas.

La ejecución conjunta de InvoiceRealPayload, InvoiceIntegration, QuickBooksInvoiceDebug, QuickBooksOAuth, RoleAuthentication, PanelNavigation, DistributionSelection, ReviewWorkOrder y ProductionWorkflow pasó: **220 pruebas, 1871 aserciones**. Tras los últimos ajustes de validación de CustomerRef/totales se repitieron las dos suites de facturas: **80 pruebas aprobadas, 802 aserciones**.

Pint, sintaxis PHP y `git diff --check` aprobados. No se ejecutó toda la suite. Las pruebas Feature/Auth tienen la limitación previamente documentada de preparación de catálogos en una base vacía; no se volvieron a ejecutar ni modificar en esta fase. No se ejecutó PHP 8.3 ni concurrencia real MySQL/MariaDB.

## Pendientes y siguiente prueba controlada

El ZIP contiene respuestas de facturas, no respuestas de Items. Las pruebas simulan tipos Inventory/Service y Assets para comprobar integridad; no demuestran cuáles son los mappings o tipos reales de producción. Su comprobación requiere una nueva autorización de lectura/prueba.

Las facturas sin cambios no vuelven a resolver mappings modificados externamente: permanecen intactas conforme al objetivo. Una necesidad de revisar mappings sin cambio contable debe tratarse mediante una operación administrativa explícita, no resolviendo conflictos automáticamente en esta corrección.

Después de autorización adicional, probar primero exclusivamente los tres IDs mediante lecturas individuales y el mismo importador, con el realm confirmado. Mantener una lista explícita, sin listado global ni reconciliación de facturas fuera de ella. Comparar datos anteriores/posteriores, relaciones, cantidades y eventos.

Para una segunda prueba de 20–50 facturas, elegir IDs explícitos que cubran envíos, subtotales vacíos, descuentos fijos/porcentaje, importes cero, servicios, productos, facturas modificadas/sin cambios y operaciones vinculadas. Validar primero en staging con PHP 8.3 y MySQL/MariaDB. Detener ante errores globales; revisar los errores de datos individualmente y comparar snapshots/cantidades antes y después. No utilizar Sync All para esa prueba. HTTP 504 y procesamiento por lotes quedan para la fase independiente solicitada.

No se modificó la base existente, no se ejecutó QuickBooks real, no hubo despliegue, commit ni push.
