# Fase 5 — Estación de revisión

## A. Resumen

La página Revisión aparece en Shop Floor para Admin, Manager y Worker. Solo admite órdenes Review cerradas, iniciadas y situadas en el centro Review. El trabajador identifica cada tubo mediante serial escrito, lector o cámara; registra diagnóstico y reparación realizada o motivo de reciclaje.

Guardar reciclaje abre una confirmación sin escribir datos. Cancelar conserva el producto; cambiar los datos invalida la confirmación. El servidor vuelve a comprobar orden, serial único, asociación activa y último ciclo rechazado al guardar. Cada resultado queda ligado al producto, miembro de la orden, ciclo rechazado, usuario y fecha en product_repairs y history.

Al iniciar una orden, sus miembros pasan a la ubicación Review. Las órdenes iniciadas antes de esta fase también aceptan miembros todavía en Quality. La orden permanece en Review mientras haya miembros pendientes. Si quedan reparados al completar todas las inspecciones, pasa al centro Fill; si todos fueron reciclados, queda Ready sin centro. Los reciclados pasan a los catálogos existentes Recycled y Recycle, se desligan de la orden operativa y liberan su asignación activa conservando su asociación histórica.

## B. Archivos

Creaciones:

- app/Services/ReviewInspectionService.php
- app/Livewire/WorkOrderReviewEditor.php
- app/Filament/Pages/Review.php
- resources/views/livewire/work-order-review-editor.blade.php
- resources/views/filament/pages/review.blade.php
- database/migrations/2026_10_13_110000_add_review_location.php
- tests/Unit/ReviewInspectionTest.php
- docs/production-review-station-phase-5.md

Modificaciones:

- app/Services/ReviewWorkOrderService.php: ubicación al iniciar Review.
- app/Services/ProductionCatalog.php y config/production.php: resolución verificada de Review, Recycle y Recycled.
- resources/views/filament/pages/review-orders.blade.php: acceso a la estación.
- tests/Unit/ReviewWorkOrderTest.php: preparación del catálogo de ubicación para la integración con la estación.

## C. Base de datos

La migración adopta una ubicación Review única o la agrega con el mismo almacén de la ubicación Quality verificada. Rechaza catálogos ambiguos. No asigna identificadores fijos ni elimina registros. El rollback se bloquea para conservar ubicaciones que pueden tener referencias operativas.

Se reutilizan las tablas, relaciones e índices de las fases 1 y 4; esta fase no agrega columnas ni índices. ProductRepair mantiene una decisión única e inmutable por miembro de la orden. Se usan los registros Recycle y Recycled existentes y se verifican sus identificadores mediante el catálogo; los identificadores configurados explícitamente también deben existir.

No se ejecutaron migraciones sobre la base real ni se modificaron datos reales.

## D. Compatibilidad

Se conservan serial, producto, orden original, pesos y ciclo rechazado. Reparar no borra los indicadores ni crea un ciclo nuevo. Los reparados mantienen su asociación activa hasta implementar el siguiente ciclo en fase 6. Se reutiliza el lector QR existente; no se modifica la báscula, exportación ni QuickBooks. Los controles administrativos de recepción, aprobación e inicio siguen restringidos a Admin y Manager.

Cada inspección, resultado, movimiento, liberación y auditoría se guarda en una sola transacción. Usa el mismo bloqueo inicial del catálogo Review que las incorporaciones y transiciones administrativas, y después bloquea orden y producto. Repetir la decisión se rechaza; un fallo de auditoría revierte todo.

## E. Pruebas

- `php vendor/bin/pest tests/Unit/ReviewInspectionTest.php`: 14 aprobadas, 85 aserciones. Cubre selección exacta sin escritura, serial ajeno/desconocido/duplicado, asignación ausente, estación incorrecta, permisos, diagnóstico y explicaciones obligatorias, confirmación y cancelación, invalidación por cambios, conservación de pesos, resultados mixtos, reciclaje total, decisión repetida, rollback de auditoría, migración idempotente y panel Worker con cámara.
- `php vendor/bin/pest tests/Unit`: 91 aprobadas y 2 fallidas, 920 aserciones. Todos los casos de producción, calidad, trazabilidad y Review pasaron. Los dos fallos corresponden a la diferencia previa de permisos de exportación descrita en F.
- `node --test tests/JavaScript/weightscale.test.mjs tests/JavaScript/quality-scanner.test.mjs`: 9 aprobadas.
- `php vendor/bin/pest tests/Concurrency/ReviewWorkOrderConcurrencyTest.php`: 1 omitida porque no se configuró una base MySQL vacía y aislada. Es la prueba de concurrencia de agrupación de fase 4; no constituye validación MySQL de inspecciones concurrentes.
- Pint aplicado a los archivos PHP de esta fase; `git diff --check` sin errores.

Las pruebas PHP usan SQLite en memoria y no modifican datos reales. La cámara se verifica con componentes y simulación JavaScript, sin dispositivo físico.

## F. Problemas

Los dos fallos preexistentes de SerialPoolTest esperan `hasRole(['Admin', 'Manager'])`; el controlador local autoriza también Worker. Se conservó esa modificación del usuario y no se cambiaron permisos ni pruebas ajenas a esta fase.

Los reparados quedan pendientes de llenado: el flujo actual de Filling admite órdenes de producción originales. El recorrido de órdenes Review y la creación de nuevos ciclos se implementan en fase 6, pendiente de aprobación.

## G. Riesgos

Antes de usar la estación en una base real, verificar catálogos Quality, Review, Recycle, Recycled, Fill y estados de orden/producto, aplicar las migraciones incrementales pendientes mediante el procedimiento habitual y validar las referencias históricas. No se realizaron despliegues ni pruebas con MySQL o dispositivos físicos. La concurrencia real debe verificarse en un entorno aislado antes de habilitar operación simultánea.

## H. Próxima fase

Fase 6: nuevos ciclos de llenado, espera de cinco días calendario y calidad para reparados, conservando serial y todos los resultados anteriores; exclusión de reciclados y regreso a Review si un nuevo ciclo vuelve a rechazarse. Se prevén cambios en ProductionFillingService, ProductionQualityService, ReviewWorkOrderService, componentes de estaciones, scheduler y pruebas de ciclos. Las fechas de espera seguirán sin hora.

Se requiere aprobación explícita antes de iniciar fase 6, según la instrucción del documento original de detenerse al terminar cada fase.
