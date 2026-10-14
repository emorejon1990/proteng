# Prueba selectiva de Invoice Sync

## 1. Arquitectura

La página Admin QuickBooksSync incluye **Test 3 Invoices**, **Apply 3 Invoices** y **Download Test Report**. El alcance está fijado en `InvoiceSelectiveSyncService::IDS`: 1982, 11241 y 11990. No existe un campo para introducir otros IDs.

Ambas modalidades consultan las facturas individualmente con `QuickBooksReadGateway::find()`. Se reutilizan OAuth y las validaciones existentes; las consultas adicionales de Customer e Item se limitan a referencias necesarias de esas facturas y se reutilizan dentro de la ejecución. SHIPPING_ITEM_ID nunca se consulta como Item. No se llama a `page()`, `sync()` ni a la reconciliación de ausentes desde la prueba selectiva.

El procesamiento usa el mismo `InvoiceSyncService::import()` de la sincronización general. Un gateway de respuestas capturadas permite reutilizarlo sin nuevas llamadas de red durante la simulación o la fase de escritura.

## 2. Archivos modificados

| Archivo | Responsabilidad |
| --- | --- |
| `app/Services/InvoiceSelectiveSyncService.php` | Alcance fijo, captura, aprobación vinculada al administrador, caducidad, comprobación de cambios y Apply transaccional. |
| `app/Services/InvoiceSelectiveSandbox.php` | Subproceso y copia temporal del esquema/datos relevantes a SQLite en memoria. |
| `app/Console/Commands/SimulateSelectedInvoices.php` | Entrada interna del subproceso; acepta exclusivamente sus archivos temporales privados. |
| `app/Services/QuickBooksCapturedReadGateway.php` | Lecturas exclusivamente de respuestas capturadas; prohíbe listados globales. |
| `app/Services/InvoiceSelectiveVerification.php` | Estado anterior/posterior y comprobaciones contables y operativas. |
| `app/Services/QuickBooksSyncLock.php` | Bloqueo atómico compartido y comprobación de propiedad antes de escribir. |
| `app/Providers/AppServiceProvider.php` | Registra el bloqueo con alcance por petición. |
| `app/Services/InvoiceSyncService.php` | Bloqueo de Sync general, comprobaciones de propiedad y comparación de JSON independiente del orden de claves de objetos. Conserva el orden de arrays de líneas. |
| `app/Services/CustomerSyncService.php`, `app/Services/AssetSyncService.php` | Comparten el bloqueo con las sincronizaciones existentes. |
| `app/Filament/Admin/Pages/QuickBooksSync.php` | Acciones, confirmaciones, estado Livewire bloqueado y descarga privada; Sync All mantiene el bloqueo durante toda su secuencia. |
| `resources/views/filament/admin/pages/quick-books-sync.blade.php` | Resultados individuales y resumen en pantalla. |
| `tests/Unit/InvoiceSelectiveSyncTest.php` | Pruebas del subproceso real con respuestas simuladas, permisos, confirmaciones, bloqueo, integridad y descarga. |
| `tests/Support/ProductionDatabase.php` | Fija el entorno de fixtures como testing aun si hay configuración local cacheada. Sigue usando SQLite en memoria. |

No se añadieron migraciones ni se modificaron credenciales, tokens, realm, rutas multipanel o dependencias.

## 3. Dry Run

Es la operación predeterminada de Test 3 Invoices y requiere confirmar el modal que identifica los tres IDs.

El proceso principal solo lee facturas y los datos locales relevantes. El subproceso recibe una entrada cifrada y desactiva la configuración cacheada heredada antes de iniciar Laravel; arranca con SQLite en memoria, caché/sesiones/correo en memoria y telemetría deshabilitada. Antes del importador se eliminan las demás conexiones configuradas. El gateway capturado impide consultas nuevas de red y los eventos de modelos se suprimen en la simulación.

