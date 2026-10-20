# Accesos autorizados por dispositivo e IP — DEV

Implementado y desplegado el 15/10/2026 en CRM DEV. PROD conserva sus despliegues anteriores.

## Uso

El Super admin dispone del menú **Accesos autorizados**. Los administradores de oficina y agentes que presentan credenciales correctas desde un navegador o una IP nuevos quedan pendientes, sin obtener una sesión utilizable. Tras la aprobación deben iniciar sesión de nuevo.

El Super admin puede configurar límites por usuario de 1 a 20 dispositivos y de 1 a 20 IP por dispositivo. El valor inicial es uno de cada uno; aumentar un límite no aprueba solicitudes automáticamente. Reducirlo por debajo de los permisos vigentes exige revocar primero los sobrantes.

La autorización puede durar una hora, ocho horas (opción inicial), 24 horas, siete días, 30 días, hasta una fecha concreta o hasta revocación. Se puede revocar una IP concreta o todas las IP de un dispositivo. El servidor comprueba el permiso en cada petición privada, por lo que la caducidad y la revocación afectan a las sesiones abiertas.

El Super admin conserva su acceso habitual para gestionar las solicitudes. La contraseña, el estado activo del usuario, el estado de la oficina, la separación entre oficinas y la invalidación de sesiones siguen siendo obligatorios.

«Dispositivo» significa navegador y perfil: otro navegador, modo privado o borrar las cookies puede generar una nueva solicitud. Un móvil que cambie de Wi-Fi a datos necesitará aprobar también esa IP. El equipo puede autorizar ambas y ampliar el límite correspondiente.

## Historial y protección

Se registran solicitudes, aprobaciones, rechazos, revocaciones de IP o dispositivo y cambios de límites, con usuario responsable, usuario afectado, oficina y datos de la autorización. No se registran contraseñas, cookies ni tokens.

La identificación del navegador usa una cookie aleatoria de servidor `__Host-reygom_device`, con Secure, HttpOnly, SameSite=Strict, Path=/ y sin Domain. La base de datos guarda su huella SHA-256 y el token de sesión queda ligado al dispositivo del usuario.

El acceso privado requiere el proxy verificado. Se ha comprobado desde el dominio público que el proxy sustituye X-Real-IP por la IP de la conexión y que enviar X-Real-IP o X-Forwarded-For falsos no cambia la IP registrada.

## Validación

- 82 comprobaciones nuevas de acceso: bloqueo inicial, ausencia de token antes de aprobación, permisos exclusivos del Super admin, cookies, cambio de IP, límites, concurrencia, fechas, caducidad, revocación, sesiones, historial y orígenes permitidos.
- 65 comprobaciones de oficinas y separación de datos, repetidas sobre la versión final.
- 75 comprobaciones de historial, repetidas sobre la versión final.
- 20 pruebas del frontend; lint y compilación correctos.
- Regresiones de anuncios recibidos, catálogo de países y persistencia de ubicaciones correctas.
- Prueba real en navegador separado: usuario sintético bloqueado; aprobación por la API real; acceso con la cookie del navegador; revocación del dispositivo; sesión abierta redirigida a la pantalla de acceso con aviso de aprobación necesaria.

La sesión anterior del Super admin en Chrome había caducado durante la comprobación visual; se solicitó iniciar sesión de nuevo para revisar esa pantalla con la cuenta real. Esto no impidió verificar los permisos y operaciones del Super admin a través de la API.

## Despliegue y operación

API DEV: `reygom-api:dev-access-20261015-r3`.
CRM DEV: `reygom-crm:dev-access-20261015`.
Versión visible: `0.5.0-dev-access-20261015`.
Fuentes aisladas: `/opt/reygom/releases/access-dev-20261015/{api,crm}`; rama `feature/device-ip-approval`.
Migración: `api/migrations/dev/20261015_device_ip_access.sql`. Se aplica una vez después de las migraciones de oficinas e historial. Se hizo copia privada de DEV y una prueba con rollback antes de aplicarla.

Variables del API DEV: `ACCESS_REQUIRE_PROXY=1` y `ACCESS_TRUSTED_PROXIES=10.1.189.203`, correspondiente al proxy observado y comprobado en este despliegue. Si cambia la IP del pod de ingress, se debe verificar su configuración y actualizar esa variable: el sistema bloquea el acceso de administradores y agentes ante un proxy no verificado. No ampliar la confianza a todas las IP del clúster.

Durante el rollout hubo respuestas transitorias de la versión anterior y del proxy. La primera comprobación activó la reversión automática de la API. Tras reconfirmar el estado, el despliegue final y las comprobaciones públicas fueron correctos. Imágenes anteriores para reversión: API `reygom-api:dev-history-20261015-r3`; CRM `reygom-crm:dev-history-20261015`. La migración aditiva no elimina datos existentes.

La bandeja muestra hasta 500 solicitudes, con prioridad de las pendientes, y 500 autorizaciones recientes; el historial completo dispone de paginación. No se ha programado ninguna promoción a main, release ni despliegue en PROD.
