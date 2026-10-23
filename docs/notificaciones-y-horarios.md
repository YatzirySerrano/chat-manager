# Notificaciones, horarios y mensajes automáticos

Módulo _automation_. Cubre las notificaciones dentro de la plataforma (campana, preferencias, avisos del navegador),
el cálculo del horario de atención con días no laborables y los mensajes automáticos de bienvenida, fuera de horario
y despedida. Documentos relacionados: [tiempo-real.md](tiempo-real.md) (canal `users.{id}`),
[chat-interno.md](chat-interno.md), [reportes-y-exportaciones.md](reportes-y-exportaciones.md) y
[decisiones.md](decisiones.md) (ADR-AUT-*).

## 1. Notificaciones

### 1.1 Modelo

- Tabla `notifications` (notificaciones de base de datos de Laravel) con columnas adicionales: `kind`, `level`
  (`urgent` / `informative`), `aggregate_key`, `occurrences` y la columna generada `unread_aggregate_key`
  (`IF(read_at IS NULL, aggregate_key, NULL)`), con índice único por destinatario. Así solo puede existir **una**
  notificación sin leer por clave de agrupación aunque varios workers compitan.
- Tabla `notification_receipts` (`user_id`, `source_key` únicos): registro de deduplicación. Antes de crear o
  agrupar una notificación se inserta el recibo del evento de origen; si ya existía, no se notifica de nuevo. Esto
  hace inofensivos los reintentos de webhooks, de listeners en cola o de jobs.
- Tabla `user_notification_preferences` (`user_id`, `kind`, `in_app`, `browser`, `sound`). Sin fila se usan los
  valores predeterminados del tipo: en la app activado, navegador desactivado, sonido solo en las urgentes.
- El `data` guardado contiene **solo identificadores** y metadatos seguros (por ejemplo `conversation_id`,
  `event_id`, `actor_id`). Nunca guarda cuerpos de mensajes, teléfonos, notas ni motivos de transferencia.

Código: `app/Notifications/*` (tipos, canal `Channels\InAppChannel`, `NotificationRecipients`,
`NotificationPresenter`, `NotificationCenter`, `NotificationPreferences`, `NotificationSignal`,
`PruneNotifications`), listeners en `app/Listeners/Notifications/*`.

### 1.2 Tipos, nivel y destinatarios

| Tipo (`kind`)              | Nivel       | Cuándo                                                                                                                       | Quién lo recibe                                                                                                                                                                                                          | Agrupación / deduplicación                                                                                             |
| -------------------------- | ----------- | ---------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------- |
| `conversation.pending`     | Urgente     | Una conversación entra a la cola: creada, devuelta a la cola, transferida a un departamento sin agente, reabierta sin agente | Usuarios activos **disponibles** que pueden aceptarla según `ConversationAccess::canAccept` (excepto quien hizo la operación). Las personas _No disponibles_ no se molestan con la cola (aceptar exige estar disponible) | Una sin leer por departamento; las ráfagas incrementan `occurrences`. Recibo por evento de conversación                |
| `conversation.assigned`    | Urgente     | Asignación manual, automática o regreso al agente al reabrirse                                                               | El nuevo responsable si aún la tiene y puede verla; nunca quien se la asignó a sí mismo (aceptar)                                                                                                                        | Recibo por evento                                                                                                      |
| `conversation.transferred` | Urgente     | Transferencia directa a un agente                                                                                            | El agente que la recibe                                                                                                                                                                                                  | Recibo por evento                                                                                                      |
| `conversation.message`     | Informativa | Mensaje entrante del cliente                                                                                                 | El responsable actual, si puede verla                                                                                                                                                                                    | Una sin leer por conversación; se marca leída al abrir la conversación (evento `ConversationChanged` con razón `read`) |
| `team-chat.message`        | Informativa | Mensaje en un hilo interno                                                                                                   | Participantes actuales, excepto el autor y quien silenció el hilo                                                                                                                                                        | Una sin leer por hilo                                                                                                  |
| `team-chat.mention`        | Urgente     | Mención (`metadata.mentions` del mensaje)                                                                                    | El mencionado, aunque haya silenciado el hilo                                                                                                                                                                            | Una sin leer por hilo                                                                                                  |
| `export.finished`          | Informativa | Exportación en cola completada o fallida                                                                                     | El dueño de la exportación                                                                                                                                                                                               | Recibo por transferencia y estado                                                                                      |
| `import.finished`          | Informativa | Importación de contactos completada o fallida                                                                                | Quien la inició                                                                                                                                                                                                          | Recibo por transferencia y estado                                                                                      |
| `connection.problem`       | Urgente     | Una conexión pasa a `error` o `credentials_invalid` (`ConnectionHealthChanged`)                                              | Usuarios con `connections.view` o `connections.manage`                                                                                                                                                                   | Una sin leer por conexión; la misma transición se ignora durante la hora                                               |
| `account.access-changed`   | Informativa | Cambio de rol, permisos, departamentos o estado (`UserAccessChanged`)                                                        | Los usuarios afectados que siguen activos                                                                                                                                                                                | Una sin leer; recibo por razón y bloque de 5 minutos                                                                   |

