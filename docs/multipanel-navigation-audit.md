# Auditoría de navegación multipanel

Fecha: 14 de octubre de 2026. Entorno local: PHP 8.2.12; dependencias bloqueadas: Laravel 12.66.0, Filament 3.3.0, Livewire 3.8.4.

**A. Diagnóstico**

La navegación de Assets contenía una ruta fija a Admin. `Resource::getUrl()` de Filament 3.3.0 usa `getRouteBaseName()`, que obtiene el panel actual cuando no se proporciona `panel`. Esta implementación instalada se comprobó directamente en `vendor/filament/filament/src/Resources/Resource.php`.

Se encontraron además un destino incorrecto en “My Invoices”, enlaces relacionados sin comprobar la disponibilidad del recurso, vistas personalizadas que omitían la autorización del registro y retorno OAuth por rol en lugar del panel de origen. Estas condiciones estaban presentes en los archivos al iniciar la auditoría; no hay evidencia suficiente para atribuirlas a las correcciones recientes de autenticación.

La prueba existente de logout de Customer devolvía 419. Añadir únicamente el token no lo resolvió: la preparación necesitaba iniciar la sesión mediante `CustomLogin`, como las otras pruebas de logout. Se corrigió la preparación de la prueba; no se cambió el middleware ni la protección CSRF.

**B. Inventario de rutas y clasificación**

| Categoría / archivo | Hallazgo y decisión |
| --- | --- |
| Navegación interna: `app/Filament/Shared/Resources/AssetResource.php` | `filament.admin.resources.assets.view` sustituida por `static::getUrl('view', ...)`, condicionada a `canView`. |
| Navegación interna: `app/Providers/Filament/CustomerPanelProvider.php` | “My Invoices” enviaba a `/customer/inventories`; ahora usa el recurso Customer de Invoices y su autorización. |
| Recursos relacionados: `InstallationResource.php`, `WorkOrderResource.php` | La clase Manager de Invoice está registrada en Admin y Manager, pero no en Customer. Las acciones comprueban registro, acceso y permiso sobre la factura antes de mostrar o generar el enlace. En Customer se oculta esta acción; no se amplían permisos ni se fuerza otro panel. |
| Página personalizada: `WorkOrderResource.php` | “Gestionar Review” mantiene `ReviewOrders::getUrl()` y comprueba el acceso a esa página. |
| Recurso relacionado: `WorkOrderResource/Pages/ViewWorkOrder.php` | El enlace a Installation mantiene el panel y comprueba acceso y permiso sobre el registro. |
| Acceso directo: `AssetResource/Pages/ViewAsset.php`, `ManualResource/Pages/ViewManual.php` | Sus `mount()` personalizados resolvían registros sin llamar a `authorizeAccess()`. Se restauró esa comprobación y se conservaron sus plantillas. |
| Callback externo: `app/Filament/Pages/QuickBooks.php`, `app/Http/Controllers/QuickBooksController.php` | El botón transmite el identificador del panel. OAuth lo conserva en su sesión y el controlador valida panel registrado, acceso del usuario y disponibilidad de la página antes del retorno. El destino se calcula antes de consumir el estado. El callback ignora cambios de panel en su query. |
| Inicio de sesión: LoginResponse, CustomLogin, RedirectByRole, User::getPanelUrl | Correctos: selección inicial por rol. `RedirectByRole` se aplica a `/`, no globalmente a la navegación de paneles. Sin cambios. |
| Logout: LogoutResponse | Retorno explícito al panel compartido `auth` y `/login`; intencional. Sin cambios. |
| Autorización: CheckRole y proveedores | `canAccessPanel` conserva la jerarquía; CheckRole persiste en solicitudes Livewire. Sin cambios. |
| Cambio obligatorio de contraseña | Rutas fijas Customer intencionales para este flujo. Sin cambios. |
| API externa: EnsureQuickBooksTokenIsValid | `/quickbooks/customers` y `/quickbooks/invoices` no llevan contexto de panel. Se conserva el retorno legado Admin/Manager ante errores. No se deduce un panel desde Referer ni desde estado global residual. |
| Descargas: ProductsResource, InvoiceResource, ExportSerials y controladores | Se conservan `products.serials.preview` firmada temporalmente, `serials.export`, `invoices.pdf`, `invoices.packing-slip`, URLs absolutas, permisos y endpoints externos. |
| Enlaces externos: Goods y Manual | URLs de documentos / enlaces almacenados; no corresponden a navegación entre paneles. Sin cambios. |
| Rutas públicas / PWA | `/offline`, iconos, manifiesto y assets sin cambios. |
| Plantillas heredadas de auth/settings/welcome | Referencias a `home`, `dashboard`, `register` y `settings.*` con rutas comentadas. No forman parte de los flujos Filament registrados. Se documentan como deuda previa; no se reactivaron ni modificaron. |

