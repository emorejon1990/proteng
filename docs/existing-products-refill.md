# Phase 9.1 — Generate Serials y Refill Prod

Rama: `phase-9.1-existing-products-refill`.

## Generate Serials

Admin → Admin Tools → Generate Serials. Introducir Quantity y pulsar Generate Serials. El servidor acepta enteros entre 1 y 1000 por solicitud y utiliza `SerialController::generatepool()`, el mismo generador usado por Production. Conserva el formato letra + siete números + letra, el estado Free y la restricción única de la tabla serial. No crea productos ni Work Orders. Una colisión se reintenta usando la lógica existente; otro fallo revierte la solicitud completa.

## Refill Prod

1. Admin → Admin Tools → Refill Prod.
2. Completar Type, Assembler, Filler, Quality Checker y Weight. Se conservan Assembled, Filled, Qualified, Status y Location. Al tratarse de productos terminados, las tres etapas deben estar completadas y los catálogos deben corresponder a Ready y Stock. Se buscan los IDs por los nombres existentes mediante ProductionCatalog; no se crean estados.
3. Escanear un serial Free y enviar Enter, o pulsar Register scan. Debe existir en el pool, no estar reservado, usado, vinculado a un producto ni tener evidencia de uso anterior.
4. Cada lectura válida crea inmediatamente Products, asigna el serial como Used con products_id y registra su origen en History. También guarda operación, usuario, formulario y snapshot. No hay confirmación final del conjunto ni órdenes de producción o Quality.
5. El campo se vacía y recupera el foco. La lista muestra Product Type, Serial, Delete y el contador de productos actualmente registrados.
6. Tras el primer escaneo se fijan los datos comunes. New operation cierra la operación abierta y permite otro formulario. La siguiente lectura válida crea la nueva operación. View operation permite consultar operaciones anteriores del mismo administrador.

Al recargar se recupera la operación abierta del usuario o, si no hay ninguna, la última cerrada. Las operaciones cerradas no reciben más escaneos. Otro Admin no puede consultar ni borrar productos de una operación ajena; cambiar IDs desde el cliente tampoco concede acceso.

Los productos quedan preparados para la selección de Stock existente: las tres etapas completadas, quality_date, f_weight positivo, Ready, Stock, sin Work Order ni ciclo de producción. No se modifica Distribution para aceptarlos.

## Transacciones y eliminación

Cada escaneo bloquea el usuario y el serial; la operación abierta tiene una restricción única por usuario. La creación del producto, el consumo del serial, History y la entrada de la operación comparten una transacción. Escaneos repetidos no crean productos adicionales. Los escaneos simultáneos compiten por el mismo serial; quien no puede registrarlo recibe un rechazo o un error recuperable sin registros incompletos.

Delete solicita confirmación y vuelve a verificar en servidor:

- Propietario y pertenencia exacta a la operación.
- Producto todavía Ready en Stock, sin Work Order original ni actual.
- Snapshot completo del producto sin cambios posteriores.
- Ausencia de asociaciones operativas, ciclos, reparaciones, asignaciones e instalación.
- Únicamente el evento de alta creado por esta operación, con contenido intacto.
- Serial Used todavía vinculado exclusivamente al mismo producto.

Solo entonces se elimina el producto, se libera el serial a Free y se marca la entrada como revertida, con usuario y fecha. También se revierte **exclusivamente su evento de alta intacto** en History: su contenido íntegro y el snapshot del producto permanecen en refill_entries como auditoría. No se eliminan movimientos posteriores ni History de otros productos. Cualquier dependencia no prevista protegida por la base de datos bloquea y revierte la eliminación completa. Una nueva lectura deliberada puede reutilizar un serial liberado por una reversión confirmada.

El bloqueo por cambios es conservador: incluso una asociación de distribución ya liberada impide borrar el producto desde Refill. Esto no es un mecanismo general para eliminar inventario histórico.

## Archivos

- `app/Filament/Admin/Pages/GenerateSerials.php` (nuevo)
- `resources/views/filament/pages/generate-serials.blade.php` (nuevo)
- `app/Services/SerialGenerationService.php` (nuevo)
- `app/Filament/Admin/Pages/RefillProd.php`
- `resources/views/filament/pages/refill-prod.blade.php`
- `app/Services/ExistingProductsRefillService.php` (nuevo)
- `app/Models/RefillOperation.php` y `app/Models/RefillEntry.php` (nuevos)
- `database/migrations/2026_10_28_100000_create_refill_operations.php` (nueva)
- `tests/Unit/ExistingProductsRefillTest.php`, `tests/Unit/RefillConcurrencyTest.php` y `tests/Support/refill-concurrency-worker.php` (nuevos)
- Esta guía.

## Despliegue y validación

1. Desplegar la rama mediante el procedimiento habitual. Confirmar que están aplicadas las migraciones existentes del pool de seriales y trazabilidad de fases anteriores.
2. Aplicar la única migración nueva, que crea refill_operations y refill_entries; no actualiza productos históricos:

   ```sh
   php artisan migrate --path=database/migrations/2026_10_28_100000_create_refill_operations.php --force
   ```

3. Si el despliegue utiliza caches de Filament/componentes o vistas, regenerarlos mediante el procedimiento existente para descubrir Generate Serials. El panel Admin ya descubre automáticamente las páginas de su directorio.
4. Confirmar que existen catálogos únicos Ready en status y Stock en locations.
5. Con Admin generar dos seriales, registrar un producto con cada uno y recargar. Revisar empleados, peso, fechas, seriales Used y contador. Volver a escanear uno debe rechazarlo.
6. Comprobar Delete sobre un alta sin movimientos y verificar Free, sin Products y con auditoría conservada. Un producto que tenga movimientos debe bloquearse.
7. Comprobar que Manager, Worker y Customer no pueden acceder a las páginas ni invocar sus acciones. Otro Admin tampoco puede acceder a operaciones ajenas.

La migración impide rollback si ya existen operaciones o auditoría, para evitar pérdida de datos.

## Pruebas y limitaciones

```sh
php artisan test --compact tests/Unit/ExistingProductsRefillTest.php tests/Unit/RefillConcurrencyTest.php tests/Unit/SerialPoolTest.php
```

Se utilizan migraciones reales sobre SQLite aislado. La concurrencia arranca dos procesos PHP con una barrera de inicio y una base SQLite temporal exclusiva; comprueba generación concurrente y dos escaneos del mismo serial. SQLite serializa escrituras a nivel de base de datos. Estas pruebas no sustituyen la validación de bloqueos de fila InnoDB en el servidor. Los conflictos de escritura revierten la transacción y permiten repetir el escaneo.

No se consultó ni modificó producción. No se modifican QuickBooks, Invoices, Production, Quality, Distribution, Installation, Equipment ni productos históricos sin serial.

Límites: máximo 1000 seriales por generación; una operación abierta por Admin; los datos comunes quedan fijados al primer escaneo; no se comparten operaciones entre administradores; el lector debe enviar Enter o utilizar Register scan. No se reintenta automáticamente una petición de generación, porque repetirla crearía otro lote solicitado.
