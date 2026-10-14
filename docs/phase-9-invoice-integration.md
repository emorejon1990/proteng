# Phase 9: integración de Invoices

## Arquitectura y auditoría

Base: `origin/main` / `bf61d19`, merge de Phase 8. Desarrollo en
`phase-9-invoice-integration`. El árbol inicial estaba limpio y main coincidía
con origin/main. No se hizo merge, push ni despliegue.

Se revisaron modelos, servicios, recursos, políticas, paneles, rutas, migraciones,
seeders, impresión, instalación y selección/trazabilidad de Distribution. El esquema
real configurado no pudo consultarse: MySQL devuelve conexión rechazada (2002).
Por tanto, los catálogos históricos deben verificarse antes del despliegue; no se
presentan los seeders como evidencia de los IDs reales.

Diferencias encontradas:

* Distribution es `work_orders` + requisitos `wo_ass.quantity`; no existe un nuevo
  modelo Distribution. `Asset::workOrders` conserva un antiguo pivot `quant`, pero
  esta implementación usa exclusivamente `WorkOrder::workOrderAssets.quantity`.
* Installation usa `installations`, templates `equipment_installation_steps`,
  `installation_steps` e `installation_assignments`. No tenía relación con
  WorkOrder ni entrada Installation en ProductionCatalog.
* La migración histórica de invoices ya impone `quickbooks_id` único. Customers
  y Assets usan IDs externos sin realm. La arquitectura es de una compañía;
  Phase 9 conserva ese índice y rechaza otra compañía una vez vinculada.
  No constituye soporte multiempresa.
* InvoiceResource permitía creación/edición y WorkOrder ofrecía Create Invoice.
  InvoicePolicy autorizaba ver cualquier registro. Se cerraron estas vías.
* AssetSync importaba solo Inventory, sin paginación ni actualización y podía
  reiniciar los pesos. Ahora pagina y conserva pesos/calibración existentes.

```text
QuickBooks
    |
    v
Invoice Sync
    |
    v
Local Invoice
    |
    v
Invoice Lines
    |
    v
Classification
    |
    +---- Distribution
    |         |
    |         v
    |     WorkOrder (New)
    |         |
    |         v
    |     Phase 8 approval / start / scanner
    |
    +---- Installation
              |
              v
         WorkOrder (New)
              |
              v
       Installation Flow (draft, existing steps / assignment)
```

## Tablas, migración y relaciones

Única migración nueva: `2026_10_13_140000_add_invoice_integration.php`.
No se editaron migraciones antiguas ni se ejecutó una migración en la base local.

* customers: direcciones billing/shipping JSON, con Line1–Line5, City,
  CountrySubDivisionCode (State de QuickBooks), PostalCode, Country y demás
  atributos originales.
* asset: quickbooks_item_type y quickbooks_active nullable.
* invoices: realm_id, operation_type/status, classified_at/by, work_order_id único
  con FK restrict, direcciones JSON, moneda, tax/discount totals decimales,
  timestamps de actualización/sync, sync_status/error, raw_payload y snapshot
  operativo JSON. Se conservan las columnas y registros existentes.
* invoice_lines: FK invoice/asset, identificación externa, ordinal único por
  Invoice, DetailType, Item ID/tipo, cantidades/precios decimal(18,6), importe
  decimal(18,2), TaxCode y payload original. Nunca se usan columnas FLOAT.
* installations: work_order_id nullable único, FK restrict. Las instalaciones
  históricas permanecen sin vincular, sin inventar WorkOrders.
* invoice_events: auditoría contable/operativa propia con FKs invoice, WorkOrder,
  Installation y actor; evento, metadata y timestamps. No se utiliza history
  de tubos para estos eventos.

Relaciones: Invoice customer/lines/workOrder/events/classifiedBy; InvoiceLine
invoice/asset; WorkOrder invoice/installation; Installation workOrder/invoice.
Una FK única en Invoice impide reutilizar una WorkOrder para varias facturas;
la clasificación bajo lock impide generar varias operaciones por factura.