Se buscaron generadores de URLs, redirects, prefijos de panel y rutas literales en app, providers, middleware, controladores, Livewire, rutas, bootstrap, vistas, configuración y código JavaScript propio. No se reemplazaron coincidencias en bundles de terceros. No se encontraron cambios de panel intencionales adicionales que necesitaran modificación.

**C. Archivos modificados**

| Archivo | Justificación |
| --- | --- |
| `app/Filament/Shared/Resources/AssetResource.php` | URL de fila dentro del panel activo y permiso de visualización. |
| `app/Filament/Shared/Resources/AssetResource/Pages/ViewAsset.php` | Autorizar el registro en la vista personalizada. |
| `app/Filament/Shared/Resources/ManualResource/Pages/ViewManual.php` | Autorizar el registro en la vista personalizada. |
| `app/Filament/Shared/Resources/InstallationResource.php` | Evitar enlaces a un recurso Invoice no registrado o no autorizado. |
| `app/Filament/Shared/Resources/WorkOrderResource.php` | Disponibilidad y permisos de enlaces Invoice / Review. |
| `app/Filament/Shared/Resources/WorkOrderResource/Pages/ViewWorkOrder.php` | Autorización del enlace Installation. |
| `app/Providers/Filament/CustomerPanelProvider.php` | Corregir el destino de “My Invoices”. |
| `app/Filament/Pages/QuickBooks.php` | Transmitir panel de origen al endpoint OAuth existente. |
| `app/Http/Controllers/QuickBooksController.php` | Conservar y validar el destino del retorno OAuth; fallback compatible para solicitudes antiguas sin panel. |
| `tests/Unit/PanelNavigationTest.php` | Regresiones de URLs, permisos, guardado, acciones relacionadas, navegación Customer, firmas y actualizaciones Livewire. |
| `tests/Unit/QuickBooksOAuthTest.php` | Origen autorizado, origen inválido, fallo de conexión y manipulación de panel en callback. |
| `tests/Unit/RoleAuthenticationTest.php` | Preparar logout Customer mediante login real y token de sesión. |
| `docs/multipanel-navigation-audit.md` | Inventario, evidencia, límites y validación de despliegue. |

Pint ordenó imports y normalizó formato únicamente en los PHP modificados. No se cambiaron modelos, políticas, migraciones, permisos, dependencias ni reglas de producción, Distribution o Installation.

**D. Recursos compartidos**

Se revisaron los diez recursos: Asset, Equipment, Goods, History, Installation, Inventory, Manual, Products, WorkOrder y sus páginas; también el relation manager Distributions (actualmente no registrado por WorkOrderResource), widgets de Inventory y ExportSerials.

Asset, Installation, Manual y WorkOrder necesitaron las correcciones descritas. Equipment, Goods, History, Inventory y Products usan navegación nativa, modales o endpoints externos adecuados. Sus redirects de creación/edición heredados de Filament y los personalizados con `getResource()::getUrl()` conservan el panel. Las acciones de eliminación siguen sujetas a sus políticas; no se habilitó la eliminación de Assets.

Admin y Manager descubren todos los recursos compartidos; Customer también los descubre, pero sus permisos filtran el acceso. Worker no descubre recursos compartidos. Admin registra explícitamente la clase Manager de Invoice; Customer usa una clase Invoice diferente. La existencia de una ruta no implica autorización.

**E. Autenticación**

Se conservan destinos iniciales Admin → `/admin`, Manager → `/manager`, Worker → `/worker`, Customer e Inst_Manager → `/customer`. Los tests existentes cubren prioridad de múltiples roles, credenciales inválidas, invitados, logout/login y denegación de paneles no autorizados.

