# Seguridad y auditoría

Este documento resume el modelo de amenazas de Chat Manager, los controles de seguridad por área, el modelo de permisos, la
bitácora de auditoría, las reglas de privacidad, los defectos corregidos en la auditoría de seguridad de la etapa de
finalización y los riesgos residuales. Los detalles de cada módulo están en su propio documento:
[bandejas](bandejas-y-asignacion.md), [mensajería WhatsApp](mensajeria-whatsapp.md), [chat interno](chat-interno.md),
[tiempo real](tiempo-real.md), [notificaciones](notificaciones-y-horarios.md), [reportes](reportes-y-exportaciones.md),
[migración](migracion-whaticket.md), [infraestructura](infraestructura-y-despliegue.md) y
[respaldos](recuperacion-y-respaldos.md).

> La integración con WhatsApp Cloud API **no se ha probado contra Meta real**. Todo lo relativo a webhooks y envíos se
> verificó con el transporte simulado (`WHATSAPP_TRANSPORT=simulated`), webhooks firmados de prueba y respuestas HTTP
> falsas en las pruebas automatizadas.

## 1. Modelo de amenazas (resumen)

| Activo                                                 | Amenaza principal                                                      | Actor                                       |
| ------------------------------------------------------ | ---------------------------------------------------------------------- | ------------------------------------------- |
| Conversaciones, mensajes y adjuntos de clientes        | Lectura horizontal entre agentes (IDOR), enumeración por ids o conteos | Empleado autenticado con permisos limitados |
| Chat interno entre empleados                           | Vigilancia invisible, acceso del Superadministrador por su rol         | Administradores                             |
| Credenciales de Meta (token, app secret, verify token) | Exposición en props, logs, URLs, exportaciones o excepciones           | Cualquier usuario, atacante con logs        |
| Webhook público de WhatsApp                            | Eventos falsificados, repetidos o masivos                              | Atacante en internet                        |
| Cuentas de usuario                                     | Fuerza bruta, enumeración de correos, escalamiento de privilegios      | Atacante externo, administrador delegado    |
| Archivos subidos                                       | Ejecución de contenido (HTML/SVG/scripts), path traversal, MIME falso  | Cliente de WhatsApp, empleado               |
| Bitácora de auditoría                                  | Borrado o lectura de entradas restringidas                             | Administradores                             |
| Canales en tiempo real                                 | Suscripción a canales ajenos, contenido sensible en eventos            | Empleado autenticado                        |

Supuestos: el servidor y la base de datos son de confianza; TLS termina en Nginx del mismo servidor; los empleados se
autentican con contraseña y, opcionalmente, verificación en dos pasos (las passkeys están retiradas deliberadamente).

## 2. Controles por área

### Autenticación y sesión

- Fortify con limitador `login` (5 intentos por minuto por correo e IP) y `two-factor` (5 por minuto por sesión de
  inicio). Cada bloqueo se audita (`auth.lockout`, una entrada por correo e IP y minuto, sin contraseña).
- El formulario «¿Olvidaste tu contraseña?» responde exactamente igual para correos con y sin cuenta, y para un enlace
  pedido hace un momento (mensaje neutral). El broker limita un enlace por usuario cada 60 s y, además, `POST
forgot-password` y `POST reset-password` aceptan 5 solicitudes por minuto por IP en cada ruta (limitador
  `password-reset`, que `FortifyServiceProvider` agrega a las rutas de Fortify y queda incluido en `route:cache`).
- Cambiar la contraseña cierra las demás sesiones de la cuenta: el cambio propio (Configuración > Seguridad, con la
  contraseña actual) conserva solo la sesión en curso; el restablecimiento por un administrador y el restablecimiento por
  enlace cierran todas y anulan el token «recordarme». Con `SESSION_DRIVER=database` se borran las filas de `sessions`
  (`User::closeSessions`); con cualquier driver, la cookie «recordarme» de otros equipos deja de valer porque lleva un hash
  de la contraseña. Con otro driver (`redis`, `file`) las sesiones abiertas sin «recordarme» no se cierran, porque no se
  usa `AuthenticateSession`. La entrada de auditoría guarda `sessions_closed`.
- Desde Administración > Usuarios nadie cambia **su propia** contraseña ni su propio correo (422 con un mensaje que remite
  a Configuración): así se exige la contraseña actual y el correo nuevo se vuelve a verificar. El formulario muestra el
  correo propio de solo lectura y un enlace a Configuración > Seguridad.
- Cuentas desactivadas (baja lógica) no inician sesión y `EnsureUserIsActive` cierra cualquier sesión abierta en la
  siguiente petición (también en `broadcasting/auth`).
