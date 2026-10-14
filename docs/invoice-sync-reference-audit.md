# Auditoría de Invoice Sync — 14 de octubre de 2026

## A. Causa identificada

La excepción de producción indica que una llamada a `QuickBooksReadGateway::find()` recibió una entidad no admitida o un identificador no numérico. Las llamadas del importador usan entidades constantes `Customer` e `Item`; el valor de la referencia es, por tanto, el principal punto que debe diagnosticarse. El ID de la factura que se estaba procesando no demuestra cuál referencia falló.

El código anterior extraía `value`/`Value` de arrays y convertía cualquier otro valor a string. No validaba valores anidados ni admitía directamente los objetos `IPPReferenceType`. Además, buscaba un Item en cualquier detalle con `ItemRef`, sin distinguir tipos de línea, y relanzaba todos los errores de una factura. Son defectos comprobados por inspección y fixtures, pero no prueban cuál ocurrió en producción.

`composer.lock` fija QuickBooks PHP SDK v6.3.1. Los archivos instalados `IPPReferenceType.php` e `IPPid.php` muestran que la referencia hereda la propiedad pública `value`. Se admiten explícitamente ese objeto, arrays `value`/`Value`, strings e integers; se rechazan estructuras anidadas y objetos arbitrarios en `ref()`. `entityRef()` exige dígitos completos, incluso al llamar directamente a `find()`. Se conserva `ref()` para referencias de códigos como `USD` y códigos de impuestos, que no son IDs numéricos de entidades.

