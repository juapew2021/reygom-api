# Notificaciones internas — DEV · 16/10/2026

Ruta: https://crm-dev.reygom.com/notifications. Campana y contador visibles para todos los roles. Recibidas, enviadas, filtro de pendientes, lectura individual, marcar todas como leídas, redacción y selección de destinatarios. El contador se actualiza cada 30 segundos mientras el CRM está visible y después de leer/enviar. Pulsar la campana abre Recibidas.

Permisos aplicados en API y base de datos:

| Emisor | Destinatarios permitidos |
|---|---|
| Super admin | Todos los usuarios activos de oficinas activas, incluido él mismo; selección individual o envío general. |
| Administrador de oficina | Los agentes activos de su propia oficina; selección individual o todo ese grupo. |
| Agente | Él mismo y los agentes y administradores activos de su oficina; selección individual o todo su equipo. |

No se incluyen usuarios inactivos ni oficinas suspendidas. La sesión y el dispositivo/IP siguen sujetos a aprobación y a los controles existentes. Los destinatarios se vuelven a validar al enviar; campos falsificados de emisor, oficina o destinatario no amplían permisos. Cada persona solo puede leer y marcar su propia bandeja. El emisor ve un resumen agregado de entrega/lectura de sus propios envíos. Los mensajes entregados no pueden editarse ni borrarse desde la interfaz.

Mensajes en texto plano: asunto hasta 160 caracteres y cuerpo hasta 4000. No hay HTML ejecutable, adjuntos, correo, WhatsApp ni notificaciones del sistema operativo. Máximo 20 envíos por usuario/hora y 1000 destinatarios por envío/directorio. La clave de idempotencia evita reenvíos duplicados; un cambio de contenido con la misma clave se rechaza. Si hay un error de conexión, se puede reintentar el mismo borrador con su misma clave. «Vaciar borrador» inicia uno nuevo. Los mensajes solo se crean mediante acción explícita del usuario.

Auditoría del Super admin: evento «Notificación interna enviada», con actor y número de destinatarios; sin copiar asunto ni cuerpo al historial general. Tablas aditivas notification_batches e internal_notifications, con RLS, permisos de actualización limitados a read_at e índices de bandeja/pendientes.

Validación: lint, build y 26 pruebas frontend existentes correctos. 95 comprobaciones HTTP de los tres roles, envío general del Super admin, límites por oficina, destinatarios inactivos, suplantación, idempotencia, atomicidad, lectura personal, paginación, columnas inmutables, RLS, límite de envíos y auditoría. Todo en una base efímera separada reygom_notifications_qa_20261016, creada solo con el esquema de DEV y cuentas ficticias. No se copiaron registros reales ni se notificó a usuarios reales durante el QA. Comprobación adicional del predicado explícito de propiedad del lote bajo RLS correcta.

API DEV: reygom-api:dev-notifications-20261016. CRM DEV: reygom-crm:dev-notifications-20261016-r2. Respaldo DEV previo validado con pg_restore --list en /opt/reygom/releases/notifications-dev-20261016/dev-before-notifications.dump (solo root). Plantillas anteriores: rollback-templates.json en esa carpeta. Para reversión de aplicación, restaurar únicamente esas plantillas en namespace reygom-dev y conservar las tablas con los mensajes que los usuarios hayan creado. No restaurar toda la DB sobre nuevos datos sin revisión.

PROD no se migra ni se despliega. Correo y personalización de DEV se conservan. La promoción conjunta sigue pendiente de revisión y confirmación explícita.

Comprobación visual con la sesión real de Cristian: campana, recibidas, redacción y alcance limitado a sus agentes correctos. Su oficina no tiene agentes activos en el momento de la comprobación; el botón de envío queda deshabilitado sin destinatarios. No se enviaron pruebas a sus cuentas reales.