La migración agrega Installation por nombre normalizado únicamente si falta.
No cambia IDs, renombra ni elimina catálogos. ProductionCatalog resuelve el tipo
por nombre o `PRODUCTION_INSTALLATION_TYPE_ID`, verificando su existencia.
Si hay nombres duplicados, la clasificación falla y exige revisión administrativa.
No se corrigen ni eliminan duplicados automáticamente.

El rollback de esta migración se bloquea deliberadamente: eliminar columnas,
eventos o relaciones perdería auditoría. Usar una migración forward revisada.
MariaDB/MySQL realiza DDL con commits implícitos: revisar el esquema y respaldo
antes de ejecutar y resolver manualmente una migración interrumpida.

## Servicios y sincronización

`QuickBooksReadGateway`: interfaz de solo lectura basada en SDK instalado,
Query paginado, FindById y DownloadPDF en memoria. Comprueba getLastError y
propaga errores/timeout/HTTP; no posee métodos Add, Update, Void, Delete o email.
Normaliza referencias tanto escalares como objetos value. El SDK declara
propiedades ausentes como null: se eliminan esos defaults al normalizar objetos.
Un array explícito conserva null y vacíos, distinguiendo ausencia de borrado.

`CustomerSyncService::import`: conserva direcciones y contacto ausentes;
actualiza o vacía únicamente campos explícitos. Importar un customer faltante
para un Invoice no crea usuario ni envía correo. El flujo existente de creación
manual de customers conserva su comportamiento.

`InvoiceSyncService`: solo Admin puede iniciar sync. Páginas de 100, orden por Id.
Customer local requerido: si falta, se lee Customer individualmente de QuickBooks.
Los Items se consultan por ID real; no se usa el nombre para mapear.
Se valida encabezado/Line antes de reemplazar datos. Header y todas las líneas
se guardan dentro de una transacción con locks. El índice externo existente
previene duplicación de encabezados; las líneas se reemplazan solo después de
recibir y validar el payload completo. Un error revierte la transacción y
conserva las líneas anteriores. No hay sincronización parcial de una factura.

Servicios/descuentos/subtotales/descripciones quedan en invoice_lines y raw_payload.
SalesItemLineDetail no implica producto: Service no genera requisito. Inventory
 y NonInventory se consideran bienes físicos y necesitan una correspondencia
Asset única. Group y tipos desconocidos se guardan, pero requieren revisión;
no se expanden grupos automáticamente. Un fallo al leer un Item aborta el Invoice,
no se asume que sea un servicio. No se crea Asset desde InvoiceSync.
AssetSync conserva la importación existente de Inventory; NonInventory se mapea
explícitamente desde el campo QuickBooks Item ID del recurso Asset y se valida
por el tipo leído durante sync. Después de mapear, volver a sincronizar.

Datos de pago, impuestos, descuentos y metadatos completos quedan también en
raw_payload. Los campos operacionales no vienen del SDK ni se envían a QuickBooks.
Los importes contables se toman del origen; no se recalcula una factura financiera.

Errores de token vencido, rate limit, HTTP, timeout o SQL se registran y detienen
el lote; facturas anteriores completas permanecen sincronizadas. No se implementa
un retry ilimitado ni se convierte un fallo en un éxito. Reconectar/reintentar
manualmente. El refresh token sigue el flujo existente de conexión QuickBooks.

Al completar la consulta, una factura local ausente se verifica mediante FindById.
Si también falla esa lectura, se registra Source Unavailable y, con operación,
requires_review. No se afirma que un timeout sea prueba de eliminación. Nunca
se elimina la operación, Installation, seriales ni reservas. La marca exacta
PrivateNote=Voided se conserva como estado void; no se confunde saldo cero con void.

## Clasificación atómica

`InvoiceClassificationService`: autoriza Admin/Manager en servidor y Gate;
Customer queda excluido incluso con roles adicionales. Bloquea el Invoice,
verifica sync, Customer, operación previa, tipos de Items y cantidades positivas
enteras dentro del rango de wo_ass.quantity. Agrega filas repetidas por Asset.