Las nuevas comprobaciones HTTP y Livewire validan Admin navegando en Manager y Manager navegando en Worker. Ninguna corrección cambia artificialmente el panel para generar enlaces ni usa `getPanelUrl()` para navegación ordinaria.

**F. Pruebas y límites**

Selección final: `php vendor/bin/pest tests/Unit tests/Integration` → **258 pruebas aprobadas, 2044 assertions, 110,50 segundos; cero fallos**. Incluye 26 casos de navegación, 26 de autenticación y 29 de QuickBooks OAuth, además de facturas, seriales y producción.

La base de datos de las pruebas es SQLite `:memory:`. No se ejecutaron `migrate:fresh`, `db:wipe` ni operaciones contra la base existente. Los tests de QuickBooks construyen URLs localmente o usan mocks para intercambios, refrescos, consultas y PDFs; no hubo solicitudes reales a QuickBooks.

Cobertura adicional: fila Asset, acciones edit/create, GET de destinos autorizados, creación y guardado Livewire con redirects, permisos de vistas personalizadas, páginas de todos los recursos compartidos autorizados en Admin/Manager, acciones Invoice y Review, enlace Customer, persistencia de panel en POST `/livewire/update`, autorización persistente, firmas válidas/manipuladas, PDFs, packing slips, seriales y flujo completo de producción.

`php artisan route:list --json` se ejecutó correctamente: Assets tiene index/create/view/edit en Admin y Manager; existen las rutas externas y sus nombres originales. También hay rutas compartidas Customer, cuya autorización sigue siendo independiente de su registro. Se verificó la sintaxis de los 12 PHP modificados, se ejecutó Pint y se revisó `git diff --check`.

No se ejecutó la carpeta Feature porque `tests/Pest.php` le aplica `RefreshDatabase`, que invoca `migrate:fresh`, expresamente excluido por la solicitud. Tampoco se ejecutaron pruebas de concurrencia contra otros motores ni PHP 8.3. No se afirma que la suite completa del repositorio haya pasado. No hubo pruebas manuales en navegador ni reconexión OAuth real.

**G. Riesgos y revisión manual**

Validar en staging los enlaces de menú, breadcrumbs, atrás/adelante, sesiones expiradas, pestañas múltiples y PWA. OAuth mantiene un único estado pendiente por sesión, igual que antes; iniciar dos conexiones simultáneas puede invalidar la primera. Solicitudes antiguas sin origen usan el fallback por rol existente.

Las políticas de Manual permiten `view` a cualquier usuario autenticado; esta auditoría conserva esa regla y añade su ejecución. Los recursos descubiertos en Customer siguen registrados aunque no aparezcan en la navegación; los tests comprueban denegación para Assets. No se cambian los permisos existentes.

El middleware ForcePasswordChange conserva su bypass previo de endpoints Livewire; CheckRole y la autenticación persistente siguen verificando acceso al panel. Revisar separadamente la política de cambio obligatorio de contraseña si se desea endurecer ese flujo. Los enlaces de plantillas heredadas deberán revisarse antes de reactivar esas pantallas.

**H. Producción: instrucciones, sin despliegue ejecutado**

1. Revisar el diff y ejecutar la selección de pruebas en staging con PHP 8.3 y SQLite aislada. No apuntar pruebas a producción.
2. Publicar una release mediante el procedimiento habitual y regenerar caches con `php artisan optimize`. Si el despliegue cachea componentes Filament, regenerar esa caché siguiendo el procedimiento existente. No hay migraciones ni cambios de dependencias para esta corrección.
3. Validar login de los cinco roles; Admin en Manager; Manager en Worker; Asset view/create/edit y guardado en ambos paneles; `/admin/assets` denegado a Manager; enlace de facturas Customer; enlaces WorkOrder/Installation/Invoice y Review.
4. Validar logout y nuevo login, PDFs, packing slips, exportación/preview firmado, modo PWA y callback OAuth en staging sin cambiar credenciales ni configuración del callback. El endpoint externo OAuth permanece `/quickbooks/callback`.
5. Observar 403, 419, errores de rutas y retorno OAuth en los logs. Ante regresión, restaurar la release anterior y sus caches; no se requiere rollback de base de datos.

No se hizo despliegue, commit ni push.
