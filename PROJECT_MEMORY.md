# PROJECT_MEMORY

## Fase 9.3 — Invoice y Packing Slip locales

- Rama de trabajo `phase-9.3-invoice-packing-slip-pdf`, desde `main` en `eeac374`. Se implementaron los dos PDF US Letter con Blade y el Dompdf instalado, a partir de `Invoice 1466.pdf` y `Packing slip 1466.pdf` de QuickBooks Online. El Invoice coloca los cuatro bloques informativos en una fila. Ambos usan el logotipo extraído del original y recursos de imagen locales.
- Las rutas locales son `invoices.local-pdf` e `invoices.packing-slip`. Usan la política de Invoice existente y, en Distribution, la autorización contextual de la Work Order. Las acciones se muestran en Invoices, el detalle de Work Order autorizado y el editor de Distribution. `invoices.pdf` conserva la descarga original de QuickBooks.
- El contenido viene de Invoice e InvoiceLine locales, incluidos servicios fuera del filtro operativo y las líneas de grupos guardadas en el payload. El Packing Slip imprime Service, Description y Qty. El Invoice usa importes persistidos y presenta subtotal, impuestos y descuentos cuando están disponibles y aplican. Los campos opcionales se muestran solo con datos disponibles. El enlace View and pay requiere una URL HTTPS de Intuit almacenada en el payload. Los iconos se muestran según los métodos registrados.
- Los datos corporativos y la dirección para cheques se tomaron de la referencia y se mantienen en `config/invoice_documents.php`. Dompdf genera los documentos sin cargar recursos remotos. Las rutas locales no usan el gateway de QuickBooks.
- Las muestras quedaron en `storage/app/Invoice 1466-local.pdf` y `storage/app/Packing Slip 1466-local.pdf`. MySQL local rechazó la conexión, por lo que se reconstruyó la muestra con los datos visibles del original. Se compararon las páginas renderizadas, incluidos logotipo, columnas, cinco líneas, cantidades, seriales y total de USD 1,600.00. El Invoice ocupa dos páginas y el Packing Slip una. Un caso de 60 líneas validó encabezados repetidos, una dirección larga, campos opcionales vacíos y texto HTML escapado. Las páginas de continuación tienen el margen de Dompdf. Se presentan los siete iconos de pago respaldados por los métodos modelados, frente a ocho en el original.
- Para desplegar tras la revisión, incluir `resources/pdf/invoice/` y `config/invoice_documents.php` en la publicación habitual, regenerar las cachés con `php artisan optimize:clear` y aplicar `php artisan optimize` si lo usa el servidor. Verificar las acciones locales y la descarga original con una factura importada y una Work Order vinculada. No se requieren migraciones, paquetes nuevos ni cambios de sincronización.
- Validación automatizada en SQLite: 99 pruebas de impresión, Distribution, Installation y Refill, más 3 pruebas enfocadas de Invoice. La suite completa InvoiceIntegrationTest tuvo 49 aprobadas y 32 fallos, todos reproducidos en main (48 aprobadas y 33 fallos, incluido el caso anterior de Packing Slip que se sustituyó). PHP lint, Pint en los archivos PHP nuevos y git diff --check pasaron.

## Distribution - permiso Retirar

La rama fix/distribution-withdraw-permissions parte de origin/main en 6116be3. Retirar una reserva requiere Admin o Manager en DistributionSelectionService::remove, antes de iniciar la transaccion. La vista usa la misma decision de permisos y exige una orden abierta. Los roles mixtos con Admin o Manager conservan el permiso de ese rol.

Sin migraciones ni dependencias funcionales con Equipment o Installation. PROJECT_MEMORY.md puede requerir combinar las secciones al integrar. Pendiente de validar Admin, Manager, Worker, llamada Livewire directa, orden ajena y regresion de escaneo y confirmacion. Las pruebas existentes que esperan que Worker retire una reserva deben alinearse con la nueva regla durante la fase de validacion.

## Equipment - identificador flexible

La rama feature/equipment-flexible-identifier parte de origin/main en 6116be3. Equipment Identifier admite texto opcional de hasta 255 caracteres, sin formato VIN ni unicidad porque el valor puede venir de distintos clientes. Se expone en el formulario compartido, detalle de solo lectura y listado con busqueda. Se conservan brand, model y company. Installation usa equipment_id y conserva sus relaciones con Customer y Work Order.

Pendiente aplicar 2026_11_27_100000_add_identifier_to_equipment_table.php antes de habilitar el recurso actualizado. La migracion agrega una columna nullable y deja los registros historicos sin identificador. El rollback elimina el campo y sus valores. Sin dependencia funcional de las otras ramas. Al integrar con trazabilidad, combinar las acciones de EquipmentResource y las secciones de PROJECT_MEMORY.md. Pendiente validar valores vacios, VIN y codigos libres, creacion, edicion, detalle y busqueda.
## Trazabilidad de productos instalados

