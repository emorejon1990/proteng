# Fase 4: órdenes Review

## A. Resumen

Rama: `phase-4-review-work-orders`. Confirmar un rechazo en calidad incorpora el tubo a una orden Review dentro de la misma transacción que registra el peso y el resultado. Si no existe una orden receptora se crea una; si existe se utiliza la más antigua disponible. La orden puede contener distintos modelos y conserva las identidades, seriales, orden original, pesos e historial de cada producto.

La capacidad es de 50 asociaciones individuales. Al llegar a 50 se cierra la recepción y la orden queda pendiente de aprobación. El siguiente rechazo crea o utiliza otra orden receptora. No se generan productos ni seriales para Review.

La página **Shop Floor → Órdenes Review**, accesible a Admin y Manager, muestra seriales, modelos, orden original y cantidad `N/50`. Permite cerrar una orden parcial, aprobarla e iniciarla mediante acciones con confirmación. La aprobación sitúa la orden en el centro Review, sin pasar por ensamblado. La inspección física se implementará en fase 5.

## B. Archivos

Creados:

- `app/Services/ReviewWorkOrderService.php`
- `app/Filament/Pages/ReviewOrders.php`
- `resources/views/filament/pages/review-orders.blade.php`
- `database/migrations/2026_10_13_100000_add_review_work_order_catalogs.php`
- `database/migrations/2026_10_13_100100_add_rejected_cycle_to_review_associations.php`
- `tests/Unit/ReviewWorkOrderTest.php`
- `tests/Concurrency/ReviewWorkOrderConcurrencyTest.php`
- `tests/Support/review-concurrency-worker.php`
- `docs/production-review-phase-4.md`

Modificados:

- `app/Services/ProductionQualityService.php`: incorporación transaccional del rechazo.
- `app/Services/ProductionCatalog.php` y `config/production.php`: catálogos Review y estados administrativos.
- `app/Models/WorkOrderProduct.php` y `app/Models/ProductCycle.php`: vínculo histórico de rechazo.
- `app/Models/WorkOrder.php`: reconocimiento de Review y protección frente a los hooks legados de producción.
- `app/Policies/WorkOrderPolicy.php`: evita edición genérica de Review.
- `app/Filament/Shared/Resources/WorkOrderResource.php`: acceso a la gestión Review y protección de las acciones genéricas.
- `app/Filament/Shared/Resources/WorkOrderResource/Pages/CreateWorkOrder.php` y `EditWorkOrder.php`: evitan crear o convertir órdenes Review por el formulario de producción.
- `app/Livewire/WorkOrderQualityEditor.php` y `resources/views/livewire/work-order-quality-editor.blade.php`: mensaje y confirmación del envío efectivo a Review.
- `tests/Unit/ProductionQualityTest.php`: actualiza expectativas del traspaso y prueba su reversión completa.
- `tests/Support/ProductionDatabase.php`: soporte de migraciones de fase 4 y fixtures reutilizables en conexiones aisladas.

Los cambios locales del usuario y las fases anteriores se conservaron. No se modificó SerialExportController.

## C. Base de datos

La primera migración incorpora el tipo Review y el centro Review solo si falta una coincidencia única en sus catálogos. Adopta los registros existentes sin cambiar sus IDs y se detiene ante duplicados. No utiliza IDs de seeders. Su rollback automático está bloqueado para conservar catálogos adoptados y referencias históricas.

La segunda migración agrega `work_order_products.rejected_cycle_id`, nullable para no alterar asociaciones existentes. Un índice único impide incorporar el mismo rechazo a dos órdenes. La FK compuesta con `product_id` garantiza que el ciclo rechazado pertenece al producto de la asociación. El vínculo no se puede cambiar desde el modelo. Su rollback se permite solo sin vínculos de rechazo; no descarta información histórica.

Se reutilizan `review_receiving_open`, `review_closed_at` y `review_closed_by` de fase 1. Las órdenes receptoras y las pendientes de aprobación comparten el estado New, distinguidas explícitamente por la recepción; después pasan a Approved y a inProcess. No se sobrecargan los estados existentes.

## D. Compatibilidad y consistencia

La cantidad se obtiene de las asociaciones, no del valor posiblemente desactualizado de `quant`. Toda mutación de Review bloquea primero la fila existente del tipo Review; así se serializa tanto la creación de la primera orden como la asignación del puesto 50. Después bloquea las órdenes y el producto necesarios. La confirmación de calidad obtiene ese bloqueo antes de bloquear la orden de producción, manteniendo el mismo orden de adquisición.

El servicio solo admite rechazos confirmados del ciclo vigente, con origen verificado y sin otra asignación operativa. La incorporación es idempotente: repetirla devuelve la asociación existente y no duplica órdenes, capacidad ni historial. Se libera la asignación de producción y se reclama la de Review; el ciclo finalizado permanece vinculado a su asociación original y no se modifica.

El tubo permanece fuera de Stock, con sus pesos y fechas intactos. En esta fase su ubicación física sigue siendo Quality; el centro de la orden aprobada/iniciada es Review. La ubicación e inspección de la estación se completarán en fase 5.