Fuentes externas: [referencia oficial de Invoice](https://developer.intuit.com/app/developer/qbo/docs/api/accounting/all-entities/invoice) y [guía oficial del SDK](https://github.com/intuit/QuickBooks-V3-PHP-SDK/blob/master/docs/quickstart.html). La implementación se contrastó con la versión instalada; no se actualizaron dependencias.

## B. Factura 1982

No se encontró su payload real en el código ni en las pruebas inspeccionadas. No se hicieron solicitudes reales a QuickBooks. La causa específica sigue sin verificarse.

La prueba de regresión utiliza una factura representativa con ID `1982` y un ItemRef no numérico. Comprueba que se omite esa factura, continúa otra válida y genera un resumen parcial. El fixture no pretende reproducir la estructura real de producción.

En el siguiente intento autorizado, el evento `Invoice Sync Skipped` mostrará la entidad, el tipo de línea, su índice desde cero y una categoría de formato: por ejemplo `nested-value`, `missing-value-key`, `empty-value` o `invalid-value`. No registra el valor rechazado. CustomerRef se diagnostica sin índice de línea porque pertenece a la cabecera. Un ID de factura ausente o inválido detiene el listado, pues no puede garantizarse una reconciliación segura.

## C. Archivos modificados

| Archivo | Cambio y propósito |
| --- | --- |
| `app/Services/InvoiceDataException.php` | Excepción específica para errores recuperables de datos, con contexto diagnóstico seguro. |
| `app/Services/QuickBooksReadGateway.php` | Normalización de referencias, validación numérica estricta y comprobación de identidad en respuestas FindById. |
| `app/Services/InvoiceSyncService.php` | Aislamiento por factura, creación de clientes dentro de la transacción, tratamiento por tipo de línea, validación de grupos, eventos y resumen parcial, caché de Items limitada a la ejecución. |
| `app/Services/InvoiceRequirements.php` | Líneas desconocidas requieren revisión; evita clasificarlas ignorando requisitos potenciales. Se conserva la revisión obligatoria de grupos. |
| `app/Filament/Admin/Pages/QuickBooksSync.php` | Notificaciones de éxito, parcial o detención; evita registrar excepciones SQL completas con bindings de facturas. |
| `app/Filament/Admin/Pages/InvoiceSyncEvents.php` | Nuevos filtros, columnas diagnósticas, contadores y fallback del ID desde la factura vinculada. Conserva eventos históricos. |
| `tests/Unit/InvoiceIntegrationTest.php` | Nuevas regresiones y adaptación de dos expectativas al tratamiento seguro de errores parciales/globales. |
| `docs/invoice-sync-reference-audit.md` | Informe, evidencia, limitaciones y procedimiento de despliegue. |

No hay migraciones nuevas ni cambios en OAuth, credenciales, roles, rutas, modelos de operaciones o dependencias.

## D. Tratamiento de líneas

- SalesItem: solo estas líneas consultan Items. Inventory/NonInventory pueden usar un Asset existente con una única coincidencia; no se crean mappings. Service conserva sus datos y no recibe Asset.
- SalesItem sin ItemRef o con ItemRef nulo: se conserva sin inventar referencia y queda para revisión operativa. Una referencia presente pero malformada provoca error de datos.
- Discount/SubTotal: conservan detalle y monto, sin consultas de Item ni mapping físico.
- DescriptionOnly: conserva el texto y el payload original. Si no trae Amount, el monto local es cero porque la columna requiere un número.
- Group: conserva el grupo y sus hijos en el payload, valida referencias presentes de grupo y SalesItem hijos, sin crear mappings ni expandir requisitos físicos. La clasificación requiere revisión administrativa. Se limita el anidamiento a 20 niveles.
- Otros tipos: se conservan cuando su estructura básica y monto son válidos, pero bloquean la clasificación automática hasta revisión.

Los payloads originales siguen almacenándose localmente como antes; la nueva instrumentación no los copia a logs o mensajes de administración.

## E. Integridad de datos

Se preparan y validan líneas antes de escribir la factura. Cliente faltante, cabecera, reemplazo de líneas, estados y eventos de importación pertenecen a la misma transacción. Un fallo revierte también el cliente recién creado. Las pruebas inducen fallos SQL durante la inserción de líneas y comprueban la conservación del total y las líneas anteriores.

La sincronización no crea, recrea ni modifica Work Orders, instalaciones, cantidades de producción ni tipos de operación. Se conserva el mecanismo `sync_conflict`, el snapshot operativo y las relaciones existentes. Para errores de datos, únicamente se actualiza el estado diagnóstico de sincronización y se añade el evento de fallo, conjuntamente en otra transacción.

## F. Errores parciales

Solo `InvoiceDataException` permite continuar por factura. Se registra `Invoice Sync Skipped`, se incrementa `failed` y se conservan los datos previos. Una ejecución finalizada devuelve `{synced, failed}` y crea exactamente un resumen `Invoice Sync Partial` o `Invoice Sync Completed`. Las importaciones también distinguen `Invoice Imported`, `Invoice Updated` e `Invoice Unchanged`.

Los demás errores detienen la ejecución: OAuth, realm, API, conexión, rate limit, respuesta global inválida, errores SQL o transaccionales. Se crea un único evento global `Invoice Sync Stopped`, con la factura en curso cuando se conoce. Las importaciones previas válidas siguen guardadas; no existe una transacción de todo el lote.

La reconciliación solo se inicia después de completar el listado. Un fallo de verificación de una factura ausente también detiene el proceso, sin alterar su estado operativo ni continuar suponiendo una eliminación. No se borran facturas. Si la base de datos impide guardar el propio evento de detención, se registra únicamente el tipo de excepción en el log y se conserva la excepción original.

## G. Pruebas y validación

Ejecución final relacionada:

```text
php vendor/bin/pest tests/Unit/InvoiceIntegrationTest.php tests/Unit/QuickBooksOAuthTest.php tests/Unit/RoleAuthenticationTest.php tests/Unit/PanelNavigationTest.php tests/Unit/DistributionSelectionTest.php tests/Unit/ReviewWorkOrderTest.php --compact
177 passed — 1152 assertions
```

Incluye 60 casos de InvoiceIntegration, normalización, SDK, arrays, múltiples productos, servicios, descuentos, subtotales, grupos, referencias nulas/malformadas, cliente faltante, payload incompleto, conservación de operaciones, errores parciales, fallos globales, cambios de realm, rollback SQL, eventos, UI parcial y caché por ejecución.

Ejecución adicional:

```text
php vendor/bin/pest tests/Feature/Auth tests/Integration/ProductionWorkflowTest.php --compact
11 passed — 16 failed — 86 assertions
```

Los 11 escenarios de ProductionWorkflow pasaron. Las 16 pruebas Feature/Auth fallaron durante la preparación de SQLite, antes de comprobar autenticación: la migración existente `2026_10_13_110000_add_review_location.php:20` exige una ubicación Quality única y la base vacía del harness no la contiene. Esa migración y las pruebas Feature/Auth no se modificaron para ocultar el fallo. Las pruebas específicas RoleAuthentication, PanelNavigation y QuickBooksOAuth sí pasaron.

Las pruebas usan base aislada SQLite en memoria y mocks; no se modificaron datos existentes ni se hicieron solicitudes reales a QuickBooks. No se ejecutó toda la suite ni las pruebas de concurrencia MySQL/MariaDB. PHP local: 8.2.12; PHP 8.3 de producción no se ejecutó aquí.

Pint aplicado y verificación `--test` aprobada en los siete archivos PHP modificados. `php -l` aprobado en todos ellos. `git diff --check` aprobado y diff completo revisado. `route:list --path=quickbooks` y `route:list --path=invoices` verificaron OAuth y rutas Admin, Manager y Customer; no se alteraron redirecciones multipanel. El servicio sigue exigiendo Admin y rechaza usuarios con rol Customer, incluso si también tienen Admin.

## H. Producción

No se hizo despliegue, commit ni push.

Procedimiento propuesto para el responsable del despliegue:

1. Revisar el diff y este informe; conservar un respaldo según el procedimiento habitual del proyecto.
2. Validar en staging con PHP 8.3 y MySQL/MariaDB, incluidas las relaciones de facturas ya clasificadas. Resolver por separado la preparación de datos del harness Feature/Auth.
3. Desplegar los cambios mediante el procedimiento habitual, sin actualizar dependencias ni ejecutar migraciones para esta corrección. Regenerar cachés/reiniciar procesos persistentes según corresponda al entorno.
4. Mantener la conexión y realm actuales. Ejecutar Sync Invoices desde Admin en un intento autorizado.
5. Comprobar el resumen, el evento de 1982 y los campos diagnósticos; contrastar la referencia con QuickBooks por lectura autorizada. Verificar cantidades, snapshots, relaciones y estados operativos previos.
6. Ante un error global, revisar la conexión, realm, API o base de datos antes de reintentar. Ante un error de datos, revisar esa factura sin resolver automáticamente conflictos operativos.

## I. Riesgos pendientes

La factura real 1982 puede revelar otro formato o problema; los fixtures no garantizan su importación. Los grupos permanecen sujetos a revisión y no generan requisitos físicos automáticamente. Una entidad ausente en FindById se trata conservadoramente como detención global porque el gateway actual no distingue de forma fiable ausencia real, permisos y otros fallos.

No se probó PHP 8.3 ni concurrencia real MySQL/MariaDB. La caché se limpia al finalizar o fallar la ejecución y no persiste entre sincronizaciones; dentro de una ejecución reutiliza el primer Item leído. Los eventos `Invoice Unchanged` aumentarán el volumen del historial, por lo que conviene observar su crecimiento sin borrar historial. Si la base de datos está indisponible, no puede garantizarse guardar un evento en ella; queda el diagnóstico seguro en logs.
