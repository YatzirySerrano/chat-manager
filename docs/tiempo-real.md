# Tiempo real (Laravel Reverb + Echo)

Chat Manager actualiza bandejas, conversaciones, contadores, exportaciones e importaciones sin recargar la página.
El servidor de WebSockets es **Laravel Reverb** (protocolo Pusher) y el navegador usa **Laravel Echo** con `pusher-js`.

> Principio rector: el WebSocket **no transporta datos**, solo _señales de invalidación_ ("algo que puedes ver
> cambió"). El navegador vuelve a pedir la información al servidor, que autoriza cada petición. La base de datos es
> siempre la fuente de verdad; si una señal se pierde, la página se recupera sola (ver [Reconexión y respaldo](#reconexión-y-respaldo)).

Documentos relacionados: [bandejas-y-asignacion.md](bandejas-y-asignacion.md), [chat-interno.md](chat-interno.md),
[notificaciones-y-horarios.md](notificaciones-y-horarios.md), [infraestructura-y-despliegue.md](infraestructura-y-despliegue.md).

## Arquitectura

```
Acción de dominio (transacción) ──commit──▶ Evento de dominio (App\Events\**, ShouldDispatchAfterCommit, solo IDs)
        │
        ▼
Listener en cola app/Listeners/Realtime/* (cola dedicada "realtime", 3 intentos)
        │  lee el estado actual de la BD y decide destinatarios (App\Broadcasting\RealtimeSignals)
        ▼
RealtimeSignal (ShouldBroadcastNow) ──HTTP──▶ Reverb ──WebSocket──▶ navegadores suscritos al canal privado
                                                                    │
                                                                    ▼
                                         useLiveReload / useChannel ──▶ router.reload({ only }) o fetch autorizado
```

- Los eventos se emiten **después del commit** (`ShouldDispatchAfterCommit`): nunca se anuncia algo que luego se revierte.
- Los listeners se ejecutan **fuera de la petición**, en la cola dedicada `realtime` (`Queues::REALTIME`; nombre
  configurable con `QUEUE_NAME_REALTIME` o `REALTIME_QUEUE`, que tiene prioridad; conexión con
  `REALTIME_QUEUE_CONNECTION`). Tiene workers propios, así que nunca espera detrás de notificaciones in-app,
  métricas o exportaciones. Si `BROADCAST_CONNECTION=null` no se encolan (sin costo en instalaciones sin tiempo real).
- Las difusiones del chat interno (`TeamThreadSignal`, `TeamChatUserSignal`) usan la misma cola `realtime`.
- La señal de notificación (`NotificationSignal`) no genera un trabajo propio: `BroadcastNotificationSignal` (listener
  síncrono) la publica con `RealtimeSignals` en el mismo worker que guardó la notificación, tras el commit. Si una
  publicación falla (Reverb detenido o lento), el resto de señales `.notification` del mismo trabajo se omiten: un
  envío a muchos destinatarios cuesta como mucho un timeout del cliente de Reverb por trabajo, nunca uno por
  destinatario, y no supera el `--timeout` del worker de `notifications`. El trabajo siguiente vuelve a intentarlo; los
  usuarios omitidos ven la notificación con la actualización periódica de la campana.
- Un mensaje produce **un solo** evento de dominio (`MessageRecorded`): un trabajo de tiempo real, una señal
  `.message.recorded` para el chat abierto y una `.inbox.changed` por canal de bandeja. `ReceiveInboundMessage` y
  `QueueTemplateMessage` ya no despachan además `ConversationChanged('message')` (solo lo hace `DownloadInboundMedia`
  cuando termina una descarga, porque no hay mensaje nuevo). Los listeners en cola que no harían nada con un evento
  (`NotifyInboundMessage` con mensajes salientes, `NotifyConversationChanges` y `SendAutomaticMessages` con cambios que
  no notifican ni abren/cierran ciclo) lo descartan en `shouldQueue()` y no encolan trabajos vacíos.
- Publicar en Reverb es _best effort_: si Reverb está detenido o lento (timeout 5 s, conexión 2 s), se registra un
  `warning` sin datos sensibles y la operación de negocio no falla.

### Archivos

| Pieza                     | Ubicación                                                                                                                                                     |
| ------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Configuración             | `config/broadcasting.php` (bloque `realtime`), `config/reverb.php`                                                                                            |
| Canales y autorización    | `routes/channels.php`, `routes/channels/conversations.php`, `app/Broadcasting/*Channel.php`, `app/Broadcasting/RealtimeAudience.php`                          |
| Enrutamiento de señales   | `app/Broadcasting/RealtimeSignals.php`, `app/Events/Broadcasts/RealtimeSignal.php`                                                                            |
| Listeners                 | `app/Listeners/Realtime/*`                                                                                                                                    |
| Configuración del cliente | `app/Broadcasting/RealtimeClientConfig.php` → prop compartida `realtime` (`HandleInertiaRequests`)                                                            |
| Cliente                   | `resources/js/lib/realtime/*`, `resources/js/composables/use{Channel,LiveReload,RealtimeStatus,UserChannel}.ts`, `resources/js/components/RealtimeStatus.vue` |
| Pruebas                   | `tests/Feature/Realtime/*`                                                                                                                                    |

## Canales

Todos son **privados** (prefijo `private-` en el protocolo), salvo los dos de presencia del chat interno (prefijo
`presence-`, también autorizados por el servidor). Cada suscripción pasa por `POST /broadcasting/auth`
(middleware `web`, `auth`, `verified`, `throttle.channels:180,1`; `EnsureUserIsActive` cierra la sesión de cuentas
eliminadas). Ver [Autorización de canales en ráfaga](#autorización-de-canales-en-ráfaga-fase-51).

| Canal                              | Quién puede suscribirse (evaluado en cada suscripción)                                                                                                                                 |
| ---------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `users.{userId}`                   | Solo ese usuario, con la cuenta activa.                                                                                                                                                |
| `conversations.{conversationId}`   | Quien puede ver la conversación ahora: `ConversationAccess::canView` (permiso, alcance, departamento, asignación; las cerradas exigen el permiso de historial).                        |
| `inbox.departments.{departmentId}` | Bandeja de equipo: alcance global, o alcance de departamentos y miembro del departamento.                                                                                              |
| `inbox.queues.{departmentId}`      | Cola de pendientes: quien puede aceptar de ese departamento (`ConversationAccess::acceptableDepartmentIds`: departamento activo, distribución distinta de manual, permiso de aceptar). |
| `inbox.global`                     | Alcance global con permiso de ver conversaciones.                                                                                                                                      |
| `inbox.supervision.{departmentId}` | Fase 5.1: miembros del departamento con alcance de departamentos que lo supervisan (`RealtimeAudience::canJoinDepartmentSupervision`, `DepartmentSupervisionChannel`).                 |
| `team-chat.threads.{threadId}`     | Registrado por el módulo de chat interno en `routes/channels/team-chat.php` (ver [chat-interno.md](chat-interno.md)).                                                                  |
| `presence-team`                    | Presencia del chat interno para quien llega a todos (alcance global o `team-chat.cross-department`), con `team-chat.use` y cuenta activa. Carga `{id}`.                                |
| `presence-team.departments.{id}`   | Presencia del chat interno para los miembros de ese departamento, con `team-chat.use` y cuenta activa. Carga `{id}`.                                                                   |

La prop compartida `realtime.inbox_channels` indica qué canales de bandeja corresponden al usuario según su alcance:
global → `inbox.global`; departamentos → `inbox.departments.{id}` de sus departamentos (y, si supervisa,
`inbox.supervision.{id}` de los mismos); personal (asignado) → `inbox.queues.{id}` de las colas que puede aceptar. Sus
propias conversaciones le llegan siempre por `users.{id}`.

### Supervisión por departamentos (fase 5.1)

Quien supervisa con alcance de departamentos lee también las conversaciones que **pasaron** por sus departamentos y
hoy están en otro ([bandejas §Vista general](bandejas-y-asignacion.md#vista-general-chips-y-contadores)). Esas no
llegan por `inbox.departments.{id}` (que sigue el departamento actual), así que `RealtimeSignals::inboxChannels()`
añade `inbox.supervision.{id}` de cada departamento de origen o destino de sus eventos que tenga al menos un
supervisor con alcance de departamentos y no reciba ya la señal por su bandeja de equipo. Si ningún departamento tiene
supervisión por departamentos (caso común: solo supervisa la administración global), no se consulta nada. La lista de
departamentos supervisados se lee una vez por proceso y se reutiliza 60 s, así que un supervisor recién nombrado oye
los pasos de sus departamentos en menos de un minuto (sus propios departamentos no dependen de ello). Medición en
[rendimiento §12.4](rendimiento-y-carga.md#124-señales-de-tiempo-real-realtimesignalsinboxchannels).

Estas señales solo recargan lista y contadores: nunca producen sonido, _toast_ ni notificación
([notificaciones §Visibilidad frente a avisos](notificaciones-y-horarios.md#visibilidad-frente-a-avisos-fase-51)).

**Separación cola / equipo.** Un agente ordinario nunca escucha la bandeja de equipo: solo la cola. La cola recibe
señales únicamente de conversaciones que están en cola o que acaban de salir de ella (aceptada, asignada, transferida o
cerrada sin responsable previo). Así un agente no se entera ni siquiera del ID de la actividad de conversaciones que
atiende un compañero (política de visibilidad, sección 7 de la especificación).

### Canal del visitante del chat web

El widget del chat web usa la **misma app de Reverb** (mismo `REVERB_APP_KEY`, mismo origen de la aplicación, sin
cambios en `REVERB_ALLOWED_ORIGINS`, porque el WebSocket sale del iframe servido por Chat Manager) con un canal privado
propio por sesión: `webchat.sessions.{session_public_id}` (`private-webchat.sessions.{id}` en el protocolo).

- **Nunca se registra en `routes/channels.php` ni en `routes/channels/*.php`** (el glob de `routes/channels.php`
  cargaría cualquier archivo nuevo). Por eso el `/broadcasting/auth` de los agentes lo rechaza siempre.
- Se autoriza solo en `POST /api/v1/widget/broadcasting/auth` (`VisitorBroadcastingAuthController`): Bearer del
  visitante (`webchat.session`), `channel_name` exactamente igual al de su sesión, `socket_id` que cumpla
  `^\d+\.\d+$` (si no, 422) y firma del protocolo Pusher `hash_hmac('sha256', socket_id.':'.channel, secreto)`.
  Canal ajeno o Reverb sin clave o secreto: 403. Sin cookies ni CSRF. Límite `webchat-broadcasting` (30/min).
- Evento `App\Events\WebChat\VisitorSessionSignal`, `broadcastAs` **`webchat.signal`**, carga `{type: 'messages' |
'conversation', cursor?}`: nunca contenido. Lo emite `SignalVisitorSessions` en la cola `realtime` (patrón
  `ShouldBroadcastNow` dentro del listener, protegido con `try/catch`) cuando un saliente web pasa a `accepted`,
  `delivered` o `read`, cuando entra un mensaje del visitante (para sus otras pestañas) y cuando la conversación se
  acepta, asigna, auto-asigna, transfiere, reabre o cierra. Destino: todas las sesiones usables del mismo contacto en
  esa conexión.
- En el iframe se usa `pusher-js` directo, sin Echo (`resources/js/widget/frame/realtime.ts`), con las opciones que
  entrega `VisitorRealtimeConfig` en la sesión (`realtime.enabled = false` si `BROADCAST_CONNECTION` no es `reverb`).
- **Reconexión y respaldo:** al conectar y en cada reconexión el frame pide `GET /api/v1/widget/session/messages?after=
{último cursor}` y deduplica por cursor (una respuesta que se volvió visible tarde puede volver con `after`). Sin
  WebSocket, sondeo con ETag/`If-None-Match` (304 sin cambios) cada 4 s con la pestaña visible y 20 s oculta
  (`webchat.poll.*`); con WebSocket, una lectura de seguridad cada 60 s. Ante 429 respeta `Retry-After` y duplica la
  espera hasta 120 s; ante errores de red, igual desde 2 s. Un 401 descarta el token y crea otra sesión.
- **Cuota:** los visitantes comparten la app y su `max_connections` con los agentes; cada visitante con el widget
  abierto es una conexión. Antes de un volumen alto, valora una segunda app de Reverb para el widget
  ([infraestructura §10](infraestructura-y-despliegue.md#10-reverb), pregunta abierta).

## Eventos

Nombres tal como los escucha el cliente (`.nombre`). Las cargas útiles solo llevan IDs y metadatos mínimos: **nunca**
cuerpos de mensaje, teléfonos, texto de notas ni nombres de archivo.

| Canal                                             | Evento                                 | Carga                                                           | Origen                                                                                                                         |
| ------------------------------------------------- | -------------------------------------- | --------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| `conversations.{id}`                              | `.conversation.changed`                | `{reason, version}`                                             | `ConversationChanged` (todo `conversation_event` + `tags`, `routing`; `message` solo al terminar una descarga multimedia)      |
| `conversations.{id}`                              | `.message.recorded`                    | `{message_id, direction}`                                       | `MessageRecorded` (con `inbound` el chat recarga también la cabecera: ventana de 24 h)                                         |
| `conversations.{id}`                              | `.message.status`                      | `{message_id, status, error_code, updated_at}`                  | `MessageStatusChanged`                                                                                                         |
| bandejas + `users.{responsable}`                  | `.inbox.message_status`                | `{conversation_id, message_id, status, error_code, updated_at}` | `MessageStatusChanged`, solo si es el **último** mensaje de la conversación (`BroadcastMessageStatus`)                         |
| `conversations.{id}`                              | `.note.saved`                          | `{note_id}`                                                     | `ConversationNoteSaved`                                                                                                        |
| bandejas + `users.{id}`                           | `.inbox.changed`                       | `{conversation_id, reason}`                                     | `ConversationChanged`, `MessageRecorded` (`reason: message`)                                                                   |
| `users.{id}`                                      | `.inbox.changed`                       | `{conversation_id, reason: read}`                               | `ConversationChanged` con `reason=read` (solo al lector)                                                                       |
| `users.{id}`                                      | `.inbox.changed`                       | `{conversation_id, reason: message_failed}`                     | `MessageStatusChanged` con `failed` (al responsable)                                                                           |
| `users.{id}`                                      | `.availability.changed`                | `{availability}`                                                | `AgentAvailabilityChanged`                                                                                                     |
| `inbox.global`, `inbox.departments.{id}`          | `.availability.changed`                | `{user_id, availability}`                                       | `AgentAvailabilityChanged` (departamentos del agente)                                                                          |
| `users.{id}`                                      | `.access.changed`                      | `{reason}`                                                      | `UserAccessChanged`                                                                                                            |
| `users.{id}`                                      | `.transfer.changed`                    | `{transfer_id, type, status}`                                   | `DataTransfers\DataTransferFinished` (exportación/importación terminada; lo emite el modelo `DataTransfer` tras confirmar)     |
| `users.{id}`                                      | `.notification`                        | `{id, kind}`                                                    | `NotificationSignal` → `BroadcastNotificationSignal` (sin trabajo en cola propio)                                              |
| `users.{id}`                                      | `.team-chat.changed`                   | ver [chat-interno.md](chat-interno.md)                          | chat interno (cola `realtime`)                                                                                                 |
| `conversations.{id}`                              | `.conversation.changed`                | `{reason: connection_changed}`                                  | `ConnectionStatusChanged` / `ConnectionHealthChanged` → `BroadcastConnectionChange` (conversaciones no cerradas, lotes de 100) |
| `team-chat.threads.{id}`                          | `.typing`                              | `{user_id}` (lo pone el servidor)                               | `POST team-chat/threads/{id}/typing`: única señal síncrona, emitida en la propia petición y protegida con `rescue`             |
| `presence-team`, `presence-team.departments.{id}` | presencia (`here`/`joining`/`leaving`) | `{id}`                                                          | Canales de presencia del chat interno (ver [chat-interno.md](chat-interno.md#tiempo-real))                                     |

La señal `connection_changed` se emite al activar, desactivar, eliminar, restaurar, verificar o reemplazar las
credenciales de una conexión y cuando cambia su salud; el chat abierto recarga su estado y el compositor se bloquea o
desbloquea sin recargar la página.

Destinatarios de `.inbox.changed` por un cambio de conversación: `inbox.global`; bandeja de equipo del departamento
actual y de los departamentos de origen/destino del evento; cola del departamento si está en cola (o si acaba de salir
de ella); canal de supervisión de los demás departamentos supervisados por los que pasó (fase 5.1); y canales personales del responsable actual y de los usuarios de origen/destino (transferencias,
reasignaciones). Las notas solo refrescan la conversación; `outbound_blocked` solo la conversación y su responsable.

`.inbox.message_status` (fase 5) corrige el icono de entrega de la fila de la bandeja sin recargar la lista. Se envía
a la misma audiencia que `.inbox.changed` (`RealtimeSignals::inboxMessageStatus()` reutiliza `inboxChannels()` y añade el
canal personal del responsable, en una sola publicación) y solo cuando el mensaje es el último de la conversación
(comprobación `EXISTS id > ?` sobre el índice `messages(conversation_id, id)`): los mensajes anteriores no aparecen en
la fila. `error_code` solo acompaña a `failed` (p. ej. `delivery_unknown`); `updated_at` versiona el estado. El
servidor ya garantiza estados monótonos e idempotentes (`RecordMessageStatus`, `DeliverOutboundMessage`); el cliente
(`useInboxMessageStatus.ts` + `canAdvanceDeliveryState()` en `messageFormat.ts`, con el `STATUS_RANK` compartido con el
chat) aplica una actualización solo si avanza el estado mostrado, nunca muestra «Enviado» antes de que el servidor lo
confirme, y la olvida cuando una recarga trae ese estado o uno más nuevo (`last_message.updated_at`). El chat abierto
reenvía su `.message.status` al mismo almacén, así que fila y chat coinciden. No se añadió ningún sondeo.

Prueba manual (no hay pruebas JS unitarias en el proyecto): con Reverb activo, abre la bandeja en dos navegadores (agente
responsable y supervisor), envía una respuesta y observa que el icono de la fila pasa de «Enviando» a «Enviado»,
«Entregado» y «Leído» sin recargar en ambos; repite con un webhook duplicado o desordenado (`read` y luego `delivered`)
y comprueba que el icono no retrocede; con la conexión de tiempo real caída, la lista se pone al día con su recarga de
respaldo.

### Puntos de emisión de `UserAccessChanged`

| Acción                                                  | `reason`                                                | Afectados                   |
| ------------------------------------------------------- | ------------------------------------------------------- | --------------------------- |
| `UpdateUser` (rol o departamentos)                      | `role_changed` / `departments_changed`                  | el usuario                  |
| `DeleteUser` / `RestoreUser`                            | `deleted` / `restored`                                  | el usuario                  |
| `SaveRole` (actualización con cambios)                  | `role_updated`                                          | todos los titulares del rol |
| `AddDepartmentMember` / `RemoveDepartmentMember`        | `department_member_added` / `department_member_removed` | el miembro                  |
| `DeleteDepartment` / `RestoreDepartment`                | `department_deleted` / `department_restored`            | miembros                    |
| `UpdateDepartmentDistribution` (entra o sale de manual) | `department_distribution_changed`                       | miembros                    |

`DeleteRole`/`RestoreRole` no emiten: un rol solo se elimina sin titulares. La membresía del chat interno la emite su
módulo. Editar solo nombre, correo o contraseña no cambia el acceso y no emite nada.

## Seguridad

- Canales privados con autorización real en cada suscripción, sobre el usuario **leído de nuevo** de la base de datos
  (rol, permisos, departamentos, estado). Una cuenta eliminada se rechaza aunque la sesión tenga un objeto antiguo.
- Las reglas son las mismas de páginas y consultas (`ConversationAccess`); no hay reglas paralelas.
- Al cambiar permisos o membresías, el servidor envía `.access.changed`: el cliente recarga la página completa (si ya no
  puede verla, el servidor lo redirige; si la cuenta se eliminó, `EnsureUserIsActive` cierra la sesión) y **vuelve a
  autorizar todos sus canales**; los que ya no le corresponden son rechazados (403).
- Fase 5.1: `SaveRole` vacía la caché de permisos **antes** de emitir la señal (tras el commit), así que la recarga ya
  lee los permisos nuevos. Tras la recarga, `usePermissionRefresh.ts` (montado una vez en el layout autenticado) compara
  rol, alcance y permisos efectivos; si cambiaron, descarta las páginas precargadas en memoria (`router.flushAll()`, que
  traían los permisos y acciones anteriores) y muestra un único aviso discreto «Tus permisos se actualizaron». Nunca
  recarga en bucle. El servidor rechaza cualquier acción revocada aunque la página aún no se haya recargado.
- Limitación del protocolo: Pusher/Reverb no permite expulsar a un suscriptor desde el servidor. Entre el cambio y la
  re-autorización (normalmente < 1 s) el cliente podría recibir señales con IDs; nunca datos, porque toda lectura se
  vuelve a autorizar. Si una conversación se transfiere fuera del alcance de quien la mira, la recarga provocada por
  `.conversation.changed` lo saca de la página y el canal se abandona.
- La clave de aplicación (`REVERB_APP_KEY`) es pública por diseño; el secreto solo vive en el servidor y firma las
  autorizaciones. Nunca se envía al navegador (prueba `SharedRealtimeConfigTest`).
- `allowed_origins` se limita al host de `APP_URL` (o `REVERB_ALLOWED_ORIGINS`); los eventos de cliente ("whispers")
  están desactivados (`REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM=none`) y el límite de mensajes por conexión está activo.
- CSRF: la autorización usa la cookie de sesión y el encabezado `X-XSRF-TOKEN`.
- El canal del visitante del chat web tiene su propio endpoint de autorización con Bearer y sin cookies; el de agentes
  nunca lo conoce (ver [Canal del visitante](#canal-del-visitante-del-chat-web)).

### Autorización de canales en ráfaga (fase 5.1)

Un supervisor con alcance de departamentos tiene dos canales por departamento, y el cliente autoriza todos sus canales
a la vez al conectar o tras `.access.changed`. Con el almacén de límites en base de datos (`cache.limiter` vacío →
`CACHE_STORE=database`), esas peticiones paralelas competían por la misma fila del contador y algunas fallaban con
_deadlock_ (500), dejando canales sin suscribir.

- **Servidor:** `throttle.channels` (`app/Http/Middleware/ThrottleChannelAuthorization.php`) cuenta por ruta como
  `throttle`, pero con `App\Support\ConcurrencySafeRateLimiter`, que reintenta los _deadlocks_ del almacén y, si
  persisten, deja pasar la petición en lugar de responder 500. Es inocuo aquí: el endpoint exige sesión verificada y
  activa y cada canal se autoriza con su propia regla; el límite solo frena tormentas de reconexión. El inicio de sesión
  y los demás límites conservan el limitador estricto.
- **Cliente** (`resources/js/lib/realtime/`): como máximo 3 autorizaciones simultáneas; ante 429, 5xx o falta de red
  reintenta hasta 3 veces con espera creciente y aleatoria (0,5 s, 1,5 s y 4 s ±25 %) o el `Retry-After` del servidor
  (máximo 10 s). Un 403 se respeta como rechazo.
- Con Redis disponible se recomienda `CACHE_LIMITER=redis` en todo entorno
  ([infraestructura §Fase 5.1](infraestructura-y-despliegue.md#procedimiento-de-la-fase-51)).
- Prueba: `tests/Feature/Realtime/ChannelAuthorizationThrottleTest.php`.

## Reconexión y respaldo

`useLiveReload({ channels, only, fallbackIntervalMs = 20000, safetyIntervalMs = 120000, debounceMs = 400, enabled, filter })`:

1. Cada señal programa **una** recarga parcial con _debounce_; señales durante una recarga en curso provocan una sola
   recarga adicional al terminar (no hay ráfagas ni duplicados).
2. Si la conexión no está disponible, el tiempo real está deshabilitado o no hay canales, la página se actualiza cada
   `fallbackIntervalMs`. El indicador del encabezado muestra **"Tiempo real no disponible — actualizando cada N s"**.
3. Al reconectar (o tras re-autorizar canales) recarga una vez para recuperar señales perdidas.
4. Con conexión, una recarga de seguridad lenta (`safetyIntervalMs`) evita depender solo del WebSocket.
5. Pestañas ocultas no recargan; al volver a mostrarse se ponen al día una vez.

Estados (`useRealtimeStatus().state`): `connecting`, `connected`, `unavailable`, `disabled`. Con `disabled`
(`BROADCAST_CONNECTION` distinto de `reverb`) el indicador no se muestra y las páginas usan el respaldo periódico.

API para otros módulos: `useChannel(nombre | () => nombre, { '.evento': handler })`, `useUserChannel(handlers)`,
`userChannelName()`, `inboxChannelNames()`, `useLiveReload(...)`. Desde PHP: `RealtimeSignals::toUsers()`,
`send()`, `conversationChanged()`, `inboxChanged()`.

Páginas migradas: `contacts/Index` (antes sondeo de 30 s), `exports/Index` (antes 3 s mientras había pendientes),
`contacts/Import` (el progreso por filas sigue con sondeo cada 2 s porque no hay señal por lote; el resultado final llega
por `.transfer.changed`). La bandeja (`conversations/*`) la integra su módulo.

## Configuración

Variables (ver `.env.example`):

| Variable                                                                                 | Uso                                                                                                                                                                                                                             |
| ---------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `BROADCAST_CONNECTION=reverb`                                                            | Activa el tiempo real (con `log`/`null` funciona con actualización periódica).                                                                                                                                                  |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`                                   | Credenciales propias de cada instalación. Generarlas: `php -r 'echo bin2hex(random_bytes(16)), PHP_EOL;'`.                                                                                                                      |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`                                            | Dirección con la que **la aplicación** publica en Reverb (normalmente `127.0.0.1:8080`, `http`).                                                                                                                                |
| `REVERB_SERVER_HOST`, `REVERB_SERVER_PORT`                                               | Dónde escucha el proceso Reverb (`127.0.0.1` detrás de un proxy).                                                                                                                                                               |
| `REVERB_CLIENT_HOST`, `REVERB_CLIENT_PORT`, `REVERB_CLIENT_SCHEME`, `REVERB_CLIENT_PATH` | Dirección pública para **el navegador** (p. ej. `chat.empresa.com`, `443`, `https`). Por omisión usa `REVERB_HOST/PORT/SCHEME`. Se entrega por la prop `realtime`, no por `VITE_*`: el mismo build sirve en todos los entornos. |
| `REVERB_ALLOWED_ORIGINS`                                                                 | Hosts permitidos separados por comas.                                                                                                                                                                                           |
| `REALTIME_QUEUE`, `REALTIME_QUEUE_CONNECTION`                                            | Cola de las señales (por omisión `realtime`, o `QUEUE_NAME_REALTIME`) y conexión de los listeners (por omisión la de `QUEUE_CONNECTION`; otra conexión necesita su propio worker).                                              |
| `REVERB_SCALING_ENABLED`, `REVERB_SCALING_CHANNEL`, `REDIS_*`                            | Escalado horizontal (ver abajo).                                                                                                                                                                                                |

### Desarrollo local

`.env` local usa `BROADCAST_CONNECTION=reverb`, Reverb en `127.0.0.1:8080`, credenciales aleatorias locales
(`.env` no se versiona). `composer run dev` ya incluye el proceso `reverb` (Reverb lo registra) y un proceso `queue`
que escucha todas las colas en orden de prioridad (`QueueMonitor::queueNames()`), `realtime` incluida (ver
[infraestructura-y-despliegue.md](infraestructura-y-despliegue.md)). `REALTIME_QUEUE_CONNECTION=sync` ejecuta los
listeners de señales al momento (solo para desarrollo). Sin worker, la página sigue funcionando con la recarga de
seguridad y la recarga al enfocar la ventana.

### Pruebas

`phpunit.xml` mantiene `BROADCAST_CONNECTION=null`. Las pruebas de `tests/Feature/Realtime` activan Reverb con
credenciales desechables y un puerto donde nadie escucha: la autorización se firma localmente y la publicación falla
rápido (se verifica que no rompe la operación). E2E: `E2E_REVERB=1 php tests/e2e/run.php escenario.mjs` arranca Reverb
en `127.0.0.1:8081` (`E2E_REVERB_PORT`) con credenciales generadas para esa ejecución y listeners síncronos, y lo
detiene al terminar.

## Producción

### Proceso supervisado

```ini
[program:chatmanager-reverb]
command=php /var/www/chat-manager/artisan reverb:start --host=127.0.0.1 --port=8080
user=www-data
autostart=true
autorestart=true
stopsignal=TERM
stopwaitsecs=10
minfds=10000
stdout_logfile=/var/log/chatmanager/reverb.log
redirect_stderr=true
```

- Tras desplegar: `php artisan reverb:restart` (cierre ordenado; supervisor lo vuelve a levantar).
- Las señales se procesan en la cola `realtime`, que atiende su propio grupo de workers (`chatmanager-queue-realtime`
  en `deploy/supervisor/chat-manager.conf`, `chat-manager-worker@realtime-N` en systemd): 2 procesos,
  `--sleep=1 --tries=3 --backoff=2 --timeout=60`. `--timeout=60` cubre varias publicaciones por trabajo (timeout de
  Reverb 5 s + conexión 2 s cada una) y queda por debajo de `retry_after` (900 s).
- Topología de colas (un grupo de workers por cola, prioridad de mayor a menor):

    | Grupo           | Cola                | Procesos | Uso                                                                    |
    | --------------- | ------------------- | -------- | ---------------------------------------------------------------------- |
    | `critical`      | `messages,webhooks` | 4        | Mensajes salientes y procesamiento del webhook (recepción)             |
    | `realtime`      | `realtime`          | 2        | Señales de tiempo real (mensaje nuevo, bandejas, chat interno)         |
    | `notifications` | `notifications`     | 2        | Notificaciones in-app y administrativas (con su señal `.notification`) |
    | `media`         | `media`             | 2        | Descargas y subidas de multimedia                                      |
    | `default`       | `default`           | 1        | Métricas, sincronización de plantillas, mantenimiento                  |
    | `exports`       | `exports`           | 1        | Exportaciones Excel/PDF, importaciones (baja prioridad, timeout largo) |

    Los workers no fijan conexión (`queue:work --queue=...`): usan `QUEUE_CONNECTION` (`database` o `redis`).
    `tests/Feature/Integration/QueueTopologyTest.php` comprueba, a partir de `App\Support\Queues::all()`, que cada cola
    tiene worker en ambas plantillas, que las críticas no comparten worker con trabajo pesado, que ningún worker fija
    conexión, que `--timeout` < `retry_after` y que Supervisor reintenta el arranque (`startretries=20`). Las unidades
    systemd arrancan después de MariaDB/Redis (`After=`/`Wants=`) y no dejan de reintentar (`StartLimitIntervalSec=0`).

- El polling es solo respaldo: las páginas lo usan cuando el WebSocket no está disponible (20 s) y como recarga de
  seguridad lenta con conexión (120 s); el mecanismo principal son las señales.
- Límite de descriptores: cada conexión usa uno; ajustar `ulimit -n`/`minfds`. Para más de ~1 000 conexiones
  simultáneas instalar la extensión `ev` (`pecl install ev`), que Reverb usa automáticamente.

### Proxy inverso (nginx)

```nginx
# Mismo dominio que la aplicación; los navegadores conectan a wss://chat.empresa.com/app/{key}
location ^~ /app/ {  # ^~ y la barra final: no capturar /apple-touch-icon.png
    proxy_http_version 1.1;
    proxy_set_header Host $http_host;
    proxy_set_header Scheme $scheme;
    proxy_set_header SERVER_PORT $server_port;
    proxy_set_header REMOTE_ADDR $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "Upgrade";
    proxy_read_timeout 120s;
    proxy_pass http://127.0.0.1:8080;
}
# La API HTTP de Reverb (/apps/...) solo la usa la aplicación desde el propio servidor: no exponerla.
```

Con este esquema: `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8080`, `REVERB_SCHEME=http` (publicación interna) y
`REVERB_CLIENT_HOST=chat.empresa.com`, `REVERB_CLIENT_PORT=443`, `REVERB_CLIENT_SCHEME=https`,
`REVERB_ALLOWED_ORIGINS=chat.empresa.com`. Puertos: 8080 solo en loopback; 443 público vía nginx.

### Escalado

- Varios servidores Reverb detrás de un balanceador: `REVERB_SCALING_ENABLED=true` y Redis compartido
  (`REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `REVERB_SCALING_CHANNEL`). Reverb reparte los mensajes entre
  instancias mediante pub/sub de Redis. El balanceador debe soportar WebSockets (no requiere afinidad de sesión).
- La autorización de canales la atienden los servidores web normales (sin estado).
- Carga: cada cambio genera un trabajo en cola y una o dos publicaciones HTTP a Reverb con hasta 100 canales cada una.
  No se publican cifras de capacidad sin medición (ver pruebas de carga en la documentación de pruebas).

## Limitaciones conocidas

- Presencia del chat interno: el cliente se une con `joinPresence()` (conteo de referencias, se vuelve a unir al
  reconectar) a los canales que publica `realtime.presence_channels`; la carga útil es solo `{id}` y cada canal reúne
  únicamente a personas que pueden chatear entre sí. Es por pestaña conectada y Reverb tarda unos segundos en notar
  una desconexión abrupta; quien llega a todos no ve en línea a usuarios departamentales de otros departamentos
  ([chat-interno.md](chat-interno.md#limitaciones-y-pendientes)).
- «Escribiendo…» es una señal del servidor, no un _whisper_: Reverb sigue sin aceptar eventos de cliente
  ([ADR-TC-4](decisiones.md#adr-tc-4--escribiendo-por-señal-efímera-del-servidor-en-lugar-de-whisper)). Un fallo de
  Reverb se ignora (la petición responde 204), pero un Reverb lento puede retrasar esa petición hasta el _timeout_ del
  cliente HTTP del broadcaster.
- Web Push es independiente del WebSocket: despierta el navegador cuando se guarda una notificación in-app aunque no
  haya pestañas abiertas ([notificaciones-y-horarios.md §1.8](notificaciones-y-horarios.md#18-web-push-avisos-con-la-plataforma-cerrada)).
  Si el navegador tiene una suscripción push activa, la página no muestra además su propio aviso del sistema.
- La revocación de acceso es por re-autorización del cliente (ver Seguridad); el protocolo no permite expulsión forzada.
- Las liberaciones masivas (`ReleaseAssignedConversations`, al eliminar un usuario, cambiarle el rol, retirarlo de un
  departamento o quitar «responder» a su rol) registran cada evento con `ConversationLifecycle::record()`, así que
  emiten las mismas señales que una operación individual.
- Las señales de este módulo y la de notificaciones se publican en modo _best effort_ (con Reverb detenido se registra
  un `warning` y la notificación queda guardada). Las del chat interno (`TeamThreadSignal`, `TeamChatUserSignal`)
  siguen siendo difusiones en cola (cola `realtime`): con Reverb detenido fallan como trabajos (log de nivel ERROR,
  que incluye la URL de publicación y la clave pública, nunca el secreto) y pueden activar la alerta de trabajos
  fallidos. `REALTIME_QUEUE_CONNECTION` solo aplica a los listeners; esas difusiones usan la conexión por defecto.
- Tras reiniciar Reverb, pusher-js tarda unos 25 s en reconectar (medido en `tests/e2e/realtime.mjs`); mientras tanto
  las páginas usan el sondeo de 20 s y al reconectar se ponen al día sin duplicados.
- Latencia medida antes de separar las colas: en `realtime.mjs` (listeners síncronos) las señales de bandeja llegaban en
  < 0,2 s y las del chat interno y notificaciones, por la cola `notifications`, en 0,3–0,6 s; en ráfagas grandes, con
  señales y notificaciones compartiendo 2 workers, llegaron con 15–35 s de retraso
  ([rendimiento-y-carga.md](rendimiento-y-carga.md)). Esas cifras son anteriores a la cola `realtime`.
- Medición local tras separar las colas (2026-10-22, portátil compartido, colas en base de datos; detalle en
  [rendimiento-y-carga.md §10](rendimiento-y-carga.md)), contada desde el POST del webhook (cota superior del objetivo):
  a 10 webhooks/s, `.message.recorded` p95 1,88 s y `.notification` p95 1,79 s (se cumplen los objetivos); en la ráfaga
  de 3 000 webhooks, `.message.recorded` p95 2,46 s (antes ~7 s) y `.notification` p95 7,97 s, máximo 31 s (no se
  cumplen en ráfaga). El suelo de ~0,3–1 s procede de las colas en base de datos con `--sleep=1`. No son cifras del VPS.
- Objetivos de latencia (metas de diseño), desde el
  commit del cambio hasta que el navegador recibe la señal, con los workers del grupo `realtime` activos y sin
  acumulación: mensaje nuevo del cliente (`.message.recorded` / `.inbox.changed`) p95 ≤ 2 s; cambios de bandeja y chat
  interno p95 ≤ 2 s; campana de notificaciones (`.notification`) p95 ≤ 5 s, porque depende además de la cola
  `notifications`. La página de Sistema avisa cuando el trabajo más antiguo de `realtime` espera ≥ 30 s (crítico
  ≥ 120 s; `config/operations.php`, `queues.overrides.realtime`). Sin señal, el respaldo garantiza como máximo
  20 s (sin conexión) o 120 s (recarga de seguridad).
- El progreso por filas de las importaciones sigue usando sondeo mientras se ejecutan.
