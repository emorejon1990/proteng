# QuickBooks OAuth2: diagnóstico, corrección y operación

## Diagnóstico

La causa demostrada en el código es la lectura directa de `env('QB_CLIENT_ID')` en `/quickbooks/connect`, middleware y página Filament, mientras que el servicio ya usaba `config()`. Laravel omite cargar `.env` cuando utiliza su configuración cacheada. Si las variables no están exportadas en el proceso PHP, las lecturas directas devuelven null aunque `services.quickbooks` sí tenga las credenciales. SDK instalado: v6.3.1; `ServiceContext::checkIfOAuthIsValid()` lanza exactamente `'ClientID' must be provided in OAuth2` ante ClientID null.

Esto demuestra un mecanismo reproducible, no el estado del servidor de producción: no se accedió al servidor, sus logs, `.env` ni PHP-FPM. Hipótesis adicionales: cachés diferentes entre releases/nodos, workers persistentes con configuración vieja, variables de FPM distintas de CLI, Client ID realmente ausente, redirect URI distinto del registrado en Intuit, entorno incompatible con credenciales/tokens, errores TLS/red/Intuit y renovación simultánea de refresh tokens. Las últimas causas producen otros errores; no explican por sí solas el ClientID null.

No hay un singleton de QuickBooks ni configuración de Octane/Horizon/Supervisor encontrada en el repositorio. Eso no excluye procesos persistentes definidos fuera del proyecto.

## Cambios

- `app/Services/QuickBooksDataServiceFactory.php`: único `DataService::Configure()`, basado en `config/services.php`. Valida credenciales, entorno y requisitos de OAuth o tokens guardados; conserva overrides. Normaliza sandbox/Development y Production según el SDK instalado; rechaza entornos desconocidos. Desactiva logs de solicitudes SDK y OAuth.
- `config/services.php`: conserva QB_CLIENT_ID, QB_CLIENT_SECRET, QB_REDIRECT_URI, QB_SCOPE y QB_ENV; aclara alias de entorno. Sin valores secretos predeterminados.
- `app/Http/Controllers/QuickBooksController.php`: fábrica compartida, errores seguros, state aleatorio de 256 bits en sesión, ligado al usuario, válido diez minutos y consumido al aceptar el callback. Comprueba cancelación, code, realm y tokens completos antes de persistir. Retorna al panel Admin/Manager real. Mantiene customers y resuelve la ruta invoices que antes apuntaba a un método inexistente con una consulta de lectura limitada.
- `app/Services/QuickBooksService.php`: mantiene selección del último token con realm y verificación del realm esperado. Renueva bajo bloqueo compartido por realm, vuelve a leer el registro dentro del bloqueo y conserva ambos tokens solo si la respuesta está completa. Una marca de caché por hash del access token evita renovar durante 50 minutos tras el éxito. Nunca borra tokens ni fuerza una conexión nueva.
- `app/Http/Middleware/EnsureQuickBooksTokenIsValid.php` y `app/Filament/Pages/QuickBooks.php`: utilizan el mismo servicio y configuración. Los fallos al construir el SDK quedan dentro del manejo de errores.
- `app/Http/Middleware/AuthorizeQuickBooks.php` y `routes/web.php`: auth y roles Admin/Manager para conectar, callback, customers e invoices. Callback GET conserva sesión web; no usa el middleware que renueva tokens, ni exige tokens anteriores. No depende de CheckRole, que necesita un panel activo.
- `app/Services/QuickBooksDiagnostics.php`: registra operación, clase, origen, traza sin argumentos, estado de caché y presencia de claves. Omite mensajes SDK, objetos de excepción, solicitudes, códigos y secretos. Los errores de programación de tipo Error no se capturan en los manejadores de Exception.
- `resources/views/filament/pages/quick-books.blade.php`: presenta los mensajes seguros de éxito/error.
- `tests/Unit/QuickBooksOAuthTest.php`: pruebas con SQLite en memoria y mocks, sin llamadas reales a Intuit.

No se cambió `.env`, dependencias, esquema ni datos existentes. Las actualizaciones de tokens solo ocurrirán durante callbacks válidos o renovaciones exitosas, al ejecutar la aplicación. No se realizó despliegue.

## Pruebas y límites

21 pruebas QuickBooks pasan (90 assertions): configuración centralizada, SDK real con overrides, credenciales ausentes, URL OAuth, state fuerte, cancelación, state incorrecto/ausente/vencido/de otro usuario, callback exitoso, errores sin pérdida de tokens, logs seguros, configuración cacheada en proceso separado sin variables OAuth, roles, renovación y reutilización, parámetros inválidos. La prueba de caché usa el bootstrap real LoadConfiguration con un archivo temporal de configuración ficticia; no regenera la caché del proyecto.

InvoiceIntegrationTest: 36 pruebas aprobadas (173 assertions). Pint aplicado; listado real de rutas confirma auth y autorización en las cuatro rutas, con renovación únicamente en consultas. La ejecución combinada inicial tuvo 78 pruebas aprobadas y 1 fallida (376 assertions), incluyendo QuickBooks, facturas y roles. El fallo de RoleAuthenticationTest en línea 168 (logout Customer pendiente de contraseña, HTTP 419) también se reproduce ejecutando solo esa prueba. No se modificó ese flujo; no se afirma que toda la suite pasa ni que se probó contra una versión del código previa a estos cambios.

Entorno local: PHP 8.2.12; la compatibilidad operativa con PHP 8.3 de producción requiere validación allí. No se probaron llamadas reales, concurrencia entre procesos ni conectividad de producción.

