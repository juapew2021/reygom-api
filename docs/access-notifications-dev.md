# Avisos de solicitudes de acceso — DEV · 18/10/2026

Las nuevas solicitudes pendientes de agentes y administradores generan una notificación interna para cada Super admin activo de una oficina activa. Incluye usuario, oficina, IP, dispositivo, identificador y fecha UTC. La bandeja presenta el remitente «Seguridad ReyGom · Sistema» y permite abrir Accesos autorizados.

La entrega se produce en la misma transacción que la solicitud. Un reintento pendiente o rechazado no repite el aviso. Una IP distinta genera una solicitud distinta; una autorización anterior que vuelve a requerir aprobación genera un nuevo aviso. Ningún aviso concede acceso. No se envían correos ni avisos del sistema operativo. No se generan avisos retroactivos por solicitudes históricas.

La función del disparador tiene ruta de búsqueda fija y ejecución pública revocada. Las notificaciones del sistema tienen emisor nulo y conservan las políticas existentes de lectura personal; no aparecen en Enviadas del solicitante. Las solicitudes siguen protegidas por los controles existentes del Super admin.

Pruebas HTTP con esquema aislado y usuarios ficticios: agentes y administradores, Super admin activo/inactivo, aislamiento de bandejas, estado leído/contador, reintentos, rechazo, nueva IP, autorización revocada, transacción revertida y persistencia del bloqueo hasta aprobación. Lint PHP, ESLint y compilación correctos.

DEV: imágenes reygom-api:dev-access-notifications-20261018 y reygom-crm:dev-access-notifications-20261018. Migración aditiva 20261018_access_notifications.sql. La dirección de ACCESS_TRUSTED_PROXIES se actualizó del antiguo 10.1.189.203 al único controlador ingress activo identificado en Kubernetes; ACCESS_REQUIRE_PROXY continúa en 1. Si ese controlador se recrea con otra IP, debe verificarse y actualizarse nuevamente.

Respaldo, plantillas anteriores y evidencias permanecen protegidos en /opt/reygom/releases/access-notifications-dev-20261018. Para revertir la aplicación, restaurar las plantillas anteriores; conservar notificaciones ya recibidas. No restaurar toda la base sobre datos nuevos. PROD no se modifica.