- Cookies de sesión `HttpOnly`, `SameSite=lax` y `Secure` automáticamente cuando `APP_URL` es https
  (`SESSION_SECURE_COOKIE`). El preflight (`php artisan app:preflight`) falla en producción con `APP_DEBUG=true`, sin https o
  con cookies no seguras.
- El cierre de sesión pide confirmación en la interfaz y es POST con CSRF. Además limpia el historial que guarda Inertia
  (`router.clearHistory()` en el cliente y `Inertia::clearHistory()` en `App\Http\Responses\LogoutResponse`), y las props
  guardadas en el historial del navegador se cifran (`INERTIA_ENCRYPT_HISTORY=true` por defecto, `config/inertia.php`):
  tras cerrar sesión, «Atrás» no muestra datos de la sesión cerrada. El cifrado necesita un contexto seguro (https o
  localhost); por http plano el navegador guarda el historial sin cifrar y solo deja un aviso en la consola.
- Se auditan el cambio de contraseña propio, el restablecimiento por enlace, la activación (confirmada) y desactivación de
  la verificación en dos pasos y la regeneración de códigos de recuperación.

### Autorización (páginas, APIs, mensajes, adjuntos, exportaciones, trabajos)

- Todas las rutas de la aplicación exigen sesión, salvo `/`, `/up`, `/health/ready` (solo `{"status":"ok|fail"}`) y el
  webhook de WhatsApp (autenticado por firma). Lo garantiza la prueba de barrido
  `tests/Feature/Security/RouteAuthorizationSweepTest.php`, que recorre **todas** las rutas registradas: un invitado
  recibe 401/redirección, un usuario sin permisos recibe solo 403/404 en todas, y un agente de otro departamento recibe
  403/404 en todas las rutas con ids anidados (conversación, mensaje, adjunto, nota, hilo, importación, contacto). Una ruta
  nueva queda cubierta automáticamente; una ruta de autoservicio debe declararse explícitamente en la prueba.
- Las políticas devuelven `denyAsNotFound()` para registros fuera de alcance: un registro ajeno es indistinguible de uno
  inexistente, también en JSON (`{"message":"Not Found"}` sin modelo ni id). La regla es la misma en todos los módulos:
  sin el permiso del módulo, 403; con el permiso, un registro fuera del alcance de datos (o eliminado, para quien no tiene
  `records.view-deleted`) responde 404 igual que un id inexistente; uno visible sobre el que no se puede actuar (un par o
  un superior, una respuesta rápida global para un gestor departamental) responde 403. Departamentos, usuarios y
  respuestas rápidas siguen esta regla también en sus form requests (`Gate::inspect`), que antes respondían 403 siempre.
- Descargar una exportación ya generada vuelve a comprobar, con los permisos actuales del dueño, el permiso de exportación
  de **ese** reporte (`reports.export` o `audit.export`), el del módulo y, en reportes de un registro, el registro
  (`ReportExporter::allowsStored`, el mismo criterio que usa el trabajo en cola). Perder cualquiera de ellos bloquea la
  descarga (403); de otro usuario, 404.
- Las operaciones de conversación comprueban la visibilidad **antes** de validar la entrada (ver hallazgo H-1) y el motor
  vuelve a autorizar bajo bloqueo con datos frescos.
- Ids anidados: los adjuntos comprueban que conversación, mensaje y adjunto se correspondan; el chat interno usa
  `scopeBindings()`; las notas comprueban su conversación.
- Trabajos asíncronos y exportaciones reaplican el alcance del usuario (`ConversationAccess::restrictToVisible`,
  `AnalyticsScope`); las notificaciones vuelven a comprobar el acceso al leerse.
- Tiempo real: canales privados autorizados en cada suscripción con el usuario recargado de la base de datos
  (`RealtimeAudience::freshUser`), eventos solo con ids; al cambiar permisos o membresías se emite `.access.changed` y el
  cliente vuelve a autorizarse.

### Protección contra ataques web

- **CSRF:** todas las rutas que cambian estado son POST/PUT/PATCH/DELETE dentro del grupo `web` con
  `ValidateCsrfToken`; no hay excepciones. El webhook está fuera del grupo `web` (sin sesión ni cookies). Generar una
  exportación es `POST exports/{report}/{format}`: el menú «Exportar» (`ExportMenu.vue`) envía un formulario nativo
  oculto con `_token` (prop compartida `csrfToken`) y los filtros, así el navegador sigue descargando el archivo o vuelve
  a la página con el aviso de que se prepara en segundo plano. Solo la descarga de un archivo ya generado
  (`exports/transfers/{transfer}/download`) es GET.
- **XSS:** ningún contenido de usuario se renderiza con `v-html` (el único `v-html` es el SVG del QR de 2FA, generado por
  el servidor). `WhatsAppText.vue` usa interpolación y solo enlaza `http(s)`; los enlaces de procedencia pasan por
  `safeUrl`. Las vistas Blade no usan `{!! !!}`. La cookie `appearance` (sin cifrar) solo se acepta si vale
  `light|dark|system` (hallazgo H-3).
