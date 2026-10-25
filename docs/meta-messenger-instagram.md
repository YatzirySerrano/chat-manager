# Facebook Messenger e Instagram

Chat Manager recibe y responde mensajes de **páginas de Facebook (Messenger)** y de **cuentas profesionales de
Instagram** con las APIs oficiales de Meta. Los mensajes entran al mismo flujo que WhatsApp y el Chat web: contacto
provisional + identidad del canal, conversación, enrutamiento a departamento, asignación, bandeja, notificaciones,
transferencias, auditoría y estados de entrega.

> **Estado: implementado y probado solo con respuestas simuladas (`Http::fake`).** Ningún flujo OAuth, webhook ni
> envío se ha ejecutado contra Meta real. Ver [§9](#9-validación-con-una-app-real-pendiente).

## 1. Arquitectura

| Pieza                                                                  | Responsabilidad                                                                                                                                                                                                          |
| ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `App\Services\Meta\MetaApp`                                            | Configuración de la app (`services.meta.*`), variables faltantes, `appsecret_proof`, permisos y requisitos en español.                                                                                                   |
| `App\Services\Meta\MetaPlatformClient`                                 | Llamadas a `graph.facebook.com`, `graph.instagram.com` y `api.instagram.com`: intercambio de código, tokens de larga duración, páginas, cuenta de Instagram, `subscribed_apps`, Send API y perfiles. Errores en español. |
| `App\Services\Meta\MetaOAuthSession`                                   | `state` de un solo uso (sesión, 10 min, ligado a usuario y canal, comparado con `hash_equals`) y cuentas candidatas cifradas en caché (10 min).                                                                          |
| `App\Services\Meta\MetaAccountAuthorization`                           | URL de autorización e intercambio del código → cuentas disponibles.                                                                                                                                                      |
| `App\Http\Controllers\Admin\MetaAuthorizationController`               | Requisitos → «Autorizar acceso» → callback → selección de cuenta → conexión; «Desconectar».                                                                                                                              |
| `App\Actions\Connections\ConnectMetaAccount` / `DisconnectMetaAccount` | Crear o reautorizar la conexión (token cifrado, permisos, quién y cuándo), suscribir y comprobar el webhook, auditar; cancelar suscripción y olvidar el token.                                                           |
| `App\Http\Controllers\Webhooks\MetaWebhookController`                  | Webhook de la app `GET/POST /webhooks/meta`: token de verificación, tope de tamaño, firma `X-Hub-Signature-256`, reparto por cuenta.                                                                                     |
| `App\Services\Meta\MetaMessagingPayload`                               | Interpreta `object: page` (Messenger) e `object: instagram` → mensajes, ecos, entregas y lecturas, ordenados por `timestamp`.                                                                                            |
| `App\Actions\Messaging\ProcessMetaMessagingPayload`                    | Mensajes → `ReceiveInboundMessage`; marcas de entrega/lectura → `RecordMessageStatus`; ecos deduplicados.                                                                                                                |
| `App\Services\Meta\MetaMessagingTransport`                             | `MessageTransport` de ambos canales (registrado en `ChannelTransports`).                                                                                                                                                 |
| `meta:refresh-instagram-tokens`                                        | Renueva a diario (03:50) los tokens de Instagram que vencen en ≤ 10 días y tienen ≥ 24 h.                                                                                                                                |
| `App\Http\Controllers\Webhooks\MetaDataCallbackController`             | Callbacks de desautorización y de eliminación de datos (`signed_request`).                                                                                                                                               |

Rutas: `routes/meta-connections.php` (administración, `auth` + `verified`, cargado antes de `connections.php`) y
`routes/webhooks.php` (públicas, sin sesión).

### Datos

- `connections`: `external_account_id` (ID de la página o `user_id` de la cuenta profesional de Instagram, único por
  canal), `external_account_name`, `external_account_picture`, `granted_scopes`, `token_expires_at` (solo Instagram),
  `authorized_at`, `authorized_by_user_id`; el token de página / Instagram va cifrado en `access_token`. El ID de
  usuario de Meta de quien autorizó se guarda en `provider_signals.meta_authorization` (para los callbacks).
- **Sin migración nueva para mensajes**: `messages.whatsapp_message_id` (único, 191) ya es el identificador genérico del
  proveedor (lo usa también el Chat web). Se guarda el `mid` de Meta; si supera 128 caracteres se guarda
  `sha256:<hash>` (`MetaMessagingPayload::messageId`), igual en entrada, salida, ecos y lecturas. La deduplicación de
  WhatsApp no cambia.
- Identidad del cliente: `contact_identities` con `channel = messenger|instagram` y `external_id` = PSID / IGSID. Los
  contactos nuevos son **provisionales** (reglas de `RegisterInboundContact`). Nombre: User Profile API solo la primera
  vez y solo si Meta lo permite; si no, «Usuario de Messenger xxxxxx» / «Usuario de Instagram xxxxxx». Nunca se
  inventa un nombre ni se guarda la foto de perfil (sus URL caducan).

## 2. Variables de entorno

| Variable                                             | Uso                                                                                                         |
| ---------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| `META_APP_ID`, `META_APP_SECRET`                     | App de Meta (Facebook Login, Messenger, firma del webhook). Obligatorias para Messenger.                    |
| `META_LOGIN_CONFIG_ID`                               | Opcional: usa Facebook Login para empresas (`config_id`) en vez de `scope`.                                 |
| `META_INSTAGRAM_APP_ID`, `META_INSTAGRAM_APP_SECRET` | ID y clave de Instagram del panel (producto Instagram). Si faltan se usan los de Facebook.                  |
| `META_WEBHOOK_VERIFY_TOKEN`                          | Texto aleatorio que se registra en Meta para verificar `GET /webhooks/meta`.                                |
| `META_GRAPH_VERSION`                                 | Versión de Graph (por defecto `v26.0`).                                                                     |
| `META_INSTAGRAM_GRAPH_BASE_URL`                      | Por defecto `https://graph.instagram.com`.                                                                  |
| `META_HUMAN_AGENT_TAG`                               | `false` por defecto. Con `true`, ventana de 7 días para respuestas humanas. Ver [§6](#6-recepción-y-envío). |
| `META_WEBHOOK_RATE_LIMIT`                            | Entregas firmadas por minuto y dirección en `POST /webhooks/meta` (por defecto 6000).                       |
| `META_WEBHOOK_INVALID_SIGNATURE_LIMIT`               | Firmas inválidas por minuto y dirección antes de responder 429 (por defecto 30).                            |
| `META_MEDIA_RATE_LIMIT`                              | Descargas por minuto y dirección de `meta/media/{id}` (por defecto 1200).                                   |

Sin `META_APP_ID`/`META_APP_SECRET` (o los de Instagram) y `META_WEBHOOK_VERIFY_TOKEN`, el selector muestra el canal
con el aviso «Requiere configurar la aplicación de Meta en el servidor» y la pantalla de requisitos lista las variables
que faltan; nunca se crea una conexión ficticia. `APP_URL` debe ser la URL pública HTTPS.

## 3. Configuración de la app de Meta

1. Crear una app de tipo **Empresa** en developers.facebook.com y vincularla al portafolio comercial.
2. **Messenger**: agregar «Inicio de sesión con Facebook» (o «para empresas») y «Messenger». En _Configuración del
   inicio de sesión_: modo estricto, HTTPS obligatorio y URI de redirección
   `https://<dominio>/connections/meta/messenger/callback`.
3. **Instagram**: agregar el producto Instagram → «API con inicio de sesión de Instagram». URI de redirección
   `https://<dominio>/connections/meta/instagram/callback`. Copiar el ID y la clave de Instagram.
4. **Webhooks** (Messenger e Instagram): URL `https://<dominio>/webhooks/meta`, token = `META_WEBHOOK_VERIFY_TOKEN`.
   Campos: Messenger `messages, message_deliveries, message_reads, message_echoes, messaging_postbacks,
messaging_policy_enforcement`; Instagram
   `messages, messaging_seen, messaging_postbacks, message_reactions`. La suscripción de **cada cuenta** la hace Chat
   Manager al conectarla (`POST /{page-id}/subscribed_apps` o `POST graph.instagram.com/me/subscribed_apps`) y la
   comprueba con `GET`.
5. _Configuración › Avanzada_: activar «Requerir clave secreta de la app» (todas las llamadas llevan `appsecret_proof`).
6. _Configuración › Básica_: URL de desautorización `https://<dominio>/meta/deauthorize` y de eliminación de datos
   `https://<dominio>/meta/data-deletion`; política de privacidad.

## 4. Permisos y revisión de Meta

| Canal     | Permisos                                                                               | Requisitos de la cuenta                                                                                                                |
| --------- | -------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| Messenger | `pages_show_list`, `pages_messaging`, `pages_manage_metadata`, `pages_read_engagement` | Página publicada; quien autoriza tiene la tarea MESSAGING/MODERATE. Nombres de clientes: función _Business Asset User Profile Access_. |
| Instagram | `instagram_business_basic`, `instagram_business_manage_messages`                       | Cuenta profesional; en Instagram: Configuración › Mensajes › Herramientas conectadas › «Permitir acceso a los mensajes».               |

- **Acceso estándar** (antes de App Review): solo personas con un rol en la app envían y reciben.
- **Producción**: app en modo _Live_, **acceso avanzado** aprobado en App Review para `pages_messaging` /
  `instagram_business_manage_messages` (y los demás), **verificación del negocio**, webhook respondiendo 200 en < 20 s,
  credenciales de prueba en la solicitud. `messaging_policy_enforcement` se suscribe en cada página: un aviso
  `block` queda como último error de la conexión y en `provider_signals.messaging_policy_enforcement`.
- **Páginas de un portafolio comercial**: `me/accounts` puede no listar las páginas que pertenecen a un Business
  Portfolio si falta `business_management` (comportamiento reportado por desarrolladores, no confirmado en la
  documentación oficial). No se pide por defecto; si «Meta no devolvió ninguna página», agrégalo a la configuración de
  Facebook Login para empresas (`META_LOGIN_CONFIG_ID`) y vuelve a autorizar.
- **Por qué Instagram Login**: no requiere una página de Facebook vinculada, sus endpoints (`graph.instagram.com`)
  están documentados para mensajería y el token se renueva sin intervención. Meta indica hoy que el **Unified Login**
  (token BISU de Facebook Login para empresas) es el recomendado para integraciones nuevas; el cambio es posible
  reemplazando `MetaAccountAuthorization::instagramAccounts` y las URL de Instagram del cliente por `graph.facebook.com`
  con el token del usuario del sistema; el webhook, el parser y el pipeline no cambian.
- **PKCE**: Meta no lo documenta para el flujo manual de Facebook Login ni para Instagram Login. Se usa `state` de un
  solo uso + intercambio del código en el servidor con la clave secreta (cliente confidencial).

## 5. Flujo de autorización

1. Conexiones › Nueva conexión › Facebook Messenger / Instagram → pantalla de requisitos (permisos, URI de retorno, URL
   del webhook) → **Autorizar acceso** (`POST connections/meta/{channel}/authorize`, throttle 10/min).
2. Redirección a `https://www.facebook.com/v26.0/dialog/oauth` (`client_id`, `redirect_uri`, `state`,
   `response_type=code`, `scope` o `config_id`) o `https://www.instagram.com/oauth/authorize`.
3. Callback: el `state` se consume siempre (falte, no coincida, haya vencido, sea de otro usuario o canal → error y
   ninguna llamada a Meta); `error`/cancelación → aviso, nada se crea.
4. Messenger: `GET /oauth/access_token` (código) → `fb_exchange_token` (larga duración; con `META_LOGIN_CONFIG_ID`
   el token suele ser de usuario del sistema y no vence, así que si Meta rechaza el intercambio se usa el del código) →
   `/me/permissions`, `/me`,
   `/me/accounts?fields=id,name,picture,access_token,tasks`. El token de usuario se descarta. Instagram:
   `POST api.instagram.com/oauth/access_token` → `ig_exchange_token` (60 días; si Meta no informa `expires_in` se
   asumen 60 días) → `/me?fields=user_id,username,name,profile_picture_url`.
5. Selección: lista con nombre, ID e imagen (sin tokens en el navegador). Solo se acepta una cuenta ofrecida por Meta
   para ese canal y, en Messenger, con la tarea MESSAGING/MODERATE (cualquier otro `account_id` da un error de
   validación sin crear, suscribir ni auditar nada). Elegir crea la conexión **inactiva** (o renueva el token de una
   existente con la misma cuenta y reinicia el ciclo de renovación; dos autorizaciones simultáneas de la misma cuenta
   terminan en una sola conexión; una cuenta de una conexión eliminada debe restaurarse primero), suscribe el webhook,
   lo comprueba y audita `connection.meta_authorized` (sin tokens).
6. Detalle de la conexión: cuenta, permisos concedidos en español, fecha y autor, vigencia del token, suscripción,
   «Volver a autorizar» y «Desconectar».

**Desconectar** (`connection.meta_disconnected`): `DELETE subscribed_apps` (si falla no bloquea), se borran token,
permisos y vencimiento, se desactiva y se marca la suscripción como cancelada; la conexión, su cuenta y el historial se
conservan. No se guarda el token de usuario, así que no hay `DELETE /me/permissions`; la persona puede retirar la app
desde su configuración de Facebook/Instagram, lo que dispara el callback de desautorización.

**Callbacks** (`signed_request`, HMAC-SHA256 con la clave de Facebook o la de Instagram): desautorización y eliminación
desconectan (sin llamar a Meta) las conexiones que autorizó esa persona; la eliminación responde
`{url, confirmation_code}` y la página de estado se conserva 30 días. Las conversaciones con clientes pertenecen al
negocio y no se borran.

## 6. Recepción y envío

- **Webhook**: tope `whatsapp.webhooks.max_body_bytes`; firma con la clave del canal del objeto (`page` → clave de
  Facebook; `instagram` → clave de Instagram, o la de Facebook si no hay una propia: un cuerpo de Instagram firmado con
  la de Facebook se rechaza cuando existe la de Instagram). Límites por dirección IP (requiere `TRUSTED_PROXIES`
  detrás de un proxy): entregas firmadas `meta-webhooks` (generoso), firmas inválidas contadas aparte (429 al superar
  `META_WEBHOOK_INVALID_SIGNATURE_LIMIT`, sin tocar la cuota de Meta) y `GET` de verificación 30/min. Reparto por
  `entry.id` → conexión (`channel` + `external_account_id`); cuentas desconocidas, eliminadas o desconectadas se ignoran
  con 200. Cada parte se guarda en `webhook_events` de su conexión (idempotente) y se procesa en la cola `webhooks`
  (`ProcessWhatsAppWebhook` elige el procesador por canal).
- **Mensajes**: texto, `quick_reply`, respuestas (`reply_to.mid`), postbacks (texto con el título del botón),
  adjuntos `image/audio/video/file` (descargados de la URL prefirmada del CDN **sin token**, con la lista de hosts de
  Meta — `fbcdn.net`, `fbsbx.com`, `cdninstagram.com`… —, tope de tamaño durante la descarga y tipo real por `finfo`;
  límites propios del canal en `MediaStorage`: imágenes JPEG/PNG/GIF/WebP hasta 8 MB, audio y video hasta 25 MB
  —incluye WAV, MOV, WebM, AVI—, archivos hasta 25 MB, en Instagram solo PDF). Los stickers de Messenger (`sticker_id`,
  también el «Me gusta») se guardan como imagen; varios adjuntos en
  un mensaje se guardan como mensajes `mid`, `mid#2`…; otros tipos (compartidos, menciones en historias, plantillas)
  quedan como «no compatible». Reacciones, optins y mensajes eliminados se ignoran. Una marca de tiempo futura se
  limita a «ahora» (también en WhatsApp) para no extender la ventana de 24 h.
- **Ecos**: el eco de un mensaje enviado por Chat Manager se reconoce por su `mid` y no se guarda dos veces; las
  respuestas enviadas desde la bandeja de Meta (Business Suite) **no se reflejan** en Chat Manager (decisión: se
  ignoran y se registran en el log).
- **Estados**: Messenger `delivery.watermark` / `read.watermark` avanzan los mensajes salientes de ese cliente en esa
  conexión que Meta aceptó (`accepted_at`, en segundos) **antes del segundo** de la marca (y los `mids` listados) con
  `RecordMessageStatus` (solo hacia adelante; `delivery_unknown` y los mensajes sin `accepted_at` no se tocan con una
  marca; un mensaje aceptado en el mismo segundo espera la siguiente marca). Instagram `read.mid` marca ese mensaje como
  leído.
- **Envío** (`MetaMessagingTransport`): `POST /{page-id}/messages` o `POST graph.instagram.com/v26.0/me/messages` con
  `recipient.id` = el PSID/IGSID que escribió en **esa conversación** (`metadata.sender_external_id` de sus mensajes
  entrantes; las conversaciones anteriores a este dato usan la identidad más reciente del contacto en el canal),
  `messaging_type: RESPONSE`, token en cabecera y `appsecret_proof`. Fuera de la ventana el envío se bloquea antes de
  llamar a Meta (`CustomerServiceWindow`); las plantillas no existen en estos canales y nunca abren la ventana.
  `META_HUMAN_AGENT_TAG=true` (requiere la aprobación de la función _Human Agent_) amplía la ventana de Messenger e
  Instagram a **7 días** para respuestas humanas y, pasadas las primeras 24 h, envía `messaging_type: MESSAGE_TAG` +
  `tag: HUMAN_AGENT`; WhatsApp no cambia. Archivos: por URL firmada (`meta/media/{id}`, 60 min, `Content-Disposition`
  con el nombre y la extensión de su tipo real, límite `META_MEDIA_RATE_LIMIT`) con los límites de
  `ChannelAttachmentPolicy`; ZIP como texto con enlace seguro (`AttachmentDownloadLink`): nunca se envía un enlace
  vencido, sin vencimiento guardado o revocado; en Instagram el texto se compacta (y se acorta el nombre del archivo)
  para caber en 1000 bytes y, si el texto del agente no cabe, el mensaje falla con «Envía el texto en un mensaje aparte».
  Texto con archivo: su longitud se valida **antes** de enviar el archivo; si el texto falla después, el mensaje queda
  aceptado con el aviso `metadata.delivery_warning` («El archivo se envió, pero el texto… no llegó»; sin reintento
  automático). Texto: Messenger ≤ 2000 caracteres, Instagram ≤ 1000 bytes. Errores (token revocado, permisos, fuera de
  ventana 10/2018278, persona no disponible 551) en español.
- **Revocar un enlace de descarga**: `POST conversations/{c}/messages/{m}/attachments/{a}/revoke-link` (quien envió el
  archivo o quien puede responder la conversación) guarda `metadata.download_link.revoked_at`, audita
  `conversation.attachment_link_revoked` y la descarga pública responde 410 con una página en español.

## 7. Limitaciones

- Plantillas/etiquetas de Messenger (utility, tags) no implementadas; solo respuestas dentro de 24 h.
- Respuestas enviadas desde la bandeja de Meta no se importan; reacciones y ediciones no se muestran.
- Conversaciones anteriores a `metadata.sender_external_id` con un contacto que tiene dos PSID de páginas distintas
  responden con la identidad vista más recientemente del canal.
- Las imágenes de perfil / cuenta se muestran desde la URL de Meta guardada al autorizar (pueden caducar; volver a
  autorizar las renueva).
- El texto que acompaña un archivo se envía después del archivo (si falla queda el aviso en el mensaje); la política
  de adjuntos no permite pie de foto en estos canales.

## 8. Validado solo con simulaciones

`tests/Feature/Connections/MetaAuthorizationTest.php`, `InstagramTokenRefreshTest.php`,
`tests/Feature/Webhooks/MetaWebhookTest.php`, `tests/Feature/Messaging/MetaMessagingTransportTest.php` y el barrido
`tests/Feature/Security/RouteAuthorizationSweepTest.php` cubren: URL de autorización y `state`; rechazo de `state`
ausente, alterado, vencido, reutilizado, de otro canal o de otro usuario; cancelación; intercambio de código y token de
larga duración; selección y conexión con token cifrado, suscripción y auditoría; reautorización; 403 sin
`connections.manage` global; desconexión; callbacks firmados; verificación GET; firma inválida; entrada Messenger e
Instagram con contacto provisional e identidad; perfil denegado; duplicados; cuentas desconocidas; adjuntos; marcas de
entrega/lectura monótonas; ecos; envío con `appsecret_proof`; bloqueo fuera de 24 h; URL firmada; ZIP como enlace;
token revocado; renovación de tokens de Instagram (también con vencimiento desconocido, y sin pisar una conexión
desconectada o reautorizada durante la llamada); manipulación de la cuenta elegida (no ofrecida, sin tarea de
mensajes, de otro canal); firma del canal equivocado; límites por dirección; stickers/GIF y audio de 25 MB entrantes;
`messaging_policy_enforcement`; marca de agua en el mismo segundo; destinatario por conversación; etiqueta
HUMAN_AGENT y ventana de 7 días; texto con archivo; enlaces vencidos/revocados y texto compacto en Instagram;
`Content-Disposition` de `meta/media`; plantillas bloqueadas en Messenger/Instagram.

## 9. Validación con una app real (pendiente)

- [ ] Variables configuradas; `GET /webhooks/meta` verificado desde el panel de Meta.
- [ ] Messenger: autorizar con un administrador de una página de prueba; la página aparece con su imagen; la conexión
      queda con suscripción confirmada (`GET /{page}/subscribed_apps`).
- [ ] Enviar un mensaje desde una cuenta con rol en la app → conversación, contacto provisional, notificación.
- [ ] Responder desde Chat Manager → aceptado, luego entregado/leído por marcas de agua; el eco no se duplica.
- [ ] Imagen, audio, video y PDF entrantes se descargan; salientes llegan (Meta descarga la URL firmada).
- [ ] Nombre del cliente con _Business Asset User Profile Access_ y sin ella (respaldo).
- [ ] Instagram: autorizar una cuenta profesional con «Permitir acceso a los mensajes»; mensaje, respuesta, `read.mid`.
- [ ] `appsecret_proof` aceptado en `graph.instagram.com` (no confirmado en la documentación).
- [ ] `meta:refresh-instagram-tokens` con un token de más de 24 h.
- [ ] Desconectar y volver a autorizar; quitar la app desde Facebook dispara `meta/deauthorize`.
- [ ] Fuera de 24 h el compositor bloquea; mensajes reales con códigos de error de Meta.
- [ ] App Review (acceso avanzado) y verificación del negocio antes de atender a clientes reales.
