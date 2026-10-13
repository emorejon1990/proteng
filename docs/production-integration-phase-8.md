# Fase 8 — Integración y verificación local

## A. Resumen

Se verificó el recorrido completo con migraciones reales en SQLite en memoria y catálogos cuyos IDs difieren de los seeders: aprobación de producción, creación de productos y reserva de seriales, ensamblado secuencial, llenado por serial, espera de cinco días, calidad, agrupación Review, reparación/reciclaje, nuevo ciclo y distribución de tubos específicos.

Se corrigieron los puntos de integración que todavía dependían de IDs fijos: hooks de aprobación y creación de productos, estados y tipos del recurso de órdenes, política de edición, página de ensamblado, transición a llenado y gráficos de inventario. Las cantidades de producción y distribución se validan como enteros positivos; el formulario rechaza modelos repetidos en distribución.

Ensamblado conserva su orden secuencial. Ahora verifica permisos, estación y primer producto pendiente en el servidor, y no avanza con una confirmación de ensamblado vacía. La creación de origen, asignación y auditoría, el uso del serial y el cambio de estación son atómicos. Las propiedades que identifican la orden y producto en Livewire quedan protegidas.

Las acciones de aprobar/iniciar producción y distribución pasan por un servicio que verifica permisos Admin/Manager, estado y catálogos. Review mantiene su servicio y permisos propios. Stock usa la misma disponibilidad que distribución, excluye reservas, pendientes de calidad y reciclados, y recupera la disponibilidad al retirar una selección. Los gráficos de producción/espera/calidad cuentan sus ubicaciones verificadas.

Se evitó que una producción enlazada que termina calidad vuelva a abrir una distribución ya Ready. Se actualizaron las expectativas de las pruebas de exportación para reflejar el permiso local Worker del usuario, conservando su controlador.

## B. Archivos

Creaciones:

- `app/Services/ProductionAssemblyService.php`.
- `app/Services/WorkOrderLifecycleService.php`.
- `tests/Integration/ProductionWorkflowTest.php`: once casos integrales.
- `tests/Concurrency/ProductionWorkflowConcurrencyTest.php`.
- `tests/Support/production-concurrency-worker.php`.
- `docs/production-integration-phase-8.md`.

Modificaciones:

- `app/Models/WorkOrder.php`: hooks con catálogos verificados, nombres y estados iniciales.
- `app/Services/ProductionCatalog.php` y `config/production.php`: centro Assemble y ubicación Production.
- `app/Services/ProductionQualityService.php`: no reabrir una distribución finalizada y verificar el tipo de la orden enlazada.
- `app/Livewire/WorkOrderAssembleEditor.php`: servicio de ensamblado y propiedades protegidas.
- `app/Filament/Pages/Assemble.php`, `resources/views/filament/pages/assemble.blade.php` y `resources/views/livewire/work-order-assemble-editor.blade.php`: catálogos y errores de estación/confirmación.
- `app/Filament/Shared/Resources/WorkOrderResource.php`: tipos/estados verificados, validación de cantidades/modelos, aprobación e inicio mediante servicio.
- `app/Policies/WorkOrderPolicy.php`: edición administrativa en estados verificados New/Approved; Review continúa bloqueada para edición genérica.
- Los cuatro widgets `StockChart`, `ProductionChart`, `WaitingChart` y `QualityChart` en `app/Filament/Shared/Resources/InventoryResource/Widgets`.
- `tests/Unit/SerialPoolTest.php`: mock alineado con los permisos actuales del controlador.
- `phpunit.xml`: registro de la suite Integration.

Los cambios de fases anteriores y del usuario permanecen en la rama local `phase-8-production-integration`. No se modificó nuevamente `SerialExportController`, ni las plantillas de seriales, ni QuickBooks.

## C. Base de datos

Esta fase no agrega migraciones, columnas, relaciones ni índices. Reutiliza los incrementos de las fases 1, 4, 5, 6 y 7.

Las pruebas locales aplicaron las migraciones existentes sobre SQLite en memoria. No se migró, rellenó, borró ni reinició la base real. No se ejecutaron comandos de reseteo ni se desplegó el proyecto.

Los nuevos parámetros opcionales `PRODUCTION_ASSEMBLE_CENTER_ID` y `PRODUCTION_LOCATION_ID` deben corresponder a registros existentes; sin configuración, se resuelven nombres únicos. Los hooks del modelo conservan compatibilidad con fixtures mínimos antiguos sin tablas de catálogos, pero cuando esas tablas existen, verifican sus IDs.

## D. Compatibilidad

Se conserva el ensamblado secuencial y la asignación del pool, sin regenerar seriales durante reparación. Aprobación produce los productos reales de la orden y reserva seriales una sola vez. Los cambios de modelo siguen dentro de transacciones y un fallo revierte también seriales y productos recién creados.

Las estaciones de llenado/calidad, la báscula y el lector mantienen sus interfaces. Las fechas de espera siguen siendo DATE sin hora. La aplicación actualmente configura UTC; las fechas se calculan con esa zona horaria, que no fue alterada en esta fase.

Las órdenes por faltantes se probaron desde creación hasta aprobación, ensamblado, llenado, espera, aprobación de calidad y selección en distribución. Cada tubo conserva su orden de producción original incluso después de reparación y distribución.