- **CSP y cabeceras:** `SecurityHeaders` agrega `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`,
  `Referrer-Policy`, `Permissions-Policy` y `Cross-Origin-Opener-Policy` a todas las respuestas; en producción agrega CSP
  con nonce (scripts solo propios y con nonce, `object-src 'none'`, `frame-ancestors 'self'`, `form-action 'self'`) y HSTS
  sobre https.
- **Inyección SQL:** consultas con Eloquent o enlaces (`?`); los fragmentos `selectRaw`/`DB::raw` revisados solo
  interpolan nombres de columna constantes del código.
- **Asignación masiva:** modelos con `#[Fillable]` explícito; campos de control (`is_active`, `assigned_user_id`, ids de
  propietario, estados) se escriben con `forceFill` desde acciones. Hay pruebas en `tests/Feature/Validation`.
- **Fórmulas en hojas de cálculo:** el renderizador XLSX neutraliza celdas que empiezan con `= + - @`, tabulador o retorno.
- **Redirecciones abiertas:** las redirecciones usan rutas con nombre o `back()`; no hay parámetros `redirect`/`next`.

### Límites de frecuencia

| Superficie                                                                     | Límite                                            |
| ------------------------------------------------------------------------------ | ------------------------------------------------- |
| Inicio de sesión / 2FA                                                         | 5/min por correo+IP / por sesión                  |
| Cambio de contraseña                                                           | 6/min                                             |
| Recuperación de contraseña (`forgot-password`, `reset-password`)               | 5/min por IP y ruta, más 1 enlace/60 s por cuenta |
| Webhook WhatsApp                                                               | `WHATSAPP_WEBHOOK_RATE_LIMIT` (1200/min) por URL  |
| Envío de mensajes / plantillas / reintentos                                    | 60/min, 30/min, 30/min                            |
| Lectura de línea de tiempo y marcadores de lectura                             | 120/min                                           |
| Exportaciones, importaciones, reversión de importación                         | 10/min                                            |
| Verificar conexión, suscripción de app, sincronizar plantillas (llaman a Meta) | 10/min (hallazgo H-6)                             |
| `broadcasting/auth`                                                            | 180/min                                           |
| Sondeo `/health/ready`                                                         | 60/min por IP y veredicto en caché 10 s           |

Cada `throttle:N,M` cuenta por usuario (o IP) **y ruta** (`App\Http\Middleware\ThrottleRequestsPerRoute`, registrado como
alias `throttle`): el sondeo de la campana o las autorizaciones de canales ya no agotan el límite de exportaciones o
importaciones. Los limitadores con nombre (`login`, `two-factor`, `password-reset`, `whatsapp-webhooks`) conservan sus
propias claves.

### Webhook de WhatsApp

- URL con clave aleatoria (`webhook_key`, 48 caracteres), nunca el id de la conexión; conexión desconocida o eliminada →
  404 idéntico.
- POST autenticado con `X-Hub-Signature-256` (HMAC-SHA256 del cuerpo crudo con el app secret) comparado en tiempo
  constante; sin app secret se rechaza. GET de verificación compara el verify token en tiempo constante y filtra el
  `hub.challenge` (solo `[A-Za-z0-9_.-]`, máx. 256).
- Tamaño máximo `WHATSAPP_WEBHOOK_MAX_BYTES` (3 MB) además de `client_max_body_size` de Nginx; respuesta inmediata y
  procesamiento en la cola `webhooks`.
- Repetición: almacenamiento idempotente por hash del cuerpo, mensajes únicos por `wamid` y estados que solo avanzan.
  Meta no firma una marca de tiempo, así que la protección contra repetición es la idempotencia, también cuando el
  registro del webhook ya se purgó (30/90 días):
    - las actualizaciones de estado de plantillas usan la hora de la entrada (`entry.time`): una más antigua que el estado
      registrado (`status_updated_at`) se ignora, así que una entrega tardía o repetida no devuelve una plantilla a un
      estado anterior; una plantilla que Meta vuelve a informar con un estado distinto de `DELETED` deja de estar eliminada,
      igual que en la sincronización;
    - los errores de conexión y las señales del proveedor cuya entrada tiene más de `WHATSAPP_WEBHOOK_MAX_AGE_DAYS` (7, la
      ventana de reintentos de Meta) no se aplican de nuevo a la salud de la conexión.
      Las actualizaciones de calidad de plantillas no tienen marca propia y se aplican tal como llegan.
- Los rechazos no explican el motivo y los registros solo llevan el id de la conexión.

### Archivos y multimedia