**Valores predeterminados** (mientras el usuario no guarde una preferencia para el tipo; una preferencia guardada
siempre manda): en la app sí, navegador no, y sonido sí para los tipos que se anuncian en vivo
(`NotificationKind::announcesInPage()`): todos los urgentes y `conversation.message`. Estos mismos tipos muestran un
aviso (_toast_) en la parte inferior de la página al llegar (campo `toast` de cada elemento de
`notifications.index`, falso si el usuario ya no tiene acceso al registro). `conversation.message` sigue siendo
informativa: no cuenta como urgente en la campana ni en Web Push. Las respuestas del propio agente (salientes) nunca
generan notificación.

**Pérdida de acceso**: cuando una conversación deja a su agente (transferencia, devolución a la cola o reasignación)
y ese agente ya no puede verla, `NotifyConversationChanges` marca como leídas sus notificaciones de esa conversación
(mensajes agregados, asignación, transferencia), igual que al abrirla. Si sigue pudiendo verla (supervisor del
departamento, alcance global), no se tocan. Si se marcó alguna, se publica la señal `.notification` (sin `id`) en
`users.{id}` de ese agente: sus pestañas abiertas recargan la campana sin avisar (lo recargado ya está leído), el
contador baja sin recargar la página y su toast, si seguía visible, se cierra. No es un `NotificationSignal`, así que
no dispara Web Push. Sin tiempo real, el cambio llega con la actualización periódica de respaldo.

Eventos escuchados: `ConversationChanged`, `MessageRecorded` (solo entrantes), `ConnectionHealthChanged`,
`UserAccessChanged`, `TeamChat\TeamMessagePosted` y `DataTransfers\DataTransferFinished` (este último lo emite
`DataTransfer` al pasar de `queued`/`processing` a `completed`/`failed`, de modo que cubre exportaciones e
importaciones sin modificar los jobs). Todos los listeners van en cola `notifications`
(`Queues::NOTIFICATIONS`), con 3 intentos y espera de 5 y 30 segundos; la deduplicación hace seguros los reintentos.

### 1.3 Seguridad del contenido

- El texto visible se construye **al leer** (`NotificationPresenter`): se vuelve a cargar el registro y se comprueba
  el acceso del usuario actual (`ConversationAccess::canView`, participación vigente en el hilo, permisos de
  conexiones, dueño de la transferencia). Si ya no tiene acceso se muestra un texto genérico («Ya no tienes acceso a
  esta conversación») sin nombres ni enlace.
- Se muestran únicamente datos que la persona ya ve en pantalla (nombre del contacto, departamento, compañero,
  conexión). Nunca el cuerpo de los mensajes.
- Las rutas JSON solo consultan notificaciones del usuario autenticado; un id ajeno simplemente no coincide
  (`changed: false`). La ruta para marcar una conversación como leída recibe un número, no resuelve el modelo, y no
  revela si existe.

### 1.4 Tiempo real

Cada notificación guardada o actualizada emite, después del commit, `App\Notifications\NotificationSignal` en el
canal privado `users.{id}` con nombre `.notification` y carga `{id, kind}` (sin contenido). La campana vuelve a pedir
la lista al servidor. Si el tiempo real no está disponible, la campana se actualiza cada 60 segundos y al reconectar.
La señal se intenta una sola vez (es solo una pista): `NotificationSignal` ya no es una difusión en cola, sino un
evento que el listener síncrono `BroadcastNotificationSignal` publica en el mismo worker que guardó la notificación.
Si Reverb falla o está lento, se registra un `warning`, las demás señales `.notification` de ese mismo trabajo se
omiten (como mucho un _timeout_ por trabajo) y la notificación nunca se pierde ni se reintenta por ello
([tiempo-real.md](tiempo-real.md)).

