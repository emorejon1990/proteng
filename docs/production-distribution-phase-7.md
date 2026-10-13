# Fase 7 — Distribución por serial

## A. Resumen

La página Distribution para Admin, Manager y Worker mantiene los modelos y cantidades solicitados en `wo_ass`. El operador selecciona un modelo y escanea cada serial mediante teclado, lector o cámara. La selección reserva exclusivamente ese producto, muestra solicitados/escaneados/pendientes/disponibles/faltantes y permite retirar tubos antes de confirmar.

Cada reserva conserva una instantánea de serial, modelo y ciclo aprobado. Confirmar vuelve a comprobar esas identidades, la calidad, Stock, estado Ready, asignación vigente y cantidades exactas por modelo. No realiza otra selección automática por ID. Solo los tubos escaneados pasan a Distribution y quedan asociados a la orden, que pasa a Ready. Los seriales confirmados siguen visibles en el componente.

Las reservas quedan fuera de la disponibilidad de otras distribuciones. Retirar libera su asignación sin eliminar producto ni asociación; los eventos de reserva, retirada y confirmación permanecen en el historial. Volver a escanear el mismo tubo retirado reutiliza la asociación del mismo producto/orden y genera otro evento.

Se mantiene la creación de producción por faltantes. La cantidad se calcula en el servidor a partir de solicitados, selección propia y Stock disponible, excluyendo tubos pendientes de calidad, en revisión, reciclados o reservados. No acepta una cantidad calculada por el cliente ni crea otra orden enlazada para el mismo modelo.

## B. Archivos

Creaciones:

- `app/Services/DistributionSelectionService.php`: disponibilidad, reserva, retirada, confirmación y faltantes.
- `database/migrations/2026_10_13_130000_add_distribution_location.php`.
- `database/migrations/2026_10_13_130100_add_distribution_selection_snapshot.php`.
- `tests/Unit/DistributionSelectionTest.php`.
- `docs/production-distribution-phase-7.md`.

Modificaciones:

- `app/Livewire/WorkOrderDistributionEditor.php`: formulario y acciones por serial; consultas de contadores sin modificar datos al montar/renderizar.
- `resources/views/livewire/work-order-distribution-editor.blade.php`: modelos, seriales y retirada/confirmación.
- `app/Filament/Pages/Distribution.php` y `resources/views/filament/pages/distribution.blade.php`: catálogos reales, permisos y cámara vinculada a una orden.
- `app/Models/WorkOrderProduct.php`: instantáneas y relaciones al modelo/ciclo de distribución; protección de la instantánea mientras está reservada o completada, incluso frente a un modelo cargado antes de una nueva reserva.
- `app/Services/ProductionCatalog.php` y `config/production.php`: tipo y ubicación Distribution, con identificadores configurables verificados.

## C. Base de datos

La primera migración adopta una ubicación Distribution única o la crea en el almacén de Stock verificado. No usa la ubicación fija `6` del código anterior: los seeders inspeccionados no definen esa ubicación. Si una instalación usa otro catálogo físico, puede configurar `PRODUCTION_DISTRIBUTION_LOCATION_ID` con su identificador verificado. El rollback no elimina una ubicación potencialmente referenciada.

La segunda agrega columnas nullable `distribution_serial`, `distribution_asset_id` y `distribution_cycle_id` a `work_order_products`. Las referencias al modelo y al ciclo restringen eliminación; la clave compuesta ciclo/producto impide guardar un ciclo de otro tubo. No modifica las asociaciones existentes. Bloquea rollback cuando hay instantáneas guardadas.

Se reutilizan el índice único producto/orden/propósito y la asignación activa única por producto. No se agregan tablas ni se cambian cantidades, seriales o registros anteriores. No se ejecutaron migraciones sobre la base real.

## D. Compatibilidad

Producción, inspección, reparación, espera, calidad, exportación y QuickBooks mantienen su implementación. Las distribuciones conservan sus líneas y cantidades en `wo_ass` y el estado final Ready. Se conserva el método de generación de nombre para producción por faltantes.