- Todos los archivos privados (multimedia de clientes, adjuntos del chat interno, importaciones, exportaciones) viven en
  discos privados (`storage/app/private` o un disco S3 privado) con nombres UUID; nunca en el disco `public`.
- La ruta firmada `storage/{path}` de Laravel está desactivada (`'serve' => false`, hallazgo H-4): solo se sirven por
  controladores que autorizan cada descarga.
- Tipo real con `finfo` (nunca el nombre ni el tipo del navegador); lista blanca de tipos y extensiones; límites por tipo
  en `config/whatsapp.php` y `config/team-chat.php`. HTML, SVG, scripts y ejecutables se rechazan.
- Nombres mostrados saneados (sin rutas, caracteres de control ni reservados). Las descargas llevan
  `Content-Disposition`, `nosniff`, `Referrer-Policy: no-referrer`, `Cache-Control: private` y CSP `sandbox` (excepto PDF en
  vista previa, ver riesgos). Los tipos no previsualizables se sirven como `application/octet-stream` adjunto.
- La descarga de multimedia entrante solo acepta hosts de Meta.

### Secretos

- `access_token`, `app_secret` y `webhook_verify_token` se cifran en reposo (cast `encrypted`, `APP_KEY`), están en
  `#[Hidden]`, nunca se reflejan como entrada antigua (`dontFlash`) y la bitácora elimina claves sensibles.
- Los recursos solo informan si el secreto existe (`true/false`). La prueba «connection secrets never reach any page…»
  comprueba que no aparecen en páginas de conexiones, plantillas, sistema, tablero ni en el JSON de salud.
- `MetaGraphClient` borra `access_token=` de los mensajes de error; `SensitiveText` enmascara tokens, correos y teléfonos en
  los errores recientes de la página de sistema.

### Producción

`APP_ENV=production`, `APP_DEBUG=false`, https obligatorio, cookies seguras, `WHATSAPP_TRANSPORT=meta`, workers
supervisados y discos privados. El preflight verifica todo esto sin escribir datos
([infraestructura](infraestructura-y-despliegue.md)).

## 3. Modelo de permisos

- **Permisos dinámicos** (`App\Enums\Permission`) agrupados por módulo y asignados a roles; cada rol tiene un **alcance de
  datos**: `global` (toda la organización), `departments` (sus departamentos) o `assigned` (solo lo asignado).
- **Jerarquía:** Superadministrador (rol raíz) > Administrador > Supervisor > Agente. Nadie puede otorgar permisos ni
  alcance que no tiene, asignar un rol superior al propio ni editar su propio rol; un Administrador no puede convertirse
  en Superadministrador con peticiones manipuladas (`tests/Feature/Admin/PrivilegeEscalationTest.php`).
- **Sin bypass global:** no existe `Gate::before`; el Superadministrador pasa por las mismas políticas. Sus facultades
  especiales (importador, bitácora restringida) están en permisos y políticas explícitas, y sus acciones se auditan como
  restringidas.
- Permisos que exigen alcance global (`Permission::requiresGlobalScope()`): crear/eliminar departamentos, roles,
  configuración y apariencia, conexiones, administración de plantillas y etiquetas, clasificación de procedencia,
  importación de contactos y sistema y operación. Algunas capacidades están reservadas al rol raíz.
- **Conversaciones** (`ConversationAccess`): un agente ordinario solo ve sus asignadas y los pendientes de colas de sus
  departamentos que puede aceptar; nunca las que ya atiende otro agente del mismo departamento. Supervisores ven su
  departamento; alcance global ve todo. La regla se aplica en listados, contadores, búsquedas, detalle, línea de tiempo,
  mensajes, adjuntos, notas, etiquetas, canales en tiempo real, exportaciones, reportes y notificaciones.
- **Chat interno:** solo participantes actuales; nadie más, tampoco el Superadministrador.
- Cambios de permisos y membresías surten efecto en la siguiente petición de las sesiones abiertas (pruebas en
  `tests/Feature/Auth/PermissionRevocationTest.php` y `tests/Feature/Validation/SecurityProbeTest.php`).

### 3.1 Permisos por rol tras la actualización de la estabilización

`app:sync-permissions` nunca modifica roles existentes. Para poner al día los roles por defecto con su definición
actual (`App\Enums\DefaultRole`) existe una actualización explícita, aditiva y auditada:

```bash
php artisan app:sync-permissions --upgrade-default-roles             # vista previa: no escribe nada
php artisan app:sync-permissions --upgrade-default-roles --apply     # aplica; cada rol cambiado → role.permissions_changed
#   --demo-b              también el rol local «Demo B · Agente» (definición del Agente)
#   --include-sensitive   también los permisos sensibles de la definición (system.view, system.manage)
#   --actor=<correo>      Superadministrador activo que figura como autor (por defecto el primero)
```