Resuelve tipo y estado New con ProductionCatalog. Usa el generador existente
WOname y prefijos WD/WI. Crea requisitos wo_ass y, para Installation,
InstallationService crea una única asignación y copia los templates del equipo.
Toda la creación, la clasificación y auditoría comparten la transacción.
Errores de validación/SQL/auditoría hacen rollback completo. Una segunda petición
tras el lock observa la operación ya creada y se rechaza.

Installation exige equipo seleccionado con pasos configurados, usuario con rol
Inst_Manager y usuario Worker. No selecciona equipos/usuarios por defecto, no
inventa templates y no completa pasos. WorkOrder permanece New e Installation
draft. Su ejecución usa el wizard y flujo Installation existentes; no se envía
a las transiciones production/distribution de Phase 8. El tipo Installation
no se ofrece en la creación manual de WorkOrder, que no tiene ese formulario.
Las instalaciones manuales existentes continúan funcionando.

Distribution no reserva ni crea productos, seriales, ubicaciones o ciclos.
Approval/start y escaneo siguen usando WorkOrderLifecycleService,
DistributionSelectionService y ProductTraceabilityService existentes.

## Conflictos y auditoría

Al clasificar se guarda snapshot de requisitos y Customer QuickBooks ID.
Sync posterior actualiza contabilidad y compara requisitos físicos nuevos con
el snapshot. Cambios de productos/cantidades/customer, mapeos inválidos o void
marcan sync_conflict y registran la diferencia. Nunca cambia wo_ass, estado de
WorkOrder, pasos, asociaciones de productos o seriales. Cambiar solo balance,
precio o estado paid no crea conflicto físico.

Los conflictos se conservan para revisión incluso si el origen vuelve al valor
anterior; no se resuelven automáticamente. Phase 9 no implementa reclasificación,
eliminación o reparación administrativa de operaciones.

Eventos: Invoice Imported/Updated/Classified, Distribution WorkOrder Created,
Installation WorkOrder Created, Invoice Sync Conflict/Failed, Source Unavailable.
Admin dispone de Invoice Sync Events, incluyendo fallos sin Invoice local.
Detalles completos de excepciones permanecen en logs; la interfaz muestra un
mensaje genérico y el ID externo, sin credenciales.

## Filament y permisos

InvoiceResource Manager se registra también en Admin. Tabla con Customer,
número/ID externo, fechas, total/balance, estado contable/operacional/sync y
WorkOrder. Búsqueda por número/ID/Customer; filtros por Customer, fechas,
paid/unpaid, tipo y estado operacional. Detalles muestran líneas y mapeos,
advertencia de conflictos y últimos eventos. Classify abre modal con datos de
Installation condicionales. Tras crear muestra enlace y no permite clasificar otra vez.
View WorkOrder muestra requisitos y enlace al flujo Installation; WorkOrder e
Installation tienen View Invoice. No se accede a recursos de Manager desde el
panel Customer.

No existen rutas de creación/edición de Invoice ni acciones de modificación.
InvoicePolicy deniega create/update/delete/restore/forceDelete; create() legacy
lanza excepción antes de tocar el SDK. Create Invoice desde WorkOrder desaparece.
Las clases legacy CreateInvoice/EditInvoice quedan sin rutas, además del bloqueo
por política y servicio; no se elimina código compartido de customers.

Admin: sync, consultas, clasificación, eventos y documentos.
Manager: consultas, clasificación y operaciones/documentos.
Worker: sin acceso a facturas; ejecuta flujos ya autorizados.
Customer: query estricta por customer del usuario y policy de propiedad; nunca
clasifica ni consulta facturas ajenas. PDF/packing aplican Gate antes de leer o
renderizar. El recurso Invoice no reclama profiles mediante coincidencia de email.
Los mecanismos de permisos/usuarios de Customer no se cambian.

## Documentos

Invoice PDF: SDK `DownloadPDF($entity, null, true)`, mismo realm de la factura,
contenido validado por firma %PDF-, sin archivo permanente ni emails. Error claro
HTTP 502 si falla. Nombre seguro basado en ID local.

Referencia oficial: https://developer.intuit.com/app/developer/qbo/docs/api/accounting/all-entities/invoice
Documenta GET /v3/company/{realm}/invoice/{id}/pdf.

