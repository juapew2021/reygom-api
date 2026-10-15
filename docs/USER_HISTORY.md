# Historial de usuarios — DEV

15 de octubre de 2026. Ampliación del CRM multiempresa, exclusivamente en DEV.

## Uso

El Super admin dispone de «Historial de usuarios» y de «Ver historial» junto a cada cuenta en «Oficinas y equipo». Puede consultar todas las inmobiliarias y filtrar por usuario, oficina, acción y rango de fechas. El filtro de usuario reúne sus propias acciones y los cambios que otros administradores hagan sobre esa cuenta. La tabla está paginada, con 50 eventos por página.

Se registran accesos correctos, intentos de acceso rechazados y cierres de sesión; altas, cambios y bajas de oficinas, usuarios, inmuebles, contactos, fotografías y anuncios recibidos. Cada evento conserva fecha, cuenta que actúa, oficina, referencia del recurso y campos modificados. En cambios de perfil, activación, estado y precio se muestran los valores anteriores y posteriores disponibles. Los nombres de cuentas y oficinas se conservan en el evento para mantener el contexto si se retiran posteriormente.

Un intento de acceso rechazado se vincula a la cuenta afectada cuando existe, pero no se atribuye como una acción autenticada de su titular. No se registran como cambios las simples consultas de fichas. No se reconstruyen eventos históricos que nunca se registraron: se mantienen los registros anteriores que existan y se indica cuándo comienza el registro ampliado.

## Acceso y privacidad

Solo Super admin puede consultar la vista y sus filtros globales. Administradores de oficina y agentes reciben rechazo también al intentar acceder directamente a la API. El historial es de solo lectura en el CRM. Los cambios quedan vinculados a la identidad comprobada por el servidor y se registran en la misma transacción que modifica el dato; un cambio revertido no deja un evento de modificación confirmado.

Se registran campos modificados, no copias de contraseñas, hashes de contraseña, tokens, enlaces privados de verificación, datos de contacto o descripciones. El historial no graba la pantalla ni actividad fuera del CRM.

El cierre de sesión invalida el token utilizado en el servidor. Cada nuevo acceso genera un identificador de sesión distinto; cerrar una sesión no impide iniciar otra posteriormente. La desactivación de usuarios y las restricciones de oficinas implantadas en la entrega anterior se mantienen.

La base de datos bloquea modificaciones o borrados del historial en el flujo normal. La retirada de registros exclusivamente sintéticos de QA requiere un contexto explícito de mantenimiento del propietario de la base de datos. Ese mecanismo no se expone en el CRM.

## Fuentes

Ramas aisladas `feature/user-history`, en `/opt/reygom/releases/history-dev-20261015/api` y `/opt/reygom/releases/history-dev-20261015/crm`. Copia de la base de datos y manifiestos anteriores conservada en el servidor con permisos restringidos. No se modifica PROD ni se envían correos.

## Validación completada

- 75 comprobaciones HTTP del historial: atribución, usuario que actúa y usuario afectado, cambios de precio/perfil/activación, privacidad de valores sensibles, filtros, fechas, paginación, cuentas retiradas, protección frente a borrados, transacciones revertidas y cierre de sesión con token revocado.
- 65 comprobaciones HTTP de separación entre oficinas: superadas con la ampliación activa.
- 20 pruebas existentes del CRM, comprobaciones de estilo y compilación: superadas. Regresiones de publicaciones, catálogo y persistencia: superadas.
- Se corrigió un caso detectado por QA: el administrador puede seguir gestionando un inmueble cuyo agente fue desactivado. El agente continúa bloqueado y siguen rechazándose asignaciones nuevas a agentes inactivos o de otra oficina.
- Interfaz desplegada: acceso desde «Ver historial» de una cuenta, filtros de usuario/acción/fecha y limpieza repetida de filtros comprobados. El registro ampliado muestra su inicio; los datos sintéticos de QA se retiraron por sus identificadores exactos.

Versión DEV: `0.5.0-dev-history-20261015`. API: `reygom-api:dev-history-20261015-r3`. CRM: `reygom-crm:dev-history-20261015`. Se conservan las imágenes del crawler y de la base de datos.