### 1.5 Interfaz

- **Campana** en el encabezado (`components/notifications/NotificationBell.vue`): contador sin leer (rojo si hay
  urgentes), lista con «Todas / Sin leer», marcar una o todas como leídas, «Ver anteriores» (paginación por fecha de
  actualización), enlace a la pantalla del registro y a las preferencias.
- **Preferencias** en _Mi cuenta › Notificaciones_ (`/settings/notifications`): por tipo, en la app, sonido y
  navegador. Solo se listan los tipos que pueden afectar al usuario por sus permisos actuales.
- **Navegador**: el permiso de notificaciones del sistema solo se solicita al pulsar «Permitir en este navegador».
  Se muestran únicamente cuando la pestaña no está visible; con la pestaña visible, los tipos que se anuncian en vivo
  (urgentes y mensajes del cliente) aparecen como aviso dentro de la página. Si ese navegador tiene una suscripción Web Push activa, la página no muestra su propio aviso
  del sistema (`useNotificationCenter`, `showUnlessPushed`): el aviso lo da el _service worker_, así que no se duplica
  pestaña + push. El sonido es un tono generado con Web Audio (sin archivos).
- **Notificaciones push**: en la misma página, «Activar en este navegador» y la lista de dispositivos suscritos
  (ver 1.8).
- Si la notificación es de la conversación que el usuario tiene abierta **en una pestaña visible y con foco**, no
  suena ni avisa: se marca leída. Si la pestaña está oculta o la ventana sin foco, avisa con normalidad y se marca
  leída al volver a ella.
- **Avisos en pantalla**: el `Toaster` del layout (`AppSidebarLayout`) queda abajo, pero por encima del compositor de
  mensajes cuando hay uno acoplado al pie (conversaciones y chat interno), en escritorio y en móvil
  (`useToastClearance`), para no tapar el cuadro de texto ni el botón de enviar. Solo cuenta un bloque con cuadro de
  texto (fuera de diálogos) cuyo borde inferior coincide con el de la ventana, con su parte superior en pantalla y
  visible en ese borde; una tarjeta que solo se desborda por debajo o una nota en edición dentro del historial no
  mueven los avisos. Se mide al terminar cada visita, al redimensionar, al desplazar y cuando el compositor cambia de
  tamaño.
- **Sin duplicados**: un elemento solo se avisa si cambia su versión (`updated_at` + `occurrences`), así que dos
  mensajes agregados en el mismo segundo también avisan y una recarga no repite avisos. La campana vive en el layout
  persistente: navegar con Inertia no duplica sus escuchas.
- **Sonido y autoplay**: los navegadores bloquean el audio hasta que la persona interactúa con la página después de
  abrirla o recargarla. El contexto de Web Audio se crea o reanuda con la primera interacción que de verdad activa la
  página (`unlockNotificationSound` escucha `pointerdown`, `pointerup`, `touchend`, `click` y `keydown`: con un toque
  la activación llega al levantar el dedo y Escape no activa). Las escuchas siguen hasta que el contexto queda en
  marcha, así que un evento sin activación solo espera al siguiente; antes de eso no se intenta sonar (sin errores) y la página de preferencias muestra un
  aviso discreto. No se garantiza sonido con la pestaña en segundo plano o con el navegador limitándola: para no
  perder avisos se recomienda activar las notificaciones del navegador o Web Push.

### 1.6 Endpoints

| Método    | Ruta                                                | Nombre                                                                         |
| --------- | --------------------------------------------------- | ------------------------------------------------------------------------------ |
| GET       | `/notifications?unread=1&before=ISO&before_id=uuid` | `notifications.index` (20 por página; cursor `next_before` + `next_before_id`) |
| GET       | `/notifications/summary`                            | `notifications.summary`                                                        |
| POST      | `/notifications/{uuid}/read`                        | `notifications.read`                                                           |
| POST      | `/notifications/read-all`                           | `notifications.read-all`                                                       |
| POST      | `/notifications/conversations/{id}/read`            | `notifications.conversations.read`                                             |
| GET / PUT | `/settings/notifications`                           | `notifications.preferences.edit` / `.update`                                   |
| POST      | `/settings/notifications/push-subscriptions`        | `notifications.push-subscriptions.store` (Web Push, ver 1.8)                   |
| DELETE    | `/settings/notifications/push-subscriptions/{id}`   | `notifications.push-subscriptions.destroy` (solo las propias; ajena = 404)     |