Los bloqueos requieren un almacén de caché compartido entre todos los nodos/procesos (Redis/database, o file en un único servidor). No usar array para producción. El bloqueo dura 120 segundos y espera 15; revisar tiempos de red para que no expire mientras una renovación siga en curso. Pérdida de la marca de caché provoca otra renovación legítima. No existe fecha de expiración persistida en la tabla; por eso la primera lectura tras perder la marca renueva. Cambiar la selección de empresa o migrar esquema está fuera del alcance. Los indicadores de conexión en la página muestran existencia de registros, no validación remota.

Si la sesión del usuario vence, se pierde o cambia de host durante OAuth, debe volver a iniciar el flujo; no aceptar callbacks sin esa sesión. Verificar cookies secure/SameSite=Lax y sesiones compartidas si hay varios nodos. No reconectar automáticamente una empresa ni cambiar realm ante errores.

## Comprobaciones de producción (solo administrador)

Ejecutar desde la raíz del release activo, con el usuario de la aplicación. Sustituir rutas/unidad FPM por las reales. No imprimir `.env`, `config:show services`, tokens ni objetos de excepción.

1. Comprobar presencia en `.env` sin mostrar valores (solo lectura):

```bash
php artisan tinker --execute='$values = Dotenv\Dotenv::parse(file_get_contents(base_path(".env"))); foreach (["QB_CLIENT_ID", "QB_CLIENT_SECRET", "QB_REDIRECT_URI", "QB_SCOPE", "QB_ENV"] as $key) { echo $key.": ".(filled($values[$key] ?? null) ? "definida" : "ausente").PHP_EOL; }'
```

2. Comprobar lo que Laravel realmente utiliza y la caché (solo lectura):

```bash
php artisan tinker --execute='echo "config_cached=".(app()->configurationIsCached() ? "si" : "no").PHP_EOL; foreach (["client_id", "client_secret", "redirect_uri", "scope", "env"] as $key) { echo $key.": ".(filled(config("services.quickbooks.".$key)) ? "definida" : "ausente").PHP_EOL; } echo "cache_store=".config("cache.default").PHP_EOL;'
php artisan route:list --path=quickbooks -v
php artisan route:list --name=pages.quick-books
ps -eo pid,lstart,args | grep -E '[a]rtisan (queue:work|horizon|octane)'
systemctl list-units --type=service 'php*-fpm.service'
```

Revisar de forma privada que Redirect URI coincida exactamente con el portal de Intuit, scope incluya `com.intuit.quickbooks.accounting`, QB_ENV sea Development/sandbox o Production y las credenciales correspondan a ese entorno. La comprobación CLI no garantiza el entorno FPM: comparar release activo de nginx/FPM y revisar los nuevos registros (campo configured/config_cached) tras una solicitud web. Si falta una clave, el administrador debe corregirla por su procedimiento de secretos; no generar credenciales nuevas por defecto.

3. Tras instalar el código mediante el procedimiento autorizado y corregir configuración si fuese necesario, regenerar cachés:

```bash
php artisan config:cache
# Si el despliegue utiliza rutas cacheadas, regenerarlas por los cambios de middleware:
php artisan route:cache
# Solo si se ejecutan queue workers persistentes:
php artisan queue:restart
# Confirmar unidad instalada; ejemplo para PHP 8.3:
sudo systemctl reload php8.3-fpm
```

`config:cache` y `route:cache` cambian configuración/rutas usadas por solicitudes siguientes; ejecutarlos en la ventana/procedimiento de despliegue. `queue:restart` pide salida al terminar el job; Supervisor/systemd debe volver a iniciar los workers o la cola quedará detenida. La recarga FPM reemplaza procesos y puede afectar brevemente el servicio. Si existe Horizon u Octane fuera del repositorio, aplicar el reinicio propio de ese servicio según el procedimiento del servidor. No ejecutar estos comandos automáticamente desde esta tarea. No se necesita reiniciar nginx si su configuración no cambia.

4. Verificación funcional:

- Antes de reconectar, iniciar sesión como Admin/Manager y ejecutar Load Customers usando la conexión existente. Comprobar lectura, sincronización de facturas y PDF; verificar que realm y relaciones existentes se conservan. Un fallo temporal no exige reconectar.
- Si se necesita probar el flujo completo, usar Connect QuickBooks, seleccionar la empresa correcta en Intuit y autorizar. Verificar retorno `/admin/quick-books` o `/manager/quick-books`, mensaje de éxito y lectura de clientes. Una autorización exitosa actualiza tokens de esa empresa como parte normal del flujo.
- Probar cancelación: mensaje seguro, tokens sin cambios. Una URL de callback copiada/reutilizada, sin state correcto, debe rechazarse.
- Comprobar que visitantes van al login y Worker recibe 403.
- Revisar Laravel logs: operación/clase/origen/configuración presente, sin códigos ni tokens. Evitar copiar URLs del callback o logs históricos a tickets; el código anterior sí registraba solicitudes completas. Revisar también que nginx/APM no recojan query strings OAuth con el administrador.
- Repetir la lectura después de regenerar configuración y recargar procesos para comprobar que el error ClientID no vuelve con claves presentes.

Referencias: implementación SDK instalada en vendor (solo lectura) y documentación oficial Intuit https://intuit.github.io/QuickBooks-V3-PHP-SDK/configuration.html y https://intuit.github.io/QuickBooks-V3-PHP-SDK/authorization.html. La validación final de producción queda pendiente del administrador.
