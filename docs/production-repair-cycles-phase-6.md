# Fase 6 — Nuevos ciclos para productos reparados

## A. Resumen

Las estaciones existentes de llenado y calidad admiten órdenes Review que terminaron todas sus inspecciones. Solo participan miembros reparados; los reciclados conservan su resultado y asociación histórica y quedan fuera de los siguientes pasos.

Escanear sigue siendo una lectura sin cambios. Al guardar llenado se crea un ciclo `repair`, con número consecutivo y vínculo único a la reparación. Conserva una instantánea de los campos anteriores del producto. El ciclo rechazado y el diagnóstico permanecen inmutables. La asignación activa cambia del rechazo al nuevo ciclo dentro de la misma transacción. Los campos actuales del producto representan el último llenado; los campos actuales de calidad se limpian después de archivar su versión anterior.

Cada reparado debe guardar su nuevo peso; el booleano histórico `filled` no cuenta como llenado del nuevo ciclo. Cuando todos completan llenado, los ciclos reciben `waiting_started_on` y `quality_available_on`: fecha de finalización de la orden y cinco días después. Son fechas sin hora, como se solicitó. Un tubo llenado antes espera también hasta cinco días después del último llenado de la orden.

El comando existente `workorders:move-after-5days` mantiene su nombre y programación. Ahora resuelve los identificadores reales de tipos, centros y ubicaciones, valida cada miembro y mueve la orden completa a Quality únicamente cuando todos sus períodos terminaron. Conserva las fechas de espera del ciclo y registra el movimiento automático. Una orden inconsistente queda intacta con un aviso; una orden todavía en espera no escribe datos. Repetir el comando después del movimiento no duplica eventos.

Calidad compara el peso con el llenado del nuevo ciclo, con tolerancia inclusiva de ±5 g. Aprobación envía el tubo a Stock y libera la asignación activa. Rechazo confirmado conserva el ciclo y crea/incorpora el tubo en otra orden Review, utilizando la agrupación y límite de 50 existentes. Es posible repetir reparación y ciclo sin crear otro producto ni cambiar su serial u orden original.

Los contadores pertenecen a los miembros y ciclos de cada orden. Aunque un tubo rechazado ya esté comenzando otro ciclo en una Review posterior, la orden anterior sigue contando su propio resultado y espera únicamente a sus miembros pendientes.

## B. Archivos

Creaciones:

- `app/Services/RepairCycleService.php`: elegibilidad, asociación, nuevo ciclo y consultas de progreso de reparados.
- `app/Services/ProductionWaitingService.php`: validación y movimiento transaccional de espera a calidad.
- `database/migrations/2026_10_13_120000_allow_automatic_history_events.php`.
- `tests/Unit/RepairProductionCycleTest.php`: 18 casos para esta fase.
- `docs/production-repair-cycles-phase-6.md`.

Modificaciones:

- `app/Services/ProductionFillingService.php`: órdenes Review, creación del ciclo y espera conjunta.
- `app/Services/ProductionQualityService.php`: evaluación del nuevo ciclo y finalización según sus miembros.
- `app/Services/ReviewWorkOrderService.php`: incorporación de rechazos producidos en ciclos de reparación.
- `app/Console/Commands/MoveWorkOrdersAfterFiveDays.php`: uso del servicio de espera sin identificadores fijos.
- `app/Livewire/WorkOrderFillingEditor.php` y `app/Livewire/WorkOrderQualityEditor.php`: contadores por ciclo.
- `app/Filament/Shared/Resources/HistoryResource.php`: muestra «Sistema» para eventos automáticos sin trabajador.

## C. Base de datos

La migración permite `history.user_id = NULL` para eventos automáticos. Conserva tipo, registros y referencias existentes; no crea un trabajador ficticio. Los eventos llevan `metadata.automatic = true`. El rollback a columna obligatoria se rechaza si existen eventos sin usuario, para no inventar un autor ni eliminar auditoría.

Se reutilizan relaciones e índices de fases anteriores: número de ciclo único por producto, reparación única por miembro, nuevo ciclo único por reparación y claves compuestas que garantizan el mismo producto. No se crean seriales, productos ni tablas nuevas.

No se ejecutaron migraciones sobre la base real. La migración de esta fase debe aplicarse antes de utilizar el scheduler actualizado; también deben estar aplicadas las migraciones de trazabilidad y Review de las fases anteriores.

