# Personalización de inmobiliarias y perfiles — DEV · 16/10/2026

El administrador de oficina y el Super admin pueden personalizar el nombre comercial, logo, color principal y fondo del menú de la oficina activa en `/personalization`. Todos los integrantes de esa oficina ven la misma marca. Los agentes no pueden modificarla. La marca se aplica al menú, acciones principales e iconos del dashboard. Los avisos de error conservan sus colores semánticos.

Cada usuario, incluidos los agentes, puede subir su propia foto en `/profile`; aparece en el perfil y en el menú. No se aceptan identificadores de otro usuario para cambiar su foto. Se puede sustituir la imagen subiendo una nueva.

Logos y fotos: JPG, PNG y WebP; hasta 2 MB y 8 megapíxeles. El servidor decodifica y vuelve a generar WebP sin metadatos. Logos de hasta 512×256 conservando proporciones; fotos recortadas al centro y reducidas a 256×256. Se rechazan SVG, imágenes inválidas y archivos demasiado grandes; el nombre de archivo nunca se usa para ejecutar ni servir contenido. Las imágenes normalizadas se guardan en DB y solo se consultan con sesión y permisos. No se crean enlaces públicos ni se reutilizan fotos personales de prueba en cuentas reales.

El texto de botones y navegación se adapta al contraste. Los colores solo admiten formato hexadecimal. La vista previa no guarda cambios; nombre/colores y logo tienen botones de guardado independientes. El cambio de marca no cambia el nombre jurídico ni los permisos de la oficina. Una edición de nombre/colores desactualizada devuelve conflicto, evitando sobrescribir cambios de otro administrador. Las actualizaciones se registran en el historial sin guardar imágenes en auditoría.

Validación: 26 tests frontend (incluyen contraste sobre 4096 colores), lint y build correctos; 20 tests del servicio y RLS; 43 comprobaciones HTTP con dos oficinas, administradores y agentes, subidas multipart reales, identidades/oficinas falsificadas, SVG encubierto y archivo de más de 2 MB. Todos pasaron. Datos sintéticos eliminados por UUID exactos al terminar. Sin nuevos correos de prueba.

Copia de DB DEV verificada mediante pg_restore --list antes de la migración aditiva `database/migrations/20261016_branding.sql`. No se modifican inmuebles ni contactos. Tablas nuevas: office_branding y user_profiles, con RLS y auditoría. El respaldo está en `/opt/reygom/releases/branding-dev-20261016/dev-before-branding.dump`, acceso root. Los manifiestos previos y rollback-templates.json están en la misma carpeta, acceso root.

Imágenes DEV: API `reygom-api:dev-branding-20261016`; CRM `reygom-crm:dev-branding-20261016-r4`. Para rollback de aplicación: restaurar las plantillas guardadas exclusivamente en namespace reygom-dev. Mantener las tablas nuevas y sus datos para no perder las imágenes que los usuarios hayan subido. No restaurar el dump completo sobre datos nuevos sin revisión.

PROD no se despliega ni se migra. El servicio de correo existente y la verificación de anuncios deben conservarse activados en DEV; el pod de QA aislado tiene correo desactivado y no recibe las credenciales del buzón.

Comprobación visual con la sesión real del administrador de Inmobiliaria Cristian: formularios, vista previa azul sobre menú claro y foto de perfil correctos. Los colores de ejemplo no se guardaron en la oficina real. Se incluyen etiquetas legibles de los eventos de marca y foto en el historial del Super admin.
