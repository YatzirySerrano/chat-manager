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
  pedido hace un momento (mensaje neutral). El broker limita un enlace por usuario cada 60 s.
- Cuentas desactivadas (baja lógica) no inician sesión y `EnsureUserIsActive` cierra cualquier sesión abierta en la
  siguiente petición (también en `broadcasting/auth`).
- Cookies de sesión `HttpOnly`, `SameSite=lax` y `Secure` automáticamente cuando `APP_URL` es https
  (`SESSION_SECURE_COOKIE`). El preflight (`php artisan app:preflight`) falla en producción con `APP_DEBUG=true`, sin https o
  con cookies no seguras.
- El cierre de sesión pide confirmación en la interfaz y es POST con CSRF.
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
  inexistente, también en JSON (`{"message":"Not Found"}` sin modelo ni id).
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
  `ValidateCsrfToken`; no hay excepciones. El webhook está fuera del grupo `web` (sin sesión ni cookies).
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

| Superficie                                                                     | Límite                                           |
| ------------------------------------------------------------------------------ | ------------------------------------------------ |
| Inicio de sesión / 2FA                                                         | 5/min por correo+IP / por sesión                 |
| Cambio de contraseña                                                           | 6/min                                            |
| Webhook WhatsApp                                                               | `WHATSAPP_WEBHOOK_RATE_LIMIT` (1200/min) por URL |
| Envío de mensajes / plantillas / reintentos                                    | 60/min, 30/min, 30/min                           |
| Lectura de línea de tiempo y marcadores de lectura                             | 120/min                                          |
| Exportaciones, importaciones, reversión de importación                         | 10/min                                           |
| Verificar conexión, suscripción de app, sincronizar plantillas (llaman a Meta) | 10/min (hallazgo H-6)                            |
| `broadcasting/auth`                                                            | 180/min                                          |
| Sondeo `/health/ready`                                                         | 60/min por IP y veredicto en caché 10 s          |

Cada `throttle:N,M` cuenta por usuario (o IP) **y ruta** (`App\Http\Middleware\ThrottleRequestsPerRoute`, registrado como
alias `throttle`): el sondeo de la campana o las autorizaciones de canales ya no agotan el límite de exportaciones o
importaciones. Los limitadores con nombre (`login`, `two-factor`, `whatsapp-webhooks`) conservan sus propias claves.

### Webhook de WhatsApp

- URL con clave aleatoria (`webhook_key`, 48 caracteres), nunca el id de la conexión; conexión desconocida o eliminada →
  404 idéntico.
- POST autenticado con `X-Hub-Signature-256` (HMAC-SHA256 del cuerpo crudo con el app secret) comparado en tiempo
  constante; sin app secret se rechaza. GET de verificación compara el verify token en tiempo constante y filtra el
  `hub.challenge` (solo `[A-Za-z0-9_.-]`, máx. 256).
- Tamaño máximo `WHATSAPP_WEBHOOK_MAX_BYTES` (3 MB) además de `client_max_body_size` de Nginx; respuesta inmediata y
  procesamiento en la cola `webhooks`.
- Repetición: almacenamiento idempotente por hash del cuerpo, mensajes únicos por `wamid` y estados que solo avanzan.
  Meta no firma una marca de tiempo, así que la protección contra repetición es la idempotencia.
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

## 6. Hallazgos corregidos en esta auditoría