Para Stock antiguo sin ciclos se exige calidad aprobada en los campos existentes, fecha y peso de calidad, ensamblado/llenado completados y estado/ubicación verificables. Con ciclos, solo vale el último ciclo aprobado y finalizado. Seriales duplicados, vacíos, asignaciones activas o productos todavía enlazados a una orden no se cuentan como disponibles.

Preparar o consultar la página ya no pausa automáticamente una orden. Las reservas cambian únicamente asociación/asignación; el producto permanece en Stock hasta confirmar. Confirmar actualiza ubicación y orden operativa, conserva la orden original de producción y todos sus ciclos, y libera la asignación activa al finalizar.

Las operaciones de distribución toman primero un bloqueo común sobre su tipo de catálogo, después orden y productos; las cantidades se vuelven a leer con bloqueo. Las escrituras y auditoría son transaccionales. Se evita que dos distribuciones reserven el mismo tubo y que se supere la cantidad de un modelo mediante verificaciones bajo bloqueo y la asignación única. Estas garantías todavía requieren prueba simultánea en MySQL/MariaDB aislado.

## E. Pruebas

- `php vendor/bin/pest tests/Unit/DistributionSelectionTest.php`: 19 aprobadas; última ejecución, 133 aserciones.
- Cobertura: serial exacto sin mover Stock, duplicados/desconocidos/modelo incorrecto, calidad pendiente, reciclados, Review activa, reserva en otra distribución, límite por modelo, retirada/nuevo escaneo con historial, confirmación exacta sin tocar tubos no seleccionados, cambios de calidad/modelo/ubicación/estado/serial/cantidades, instantáneas protegidas frente a modelos desactualizados, último ciclo, faltantes calculados en servidor, permisos, selección de otra orden, rollback de auditoría, migraciones, formulario Livewire sin escrituras al montar y página Worker con cámara.
- Suite completa `php vendor/bin/pest tests/Unit`: 128 aprobadas y 2 fallidas. Pasaron todas las pruebas de fases anteriores de producción, calidad, ciclos y Review. Después se volvió a ejecutar la suite específica tras reforzar la protección de instantáneas.
- `node --test tests/JavaScript/weightscale.test.mjs tests/JavaScript/quality-scanner.test.mjs`: 9 aprobadas.
- Pint aplicado a los archivos PHP de la fase y `git diff --check` sin errores.

Las pruebas usan SQLite en memoria. No se ejecutaron pruebas con MySQL/MariaDB ni dispositivos físicos; las pruebas de reserva entre órdenes son secuenciales, no una prueba de carrera entre procesos.

## F. Problemas

Los dos fallos de `SerialPoolTest` siguen siendo los conocidos: el mock espera Admin/Manager mientras el controlador local también autoriza Worker. Se preservó ese cambio del usuario.

Las distribuciones antiguas preparadas mediante el método automático no poseen asociaciones ni instantáneas de los seriales elegidos. Esta fase no inventa ni reconstruye esa selección. Los productos Stock antiguos con referencias operativas o datos de calidad incompletos quedan excluidos hasta reconciliar esos datos explícitamente.

## G. Riesgos

Antes de habilitar en datos reales, aplicar migraciones incrementales pendientes, verificar el destino físico de Distribution y sus catálogos, y validar bloqueos/reservas simultáneas con MySQL/MariaDB aislado. Comprobar cámara y lector físicos. Revisar órdenes antiguas y el flujo de aprobación de producción por faltantes durante la integración de fase 8.

No se desplegó ni se modificó información real. Los cambios permanecen locales en `phase-7-distribution-serials`, conservando el trabajo de fases anteriores y los cambios del usuario.

## H. Próxima fase

Fase 8: pruebas de integración del recorrido completo y revisión de compatibilidad, permisos, catálogos, inventario, órdenes antiguas y producción por faltantes. Se prevén pruebas de integración y ajustes puntuales en servicios, estaciones, recursos, políticas y documentación que esas pruebas justifiquen.

La instrucción original exige detenerse al terminar cada fase. Fase 8 queda pendiente de aprobación explícita; cualquier despliegue también requiere aprobación.
