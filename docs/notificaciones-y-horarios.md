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
La señal se intenta una sola vez (es solo una pista).

### 1.5 Interfaz

- **Campana** en el encabezado (`components/notifications/NotificationBell.vue`): contador sin leer (rojo si hay
  urgentes), lista con «Todas / Sin leer», marcar una o todas como leídas, «Ver anteriores» (paginación por fecha de
  actualización), enlace a la pantalla del registro y a las preferencias.
- **Preferencias** en _Mi cuenta › Notificaciones_ (`/settings/notifications`): por tipo, en la app, sonido y
  navegador. Solo se listan los tipos que pueden afectar al usuario por sus permisos actuales.
- **Navegador**: el permiso de notificaciones del sistema solo se solicita al pulsar «Permitir en este navegador».
  Se muestran únicamente cuando la pestaña no está visible; con la pestaña visible, las urgentes aparecen como aviso
  dentro de la página. El sonido es un tono generado con Web Audio (sin archivos).
- Si la notificación es de la conversación que el usuario tiene abierta, no suena ni avisa: se marca leída.

### 1.6 Endpoints

| Método    | Ruta                                                | Nombre                                                                         |
| --------- | --------------------------------------------------- | ------------------------------------------------------------------------------ |
| GET       | `/notifications?unread=1&before=ISO&before_id=uuid` | `notifications.index` (20 por página; cursor `next_before` + `next_before_id`) |
| GET       | `/notifications/summary`                            | `notifications.summary`                                                        |
| POST      | `/notifications/{uuid}/read`                        | `notifications.read`                                                           |
| POST      | `/notifications/read-all`                           | `notifications.read-all`                                                       |
| POST      | `/notifications/conversations/{id}/read`            | `notifications.conversations.read`                                             |
| GET / PUT | `/settings/notifications`                           | `notifications.preferences.edit` / `.update`                                   |

### 1.7 Retención

`notifications:prune` (diario 04:10): borra notificaciones leídas con más de 90 días, cualquiera sin actualizar en
180 días y recibos de deduplicación con más de 30 días.

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

- **Límite de carga por departamento**: no implementado; requeriría cambiar `ConversationAccess` (propiedad del
  módulo de bandeja). Queda como mejora futura; hoy solo existe el límite global.
- **Menciones del chat interno**: el chat interno todavía no registra menciones; el listener ya lee
  `team_messages.metadata.mentions` (lista de ids de usuario) en cuanto exista.
- **Envío real**: los mensajes automáticos se encolan igual que los de un agente; su entrega depende del transporte
  de WhatsApp configurado (ver [mensajeria-whatsapp.md](mensajeria-whatsapp.md)). Sin credenciales reales de Meta
  la validación es con pruebas automatizadas y el transporte simulado.
- Las notificaciones del navegador usan la API `Notification` de la pestaña abierta (no hay _service worker_ ni Web
  Push): con todas las pestañas cerradas no llegan avisos.