| Id  | Síntoma                                                                                                                                                 | Causa                                                                                                        | Corrección                                                                                                       | Prueba                                                                      |
| --- | ------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| H-1 | Con un cuerpo inválido, 9 operaciones sobre una conversación ajena respondían 422 en vez de 404, lo que permitía saber qué ids de conversación existen. | Los form requests de conversación autorizaban siempre (`return true`) y validaban antes del `ensureVisible`. | Trait `App\Concerns\AuthorizesVisibleConversation`: los requests deniegan como no encontrado antes de validar.   | `SecurityHardeningTest` (dataset de 9 rutas + edición de nota) y el barrido |
| H-2 | Cambiar el estado de una conexión sin permiso respondía 422 antes de 403.                                                                               | `UpdateStatusRequest` validaba antes de la política.                                                         | El request comprueba `changeStatus` antes de validar.                                                            | `SecurityHardeningTest`                                                     |
| H-3 | La cookie `appearance` (sin cifrar) se insertaba sin validar en un script en línea de la vista raíz.                                                    | `HandleAppearance` compartía cualquier valor.                                                                | Solo se aceptan `light`, `dark`, `system`; lo demás se trata como `system`.                                      | `SecurityHardeningTest` (4 casos)                                           |
| H-4 | El disco privado `local` publicaba las rutas firmadas `storage/{path}` (GET y PUT) que sirven y reciben cualquier archivo privado solo con firma.       | `'serve' => true` heredado de la plantilla de Laravel.                                                       | `'serve' => false`; los archivos solo se sirven por controladores autorizados.                                   | `SecurityHardeningTest`                                                     |
| H-5 | El formulario de recuperación revelaba si un correo tiene cuenta («No encontramos un usuario…» / «Espera…»).                                            | Respuesta de error por defecto de Fortify.                                                                   | `App\Http\Responses\FailedPasswordResetLinkResponse` y mensaje neutral en `lang/es/passwords.php`.               | `SecurityHardeningTest`                                                     |
| H-6 | Las rutas que llaman a Meta (verificar conexión, consultar/crear suscripción, sincronizar plantillas) no tenían límite.                                 | Faltaba `throttle`.                                                                                          | `throttle:10,1` en las cuatro rutas.                                                                             | Barrido de rutas (sin regresiones) y pruebas de conexiones y plantillas     |
| H-7 | No se auditaban eventos de seguridad de cuenta y el bloqueo por fuerza bruta no generaba ningún evento.                                                 | Faltaba un listener; el limitador con nombre de Fortify sustituye al que dispara `Lockout`.                  | `App\Listeners\Security\AuditSecurityEvents`, nuevas acciones `auth.*` y el limitador `login` dispara `Lockout`. | `SecurityHardeningTest` (6 casos)                                           |

## 7. Riesgos residuales y recomendaciones

- **No validado contra Meta real:** firma, handshake de verificación, descarga de multimedia y límites reales de Meta
  (pendiente de validación externa con una cuenta de WhatsApp Business real).
- **PDF en vista previa sin `sandbox`:** los navegadores no muestran PDF en documentos con CSP `sandbox`; se sirven en línea
  con `nosniff` y con el visor del navegador. Un PDF malicioso queda limitado por el visor del navegador.
- **GET con efectos:** `exports/{report}/{format}` genera la exportación con GET (limitada a 10/min, archivo solo para el
  solicitante). `SameSite=lax` impide que subrecursos de otros sitios la disparen, pero una navegación de nivel superior
  sí podría iniciar una exportación para el propio usuario.
- **Recuperación de contraseña sin límite por IP:** solo existe el límite del broker (un enlace por usuario cada 60 s).
- **Cambiar la contraseña no cierra otras sesiones abiertas** del mismo usuario; desactivar la cuenta sí las cierra.
- **Historial del navegador:** Inertia guarda las props de las páginas visitadas en el historial del navegador; tras
  cerrar sesión en un equipo compartido, «Atrás» puede mostrar datos en caché. En producción con https se recomienda
  `INERTIA_ENCRYPT_HISTORY=true` junto con `Inertia::clearHistory()` al cerrar sesión (no se activó porque requiere un
  contexto seguro y no se pudo probar en navegador en esta etapa).
- **Herramientas de desarrollo:** con `APP_ENV=local`, Inertia DevTools registra las props de cada página en
  `storage/inertia-devtools` y las sirve sin autenticación en `/_inertia/devtools/entries`. Nunca usar `APP_ENV=local` con
  datos reales; en producción está desactivado por defecto (`INERTIA_DEVTOOLS_ENABLED`). `laravel/boost` es dependencia de
  desarrollo y no debe instalarse en producción (`composer install --no-dev`).
- **Proxies:** si se coloca un balanceador delante de Nginx, hay que configurar `trustProxies` para que `https` y la IP
  real se detecten (el preflight lo advierte).
- **Diagnóstico de conexiones:** usuarios con `connections.view` ven el último error del webhook (mensaje saneado).
- **Descarga de multimedia sin tope en streaming** cuando Meta no envía `file_size` (archivo temporal borrado siempre).
- **La señal de tiempo real** de bandejas de equipo puede anunciar ids de conversaciones cerradas a usuarios sin permiso de
  historial; toda recarga vuelve a autorizar.