## D. Compatibilidad

Los casos de producción original, ensamblado, seriales, calidad y Review siguen pasando sus pruebas previas. La báscula y el lector QR mantienen su implementación. La exportación y QuickBooks no se modificaron. Se conserva la programación existente del comando de espera.

Producción antigua sin ciclo puede avanzar con llenados y fecha de ingreso a espera verificables. Si faltan datos o existe una ubicación/asignación inconsistente, se informa y no se mueve la orden. Los reparados requieren las fechas de su ciclo; nunca recuperan una fecha de espera de un ciclo anterior.

Las escrituras de órdenes Review toman primero el mismo bloqueo de catálogo usado por incorporación e inspección, y después orden/producto. La creación del ciclo, actualización de campos actuales, asignación y auditoría son atómicas. Los movimientos de espera y los rechazos también revierten completamente si falla la auditoría. Los índices únicos y las verificaciones bajo bloqueo previenen ciclos repetidos por doble guardado.

Los reparados aprobados quedan disponibles en Stock. La selección individual de distribución y la integración general corresponden a fases 7 y 8; esta fase no altera los estados de distribuciones enlazadas desde órdenes Review.

## E. Pruebas

- `php vendor/bin/pest tests/Unit`: **109 aprobadas y 2 fallidas**, 1048 aserciones. Las 18 nuevas de `RepairProductionCycleTest` pasaron, junto con todas las anteriores de producción, calidad, trazabilidad e inspección/agrupación Review.
- Los casos nuevos verifican creación solo al guardar, pesos archivados, llenado repetido, fechas sin hora al límite de medianoche, reciclados excluidos, aprobación con el nuevo peso, tres ciclos del mismo serial con dos reparaciones, avance solo con todos los resultados, contadores Livewire, permisos, identidad, tolerancia, asignación inválida, inspección incompleta, fechas ausentes/acortadas, producción antigua, rollback de llenado/scheduler/rechazo y migración del historial.
- `node --test tests/JavaScript/weightscale.test.mjs tests/JavaScript/quality-scanner.test.mjs`: **9 aprobadas**.
- `php vendor/bin/pest tests/Concurrency/ReviewWorkOrderConcurrencyTest.php`: **1 omitida**, sin base MySQL vacía y aislada configurada. Es la prueba de agrupación de fase 4, no una validación de concurrencia real de ciclos.
- Pint aplicado a los PHP de esta fase; `git diff --check` sin errores.

PHP se verificó con SQLite en memoria. No se usó la base real para pruebas y no se ejecutó el scheduler sobre datos reales.

## F. Problemas

Los dos fallos de `SerialPoolTest` son los mismos de la fase anterior: las expectativas del mock admiten Admin/Manager, mientras el cambio local del usuario en `SerialExportController` incluye Worker. Ese controlador y sus permisos se conservaron.

No se probaron MySQL/MariaDB ni dispositivos físicos. La prueba MySQL disponible quedó omitida. El cambio de nulabilidad del historial se comprobó con las migraciones reales en SQLite, pero todavía necesita validación en el motor de destino aislado.

## G. Riesgos

Antes de habilitar este flujo en datos reales: aplicar las migraciones incrementales pendientes mediante el procedimiento habitual, verificar catálogos reales y comprobar nulabilidad del historial, bloqueos, concurrencia y dispositivos en un entorno aislado. Las órdenes antiguas con fechas o asociaciones incompletas pueden quedar pendientes de revisión administrativa en lugar de avanzar automáticamente.

Todos los cambios siguen locales y sin despliegue. Se preservaron los cambios existentes de fases anteriores y del usuario en la rama `phase-6-repair-cycles`.

## H. Próxima fase

Fase 7: identificación por serial de los tubos específicos de cada distribución, conservando modelos y cantidades solicitadas. Validar Stock, calidad aprobada, exclusión de reciclados, ausencia de otras asignaciones/reservas y límites de cantidad; permitir retirar una selección equivocada antes de confirmar.

Se prevén cambios en `WorkOrderDistributionEditor`, sus vistas, página de distribución, servicios de selección/reserva y pruebas correspondientes, reutilizando las asociaciones y asignaciones existentes.

La instrucción original exige detenerse y esperar aprobación explícita al finalizar cada fase. No se inició fase 7.