Los eventos de importación se crean únicamente en SQLite y desaparecen al terminar el proceso. No se usa un rollback sobre producción como mecanismo de aislamiento. Los temporales están fuera de directorios públicos, en un directorio aleatorio privado; entrada, informe temporal y directorio se eliminan en `finally`.

Una aprobación solo se genera si pasan las verificaciones de las tres facturas. Se guarda cifrada en la caché privada, vinculada al administrador, por 15 minutos y con uso único. La caché y el bloqueo son metadatos temporales de coordinación; Dry Run no modifica tablas de facturas, líneas, clientes, Assets, Work Orders, instalaciones ni eventos del negocio.

## 4. Apply

Apply aparece tras superar Dry Run. Requiere otro modal y una casilla de aceptación obligatoria. El servicio exige tanto esa aceptación como una aprobación vigente del mismo administrador; no basta con manipular propiedades del navegador.

Antes de escribir vuelve a consultar los mismos IDs. Compara respuestas, realm, entorno, esquema y estado local con lo aprobado. Si cambian, se detiene sin importar y el informe identifica rutas de campos diferentes, sin publicar sus valores sensibles. La aprobación se consume incluso si Apply falla; hace falta otro Dry Run para reintentar.

Cada factura se procesa dentro de una transacción exterior que comprende el importador y las comprobaciones posteriores. Se bloquean y registran la factura, sus líneas, Work Order, cantidades y la instalación vinculada. Una comprobación fallida provoca rollback de esa factura y detiene las siguientes. Las facturas anteriores que hayan terminado correctamente permanecen guardadas: no hay una transacción de todo el lote.

## 5. Protección de datos

Se verifican identidad, realm, cliente, número, moneda, fechas, estado contable, total, balance, impuestos, descuentos, payload completo, número de líneas, ausencia de duplicados, payload e importes de cada línea, cantidades, precios y códigos de impuestos.

También se comprueba que Shipping no tenga Asset ni requisitos físicos, que descuentos/subtotales no sean productos y que se conserven Work Orders, cantidades, instalaciones, clasificación y snapshots. Los conflictos existentes no deben resolverse automáticamente. Las facturas sin cambios mantienen los IDs de sus líneas; solo añaden el evento de revisión y pueden limpiar un diagnóstico anterior de fallo, conforme al importador existente.

El informe contiene comprobaciones individuales, errores, cambios, estado final, tiempos, IDs de líneas y hashes de snapshots/cantidades. No exporta payloads completos, nombres de clientes, tokens ni credenciales. Si falla Apply, diferencia el estado intentado del estado posterior al rollback. Si no puede verificar la base después del fallo, marca `unverified_stop`, no éxito.

El bloqueo compartido cubre Dry Run, Apply, Sync Invoices, Sync Customers, Sync Products y la secuencia Sync All. Tiene una concesión de una hora; se comprueba su propiedad antes de escribir y dentro de las transacciones de facturas. Si se pierde, se detienen nuevas escrituras.

## 6. Pruebas locales

La ejecución conjunta relacionada aprobó **238 pruebas, 2081 aserciones**: InvoiceSelectiveSync, InvoiceRealPayload, InvoiceIntegration, QuickBooksInvoiceDebug, QuickBooksOAuth, RoleAuthentication, PanelNavigation, DistributionSelection, ReviewWorkOrder y ProductionWorkflow.

Tras reforzar las comprobaciones contables posteriores, se repitió la suite selectiva: **18 pruebas, 210 aserciones aprobadas**. Incluye el subproceso CLI real y comprueba que los datos originales y su conexión PDO permanecen intactos; todas las respuestas de QuickBooks son mocks/fixtures sanitizados.

Se comprobaron aprobación caducada/ajena/falsificada, segunda confirmación, descarga de informe, bloqueo compartido y perdido, rechazo de datos cambiados, errores globales sin lecturas posteriores, conservación de líneas/operaciones y rollback ante errores SQL o modificaciones inesperadas de cantidades. Pint, sintaxis PHP y revisión del diff forman parte de la validación final.

