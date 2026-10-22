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
Listener en cola app/Listeners/Realtime/* (cola "notifications", 3 intentos)
        │  lee el estado actual de la BD y decide destinatarios (App\Broadcasting\RealtimeSignals)
        ▼
RealtimeSignal (ShouldBroadcastNow) ──HTTP──▶ Reverb ──WebSocket──▶ navegadores suscritos al canal privado
                                                                    │
                                                                    ▼
                                         useLiveReload / useChannel ──▶ router.reload({ only }) o fetch autorizado
```

- Los eventos se emiten **después del commit** (`ShouldDispatchAfterCommit`): nunca se anuncia algo que luego se revierte.
- Los listeners se ejecutan **fuera de la petición**, en la cola `notifications` (configurable con `REALTIME_QUEUE` /
  `REALTIME_QUEUE_CONNECTION`). Si `BROADCAST_CONNECTION=null` no se encolan (sin costo en instalaciones sin tiempo real).
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

Todos son **privados** (prefijo `private-` en el protocolo). Cada suscripción pasa por `POST /broadcasting/auth`
(middleware `web`, `auth`, `verified`, `throttle:180,1`; `EnsureUserIsActive` cierra la sesión de cuentas eliminadas).

| Canal                              | Quién puede suscribirse (evaluado en cada suscripción)                                                                                                                                 |
| ---------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `users.{userId}`                   | Solo ese usuario, con la cuenta activa.                                                                                                                                                |
| `conversations.{conversationId}`   | Quien puede ver la conversación ahora: `ConversationAccess::canView` (permiso, alcance, departamento, asignación; las cerradas exigen el permiso de historial).                        |
| `inbox.departments.{departmentId}` | Bandeja de equipo: alcance global, o alcance de departamentos y miembro del departamento.                                                                                              |
| `inbox.queues.{departmentId}`      | Cola de pendientes: quien puede aceptar de ese departamento (`ConversationAccess::acceptableDepartmentIds`: departamento activo, distribución distinta de manual, permiso de aceptar). |
| `inbox.global`                     | Alcance global con permiso de ver conversaciones.                                                                                                                                      |
| `team-chat.threads.{threadId}`     | Registrado por el módulo de chat interno en `routes/channels/team-chat.php` (ver [chat-interno.md](chat-interno.md)).                                                                  |

La prop compartida `realtime.inbox_channels` indica qué canales de bandeja corresponden al usuario según su alcance:
global → `inbox.global`; departamentos → `inbox.departments.{id}` de sus departamentos; personal (asignado) →
`inbox.queues.{id}` de las colas que puede aceptar. Sus propias conversaciones le llegan siempre por `users.{id}`.

**Separación cola / equipo.** Un agente ordinario nunca escucha la bandeja de equipo: solo la cola. La cola recibe
señales únicamente de conversaciones que están en cola o que acaban de salir de ella (aceptada, asignada, transferida o
cerrada sin responsable previo). Así un agente no se entera ni siquiera del ID de la actividad de conversaciones que
atiende un compañero (política de visibilidad, sección 7 de la especificación).

## Eventos

Nombres tal como los escucha el cliente (`.nombre`). Las cargas útiles solo llevan IDs y metadatos mínimos: **nunca**
cuerpos de mensaje, teléfonos, texto de notas ni nombres de archivo.

| Canal                                    | Evento                                | Carga                                       | Origen                                                                                                                     |
| ---------------------------------------- | ------------------------------------- | ------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| `conversations.{id}`                     | `.conversation.changed`               | `{reason, version}`                         | `ConversationChanged` (todo `conversation_event` + `tags`, `routing`, `message`)                                           |
| `conversations.{id}`                     | `.message.recorded`                   | `{message_id, direction}`                   | `MessageRecorded`                                                                                                          |
| `conversations.{id}`                     | `.message.status`                     | `{message_id, status}`                      | `MessageStatusChanged`                                                                                                     |
| `conversations.{id}`                     | `.note.saved`                         | `{note_id}`                                 | `ConversationNoteSaved`                                                                                                    |
| bandejas + `users.{id}`                  | `.inbox.changed`                      | `{conversation_id, reason}`                 | `ConversationChanged`, `MessageRecorded` (`reason: message`)                                                               |
| `users.{id}`                             | `.inbox.changed`                      | `{conversation_id, reason: read}`           | `ConversationChanged` con `reason=read` (solo al lector)                                                                   |
| `users.{id}`                             | `.inbox.changed`                      | `{conversation_id, reason: message_failed}` | `MessageStatusChanged` con `failed` (al responsable)                                                                       |
| `users.{id}`                             | `.availability.changed`               | `{availability}`                            | `AgentAvailabilityChanged`                                                                                                 |
| `inbox.global`, `inbox.departments.{id}` | `.availability.changed`               | `{user_id, availability}`                   | `AgentAvailabilityChanged` (departamentos del agente)                                                                      |
| `users.{id}`                             | `.access.changed`                     | `{reason}`                                  | `UserAccessChanged`                                                                                                        |
| `users.{id}`                             | `.transfer.changed`                   | `{transfer_id, type, status}`               | `DataTransfers\DataTransferFinished` (exportación/importación terminada; lo emite el modelo `DataTransfer` tras confirmar) |
| `users.{id}`                             | `.notification`, `.team-chat.changed` | ver módulos dueños                          | notificaciones / chat interno                                                                                              |

Destinatarios de `.inbox.changed` por un cambio de conversación: `inbox.global`; bandeja de equipo del departamento
actual y de los departamentos de origen/destino del evento; cola del departamento si está en cola (o si acaba de salir
de ella); y canales personales del responsable actual y de los usuarios de origen/destino (transferencias,
reasignaciones). Las notas solo refrescan la conversación; `outbound_blocked` solo la conversación y su responsable.

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
- Limitación del protocolo: Pusher/Reverb no permite expulsar a un suscriptor desde el servidor. Entre el cambio y la
  re-autorización (normalmente < 1 s) el cliente podría recibir señales con IDs; nunca datos, porque toda lectura se
  vuelve a autorizar. Si una conversación se transfiere fuera del alcance de quien la mira, la recarga provocada por
  `.conversation.changed` lo saca de la página y el canal se abandona.
- La clave de aplicación (`REVERB_APP_KEY`) es pública por diseño; el secreto solo vive en el servidor y firma las
  autorizaciones. Nunca se envía al navegador (prueba `SharedRealtimeConfigTest`).
- `allowed_origins` se limita al host de `APP_URL` (o `REVERB_ALLOWED_ORIGINS`); los eventos de cliente ("whispers")
  están desactivados (`REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM=none`) y el límite de mensajes por conexión está activo.
- CSRF: la autorización usa la cookie de sesión y el encabezado `X-XSRF-TOKEN`.

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
| `REALTIME_QUEUE`, `REALTIME_QUEUE_CONNECTION`                                            | Cola/conexión de los listeners (por omisión `notifications` en la conexión por defecto).                                                                                                                                        |
| `REVERB_SCALING_ENABLED`, `REVERB_SCALING_CHANNEL`, `REDIS_*`                            | Escalado horizontal (ver abajo).                                                                                                                                                                                                |

### Desarrollo local

`.env` local usa `BROADCAST_CONNECTION=reverb`, Reverb en `127.0.0.1:8080`, credenciales aleatorias locales
(`.env` no se versiona). `composer run dev` ya incluye el proceso `reverb` (Reverb lo registra) y un proceso `queue`
que escucha todas las colas, `notifications` incluida (ver
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
- Las señales se procesan en la cola `notifications`, que atiende el grupo `general` de
  `deploy/supervisor/chat-manager.conf` (`--queue=notifications,default`); ver
  [infraestructura-y-despliegue.md](infraestructura-y-despliegue.md) para el resto de colas.
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

- Presencia: el servidor autoriza `presence-team` (módulo de chat interno), pero `resources/js/lib/realtime` no ofrece
  unirse a canales de presencia, así que ningún cliente lo usa; tampoco hay indicador "escribiendo" (ambos opcionales
  en el contrato).
- La revocación de acceso es por re-autorización del cliente (ver Seguridad); el protocolo no permite expulsión forzada.
- Las liberaciones masivas (`ReleaseAssignedConversations`, al eliminar un usuario, cambiarle el rol, retirarlo de un
  departamento o quitar «responder» a su rol) registran cada evento con `ConversationLifecycle::record()`, así que
  emiten las mismas señales que una operación individual.
- Solo las señales de este módulo se publican en modo _best effort_. Las del chat interno (`TeamThreadSignal`,
  `TeamChatUserSignal`) y la de notificaciones (`NotificationSignal`) son difusiones en cola: con Reverb detenido
  fallan como trabajos (log de nivel ERROR, que incluye la URL de publicación y la clave pública, nunca el secreto) y
  pueden activar la alerta de trabajos fallidos.
- Tras reiniciar Reverb, pusher-js tarda unos 25 s en reconectar (medido en `tests/e2e/realtime.mjs`); mientras tanto
  las páginas usan el sondeo de 20 s y al reconectar se ponen al día sin duplicados.
- Latencia: en `realtime.mjs` (listeners de este módulo síncronos) las señales de bandeja llegan en < 0,2 s y las del
  chat interno y notificaciones, que siempre pasan por la cola `notifications`, en 0,3–0,6 s. En producción todas pasan
  por esa cola; en ráfagas grandes con 2 workers llegaron con 15–35 s de retraso
  ([rendimiento-y-carga.md](rendimiento-y-carga.md)).
- El progreso por filas de las importaciones sigue usando sondeo mientras se ejecutan.