Solo considera los roles no raíz `administrator`, `supervisor` y `agent` (y el demo con `--demo-b`); nunca concede
permisos reservados a la raíz (`contacts.import`) ni permisos globales a roles de alcance Departamentos o Asignado;
solo quita lo que el rol nunca podría ejercer y conserva los permisos extra. **No recuerda las retiradas manuales**:
vuelve a añadir un permiso de la definición que un administrador quitó a propósito, por eso hay que revisar la vista
previa antes de `--apply`. Una segunda ejecución no escribe nada.

En la base local `chatmanager` el coordinador ejecutó el 2026-10-22 `app:sync-permissions` (catálogo completo, 55
permisos) y `app:sync-permissions --upgrade-default-roles --demo-b --apply`, con este resultado (comprobado después con
una consulta de solo lectura):

| Rol (alcance)                                | Añadido en la actualización                                                                                                                                                                                                   | Permisos tras la actualización                                                                                                                                                                                                                                                        |
| -------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Superadministrador (`admin`, raíz, global)   | — (siempre tiene todo el catálogo)                                                                                                                                                                                            | Los 55, incluidos `contacts.import`, `system.view` y `system.manage`                                                                                                                                                                                                                  |
| Administrador (`administrator`, global)      | `templates.view`, `templates.manage`                                                                                                                                                                                          | 52: todo salvo `contacts.import` (reservado a la raíz), `system.view` y `system.manage` (sensibles, **no concedidos**: decisión pendiente)                                                                                                                                            |
| Supervisor (`supervisor`, sus departamentos) | `conversations.reopen`, `conversations.accept`, `conversations.notes`, `contacts.view`, `tags.view`, `tags.assign`, `team-chat.use`, `team-chat.create-groups`, `team-chat.manage-groups`, `team-chat.cross-department` (+10) | 21: `conversations.{view,reply,accept,assign,transfer,close,reopen,history,notes}`, `contacts.view`, `dashboard.view`, `departments.{view,manage-members}`, `quick-replies.use`, `reports.view`, `tags.{view,assign}`, `team-chat.{use,create-groups,manage-groups,cross-department}` |
| Agente (`agent`, solo lo asignado)           | `conversations.accept`, `conversations.transfer`, `conversations.notes`, `contacts.view`, `tags.view`, `tags.assign`, `reports.view`, `team-chat.use` (+8)                                                                    | 14: `conversations.{view,reply,accept,transfer,close,history,notes}`, `contacts.view`, `dashboard.view`, `quick-replies.use`, `reports.view` (solo sus cifras), `tags.{view,assign}` (solo sus conversaciones), `team-chat.use`                                                       |
| Demo B · Agente (`demo-b-agente`, asignado)  | `tags.view`, `tags.assign`, `reports.view`, `team-chat.use`                                                                                                                                                                   | Los mismos 14 que el Agente                                                                                                                                                                                                                                                           |

Ningún rol no raíz tiene `contacts.import` ni permisos globales fuera del alcance global. El Agente no recibe
`conversations.assign`, `tags.manage` ni `contacts.import`: solo transfiere a la cola o a quien esté Disponible y con
capacidad.

**Decisión pendiente del usuario — `system.view` / `system.manage` para el Administrador.** Ambos son
`Permission::isSensitive()`: dan acceso a Administración → Sistema (estado de colas, trabajos fallidos, salud, alertas)
y, con `system.manage`, a reintentar o descartar trabajos. Hoy solo los tiene el Superadministrador. Si se decide
concederlos: `php artisan app:sync-permissions --upgrade-default-roles --include-sensitive` (vista previa) y después
con `--apply`, o asignarlos a mano en Roles; ambos caminos quedan auditados (`role.permissions_changed`). Mientras no
se decida, el Administrador no ve la página de Sistema ni recibe las alertas dirigidas a `system.view`.

## 4. Auditoría

- `App\Services\AuditLogger` escribe en `audit_logs` (solo inserción; no hay ruta para editar ni borrar entradas). Guarda
  actor, acción, sujeto, propiedades saneadas (sin claves `password`, `token`, `secret`, `recovery_code`, `api_key`,
  `credential`), IP y agente de usuario.
- Se auditan: usuarios, roles y permisos, departamentos y membresías, conexiones y credenciales (sin valores),
  suscripción de app, contactos, etiquetas, respuestas rápidas, configuración y horarios, operaciones de conversación,
  exportaciones, importaciones y su reversión, administración de grupos del chat interno, reintento/descartado de
  trabajos fallidos y, desde esta auditoría, eventos de seguridad de cuenta (`auth.*`).
- **Visibilidad restringida:** ver la bitácora requiere `audit.view`; las entradas `restricted` (acciones del rol raíz o
  sobre él, y todo el importador) solo las ve el rol raíz. Exportarla requiere `audit.export`.