Packing Slip: no se encontró operación de Packing Slip en la API Invoice oficial
ni SDK instalado. Se genera localmente con la biblioteca DomPDF existente y el
snapshot materializado en requisitos de la operación Distribution. Muestra
compañía, Customer, dirección shipping sincronizada, números, fecha, producto,
cantidad y descripción. No incluye servicios, descuentos, precio, impuestos,
saldo ni pagos. Una futura extensión puede añadir los seriales de asociaciones
Phase 8; no añade escaneo nuevo.

Tipos oficiales de Items: https://developer.intuit.com/app/developer/qbo/docs/api/accounting/all-entities/Item

## Pruebas y validación

`tests/Unit/InvoiceIntegrationTest.php`: mocks de QuickBooks; migraciones reales
sobre SQLite in-memory; importación/actualización/idempotencia, líneas mixtas,
servicios, pendientes de mapeo, customers ausentes, direcciones ausentes/vacías,
cantidades inválidas, tipos dinámicos, Distribution New sin seriales, integración
requirements Phase 8, Installation/templates/asignación, duplicados, rollback,
conflictos, void, desaparición, políticas/propiedad, PDF, packing, paginación,
rechazo de escrituras legacy y acciones Filament Manager/Admin.

`tests/Concurrency/InvoiceClassificationConcurrencyTest.php`: dos procesos con
barrera simultánea sobre una base MySQL vacía separada; uno crea y el otro se
rechaza. El test verifica una WorkOrder y un conjunto de requisitos. SQLite no
prueba lockForUpdate; por eso esta prueba se omite si no hay MySQL aislado.
Nunca conecta este test a la base de aplicación ni limpia una base existente.

Comandos:

```text
php vendor/bin/pest tests/Unit tests/Integration tests/Concurrency --compact
php vendor/bin/pest tests/Unit/InvoiceIntegrationTest.php --compact
php artisan route:list --path=invoices
```

Para concurrencia, crear externamente una base VACÍA cuyo nombre empiece con
`proteng_invoice_test_` y configurar INVOICE_TEST_MYSQL_DATABASE, HOST, PORT,
USERNAME, PASSWORD. Ejecutar el archivo concurrency directamente. No reutiliza
la base después del test, ni ejecuta wipe/fresh/truncate. Los resultados finales
y limitaciones de ejecución se añaden abajo antes de entregar la rama.

## Preparación de despliegue (no ejecutada)

1. Revisar diff de la rama y respaldar base/datos antes de la nueva migración.
2. Verificar catálogos reales: Production/Producction, Distribution, New y
   Installation. Resolver nombres ambiguos con configuración de ProductionCatalog.
   No cambiar IDs ni borrar duplicados automáticamente.
3. Verificar índice único existente invoices.quickbooks_id y correspondencias
   Customer/Asset con la compañía conectada. Facturas legacy con realm null
   requieren primera sincronización para imprimir; verificar primero que los IDs
   históricos pertenecen al mismo realm. No migrar a otra compañía silenciosamente.
4. Ejecutar pruebas, incluyendo MySQL aislado de concurrencia y migración en staging.
5. Instalar dependencias existentes y ejecutar `php artisan migrate --force`
   únicamente durante el despliegue aprobado. No usar migrate:fresh ni db:wipe.
6. Limpiar/reconstruir caches según el procedimiento normal del proyecto.
7. Admin sincroniza Customers, Products e Invoices. Revisar pendientes de mapeo
   y errores. Configurar templates/usuarios de Installation antes de clasificar.
8. Verificar PDF con una cuenta sandbox y seguir Distribution approval/start/scanner.

## Riesgos y límites pendientes

MySQL real y catálogos históricos no se pudieron inspeccionar por conexión 2002.
Concurrencia y compatibilidad SQL deben validarse en MySQL/MariaDB staging.
No se hicieron llamadas a una cuenta real: los tests no necesitan credenciales.
La API no garantiza snapshot temporal entre páginas si el origen cambia durante
sync; el orden por ID y reejecución idempotente reducen el riesgo, y no eliminan
registros por ausencia. No hay soporte multiempresa ni expansión automática de
GroupLineDetail. No existe resolución administrativa automática de conflictos.
La clasificación exige productos físicos; facturas solo de servicio requieren
revisión y no crean una WorkOrder sin requisitos.