### 1.7 Retención

`notifications:prune` (diario 04:10): borra notificaciones leídas con más de 90 días, cualquiera sin actualizar en
180 días, recibos de deduplicación con más de 30 días y suscripciones Web Push sin registro ni envío correcto en
`WEBPUSH_RETENTION_DAYS` días (120 por defecto).

### 1.8 Web Push (avisos con la plataforma cerrada)

Web Push **no tiene relación con la recepción de WhatsApp**: los mensajes de clientes siguen llegando por el webhook
de Meta. Web Push solo despierta el navegador del empleado cuando se guarda una notificación in-app para él.

**Push sin carga útil.** El servidor envía a cada servicio push del navegador (FCM de Chrome/Edge/Opera, Mozilla
autopush, Apple, WNS) un `POST` vacío (`Content-Length: 0`) firmado con VAPID. No hay cifrado `aes128gcm` porque no
viaja contenido: el servicio push solo sabe que «hay algo nuevo». No se usa ninguna dependencia ni servicio de pago;
solo `ext-openssl` (par EC P-256 y JWT ES256 en `App\Notifications\Push\Vapid`). Encabezados:
`Authorization: vapid t=<jwt>, k=<clave pública>`, `TTL` (`WEBPUSH_TTL`, 3600 s), `Urgency` (`high` para tipos
urgentes, `normal` el resto) y `Topic: notifications` (el servicio push sustituye un aviso aún no entregado).

**Flujo.**

1. `InAppChannel` guarda la notificación y emite `NotificationSignal` después del commit (sin cambios en el canal ni
   en su transacción). El listener `QueueWebPush` comprueba que haya claves VAPID, que el usuario tenga
   suscripciones, que siga activo y que tenga «Navegador» activado para ese tipo.
2. Límite por usuario: como máximo un push cada `WEBPUSH_THROTTLE_SECONDS` (60 s) con `Cache::add` atómico. Una ráfaga
   o una notificación agregada despierta cada navegador una sola vez.
3. Un trabajo `App\Jobs\SendWebPush` por suscripción en la cola `notifications` (nunca en las críticas), con
   _timeout_ de `WEBPUSH_TIMEOUT` (5 s) y sin seguir redirecciones. 2xx actualiza `updated_at`; 404/410 borra la
   suscripción; 429/5xx o error de conexión se reintentan hasta 3 veces (respetando `Retry-After` hasta 5 min) y
   después se descartan con un aviso en el log; otros 4xx (claves VAPID incorrectas) se registran sin reintento. El
   log nunca incluye el endpoint.
4. El _service worker_ (`public/sw.js`, alcance `/`) recibe el push y pide `GET /notifications/summary` al mismo
   origen con la cookie de sesión del usuario. Muestra un texto genérico («Tienes 3 notificaciones sin leer (1
   urgente).») con _tag_ único `chat-manager-push` y `renotify: false`. Si hay una ventana de la plataforma visible y
   enfocada, no muestra nada. Si la sesión ya no es válida (401), muestra «Tienes notificaciones nuevas. Inicia
   sesión para verlas.», sin datos (Chrome exige mostrar algo por cada push). Al pulsarlo enfoca una pestaña abierta o
   abre `/dashboard`.

**Suscripciones.** Se activan solo desde _Mi cuenta › Notificaciones_ con «Activar en este navegador», que pide el
permiso (`Notification.requestPermission`) tras el clic, registra el _service worker_ y la suscripción con la clave
pública VAPID. La tabla `push_subscriptions` guarda solo `endpoint`, `endpoint_hash` (SHA-256, único), una etiqueta
corta del navegador («Chrome en Windows», nunca el User-Agent completo) y fechas; no guarda las claves `p256dh`/`auth`
porque no se cifra nada. Registrar de nuevo el mismo navegador es un _upsert_ por hash y lo deja ligado al último
usuario que lo registró. Máximo 10 navegadores por usuario (se descarta el más antiguo). Solo se aceptan endpoints
HTTPS de servicios push conocidos (`WEBPUSH_ALLOWED_HOSTS`), así el servidor nunca llama a una URL arbitraria. La
página lista los dispositivos del propio usuario (navegador y fecha), marca «este navegador» y permite eliminarlos.