- Los valores de `App\Enums\AuditAction` son permanentes: quitar un caso rompe la lectura de las filas que lo llevan. En
  la estabilización se quitaron los casos que nada emitía y que no aparecían en la bitácora (consulta de solo lectura):
  activación y desactivación de roles, etiquetas y respuestas rápidas, activación de departamentos, archivado de
  contactos y cambio de conexiones de un departamento (este se audita como `connection.updated`). Se conservan
  `user.activated`, `user.deactivated` y `department.deactivated` porque hay entradas históricas con esos valores.
- El contenido de mensajes privados del chat interno no se audita ni se expone a administradores.

## 5. Reglas de privacidad

- Ningún mecanismo permite leer chats internos ajenos ni conversaciones fuera del alcance por el solo hecho de ser
  Superadministrador. No existe vigilancia invisible: no hay pantallas, reportes ni exportaciones del contenido del chat
  interno.
- Los eventos en tiempo real y las notificaciones almacenadas llevan solo ids; el texto se construye al leer, con acceso
  revalidado.
- Baja lógica en todo el sistema (`DeletesLogically`): usuarios, conexiones, departamentos, contactos, etiquetas,
  plantillas y mensajes internos se marcan inactivos o eliminados; el historial se conserva y los registros eliminados
  solo los ve quien tiene `records.view-deleted`.
- Los registros de aplicación no contienen cuerpos de mensajes, teléfonos completos ni secretos.

## 6. Hallazgos corregidos

### En la auditoría de la etapa de finalización

| Id  | Síntoma                                                                                                                                                 | Causa                                                                                                        | Corrección                                                                                                       | Prueba                                                                      |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| H-1 | Con un cuerpo inválido, 9 operaciones sobre una conversación ajena respondían 422 en vez de 404, lo que permitía saber qué ids de conversación existen. | Los form requests de conversación autorizaban siempre (`return true`) y validaban antes del `ensureVisible`. | Trait `App\Concerns\AuthorizesVisibleConversation`: los requests deniegan como no encontrado antes de validar.   | `SecurityHardeningTest` (dataset de 9 rutas + edición de nota) y el barrido |
| H-2 | Cambiar el estado de una conexión sin permiso respondía 422 antes de 403.                                                                               | `UpdateStatusRequest` validaba antes de la política.                                                         | El request comprueba `changeStatus` antes de validar.                                                            | `SecurityHardeningTest`                                                     |
| H-3 | La cookie `appearance` (sin cifrar) se insertaba sin validar en un script en línea de la vista raíz.                                                    | `HandleAppearance` compartía cualquier valor.                                                                | Solo se aceptan `light`, `dark`, `system`; lo demás se trata como `system`.                                      | `SecurityHardeningTest` (4 casos)                                           |
| H-4 | El disco privado `local` publicaba las rutas firmadas `storage/{path}` (GET y PUT) que sirven y reciben cualquier archivo privado solo con firma.       | `'serve' => true` heredado de la plantilla de Laravel.                                                       | `'serve' => false`; los archivos solo se sirven por controladores autorizados.                                   | `SecurityHardeningTest`                                                     |
| H-5 | El formulario de recuperación revelaba si un correo tiene cuenta («No encontramos un usuario…» / «Espera…»).                                            | Respuesta de error por defecto de Fortify.                                                                   | `App\Http\Responses\FailedPasswordResetLinkResponse` y mensaje neutral en `lang/es/passwords.php`.               | `SecurityHardeningTest`                                                     |
| H-6 | Las rutas que llaman a Meta (verificar conexión, consultar/crear suscripción, sincronizar plantillas) no tenían límite.                                 | Faltaba `throttle`.                                                                                          | `throttle:10,1` en las cuatro rutas.                                                                             | Barrido de rutas (sin regresiones) y pruebas de conexiones y plantillas     |
| H-7 | No se auditaban eventos de seguridad de cuenta y el bloqueo por fuerza bruta no generaba ningún evento.                                                 | Faltaba un listener; el limitador con nombre de Fortify sustituye al que dispara `Lockout`.                  | `App\Listeners\Security\AuditSecurityEvents`, nuevas acciones `auth.*` y el limitador `login` dispara `Lockout`. | `SecurityHardeningTest` (6 casos)                                           |

### En la estabilización