Los registros antiguos se conservan. El diagnóstico de trazabilidad sigue siendo de lectura por defecto y exige un ID de producción verificado. Los datos ambiguos no se convierten automáticamente en productos disponibles. Las distribuciones antiguas sin asociaciones de serial no se reconstruyen ni se inventan.

Las páginas administrativas generales y las integraciones externas ajenas al recorrido de producción no fueron reescritas. La creación manual antigua de productos sin serial no los vuelve automáticamente elegibles para distribuir.

## E. Pruebas

Comandos ejecutados:

```text
php vendor/bin/pest tests/Unit tests/Integration tests/Concurrency
node --test tests/JavaScript/weightscale.test.mjs tests/JavaScript/quality-scanner.test.mjs
git diff --check
```

Resultados finales:

- PHP: **141 aprobadas, 2 omitidas, 1284 aserciones; cero fallos**.
- JavaScript: **9 aprobadas; cero fallos**.
- Pint aplicado a los PHP modificados y nuevos de esta fase; comprobaciones de sintaxis y diferencias sin errores.

Los once casos integrales cubren el recorrido completo con reparación y reciclaje, seriales e historial conservados, ensamblado secuencial, componente real de ensamblado, aprobación/inicio desde la tabla real de Filament, producción por faltantes, permisos, rollback de aprobación/ensamblado, protección de distribuciones finalizadas e inventario por disponibilidad/ubicación.

Las pruebas existentes de pool/exportación, producción, calidad, Review, reparaciones y distribución quedaron aprobadas. Los dos fallos previos de exportación se resolvieron al alinear el mock con el controlador actual, sin cambiar sus permisos.

Las suites omitidas son las pruebas opt-in de concurrencia en MySQL: agrupación Review y operaciones de distribución/ciclos. No se configuraron bases MySQL vacías y aisladas. El nuevo caso lanza dos procesos sincronizados para competir por el mismo tubo, la última unidad de una orden, la confirmación y el llenado de un reparado; espera exactamente una operación aceptada y otra rechazada. Es una prueba preparada, no evidencia de ejecución real en MySQL.

No se ejecutó `tests/Feature`: su configuración usa `RefreshDatabase` y resetea el esquema. Se mantuvo fuera de estos comandos para respetar la prohibición de usar reseteos. El informe no afirma que toda la suite general de autenticación/ajustes ni QuickBooks estén verificados.

## F. Problemas y límites

No quedan fallos en las pruebas locales ejecutadas. Quedan sin validar MySQL/MariaDB, carreras reales entre procesos, dispositivos físicos, impresión física y la suite Feature general mencionada arriba.

El flujo conserva registros antiguos; aquellos sin datos suficientes, con referencias operativas inconsistentes o seriales duplicados necesitan conciliación administrativa explícita. La verificación local no certifica los datos de la base real, que no se modificó ni se usó como entorno de pruebas.

## G. Validación previa a habilitar el flujo

Usar una copia aislada en el mismo motor del destino para revisar/aplicar las migraciones incrementales pendientes y verificar claves, índices, cambio de nulabilidad del historial y catálogos reales. No usar `migrate:fresh`, reset, truncado ni borrado de registros.

El diagnóstico existente se ejecuta sin escritura con `production:backfill-traceability --production-type=ID_VERIFICADO`; sustituir el marcador por el ID comprobado. Revisar el informe antes de cualquier incorporación explícita con `--apply`. Ese diagnóstico/incorporación no se ejecutó sobre datos reales en esta fase.

Para ejecutar únicamente la prueba nueva de concurrencia, proporcionar una base **vacía y exclusiva** cuyo nombre cumpla `proteng_production_test_...` y variables `PRODUCTION_TEST_MYSQL_DATABASE`, `PRODUCTION_TEST_MYSQL_HOST`, `PRODUCTION_TEST_MYSQL_PORT`, `PRODUCTION_TEST_MYSQL_USERNAME` y `PRODUCTION_TEST_MYSQL_PASSWORD`. Después ejecutar:

```text
php vendor/bin/pest tests/Concurrency/ProductionWorkflowConcurrencyTest.php
```

La prueba verifica que no haya tablas antes de escribir, no crea/elimina bases, no limpia tablas al terminar y no admite reutilizar una base poblada. Para repetirla, preparar otra base vacía. Las credenciales viajan al proceso hijo por stdin y no se imprimen. La prueba de agrupación anterior usa sus variables `REVIEW_TEST_MYSQL_*` y una base separada `proteng_review_test_...`.

Finalmente verificar físicamente cámara, lector, báscula, formato de impresión, destino de Distribution y zona horaria operativa; recorrer aprobación → ensamblado → llenado → espera → calidad → Review → reparación/reciclaje → nuevo ciclo → Stock → distribución. Estas verificaciones todavía están pendientes.

## H. Siguiente paso

Terminó la implementación y validación local de las ocho fases. No se inició despliegue. Corresponde completar la validación aislada de MySQL/MariaDB y equipos descrita en G, revisar datos antiguos y obtener aprobación explícita antes de habilitar o desplegar el flujo en el entorno real.