La rama feature/installation-product-traceability parte de origin/main en 6116be3. El Worker asignado puede registrar por serial desde el asistente con un lector que escribe en el campo. Los perfiles autorizados para editar la Installation pueden registrar en su detalle. La operacion exige Installation y Work Order activas para nuevos registros. Admite Stock disponible segun los filtros actuales de Distribution o una distribucion completada, vigente y destinada al mismo cliente. El registro conserva cliente, Equipment, Installation, Work Order, serial, fecha y usuario.

Se reutiliza installed_products, la relacion del producto, los registros historicos de Distribution y la tabla history. La transaccion bloquea la Work Order, Installation y producto, aplica las reglas de inventario, registra la asociacion, mueve el producto a Installed y escribe el historial. Se conservan el estado de calidad, la orden original de produccion y la distribucion anterior. Reescanear la misma unidad en la misma Installation devuelve el registro existente. Los registros historicos se conservan y los productos con uno de ellos requieren revision antes de otra instalacion. No se inventan garantias para nuevos registros.

Pendientes las migraciones 2026_11_27_110000_add_traceability_to_installed_products_table.php y 2026_11_27_111000_add_installed_location.php. La primera agrega relaciones opcionales e indice unico por instalacion y producto. La segunda incorpora Installed al catalogo cuando falta, usando el almacen de Stock. Si existen ubicaciones homonimas, la migracion requiere resolver la ambiguedad antes de avanzar. Los registros existentes quedan sin cambios. Se requiere un plan de rollback que preserve datos.

El producto muestra fecha de ensamblaje cuando existe, historial y asociaciones. Equipment usa brand y model y expone sus productos en una accion de consulta. El detalle de Installation y el asistente muestran los productos asociados. No se agrega un flujo de desinstalacion ni de reinstalacion. Una Installation historica sin Work Order valida no admite nuevos registros desde este flujo. La regla de Stock reutiliza DistributionSelectionService de main y es independiente del cambio Retirar. Al combinar con Equipment, conservar ambas acciones en EquipmentResource y las tres secciones de PROJECT_MEMORY.md. Pendiente validar roles, concurrencia por serial, rollback, distribuciones de otros clientes, ubicacion, historial y pantallas.


## Integración conjunta

Se retuvieron ambas acciones de Equipment y se combinaron las notas de las tres ramas. La migración del catálogo agrega o reutiliza ubicación y estado Installed. La transición mantiene los indicadores de calidad y la orden original de producción. Las tres migraciones de esta integración siguen pendientes en producción.

### Despliegue de la integración

Se requiere la sesión autorizada del VPS actual y la ruta activa de Laravel. El repositorio documenta la regeneración de cachés en docs/quickbooks-oauth.md. No registra un alias SSH, una ruta activa ni un proveedor de backups verificable desde este workspace.

Antes de publicar, identificar el checkout y la versión PHP que utiliza la instalación activa. Revisar el estado de Git y las migraciones. Con las credenciales ya configuradas para backups, guardar un dump MySQL transaccional fuera del directorio público y verificar una restauración en una base aislada. Consultar en producción, en modo solo lectura, que exista una sola ubicación Stock resoluble, que Installed no sea ambiguo en locations ni en status y que no haya restricciones o columnas duplicadas por una ejecución parcial.

Si el VPS ya usa un perfil MySQL para respaldos, generar el dump con los valores autorizados y restaurarlo con el perfil de pruebas en una base aislada ya preparada. Los perfiles y rutas se obtienen del procedimiento del VPS.

```bash
umask 077
mysqldump --login-path="$PROTENG_BACKUP_LOGIN" --single-transaction --routines --triggers --events --no-tablespaces "$PROTENG_DB_NAME" > "$PROTENG_BACKUP_FILE"
test -s "$PROTENG_BACKUP_FILE"
mysql --login-path="$PROTENG_RESTORE_LOGIN" "$PROTENG_ISOLATED_RESTORE_DB" < "$PROTENG_BACKUP_FILE"
```

Desde la aplicación activa, y después de comprobar el respaldo y el commit de main, usar la ventana de mantenimiento del VPS y su procedimiento habitual. Para un checkout Git directo, los comandos siguientes presuponen un árbol limpio, que la rama activa es main y que se confirmó el SHA aprobado.