| Id     | Síntoma                                                                                                                           | Corrección                                                                                                                                                                                    | Prueba                                                                                                             |
| ------ | --------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| SEC-02 | Desde `/admin/users/{yo}` se cambiaba la propia contraseña sin dar la actual y el correo nuevo quedaba verificado.                | `UpdateUserRequest`: para la propia cuenta `password` es `prohibited` y el correo debe ser el actual; la interfaz remite a Configuración.                                                     | `UserManagementTest` («one's own password and e-mail…»)                                                            |
| SEC-03 | Restablecer la contraseña (administración, enlace o cambio propio) no cerraba las sesiones abiertas.                              | `User::closeSessions()` en `UpdateUser`, `ResetUserPassword` y `SecurityController` (este conserva la sesión actual); se audita el número.                                                    | `UserManagementTest`, `PasswordResetTest`, `Settings/SecurityTest`                                                 |
| SEC-04 | Las exportaciones se generaban con GET (navegación de otro sitio podía iniciarlas).                                               | `POST exports/{report}/{format}` con CSRF; formulario nativo en `ExportMenu.vue`; GET responde 405.                                                                                           | `ReportExportTest` (405, CSRF 419/200 con el token compartido), `SecurityProbeTest`                                |
| SEC-05 | `forgot-password` y `reset-password` sin límite por IP.                                                                           | Limitador `password-reset` (5/min por IP y ruta).                                                                                                                                             | `RouteThrottleTest`                                                                                                |
| SEC-07 | Una actualización de plantilla tardía o repetida tras la purga aplicaba un estado obsoleto; errores antiguos degradaban la salud. | Orden por `entry.time` en `RecordTemplateUpdate`, limpieza de `deleted_at`, y `whatsapp.webhooks.max_age_days` para errores y señales.                                                        | `TemplateSyncTest` (3 casos)                                                                                       |
| SEC-08 | La descarga de una exportación solo revisaba un permiso genérico de exportación.                                                  | `DataTransferPolicy::download` usa `ReportExporter::allowsStored` del reporte concreto.                                                                                                       | `ReportExportTest` («downloading a finished export checks…»)                                                       |
| SEC-09 | Departamentos, usuarios y respuestas rápidas respondían 403 a registros existentes fuera de alcance y 404 a ids inexistentes.     | Políticas con `Response` (`denyAsNotFound` fuera de alcance) y form requests con `Gate::inspect`; `email-availability` resuelve `user_id` sin `exists` (inexistente y fuera de alcance: 404). | Pruebas de departamentos, usuarios, respuestas rápidas y sondas de `tests/Feature/Validation`                      |
| SEC-11 | Historial de Inertia sin cifrar y sin limpiar al cerrar sesión.                                                                   | `inertia.history.encrypt` (por defecto `true`) y `clearHistory` en el cierre de sesión (cliente y servidor).                                                                                  | `AuthenticationTest` («signing out clears the browser history…»)                                                   |
| DEL-02 | Casos de `AuditAction` sin emisor.                                                                                                | Eliminados los que no tenían registros; documentados los que se conservan.                                                                                                                    | `AuditLogTest` (sin cambios)                                                                                       |
| SEC-01 | Un mensaje en cola salía aunque su autor ya no pudiera responder (transferida, cerrada, en espera, cuenta desactivada).           | `DeliverOutboundMessage::claim` revalida bajo bloqueo `ConversationPolicy::reply` con el autor (o quien reintentó); si no, `sender_not_allowed` sin llamar a Meta. Automáticos exentos.       | `CustomerServiceWindowTest`, `MessageRetryTest`                                                                    |
| SEC-06 | `presence-team` entregaba id, nombre y disponibilidad de todos los usuarios del chat interno, sin alcance por departamento.       | `presence-team` solo para quien llega a todos y `presence-team.departments.{id}` para miembros; carga útil `{id}`.                                                                            | `TeamChatAccessTest` (canales y `POST /broadcasting/auth`)                                                         |
| SEC-10 | La descarga de multimedia entrante no tenía tope durante el _streaming_ si Meta no informaba `file_size`.                         | `MetaGraphClient::download(..., $maxBytes)`: el sumidero cuenta bytes y aborta en el primer bloque que excede; `MediaRejected('too_large')`.                                                  | `InboundMediaTest` (2 casos)                                                                                       |
| TC-07  | Se podía publicar en el chat interno justo después de una expulsión o un archivado.                                               | `PostTeamMessage` y las reacciones bloquean el hilo (y el mensaje) y vuelven a autorizar dentro de la transacción.                                                                            | `TeamMessagesTest`, `TeamReactionsTest`                                                                            |
| DEL-01 | Marca de eliminación heterogénea (varias entidades no guardan cuándo ni quién eliminó).                                           | **Sin cambio de esquema, por decisión**: se mantiene el modelo (`is_active` o `deleted_at` según la entidad); la fuente de quién y cuándo es la auditoría (`*.deleted` / `*.restored`).       | — (documentado; [ADR-SEC-8](decisiones.md#adr-sec-8--eliminación-lógica-heterogénea-con-la-auditoría-como-fuente)) |

## 7. Riesgos residuales y recomendaciones

- **No validado contra Meta real:** firma, handshake de verificación, descarga de multimedia y límites reales de Meta
  (pendiente de validación externa con una cuenta de WhatsApp Business real).
- **PDF en vista previa sin `sandbox`:** los navegadores no muestran PDF en documentos con CSP `sandbox`; se sirven en línea
  con `nosniff` y con el visor del navegador. Un PDF malicioso queda limitado por el visor del navegador.
- **Herramientas de desarrollo:** con `APP_ENV=local`, Inertia DevTools registra las props de cada página en
  `storage/inertia-devtools` y las sirve sin autenticación en `/_inertia/devtools/entries`. Nunca usar `APP_ENV=local` con
  datos reales; en producción está desactivado por defecto (`INERTIA_DEVTOOLS_ENABLED`). `laravel/boost` es dependencia de
  desarrollo y no debe instalarse en producción (`composer install --no-dev`).
- **Proxies:** si se coloca un balanceador delante de Nginx, hay que configurar `trustProxies` para que `https` y la IP
  real se detecten (el preflight lo advierte).
- **Diagnóstico de conexiones:** usuarios con `connections.view` ven el último error del webhook (mensaje saneado).
- **Web Push:** la clave privada VAPID es un secreto (`.env`); el servidor solo llama a endpoints HTTPS de servicios
  push conocidos (`WEBPUSH_ALLOWED_HOSTS`, sin credenciales en la URL) para evitar SSRF; el push no lleva contenido y
  el _service worker_ pide el resumen con la sesión del usuario. Al cerrar sesión la suscripción del navegador se
  conserva (los avisos siguientes son genéricos, sin datos); en un equipo compartido puede mostrar los contadores de
  quien tenga la sesión abierta ([notificaciones-y-horarios.md §5](notificaciones-y-horarios.md#5-pendiente--limitaciones)).
- **Destinos de transferencia:** un agente con permiso de transferir ve el nombre y la disponibilidad de las personas
  de otros departamentos que pueden atender esa conexión (nunca correo, rol, cifras de carga ni conversaciones); es
  la regla `canAttend` que el backend ya aplicaba y responde igual a un id inexistente que a uno fuera de alcance.
- **La señal de tiempo real** de bandejas de equipo puede anunciar ids de conversaciones cerradas a usuarios sin permiso de
  historial; toda recarga vuelve a autorizar. Se acepta como riesgo residual: la señal solo lleva el id y el motivo, el
  aviso de cierre es justo lo que esos usuarios necesitan para quitar la conversación de su lista (ya conocían el id
  cuando estaba abierta), y separarla exigiría canales distintos para quien tiene historial, a costa de más mensajes y
  más complejidad en el cliente.
- **Dependencias de npm (solo desarrollo):** `npm audit --omit=dev` no reporta nada; el bundle de producción
  (`public/build`) no contiene `vitest`, `tinypool` ni `launch-editor`. Todas las alertas vienen de `vite-plus` 0.3.0
  (`devDependencies`, versión exacta). Se aplicó la única corrección no rompedora disponible: `overrides` de npm con
  `tinypool` 2.1.2 (alerta crítica de `oxfmt`, publicada hace dos meses); `npm run build`, `vp check` y `vue-tsc` siguen
  funcionando. Quedan 5 alertas (4 moderadas, 1 alta): `vitest` y `@vitest/*` 4.1.11 (registro de mocks del modo
  navegador, que el proyecto no usa: no hay pruebas de Vitest) y las propias de `vite-plus` <=1.0.0 (servidor de
  desarrollo de Vite y `launch-editor`). La versión parcheada 4.1.12 de Vitest se publicó hace solo una semana y la
  corrección de `vite-plus` es un salto mayor (1.x), así que se dejan para un cambio aparte que valide `vp build`,
  `vp check` y `vp dev`. Mientras tanto, el servidor de desarrollo (`npm run dev`) solo debe escuchar en local y nunca
  exponerse a la red ni usarse en producción.
- **Dependencias de Composer:** `composer audit` no reporta nada. Guzzle está en 7.16.0 (rama 7 mantenida, publicada
  esta semana) porque `laravel/reverb` exige `guzzlehttp/psr7 ^2.6` y Guzzle 8 necesita psr7 ^3 (`composer why-not
guzzlehttp/guzzle 8.0`); el framework, Pusher y Boost aceptan 7.x. Se podrá volver a Guzzle 8 cuando Reverb admita psr7
  ^3.
- **Dependencias sin uso (recomendación, requiere aprobación):** `laravel/chisel` está en `require` de producción y nada
  lo usa (`composer remove laravel/chisel`); `laravel/sail` en `require-dev` sin `compose.yaml` (`composer remove --dev
laravel/sail`); `@laravel/passkeys` sin ningún import, con las passkeys desactivadas (`npm uninstall
@laravel/passkeys`). No se eliminaron.