No se ejecutó toda la suite ni QuickBooks real. Las limitaciones previamente documentadas de preparación de catálogos en Feature/Auth no se corrigieron ni se ocultaron aquí.

## 7. Limitaciones

Dry Run usa SQLite, no reproduce todos los detalles de MySQL/MariaDB. Copia columnas, nulabilidad, defaults e índices únicos relevantes; no replica triggers, todas las restricciones de claves foráneas, collations o límites de longitud del motor original. Apply usa las restricciones reales y revierte ante fallos. Tampoco ejecuta observadores de modelos durante Dry Run; el flujo actual inspeccionado de importación no depende de ellos para sus validaciones. Cambios futuros en observadores requieren revisar el aislamiento y las comprobaciones.

Se necesita PHP CLI con PDO SQLite y permiso para ejecutar procesos y crear temporales privados. El subproceso tiene un timeout de 120 segundos. No se probó PHP 8.3 ni concurrencia real entre nodos MySQL/MariaDB; las pruebas locales usaron PHP 8.2 y SQLite.

La caché debe ofrecer bloqueos atómicos y ser compartida por todos los trabajadores/nodos. Array se permite solo en testing. File sirve para una instalación en un único host con almacenamiento compartido entre sus procesos; para varios nodos se requiere un store compartido como database o Redis. Una interrupción abrupta puede dejar el bloqueo hasta su caducidad; no forzar su liberación sin comprobar que el proceso terminó.

Las operaciones operativas de otros usuarios pueden cambiar el estado entre Dry Run y Apply; en ese caso la aprobación deja de ser válida. HTTP 504 y procesamiento por lotes siguen fuera de esta fase.

## 8. Despliegue

No se desplegó, hizo commit ni push. Antes de un despliegue autorizado:

1. Revisar el diff y validar en staging con PHP 8.3/MySQL o MariaDB.
2. Comprobar PHP CLI, PDO SQLite, ejecución de procesos, temporales privados y caché atómica compartida.
3. Desplegar por el procedimiento habitual, sin migraciones nuevas ni cambios de OAuth/realm. Regenerar cachés y reiniciar procesos persistentes según el entorno.
4. Confirmar que las acciones solo estén disponibles para Admin autorizado y que Apply esté oculto inicialmente.

## 9. Ejecución real supervisada

La ejecución real sigue pendiente de autorización adicional, tanto para Dry Run como para Apply.

1. Confirmar la empresa/entorno y conservar el respaldo habitual y las referencias operativas anteriores.
2. Entrar como Admin en QuickBooks Sync y seleccionar Test 3 Invoices.
3. Confirmar Dry Run para 1982, 11241 y 11990. No usar Sync All ni Sync Invoices para esta prueba.
4. Descargar y revisar el informe. Todas las verificaciones obligatorias deben pasar; revisar especialmente envío, subtotales, descuentos, líneas y relaciones.
5. Si se autoriza Apply, ejecutarlo dentro de 15 minutos, revisar el segundo modal y aceptar la casilla explícita.
6. Descargar el informe posterior y contrastar estado contable, IDs de líneas sin cambios, snapshots, Work Orders y cantidades. Si se detiene, revisar la factura señalada y las verificaciones; no reintentar automáticamente.

## 10. Retirada del botón temporal

Eliminar las tres acciones selectivas, sus propiedades Livewire y la sección de resultados de la vista. Retirar InvoiceSelectiveSyncService, InvoiceSelectiveSandbox, InvoiceSelectiveVerification, QuickBooksCapturedReadGateway y SimulateSelectedInvoices, junto con sus pruebas específicas, cuando ya no se necesiten.

Conservar las correcciones generales del importador y, preferiblemente, el bloqueo compartido de sincronización. Las aprobaciones pendientes caducan a los 15 minutos; su almacenamiento físico sigue el mantenimiento habitual del store de caché. No vaciar toda la caché ni borrar historial de facturas para retirar la herramienta.