Los rechazos confirmados que quedaron pendientes durante fase 3 aparecen para incorporación administrativa desde la misma página. El procedimiento conserva el ciclo histórico y registra un nuevo evento de asociación. No interpreta indicadores ambiguos de productos legados como rechazos confirmados.

Cierre, aprobación e inicio vuelven a validar estado y miembros en el servidor y requieren Admin/Manager. Los operadores autorizados de calidad pueden incorporar el rechazo al confirmarlo. Se registra History con orden, asociación, ciclo, serial, usuario y fecha. Las acciones repetidas no duplican eventos.

Se protege Review frente a los hooks antiguos que crean productos y reservan seriales al aprobar producción. Incluso si el ID de Review coincide con un ID legado, su aprobación no ejecuta esos hooks. La edición genérica queda bloqueada; producción y distribución conservan su flujo anterior.

## E. Pruebas

Se ejecutaron pruebas de catálogo y migraciones incrementales, modelos mezclados, capacidad 50/51, cantidad desactualizada, cierre parcial, aprobación, inicio, permisos, incorporación pendiente, idempotencia, contexto del rechazo, asignaciones incompatibles y preservación del historial. Se probaron también las acciones reales de Filament con un usuario Admin y roles en la base aislada.

Se verificó la reversión completa si falla el historial de Review, tanto al incorporar un rechazo existente como al confirmar calidad: no quedan peso, rechazo, nueva orden, asociación ni cambio de asignación parciales.

Llenado, calidad, trazabilidad y Review: **71 pruebas aprobadas, 601 aserciones**, ejecutadas con SQLite en memoria. Báscula y cámara: **9 pruebas JavaScript aprobadas**. Pint y `git diff --check` aprobados. `route:list` confirmó el registro de la página; `canAccess` restringe su uso a los roles administrativos aunque los paneles compartan descubrimiento de páginas.

La suite Unit completa terminó con **77 aprobadas y dos fallos preexistentes**, 835 aserciones. La prueba MySQL de concurrencia terminó con **una omitida**, sin aserciones ejecutadas.

Comandos:

```text
php vendor/bin/pest tests/Unit --compact --do-not-cache-result
php vendor/bin/pest tests/Concurrency/ReviewWorkOrderConcurrencyTest.php --compact --do-not-cache-result
node --test tests/JavaScript/weightscale.test.mjs tests/JavaScript/quality-scanner.test.mjs
```

## F. Problemas y límites

Persisten los dos fallos anteriores de SerialPoolTest: el mock exige Admin/Manager, mientras el cambio local del usuario incluye Worker en el controlador de exportación. Se conserva su cambio y esas pruebas.

La prueba de concurrencia MySQL quedó **omitida**, no aprobada, porque no está configurada una base aislada. SQLite en memoria no prueba bloqueos de filas ni carreras reales de MySQL. La prueba preparada usa dos procesos con una barrera de inicio para comprobar creación concurrente, el puesto 50 y repetición del rechazo.

No se aplicaron migraciones ni se incorporaron rechazos en una base real. No se hicieron push, merge ni despliegue. La conexión MySQL disponible en el análisis anterior estaba rechazada.

## G. Verificaciones pendientes

Antes de habilitar, revisar/aplicar las migraciones incrementales autorizadas y verificar los catálogos reales. Los IDs opcionales nuevos son `PRODUCTION_REVIEW_TYPE_ID`, `PRODUCTION_REVIEW_CENTER_ID`, `PRODUCTION_NEW_ORDER_STATUS_ID` y `PRODUCTION_APPROVED_ORDER_STATUS_ID`. Las coincidencias ausentes o ambiguas bloquean el flujo con un mensaje administrativo.

Para ejecutar concurrencia, suministrar una base **vacía y exclusivamente de pruebas**, con nombre `proteng_review_test_...`, mediante `REVIEW_TEST_MYSQL_DATABASE`, más `REVIEW_TEST_MYSQL_HOST`, `REVIEW_TEST_MYSQL_PORT`, `REVIEW_TEST_MYSQL_USERNAME` y `REVIEW_TEST_MYSQL_PASSWORD`. El test rechaza nombres distintos y bases con tablas antes de preparar datos. No crea, borra, trunca ni reinicia bases; conserva los datos de prueba y requiere otra base vacía para repetirlo. Verificar MySQL/MariaDB con InnoDB y soporte de las FKs compuestas.

Las órdenes Review preexistentes sin contexto de rechazo o con asignaciones inconsistentes requieren diagnóstico: no se inventan sus vínculos ni se aprueban silenciosamente. Debe habilitarse el esquema nuevo antes de usar la incorporación automática de calidad. Actualizar las cachés de configuración/componentes/rutas según el procedimiento de puesta en marcha autorizado.

## H. Próxima fase

Fase 5: estación de revisión, selección individual por serial, diagnóstico y reparación, resultado reparable/irreparable, reciclaje y finalización de la inspección de todos los miembros. Se prevén cambios en servicios y componentes de estación, catálogos/ubicaciones, `ProductRepair`, vistas y pruebas. Los ciclos posteriores de llenado, espera y calidad siguen reservados para fase 6. Requiere aprobación expresa.