**Limpieza.** Al desactivar o eliminar un usuario (`UserAccessChanged`), `RevokePushSubscriptions` borra sus
suscripciones; al restaurarlo debe volver a activarlas. `SendWebPush` descarta también la de un usuario inactivo.
`notifications:prune` borra las antiguas (1.7).

**Requisitos y configuración.**

- HTTPS (en desarrollo vale `http://localhost`). Sin contexto seguro, sin soporte del navegador o sin claves, la
  página lo explica y no ofrece activar.
- `php artisan webpush:vapid` imprime `VAPID_PUBLIC_KEY` y `VAPID_PRIVATE_KEY` (base64url); copiarlas a `.env` (el
  comando nunca escribe el archivo). `VAPID_SUBJECT`: `mailto:` o URL https del operador (vacío = `APP_URL`). La
  clave privada es secreta. Cambiar las claves invalida todas las suscripciones: cada usuario debe activarlas de nuevo.
- Navegadores: Chrome, Edge, Firefox y Opera de escritorio y Android; Safari 16.4+ en macOS. En iOS/iPadOS solo
  funciona con la plataforma instalada como app en la pantalla de inicio, lo que requiere un _manifest_ que no existe
  todavía.
- El servidor necesita salida HTTPS hacia los servicios push (`*.googleapis.com`, `*.push.services.mozilla.com`,
  `*.push.apple.com`, `*.notify.windows.com`) y la CSP ya permite `worker-src 'self'`.

## 2. Horario de atención

`App\Services\BusinessHours` evalúa en la zona horaria de la plataforma (`general.timezone`). Regla de horario
aplicable (gana la primera):

1. El horario propio del departamento si tiene «Horario propio».
2. El horario de la plataforma si «Aplicar horario de atención» está activado.
3. Si no, **atención continua** (siempre abierto).

Los **días no laborables** (`business_hours.holidays`, lista de `{date, label, recurring}`) cierran todo el día
los casos 1 y 2; los marcados «Cada año» se repiten en el mismo día y mes. En atención continua no se aplican.
Se administran en _Administración › Configuración › Días no laborables_ (ruta
`PUT /admin/settings/holidays`, permiso `settings.update`, auditado como `settings.updated`).

API: `isOpenAt(?Department, fecha)`, `nextOpening(?Department, fecha)`, `holidayOn()`, `describe()` (lo usa la ficha
del departamento: «Dentro / Fuera del horario», próxima apertura, día no laborable de hoy).

Se corrigió `PlatformSettings::update()` para detectar como cambio un elemento eliminado de una lista (antes quitar
el último día no laborable no se guardaba).

## 3. Mensajes automáticos

Configuración existente que ahora se usa:

- Conexión: `welcome_message`/`welcome_enabled`, `away_message`/`away_enabled`, `farewell_message`/`farewell_enabled`
  (se editan en la conexión).
- Departamento: `welcome_message` (existente) y `away_message` (nuevo), en la tarjeta «Mensajes automáticos».

### 3.1 Prioridades

**Saludo** cuando el _cliente_ inicia un ciclo: conversación nueva creada por un mensaje entrante, o reabierta por un
mensaje entrante dentro de la ventana de reapertura de la conexión.

1. Fuera de horario (incluye días no laborables) → mensaje fuera de horario del **departamento**; si está vacío, el
   de la **conexión** si está activado; si ninguno existe **no se envía nada** (una bienvenida que promete atención
   inmediata sería engañosa fuera de horario).
2. Dentro del horario → bienvenida del **departamento**; si está vacía, la de la **conexión** si está activada.

**Despedida** al cerrar (por agente o por inactividad): la de la conexión si está activada (los departamentos no
tienen despedida propia).

El horario evaluado es el del departamento de la conversación al momento del saludo. Un departamento eliminado o
inactivo no aporta mensajes (se usa la conexión).

### 3.2 Garantías

`App\Actions\Automation\QueueAutomaticMessage`, disparado por `App\Listeners\Automation\SendAutomaticMessages`
(cola `messages`) al recibir `ConversationChanged` con razón `created`, `reopened` o `closed`:

