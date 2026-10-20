# Correo ReyGom en DEV — 15/10/2026

Bandeja Hostinger de info@reygom.com vinculada exclusivamente a la oficina de ReyGom. Solo Super admin y administrador de esa oficina pueden consultar carpetas, leer, redactar y responder. Agentes y otras oficinas están bloqueados. El proveedor muestra alcance «Todos los buzones» pese a seleccionar solo info; el propietario ha autorizado expresamente usarlo en DEV. La aplicación fija un único resourceId y nunca admite otro desde el navegador. Resolver/renovar alcance antes de PROD; crear una credencial independiente para PROD.

Los mensajes se muestran como texto sin ejecutar HTML ni cargar imágenes remotas. Abrir un correo lo marca leído en Hostinger. Adjuntos: se informa de su presencia; su descarga/carga sigue en webmail en esta primera versión.

Verificación automática de anuncios activada en DEV: enlace de 24h, único uso, hash en DB, sin token accesible en bandeja de pruebas cuando modo=mail. Los anuncios siguen pendientes de revisión después de verificar. Límites de publicación existentes y máximo 50 envíos por oficina/hora. Clave de idempotencia evita duplicados. Entrega incierta queda unknown y no se reenvía automáticamente; comprobar Enviados antes de repetir manualmente. Sin campañas ni envíos a contactos existentes.

Credencial cifrada con sodium secretbox. Ciphertext y clave se inyectan desde Secret reygom-mail-dev; fuera de Git. Copia de custodia root-only en /opt/reygom/secrets/mail-dev. Kubernetes Secrets necesitan las protecciones de acceso/almacenamiento del clúster; base64 por sí solo no cifra.

## Validación
20 comprobaciones del servicio de correo con transporte simulado, sin emails reales. Incluyen aislamiento de oficina, RLS, validación de destinatarios/cabeceras, contenido HTML inerte, duplicados y entrega incierta. Pruebas HTTP de agente y administrador ajeno bloqueados. Cuenta, carpetas, entrada y lectura del correo de prueba comprobados contra Hostinger. Prueba real previa: exactamente un correo a sí mismo, recibido. Regresión de oficinas 65, historial 75, accesos 82; validación de anuncios, catálogos y persistencia de país. Frontend: lint, 20 tests y build correctos.

## Despliegue y reversión DEV
API reygom-api:dev-mail-20261015-r3; CRM reygom-crm:dev-mail-20261015. Migración aditiva database/migrations/20261015_mail.sql (historial de entregas con RLS y auditoría sin asunto, cuerpo ni destinatarios). Rollback: restaurar spec/template desde /opt/reygom/releases/mail-dev-20261015/mail-dev-rollback.json en namespace reygom-dev. Conservar tabla de entregas para evitar duplicados y mantener auditoría. No borrar Secret antes de desactivar correo.

## Promoción pendiente a PROD
No realizada ni autorizada todavía. PROD sigue con API v11-20260917073554 y CRM v0.5.0-e4b50d6. La diferencia incluye mejoras anteriores: crawler y habitaciones/localización, anuncios verificados y revisión, países ES/CO, oficinas/roles y aislamiento, historial, autorización dispositivo/IP, y ahora correo. No basta desplegar únicamente la imagen de correo.

Antes de promover: revisar diff integral de CRM/API/scraper desde main, inventariar migraciones pendientes y resolver datos/oficinas de PROD con confirmación de asignación; copia verificada de DB/media y manifestos; credencial de correo propia de PROD y decisión sobre qué entorno envía mensajes reales; verificar ingress/IP de confianza de PROD; ejecutar QA candidato aislado; preparar imágenes reproducibles y plan de rollback de aplicación y datos; obtener confirmación explícita final. No compartir Secret DEV con PROD. Nunca apuntar DEV a DB o medios PROD.
