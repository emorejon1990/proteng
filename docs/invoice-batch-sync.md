# Invoice sync por bloques

## Funcionamiento

`Sync Invoices` conserva su ubicación en Admin. Inicia o reanuda una única sesión persistida en `invoice_sync_sessions`, en la base de datos configurada de la aplicación. La tabla contiene la sesión actual; los eventos conservan el resumen de las ejecuciones anteriores.

Primero identifica facturas mediante páginas pequeñas. Después consulta de nuevo cada QuickBooks ID e importa hasta cinco facturas por solicitud Livewire. No reutiliza los payloads del listado. Cada importación y su punto de reanudación se confirman en la misma transacción. Al refrescar la página se recupera el estado guardado. Si la sesión está detenida, `Sync Invoices` permite reanudarla. Una sesión completada permite comenzar otra.

Se reutilizan el gateway de solo lectura, los filtros y el importador existentes. Las facturas ya importadas se actualizan por QuickBooks ID; no se eliminan ni se recrean sus operaciones. Las facturas locales ausentes del listado se consultan individualmente, como antes. Los cambios contables siguen las reglas existentes de revisión/conflicto, sin modificar las Work Orders ni sus snapshots.

Un bloqueo compartido dura como máximo 120 segundos por solicitud y se libera al terminar. Se comprueba su propiedad antes de escribir. Entre bloques hay una reserva renovable de 10 minutos para evitar otra sincronización convencional; una interrupción no la retiene indefinidamente. Otros administradores retoman la misma sesión, sin crear otra simultánea. Los errores de API detienen el avance conservando el cursor; los errores de datos individuales se registran y contabilizan según el importador existente.

## Despliegue y validación en servidor

1. Desplegar los archivos de la rama `phase-9-invoice-integration` mediante el procedimiento habitual. No hace falta instalar un worker de colas ni aumentar el timeout de Nginx.
2. Ejecutar únicamente la nueva migración si las migraciones anteriores ya están aplicadas:

   ```sh
   php artisan migrate --path=database/migrations/2026_10_27_120000_create_invoice_sync_sessions_table.php --force
   ```

3. Configurar opcionalmente `QB_INVOICE_BATCH_SIZE=5` y reconstruir la configuración/vistas usando el procedimiento de despliegue existente. El tamaño admitido está limitado a 1–20. Si cada factura tarda mucho, reducirlo a 1.
4. Verificar que el cache permite locks atómicos y es compartido entre todas las instancias de la aplicación; `array` no es válido fuera de pruebas. No borrar el cache de locks durante una sincronización.
5. Como Admin, pulsar `Sync Invoices`. En Network deben aparecer solicitudes Livewire separadas y el resumen debe avanzar hasta `completed`. Durante el descubrimiento todavía no habrá importaciones.
6. Cerrar y reabrir la página durante la importación; comprobar la continuación desde el progreso guardado. Si aparece `stopped`, resolver la conexión y pulsar el mismo botón. Abrir otra pestaña no debe crear otra sesión.
7. Comprobar el resultado y los eventos `Invoice Batch Sync Started`, `Stopped` y `Completed`. Verificar algunos QuickBooks IDs y números previamente importados y sus vínculos con Distribution/Installation. Repetir una sesión completada no debe duplicar facturas.
8. Comprobar que Manager, Worker y Customer no tienen acceso a la página ni a los métodos de sincronización.

Las 19 facturas anteriormente sincronizadas no se borran ni se restablecen. El resumen de cada sesión cuenta su propia ejecución; no suma automáticamente el resumen anterior de 19/400/3.

## Límites y pruebas

- El navegador debe mantener abierta la página para continuar automáticamente. El progreso confirmado sobrevive a una desconexión o un 504; el trabajo restante se retoma al volver. No hay procesamiento en segundo plano.
- QuickBooks no proporciona una instantánea transaccional mediante este listado paginado. Cada ID se revalida antes de importarlo. Las facturas creadas o eliminadas mientras cambia el listado pueden necesitar una nueva sesión; al reanudar el descubrimiento se reconstruye el listado desde el inicio. Una factura que deja de estar disponible detiene la sesión para revisión, sin eliminar datos locales.
- Cambiar de empresa o entorno impide reanudar: debe restaurarse la conexión original. La aplicación ya restringe el catálogo a una empresa.
- Los errores individuales contabilizados como `failed` se vuelven a intentar en una nueva sesión. Los errores de transporte detienen y permiten reanudar la sesión actual.
- `Sync ALL` mantiene su flujo anterior y no es el botón que se convierte a bloques en este cambio. Mientras la sesión incremental esté activa, el bloqueo evita solaparlo.
- Pruebas locales: QuickBooks simulado y SQLite en memoria exclusivamente. Falta validar la latencia real y los locks compartidos en la infraestructura del servidor.

Pruebas puntuales:

```sh
php artisan test --compact tests/Unit/InvoiceBatchSyncTest.php tests/Unit/RandomInvoiceDryRunTest.php
php artisan test --compact tests/Unit/InvoiceFiltersTest.php --filter='filters pending|uses verified category|reviews unavailable|skips new excluded|keeps included physical'
```

La revisión adicional de `InvoiceIntegrationTest` y de tres casos antiguos de `InvoiceFiltersTest` encontró pruebas desactualizadas (categorías Product ausentes en fixtures y referencias a `InvoiceSelectiveSyncService::IDS`, eliminado anteriormente). Se reprodujeron ejemplos con los servicios y la vista de HEAD sin esta implementación. No se cambiaron esos filtros ni el Dry Run para adaptar pruebas antiguas.