- **Idempotencia**: `client_reference` es un UUID v5 de `(conversación, ranura, ciclo)`; la columna es única. La
  bienvenida y el mensaje fuera de horario comparten la ranura «saludo», así que un ciclo nunca recibe ambos. El
  ciclo es `reopen_count`. Webhooks repetidos, eventos re-despachados o workers en paralelo guardan un solo mensaje.
- **Reglas de Meta**: solo texto libre dentro de la ventana de 24 h abierta, nunca plantillas, nunca fuera de la
  ventana; solo por una conexión operativa (activa y no eliminada). El job de envío (`DeliverOutboundMessage`)
  vuelve a comprobarlo.
- **Sin bucles**: solo eventos del ciclo de vida disparan mensajes automáticos; ningún mensaje (automático, saliente
  o entrante adicional) dispara otro. Una reapertura manual por un agente o una conversación creada por importación
  no envía saludo.
- **Retrasos**: si el último mensaje del cliente tiene más de 60 minutos (cola atrasada), no se saluda.
- **Trazabilidad**: el mensaje queda con `sender_user_id = null` y `metadata` = `automatic: true`,
  `automatic_kind` (`welcome|away|farewell`), `automatic_source` (`department|connection`), `cycle`,
  `trigger_event_id`. Se emite `MessageRecorded` (saliente).
- No modifica `first_response_at` ni `last_outbound_at` (no es una respuesta del equipo; la conversación sigue
  esperando atención humana). Sí avanza `last_message_at`.

## 4. Configuración de departamentos

La ficha del departamento reúne: distribución (bandeja compartida, manual o automática — sin cambios en el motor),
mensajes automáticos (bienvenida y fuera de horario), horario (de la plataforma o propio), y la tarjeta «Atención en
este momento»: estado del horario ahora, próximos días no laborables, límite global de carga por agente
(`operations.max_open_conversations_per_agent`, solo lectura con enlace a Configuración) y, por conexión activa, la
ventana de reapertura y qué mensajes automáticos tiene activos.

Ausencias: no se cambió el significado de «Disponible / No disponible». La disponibilidad solo decide la asignación
automática y, ahora, quién recibe avisos de cola.

## 5. Pendiente / limitaciones

- **Límite de carga**: resuelto en la estabilización con la precedencia agente > departamento > plataforma
  ([bandejas-y-asignacion.md §4 «Capacidad»](bandejas-y-asignacion.md#capacidad)); los avisos de cola siguen
  dirigiéndose solo a quien está Disponible.
- **Menciones del chat interno**: resueltas; el compositor y el servidor guardan `team_messages.metadata.mentions` y
  el listener envía `TeamChatMention` ([chat-interno.md](chat-interno.md#funciones)).
- **Alertas operativas** (log y correo) siguen fuera del centro de notificaciones.
- **Envío real**: los mensajes automáticos se encolan igual que los de un agente; su entrega depende del transporte
  de WhatsApp configurado (ver [mensajeria-whatsapp.md](mensajeria-whatsapp.md)). Sin credenciales reales de Meta
  la validación es con pruebas automatizadas y el transporte simulado.
- El aviso duplicado pestaña + push (pestaña abierta pero oculta) quedó resuelto: con una suscripción push activa en
  ese navegador la página no muestra su aviso del sistema y solo aparece el aviso genérico del _service worker_. En
  un navegador sin suscripción push se mantiene el aviso de la pestaña.
- iOS/iPadOS necesitaría instalar la plataforma como app (PWA con _manifest_), que no existe. El título del aviso
  push es genérico («Notificaciones nuevas») y no usa el nombre configurable de la plataforma.
- Al cerrar sesión la suscripción del navegador se conserva: los siguientes push muestran el aviso genérico sin datos.
  Cada envío correcto renueva la suscripción, así que la poda no la elimina mientras el usuario siga recibiendo
  notificaciones; se conserva hasta que se desactive desde _Preferencias_, el servicio push la invalide (404/410) o
  se desactive o elimine el usuario. En un equipo compartido, si otro usuario inicia sesión en ese navegador sin
  pulsar «Activar en este navegador», los avisos del primero muestran los contadores del usuario con la sesión
  abierta (texto genérico, sin contenido); la suscripción solo cambia de dueño cuando el segundo la activa.
- Web Push no se probó contra los servicios push reales en este entorno; la firma VAPID, los encabezados y las
  respuestas 404/410/429/5xx están cubiertos con pruebas automáticas (`tests/Feature/Notifications/WebPushTest.php`).