## Archivos creados

* `app/Filament/Admin/Pages/InvoiceSyncEvents.php`
* `app/Filament/Shared/Resources/WorkOrderResource/Pages/ViewWorkOrder.php`
* `app/Http/Controllers/InvoicePackingSlipController.php`
* `app/Models/InvoiceEvent.php`
* `app/Models/InvoiceLine.php`
* `app/Services/InvoiceClassificationService.php`
* `app/Services/InvoiceDocumentService.php`
* `app/Services/InvoiceRequirements.php`
* `app/Services/QuickBooksReadGateway.php`
* `database/migrations/2026_10_13_140000_add_invoice_integration.php`
* `docs/phase-9-invoice-integration.md`
* `resources/views/filament/admin/pages/invoice-sync-events.blade.php`
* `resources/views/filament/invoices/details.blade.php`
* `resources/views/pdf/invoice-packing-slip.blade.php`
* `tests/Concurrency/InvoiceClassificationConcurrencyTest.php`
* `tests/Support/InvoiceDatabase.php`
* `tests/Support/invoice-concurrency-worker.php`
* `tests/Unit/InvoiceIntegrationTest.php`

## Archivos modificados

* `app/Filament/Admin/Pages/QuickBooksSync.php`
* `app/Filament/Customer/Resources/InvoiceResource.php`
* `app/Filament/Manager/Resources/InvoiceResource.php`
* `app/Filament/Manager/Resources/InvoiceResource/Pages/ListInvoices.php`
* `app/Filament/Shared/Resources/AssetResource.php`
* `app/Filament/Shared/Resources/InstallationResource.php`
* `app/Filament/Shared/Resources/WorkOrderResource.php`
* `app/Filament/Shared/Resources/WorkOrderResource/Pages/CreateWorkOrder.php`
* `app/Filament/Shared/Resources/WorkOrderResource/Pages/EditWorkOrder.php`
* `app/Http/Controllers/QuickBooksInvoicePdfController.php`
* `app/Models/Customer.php`
* `app/Models/Installation.php`
* `app/Models/Invoice.php`
* `app/Models/WorkOrder.php`
* `app/Policies/InvoicePolicy.php`
* `app/Policies/WorkOrderPolicy.php`
* `app/Providers/Filament/AdminPanelProvider.php`
* `app/Services/AssetSyncService.php`
* `app/Services/CustomerSyncService.php`
* `app/Services/InvoiceSyncService.php`
* `app/Services/ProductionCatalog.php`
* `app/Services/QuickBooksService.php`
* `config/production.php`
* `routes/web.php`

## Resultados de ejecución

* Regresión Unit + Integration + Concurrency: **176 passed, 3 skipped,
  1451 assertions**, 81.84 s. Los skips corresponden a las tres suites MySQL
  de concurrencia (Production, Review e Invoice), sin base aislada configurada.
* Después de las últimas mejoras de aislamiento de realm y descripción del
  Packing Slip, la suite final Phase 9 pasa: **36 passed, 173 assertions**, 21.20 s.
* Pruebas focalizadas Filament/Phase 8 tras correcciones: 46 passed,
  253 assertions. La suite Phase 9 anterior a la prueba adicional de realm:
  35 passed, 169 assertions.
* PHP lint sobre todos los archivos PHP cambiados: sin errores.
* Pint sobre archivos editados: aplicado. `git diff --check`: sin errores.
* route:list confirma Invoices Admin/Manager/Customer, PDF y Packing Slip,
  sin rutas create/edit de InvoiceResource.
* `main` y `origin/main` conservan bf61d19b5faf631d0fcea560c8ca9977412f72be.
  Todo el desarrollo está en phase-9-invoice-integration, sin commit/merge/push
  ni despliegue. No se aplicaron migraciones a la base configurada ni se hicieron
  operaciones de escritura de Invoices a QuickBooks.

La descripción física original del Invoice se conserva además en el snapshot
para el Packing Slip. QuickBooksReadGateway fija el realm durante el proceso y
QuickBooksService rechaza un token de otra compañía antes de ejecutar la lectura.