```bash
cd "$PROTENG_APP_DIR"
git status --short --branch
git fetch origin main
git rev-parse origin/main
php artisan migrate:status
php artisan down
git merge --ff-only origin/main
php artisan migrate --force --path=database/migrations/2026_11_27_100000_add_identifier_to_equipment_table.php --path=database/migrations/2026_11_27_110000_add_traceability_to_installed_products_table.php --path=database/migrations/2026_11_27_111000_add_installed_location.php
php artisan optimize:clear
php artisan optimize
php artisan migrate:status
php artisan up
```

Detener la secuencia ante un error. Las migraciones de trazabilidad requieren una estrategia de reversión que preserve los datos. Tras el despliegue, comprobar la carga de la aplicación, las vistas de Equipment e Installation, la visibilidad de Retirar según roles y el estado de las migraciones mediante consultas de solo lectura. No enviar formularios ni generar movimientos de prueba en producción.

### Validación de la integración

PHP 8.2.12 y SQLite aislado. Se aplicaron las tres migraciones a los fixtures de las pruebas focalizadas. Pasaron 62 casos con 532 aserciones al combinar Installation/Equipment, Distribution, impresión de documentos e Installation con Invoice. Incluye Stock, despacho al cliente correcto, rechazo de otro cliente, escaneo duplicado, rollback del movimiento y auditoría, vistas de historial y dos procesos concurrentes sobre el mismo serial. La concurrencia de este entorno usa SQLite y queda pendiente validar los bloqueos de InnoDB.

Pest informó 427 aprobadas y 88 fallos en la ejecución general. Los fallos de Invoice y de la migración histórica de Work Orders en SQLite se reprodujeron en una copia aislada de main 6116be3. El informe general se ejecutó antes de incorporar el caso concurrente, que pasó en el lote focalizado final. Se corrigieron la reproducción del fixture de Equipment y el reconocimiento de asignaciones ya liberadas de productos despachados. Quedan pendientes las migraciones y la verificación del VPS operativo tras confirmar acceso, ruta y respaldo restaurable.

### Intento de despliegue de producción — 2026-11-27

Se verificó que origin/main apunta al commit autorizado 4d032f659909874bd50b9fe87f37f966bbccddd1, que integra el PR #4. Se revisaron las tres migraciones del commit y se comprobó que el merge no cambia las rutas ni las plantillas PDF de la Fase 9.3 ni los lockfiles de dependencias.

Despliegue bloqueado antes de conectar. En esta sesión no se identificó una conexión SSH autorizada y verificable para PROTENG LAB. El alias SSH visible no está vinculado al proyecto. La ruta activa, el commit anterior, los servicios y el esquema MySQL de producción quedan sin verificar. No se creó un respaldo, no se actualizó código en el VPS y no se ejecutó ninguna de las tres migraciones. Retomar desde la identificación del VPS con su alias autorizado o desde una sesión existente, verificar un respaldo restaurable antes de avanzar y mantener pendiente la prueba concurrente de InnoDB en un entorno aislado.

## Fase 9.4 — estado y estabilización

El propietario confirma el despliegue manual del PR #4 en el servidor proteng-lab, ruta /var/www/proteng, con main en 4d032f659909874bd50b9fe87f37f966bbccddd1. Reportó las tres migraciones de Equipment Identifier, relaciones de trazabilidad y catálogo Installed aplicadas correctamente, y todos los pasos de php artisan optimize como DONE. Este reporte sustituye el estado pendiente del intento anterior. La existencia de un respaldo restaurable previo sigue sin confirmar.

La revisión identificó dos defectos. EditInstallation permitía cambiar cliente o equipo después de registrar un producto, dejando la ficha y la asignación en desacuerdo con la trazabilidad y bloqueando escaneos posteriores. La edición ahora comparte el orden de bloqueo con el registro y rechaza el cambio antes de guardar. Los demás campos siguen editables. Distribution pedía a Worker retirar una reserva inválida aunque el rol carece de ese permiso. El mensaje ahora indica solicitarlo a Admin o Manager. Equipment Identifier y la visibilidad de Retirar conservan su implementación. La corrección no requiere migraciones.

El propietario conserva el acceso y la operación del VPS. Durante la Fase 9.4 no se accedió a producción ni se repitió el despliegue. El reporte del propietario avala instalación y migraciones, no el comportamiento de las pantallas. Quedan pendientes la comprobación visual de Equipment, el historial de Installation y la visibilidad de Retirar con cuentas autorizadas; también la prueba de concurrencia MySQL/InnoDB en un entorno aislado.

Validación directa de Fase 9.4: 63 pruebas y 550 aserciones en SQLite aislado. Pasaron la edición protegida de Installation, los casos de Equipment, los permisos y el mensaje de Distribution, los PDF y la vinculación Installation–Invoice. PHP lint y git diff --check pasaron.
