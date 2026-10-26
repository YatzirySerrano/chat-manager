# Arquitectura de Chat Manager

Visión transversal del sistema: piezas, módulos, modelo de datos, flujos de mensajes, estados, disponibilidad,
colas y tareas programadas. El detalle de cada área está en su documento (ver [README](README.md)); aquí solo se
resume y se enlaza, sin repetirlo. Los números de sección siguen los temas de la documentación obligatoria (1–5, 8 y
11); la sección 12 resume colas y programador y la 13, la ayuda por módulo, el lenguaje claro y la bitácora.

> **Estado de validación:** la integración con WhatsApp Cloud API **no se ha probado contra Meta real**. Los flujos de
> este documento se verificaron con webhooks firmados de prueba, el transporte simulado y pruebas contractuales
> (`Http::fake`). Ver [limitaciones.md](limitaciones.md).

## 1. Arquitectura general

Chat Manager es una bandeja multiagente para WhatsApp Business sobre la **API oficial de WhatsApp Cloud de Meta** (sin
WhatsApp Web ni QR) y, desde la fase 4, para un segundo canal, el **Chat web** (widget insertable en sitios externos,
[canal-chat-web.md](canal-chat-web.md)), con una API de integración `/api/v1` ([api-v1.md](api-v1.md)). Los dos canales
comparten el mismo motor: `ReceiveInboundMessage` para la entrada y `DeliverOutboundMessage` con
`ChannelTransports::for($connection)` (WhatsApp → `WhatsAppTransport`, Chat web → `WebChatTransport`) para la salida.
Es una sola aplicación Laravel (monolito modular):

| Capa                | Tecnología                                                                                                                                        |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| Servidor            | Laravel 13, PHP 8.4, Fortify (autenticación y 2FA), spatie/laravel-permission (roles y permisos dinámicos)                                        |
| Interfaz            | Inertia v3 + Vue 3 + TypeScript, Tailwind CSS 4, Wayfinder (rutas tipadas en `resources/js/actions` y `resources/js/routes`)                      |
| Base de datos       | MariaDB 11.4 (`chatmanager`; las pruebas usan `chatmanager_testing`)                                                                              |
| Colas               | Driver `database` por defecto (Redis preparado, ver [infraestructura](infraestructura-y-despliegue.md#9-colas-y-workers))                         |
| Tiempo real         | Laravel Reverb (protocolo Pusher) + Laravel Echo, solo señales de invalidación ([tiempo-real.md](tiempo-real.md))                                 |
| Archivos            | Discos privados (`storage/app/private` o S3 privado), servidos solo por controladores autorizados                                                 |
| Exportaciones       | PhpSpreadsheet (XLSX) y Browsershot + Chromium (PDF) ([reportes-y-exportaciones.md](reportes-y-exportaciones.md#6-exportaciones-motor-existente)) |
| Producción prevista | Nginx + PHP-FPM + Supervisor (workers, Reverb, programador) en un VPS Linux ([infraestructura](infraestructura-y-despliegue.md))                  |

```
              Internet                                    Navegadores de empleados
                 │                                           │  HTTPS (Inertia)   │ WSS
   Meta ── POST /webhooks/whatsapp/{clave} ──┐               ▼                    ▼
   (firma HMAC)                              │        ┌────────────┐       ┌─────────┐
                                             └──────▶ │  Laravel   │──HTTP▶│ Reverb  │
                                                      │ (PHP-FPM)  │       └─────────┘
                                                      └─────┬──────┘            ▲
                                       transacción + evento │ después del commit │ señales (solo IDs)
                                                            ▼                    │
                                  ┌──────────────── MariaDB ───────────────┐     │
                                  │ datos de negocio + jobs + caché/sesión │     │
                                  └──────────────────┬─────────────────────┘     │
                                                     ▼                           │
      Workers: messages,webhooks │ realtime │ notifications │ media │ default │ exports
                  │                   └──────────────────────────────────────┘ (señales)
                  ├── Graph API de Meta (envíos, multimedia, plantillas)
                  └── servicios Web Push del navegador (POST vacío firmado con VAPID, cola notifications)
```

Principios que atraviesan todos los módulos:

- **La base de datos es la fuente de verdad.** Toda operación de negocio ocurre en una transacción; los eventos de
  dominio (`App\Events\**`) implementan `ShouldDispatchAfterCommit` y solo llevan IDs.
- **Nada costoso en la petición.** El webhook guarda el cuerpo y responde 200; envíos, descargas, notificaciones,
  señales, métricas y exportaciones van a colas con intentos y esperas acotados.
- **Nunca reenviar lo que Meta pudo haber aceptado** ([ADR-META-3](decisiones.md#adr-meta-3--nunca-reenviar-automáticamente-lo-que-meta-pudo-aceptar)).
- **Una sola regla de visibilidad** (`App\Services\Conversations\ConversationAccess`) usada por páginas, consultas,
  contadores, adjuntos, canales, exportaciones, reportes y notificaciones.
- **Eliminación lógica** de entidades de negocio; nunca borrados físicos de historial (§3.3).
- **Sin bypass de superusuario:** no existe `Gate::before`; el Superadministrador pasa por las mismas políticas y no
  puede leer chats internos ajenos ([seguridad-y-auditoria.md](seguridad-y-auditoria.md)).
- **Lo simulado se etiqueta**: transporte `simulated` solo en desarrollo, con la etiqueta «No enviado (modo de prueba)» en la interfaz (antes «Simulación»).

## 2. Mapa de módulos

| Módulo                         | Directorios principales                                                                                                                                                                                                                                                                                 | Responsabilidad                                                                  | Documento                                                                                                                      |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ |
| Fundación (incremento 01)      | `app/Actions/{Users,Roles,Departments,Settings,Audit,Authorization}`, `app/Services/{AuditLogger,PrivilegeGuard,PlatformSettings}.php`, `app/Enums/{Permission,PermissionGroup,DefaultRole}.php`, `routes/{admin,settings}.php`                                                                         | Usuarios, roles con alcance, departamentos, configuración, auditoría             | [seguridad-y-auditoria.md](seguridad-y-auditoria.md)                                                                           |
| Contactos y operación (A)      | `app/Actions/{Contacts,Tags,QuickReplies}`, `app/Services/ResolveContactOrigin.php`, `routes/{contacts,tags,quick-replies}.php`                                                                                                                                                                         | Directorio de contactos, identidades, procedencia, etiquetas, respuestas rápidas | —                                                                                                                              |
| Motor de conversaciones (B)    | `app/Actions/Conversations/*`, `app/Services/Conversations/*`, `app/Policies/Conversation*.php`                                                                                                                                                                                                         | Ciclo de vida, enrutamiento, asignación, ventana de 24 h, visibilidad            | [bandejas-y-asignacion.md](bandejas-y-asignacion.md)                                                                           |
| Bandeja (C, _inbox_)           | `app/Http/Controllers/Conversations/*`, `app/Http/Resources/Conversation*`, `resources/js/pages/conversations`, `resources/js/components/conversations`, `routes/conversations.php`                                                                                                                     | Bandeja de tres paneles, compositor, historial, lecturas, adjuntos               | [bandejas-y-asignacion.md](bandejas-y-asignacion.md)                                                                           |
| Tiempo real (_realtime_)       | `app/Broadcasting/*`, `app/Listeners/Realtime/*`, `app/Events/Broadcasts/*`, `routes/channels.php`, `routes/channels/*`, `resources/js/lib/realtime`, `resources/js/composables/use{Channel,LiveReload,RealtimeStatus,UserChannel}.ts`                                                                  | Canales privados, señales, reconexión y respaldo por sondeo                      | [tiempo-real.md](tiempo-real.md)                                                                                               |
| Chat interno (D, _teamchat_)   | `app/**/TeamChat/*`, `routes/team-chat.php`, `routes/channels/team-chat.php`, `resources/js/pages/team-chat`                                                                                                                                                                                            | Chats privados y grupos entre empleados, separados de clientes                   | [chat-interno.md](chat-interno.md)                                                                                             |
| WhatsApp Cloud API (E, _meta_) | `app/Services/Meta/*`, `app/Actions/{Messaging,Templates,Connections}`, `app/Http/Controllers/{Webhooks,Messaging}`, `app/Jobs/{ProcessWhatsAppWebhook,DeliverOutboundMessage,DownloadInboundMedia,SyncConnectionTemplates,SimulateMessageStatuses}.php`, `routes/{webhooks,messaging,connections}.php` | Webhooks, recepción, envío, estados, multimedia, plantillas, salud de conexiones | [mensajeria-whatsapp.md](mensajeria-whatsapp.md)                                                                               |
| Reportes (F, _reports_)        | `app/Services/Analytics/*`, `app/Actions/Analytics/*`, `app/Listeners/Analytics/*`, `app/Jobs/Analytics/*`, `app/Reports/*`, `app/Services/Exports/ReportExporter.php`, `routes/{reports,exports}.php`                                                                                                  | Métricas por conversación, reportes, panel, exportaciones XLSX/PDF               | [reportes-y-exportaciones.md](reportes-y-exportaciones.md)                                                                     |
| Automatización (_automation_)  | `app/Notifications/*`, `app/Listeners/{Notifications,Automation}/*`, `app/Services/{Automation,BusinessHours.php}`, `app/Actions/Automation/*`, `routes/{notifications,automation}.php`                                                                                                                 | Notificaciones, horarios, días no laborables, mensajes automáticos               | [notificaciones-y-horarios.md](notificaciones-y-horarios.md)                                                                   |
| Operación (_ops_)              | `app/Services/Operations/*`, `app/Console/Commands/{Preflight,CheckOperationalAlerts,RecordSchedulerHeartbeat}.php`, `app/Support/{Queues,LogContext}.php`, `routes/{health,operations}.php`, `deploy/*`                                                                                                | Salud, alertas, preflight, colas, plantillas de servidor, respaldos              | [infraestructura-y-despliegue.md](infraestructura-y-despliegue.md), [recuperacion-y-respaldos.md](recuperacion-y-respaldos.md) |
| Migración (_migration_)        | `app/Services/{Imports,Migration}/*`, `app/Jobs/{ImportContacts,RollbackContactImport}.php`, `routes/imports.php`                                                                                                                                                                                       | Importación de contactos, adaptador Whaticket, trazabilidad y reversión por lote | [migracion-whaticket.md](migracion-whaticket.md)                                                                               |
| Seguridad transversal          | `app/Concerns/AuthorizesVisibleConversation.php`, `app/Listeners/Security/*`, `app/Http/Middleware/{SecurityHeaders,ThrottleRequestsPerRoute,EnsureUserIsActive}.php`                                                                                                                                   | Autorizar antes de validar, eventos de cuenta, cabeceras, límites por ruta       | [seguridad-y-auditoria.md](seguridad-y-auditoria.md)                                                                           |

### 2.1 Eventos de dominio y quién los emite

Todos implementan `ShouldDispatchAfterCommit` y llevan solo IDs. Los listeners se registran por autodescubrimiento
(`app/Listeners/<Módulo>`).

| Evento                                                              | Emite                                                                                                                                        | Escuchan                                                                                          |
| ------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------- |
| `Conversations\ConversationChanged(id, reason, actor, event)`       | `ConversationLifecycle::record()` (todo `conversation_event`); bandeja para `read`, `tags`, `routing`; `message` solo `DownloadInboundMedia` | tiempo real, notificaciones, mensajes automáticos (`created`/`reopened`/`closed`), métricas       |
| `Conversations\ConversationNoteSaved`                               | bandeja (`AddConversationNote`, `UpdateConversationNote`)                                                                                    | tiempo real                                                                                       |
| `Messaging\MessageRecorded(message, conversation, direction)`       | meta (entrantes, nunca duplicados), bandeja (respuestas de agentes), automatización (automáticos)                                            | tiempo real, notificaciones (entrantes), métricas                                                 |
| `Messaging\MessageStatusChanged`                                    | meta (`RecordMessageStatus`, entrega, reconciliación)                                                                                        | tiempo real, métricas                                                                             |
| `Connections\ConnectionHealthChanged`                               | meta (`ConnectionMonitor`)                                                                                                                   | notificaciones (`connection.problem`), tiempo real (`connection_changed`)                         |
| `Connections\ConnectionStatusChanged`                               | acciones de conexiones (activar, desactivar, eliminar, restaurar, verificar, reemplazar credenciales)                                        | tiempo real (`BroadcastConnectionChange`: `conversation.changed` con `reason=connection_changed`) |
| `Notifications\NotificationSignal(userId, notificationId, kind)`    | `InAppChannel` tras guardar una notificación                                                                                                 | tiempo real (`BroadcastNotificationSignal`, síncrono), Web Push (`QueueWebPush`)                  |
| `Users\AgentAvailabilityChanged`                                    | `ChangeAgentAvailability`                                                                                                                    | tiempo real                                                                                       |
| `Users\UserAccessChanged(userIds, reason)`                          | acciones de usuarios, roles, membresías de departamentos y del chat interno ([lista](tiempo-real.md#puntos-de-emisión-de-useraccesschanged)) | tiempo real (`.access.changed`), notificaciones                                                   |
| `DataTransfers\DataTransferFinished`                                | modelo `DataTransfer` al pasar a `completed`/`failed` (exportaciones e importaciones)                                                        | tiempo real (`.transfer.changed`), notificaciones                                                 |
| `TeamChat\TeamMessagePosted`, `TeamThreadChanged`, `TeamThreadRead` | chat interno                                                                                                                                 | chat interno (señales), notificaciones                                                            |

## 3. Modelo de datos

Generado a partir del esquema real (`php artisan db:show` / `db:table`, solo lectura) y de `database/migrations`
(68 migraciones). Las fechas se guardan en UTC.

### 3.1 Tablas principales

| Área                                                              | Tablas                                                                                                                                                                                                         | Relaciones clave                                                                                                                                                                                                                                                                                 |
| ----------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Usuarios y acceso                                                 | `users`, `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`, `sessions`, `password_reset_tokens`, `passkeys` (heredada, passkeys retiradas)                            | `roles.scope` (`global`/`departments`/`assigned`) e `is_root`; `users.availability`, `availability_changed_at`, `last_assigned_at`, `is_active`, `max_open_conversations` (límite propio, nullable)                                                                                              |
| Departamentos                                                     | `departments`, `department_user`                                                                                                                                                                               | `distribution_mode` (`shared`/`manual`/`automatic`), `max_open_conversations_per_agent` (nullable), horario propio, `welcome_message`, `away_message`                                                                                                                                            |
| Conexiones                                                        | `connections`, `connection_department`                                                                                                                                                                         | `phone_number_id` y `webhook_key` únicos; credenciales cifradas; `default_department_id`; `is_active` (pausa) y `deleted_at` (baja lógica)                                                                                                                                                       |
| Contactos                                                         | `contacts`, `contact_identities`, `connection_contact`, `contact_attributions`, `contact_notes`, `contact_review_cases`, `tags`, `contact_tag`                                                                 | Una identidad por canal/proveedor/`external_id`; procedencia confirmada por referencias de Meta; FULLTEXT `(name, profile_name)`                                                                                                                                                                 |
| Conversaciones                                                    | `conversations`, `conversation_events`, `conversation_notes`, `conversation_tag`, `conversation_reads`                                                                                                         | `conversations` → `connections`, `contacts`, `departments`, `users` (responsable); clave única `(connection_id, contact_id, open_lock)`: una sola abierta por contacto y conexión; `version` para concurrencia optimista                                                                         |
| Mensajes                                                          | `messages`, `message_attachments`, `message_templates`                                                                                                                                                         | `messages.whatsapp_message_id` y `client_reference` únicos (idempotencia); FULLTEXT sobre `body` (búsqueda en mensajes); índice `(updated_at, conversation_id)`; `reply_to_message_id`; adjuntos con `status` y `whatsapp_media_id`                                                              |
| Webhooks                                                          | `webhook_events`                                                                                                                                                                                               | Cuerpo crudo por conexión, deduplicado por `payload_hash`, con estado e intentos                                                                                                                                                                                                                 |
| Respuestas rápidas                                                | `quick_replies`, `department_quick_reply`                                                                                                                                                                      | Corporativas: globales o por departamento; personales: solo su propietario (`owner_user_id`), nunca por departamento                                                                                                                                                                             |
| Chat interno                                                      | `team_threads`, `team_participants`, `team_messages`, `team_attachments`, `team_message_reactions`                                                                                                             | Sin relación con conversaciones de clientes ([chat-interno.md](chat-interno.md#modelo-de-datos))                                                                                                                                                                                                 |
| Notificaciones                                                    | `notifications`, `notification_receipts`, `user_notification_preferences`, `push_subscriptions`                                                                                                                | Deduplicación por recibo y agrupación por clave ([notificaciones](notificaciones-y-horarios.md#11-modelo))                                                                                                                                                                                       |
| Transferencias de datos                                           | `data_transfers`, `import_batch_records`                                                                                                                                                                       | Exportaciones e importaciones; trazabilidad y reversión por lote ([migración](migracion-whaticket.md#trazabilidad-por-lote))                                                                                                                                                                     |
| Historial importado (fase 5.2, migraciones pendientes de aplicar) | `import_source_keys`, `history_import_files`, `history_import_rules`, `history_import_conversations`, `history_import_messages`; columnas `conversations.origin`, `history_only_at`, `imported_by_transfer_id` | Importador de Whaticket: idempotencia global, staging del lote y origen de cada conversación ([migración](migracion-whaticket.md#importador-de-historial-de-whaticket-fase-52), [ADR-MIG-4](decisiones.md#adr-mig-4--historial-importado-en-las-tablas-existentes-con-origin-y-history_only_at)) |
| Configuración y auditoría                                         | `settings`, `audit_logs`                                                                                                                                                                                       | `audit_logs` solo inserción, con `restricted` para acciones del rol raíz                                                                                                                                                                                                                         |
| Infraestructura Laravel                                           | `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `migrations`                                                                                                                                     | Colas y caché en base de datos por defecto                                                                                                                                                                                                                                                       |

```
connections ─┬─< conversations >─ contacts ─< contact_identities
             │        │  │  └──< conversation_events, conversation_notes, conversation_reads, conversation_tag
             │        │  └── department (nullable) ── department_user ── users
             │        └──< messages ──< message_attachments
             ├──< message_templates
             └──< webhook_events
users ──< team_participants >── team_threads ──< team_messages ──< team_attachments
```

### 3.2 Tablas derivadas

Se pueden reconstruir desde la fuente y no son historial:

- `conversation_metrics`: una fila por conversación, recalculada completa (`analytics:rebuild`;
  [ADR-REP-1](decisiones.md#adr-rep-1--métricas-por-conversación-recalculadas-desde-la-fuente)). Sus dimensiones no
  tienen claves foráneas a propósito.
- `conversation_reads`: marcadores de lectura personales (solo avanzan).
- `notification_receipts`: recibos de deduplicación con retención de 30 días.
- Columna generada `notifications.unread_aggregate_key` y `conversations.open_lock` (columna almacenada que hace única
  la conversación no cerrada).

### 3.3 Eliminación lógica e integridad histórica

- Entidades de negocio con `App\Concerns\DeletesLogically` (no `SoftDeletes`, para que las relaciones de historial sigan
  viéndolas): `users`, `roles`, `departments`, `tags`, `quick_replies` (eliminado = `is_active = false`), `contacts`
  (estado `archived`), `connections` y `message_templates` (`deleted_at`). Quien tiene `records.view-deleted` consulta
  los eliminados. La marca es heterogénea a propósito (unas entidades guardan `is_active`, otras `deleted_at`): no se
  añadió `deleted_at` a roles, departamentos, etiquetas ni respuestas rápidas; **quién y cuándo eliminó o restauró**
  se consulta en la auditoría (`*.deleted` / `*.restored`), que es la fuente
  ([ADR-SEC-8](decisiones.md#adr-sec-8--eliminación-lógica-heterogénea-con-la-auditoría-como-fuente)).
- Conversaciones, mensajes, eventos, notas, webhooks, auditoría, hilos y mensajes internos **nunca se borran**. Un
  mensaje interno eliminado por su autor conserva la fila con `deleted_at` y pierde su contenido
  ([ADR-TC-3](decisiones.md#adr-tc-3--eliminar-un-mensaje-borra-su-contenido-y-deja-una-marca)).
- Claves foráneas de registros de negocio: `restrict` o `set null`. Solo las tablas pivote (`department_user`,
  `connection_department`, `contact_tag`, `conversation_tag`, `department_quick_reply`, `connection_contact`), las de
  permisos de spatie y las derivadas por usuario/conversación (`conversation_metrics`, `conversation_reads`,
  `notification_receipts`, `user_notification_preferences`, `passkeys`) usan `cascade`; como sus padres nunca se borran
  físicamente, la cascada no elimina historial.
- Migraciones solo aditivas en producción; los comandos destructivos de Artisan están bloqueados
  (`DB::prohibitDestructiveCommands`).

## 4. Flujo de mensajes entrantes

Detalle en [mensajeria-whatsapp.md §2–§4](mensajeria-whatsapp.md#2-conexiones-y-webhooks).

```
Meta ──POST /webhooks/whatsapp/{webhook_key}
        │ 1. tamaño ≤ 3 MB · 2. firma X-Hub-Signature-256 (HMAC del cuerpo crudo) · 3. JSON válido
        ▼
WhatsAppWebhookController ── guarda webhook_events (dedupe sha256 + conexión) ── responde 200
        │ cola "webhooks"
        ▼
ProcessWhatsAppWebhook (5 intentos) → ProcessWebhookPayload
        ├─ statuses ─────────────▶ RecordMessageStatus (§8)
        ├─ plantillas / avisos ──▶ message_templates, connections.provider_signals
        └─ messages ─────────────▶ ProcessInboundMessages → ReceiveInboundMessage (READ COMMITTED, reintentos)
                                     1. duplicado por wamid → se responde sin tocar nada
                                     2. RegisterInboundContact (contacto provisional si es nuevo, identidad, procedencia)
                                     3. conversación no cerrada | reapertura en ventana | nueva (ConversationRouter)
                                     4. guarda el mensaje; fechas de actividad solo avanzan
                                     5. AssignAutomatically si el departamento es automático
                                     6. adjunto «pending» → DownloadInboundMedia (cola "media")
                                   ──commit──▶ MessageRecorded (un solo evento por mensaje)
                                                ├─ señales (cola realtime) → Reverb → bandejas y chat
                                                ├─ notificaciones (cola notifications) → señal .notification y Web Push
                                                ├─ mensaje automático de bienvenida/fuera de horario (cola messages, vía ConversationChanged created/reopened)
                                                └─ recálculo de métricas (cola default)
```

- Solo se aceptan mensajes del Phone number ID de la conexión cuya URL recibió el webhook; el cuerpo nunca elige otra
  conexión.
- Una conexión **pausada** sigue recibiendo y guardando; una **eliminada** responde 404
  ([ADR-META-5](decisiones.md#adr-meta-5--una-conexión-pausada-recibe-pero-no-envía)).
- Enrutamiento por departamento sin inferir intención: predeterminado de la conexión → predeterminado de la
  plataforma → sin departamento ([bandejas §3](bandejas-y-asignacion.md#3-distribución-entre-departamentos)).
- La transacción de recepción y el registro del contacto corren en READ COMMITTED con reintento registrado
  (`App\Support\ReadCommittedTransaction`, [ADR-LOAD-2](decisiones.md#adr-load-2--transacción-read-committed-compartida-y-upsert-de-notificaciones-agregadas)).
- Recuperación: `whatsapp:maintenance recover-webhooks` (cada 10 min) reencola eventos `received` atascados;
  `retry-webhooks` reprocesa fallidos manualmente.
- **Facebook Messenger e Instagram** entran por el webhook de la app `POST /webhooks/meta` (firma con la clave de la app
  de Facebook o de Instagram), que reparte cada `entry` a su conexión por `external_account_id`, la guarda en
  `webhook_events` y la procesa en la misma cola con `ProcessMetaMessagingPayload` → `ReceiveInboundMessage` (mismo
  contacto provisional, conversación, enrutamiento, asignación y notificaciones). Marcas de entrega/lectura →
  `RecordMessageStatus`. Detalle en [meta-messenger-instagram.md](meta-messenger-instagram.md).

## 5. Flujo de mensajes salientes

Detalle en [mensajeria-whatsapp.md §5](mensajeria-whatsapp.md#5-mensajes-salientes-y-estados) y
[bandejas §1 Compositor](bandejas-y-asignacion.md#compositor).

```
Compositor ──POST conversations/{id}/messages (texto o archivo, client_reference UUID)
           ──POST conversations/{id}/templates (plantilla aprobada, fuera de ventana permitido)
        │ autoriza (visible + responsable + conversations.reply) antes de validar
        ▼
QueueOutboundMessage / QueueTemplateMessage (RetryOutboundMessage para reintentos)
   · la conexión puede enviar (Connection::sendingBlockReason(); si no, 409 con el motivo, sin guardar nada)
   · ventana de 24 h abierta para texto libre · archivo validado con finfo → disco privado
   · una fila por client_reference (doble envío inofensivo) · estado "queued"
   ──commit──▶ MessageRecorded(outbound) · DeliverOutboundMessage (cola "messages")
        ▼
DeliverOutboundMessage
   1. bajo bloqueo: conexión operativa, el autor aún puede responder, ventana, plantilla aprobada → "sending"
      (si no: failed + outbound_blocked; autor sin permiso → sender_not_allowed)
   2. fuera de transacción: WhatsAppTransport::send()  (meta | simulated | unavailable; piloto WHATSAPP_PILOT_RECIPIENTS)
   3. wamid → "accepted"   ·  no llegó a Meta → "queued" con espera  ·  ambiguo → queda "sending"
        ▼
Webhooks de estado → sent / delivered / read   ·   reconcile-deliveries (5 min) → failed/delivery_unknown
```

- `ChannelTransports` elige el transporte por canal: WhatsApp (`WhatsAppTransport`), Chat web (`WebChatTransport`) y
  Messenger / Instagram (`MetaMessagingTransport`, Send API con `appsecret_proof`, `messaging_type: RESPONSE`, archivos
  por URL firmada; [meta-messenger-instagram.md §6](meta-messenger-instagram.md#6-recepción-y-envío)).
- Los mensajes automáticos (bienvenida, fuera de horario, despedida) usan el mismo camino con referencia determinista
  ([notificaciones §3](notificaciones-y-horarios.md#3-mensajes-automáticos)).
- Reintento manual: misma fila, confirmación obligatoria para `delivery_unknown`
  ([ADR-META-4](decisiones.md#adr-meta-4--el-reintento-manual-reutiliza-la-misma-fila)).
- Con la conexión pausada, eliminada, sin credenciales o con credenciales rechazadas, el compositor muestra el motivo
  y no deja enviar; el servidor responde `409` con el mismo motivo y el job vuelve a comprobarlo en el momento del envío
  ([mensajeria-whatsapp.md §2.5](mensajeria-whatsapp.md#25-política-de-conexión-inactiva-o-eliminada)).

## 8. Estados de mensajes

Fuente: `App\Enums\MessageStatus` y `App\Actions\Messaging\RecordMessageStatus`. Los entrantes siempre son `received`.

```
                     reintento manual autorizado (misma fila)
          ┌──────────────────────────────────────────────────────────────┐
          ▼                                                              │
      queued ──claim──▶ sending ──wamid──▶ accepted ──▶ sent ──▶ delivered ──▶ read
        ▲   │             │  │                 │          │          │
        │   │             │  └─ambiguo: se queda sending  │          └─ failed tardío: metadata.late_failure,
        │   │             │       └─15 min sin evidencia─▶ failed (delivery_unknown) ─ evidencia de Meta lo corrige
        │   │             └─ no llegó a Meta / 429 / temporal ─▶ vuelve a queued (≤ 5 intentos)
        │   └─ bloqueo previo (conexión, ventana, plantilla) ─▶ failed
        └──────────────── rechazo explícito de Meta ──────────▶ failed
```

| Estado                          | Etiqueta              | Origen                                                      |
| ------------------------------- | --------------------- | ----------------------------------------------------------- |
| `received`                      | Recibido              | Mensaje entrante                                            |
| `queued`                        | Enviando              | Aplicación: guardado, esperando un worker                   |
| `sending`                       | Enviando              | Aplicación: un worker lo reclamó y está llamando a Meta     |
| `accepted`                      | Enviado               | Respuesta de la API con `wamid` (aceptado por el proveedor) |
| `sent`                          | Enviado               | Webhook de estado de Meta                                   |
| `delivered`                     | Entregado             | Webhook de estado de Meta (chat web: acuse del widget)      |
| `read`                          | Leído                 | Webhook de estado de Meta (chat web: acuse del widget)      |
| `failed`                        | Fallido               | Aplicación o Meta; terminal salvo reintento autorizado      |
| `failed` con `delivery_unknown` | Entrega no confirmada | Reconciliación: 15 min en `sending` sin evidencia           |

Las etiquetas son solo de presentación (`MessageStatus::label()`, `messageFormat.ts`, expediente PDF y API v1); los
valores internos no cambian. La acción para un fallido es «Reintentar».

Reglas: solo hacia adelante; tolera desorden (un `read` temprano fija también `delivered_at` y `sent_at`); un evento
repetido no cambia nada; un `failed` después de `delivered` no deshace la entrega; `delivery_unknown` es el único fallo
local que la evidencia de Meta reemplaza. «Entregado» y «Leído» nunca se marcan sin un webhook de Meta; con el
transporte simulado esos estados son **simulados** y llevan la etiqueta «No enviado (modo de prueba)». Clasificación de errores en
[mensajeria-whatsapp.md §5.3](mensajeria-whatsapp.md#53-clasificación-de-errores-metagraphclient).

## 11. Disponibilidad de agentes

- **Fase 5: no hay disponibilidad manual.** Se retiraron el interruptor «Disponible / No disponible», su ruta, la acción
  `ChangeAgentAvailability`, la señal `.availability.changed` y las confirmaciones «asignar a alguien no disponible».
  Las columnas `users.availability` y `availability_changed_at` se conservan sin uso operativo (historial y entradas
  antiguas de bitácora `user.availability_changed`, que se siguen mostrando).
- Puede recibir, aceptar, transferir o ser asignado quien cumple: cuenta activa, rol activo, permiso, pertenencia a un
  departamento activo y límite de carga (más el horario donde ya aplicaba). Panel y reportes cuentan «Agentes activos».
- La asignación automática elige, entre los elegibles por debajo de su límite (propio del agente > por agente del
  departamento > `operations.max_open_conversations_per_agent`), la menor carga; empate por quien lleva más tiempo sin
  recibir una (`last_assigned_at`) y luego por id ([bandejas §4](bandejas-y-asignacion.md#4-bandeja-compartida-aceptación-y-asignación)).
- Asignar o transferir por encima del límite exige `conversations.assign` y confirmación explícita, y queda en el evento
  (`assigned_over_limit`).
- Chat web: «disponible» = conexión operativa y configurada, modo «siempre» u horario abierto, y al menos un miembro
  activo del departamento por defecto que podría atender; no depende de presencia
  ([canal-chat-web.md](canal-chat-web.md)).
- Perder el acceso (baja, cambio de rol, retiro de departamento, quitar «responder» a un rol) devuelve sus
  conversaciones a la cola mediante `ReleaseAssignedConversations` ([bandejas §5](bandejas-y-asignacion.md#5-ausencias-y-cambios-de-acceso)).
- Cuando se libera capacidad (cerrar, liberar, reasignar, transferir, subir o quitar un límite) la cola se redistribuye
  después del commit, acotada a 100 conversaciones por departamento
  ([bandejas §4 «Capacidad»](bandejas-y-asignacion.md#capacidad)).

## 12. Colas y tareas programadas

### 12.1 Colas

Nombres en `App\Support\Queues` (remapeables con `QUEUE_NAME_*`; las exportaciones siguen `EXPORTS_QUEUE`). Grupos de
workers de `deploy/supervisor/chat-manager.conf` ([infraestructura §9](infraestructura-y-despliegue.md#9-colas-y-workers)):

| Cola            | Trabajos                                                                                        | Grupo           |
| --------------- | ----------------------------------------------------------------------------------------------- | --------------- |
| `webhooks`      | `ProcessWhatsAppWebhook`, `SimulateMessageStatuses` (solo transporte simulado)                  | `critical`      |
| `messages`      | `DeliverOutboundMessage`, mensajes automáticos (`SendAutomaticMessages`)                        | `critical`      |
| `realtime`      | Señales de tiempo real (`app/Listeners/Realtime`) y difusiones del chat interno                 | `realtime`      |
| `notifications` | Notificaciones in-app (su señal `.notification` se publica en el mismo trabajo) y `SendWebPush` | `notifications` |
| `media`         | `DownloadInboundMedia`                                                                          | `media`         |
| `default`       | Métricas (`RefreshConversationMetrics`), sincronización de plantillas y lo no clasificado       | `default`       |
| `exports`       | `GenerateReport`, `ImportContacts`, `RollbackContactImport`                                     | `exports`       |

Pruebas y escenarios E2E sin worker usan `QUEUE_CONNECTION=sync`. Antes de cada trabajo en cola,
`RefreshPermissionsBeforeQueuedJob` vacía la caché de permisos en memoria del worker, de modo que un permiso revocado
deja de valer en workers de larga vida. Las mediciones de carga anteriores a la estabilización mostraron que la cola
compartida `notifications` era el cuello de botella del tiempo real en ráfagas; por eso las señales tienen ahora su
propia cola y workers ([rendimiento-y-carga.md](rendimiento-y-carga.md)). Los listeners que no harían nada con un
evento lo descartan en `shouldQueue()` y no encolan trabajos vacíos.

### 12.2 Programador (`routes/console.php`, verificado con `php artisan schedule:list`)

| Tarea                                       | Frecuencia   |
| ------------------------------------------- | ------------ |
| `ops:heartbeat`                             | cada minuto  |
| `ops:check-alerts`                          | cada 5 min   |
| `whatsapp:maintenance reconcile-deliveries` | cada 5 min   |
| `whatsapp:maintenance recover-webhooks`     | cada 10 min  |
| `conversations:close-inactive`              | cada hora    |
| `exports:prune`                             | cada hora    |
| `whatsapp:maintenance sync-templates`       | cada 6 h     |
| `whatsapp:maintenance prune-webhooks`       | diaria 03:10 |
| `analytics:rebuild --days=2`                | diaria 03:30 |
| `notifications:prune`                       | diaria 04:10 |
| `webchat:prune-sessions`                    | diaria 04:20 |
| `queue:prune-failed --hours=2160`           | diaria 04:30 |
| `queue:prune-batches --hours=168`           | diaria 04:40 |
| `meta:refresh-instagram-tokens`             | diaria 03:50 |

El programador corre con `schedule:work` (Supervisor) o cron `schedule:run`; su latido alimenta la salud del sistema
([infraestructura §11](infraestructura-y-despliegue.md#11-programador)).

## 13. Ayuda por módulo, lenguaje claro y presentación de la bitácora (fase 5)

### Guía completa por módulo

- Contenido en `resources/js/help/<módulo>.ts` (tipo `ModuleHelp` de `help/types.ts`: objetivo, acciones, flujo, campos,
  estados, permisos, errores comunes, consejos y ejemplos), en español claro y sin claves internas.
- `resources/js/help/index.ts` es el registro: cada clave (`ModuleHelpKey`) carga su archivo bajo demanda (un fragmento
  por módulo, memorizado), así las páginas no incluyen todos los textos.
- `components/help/ModuleHelpSheet.vue` muestra la guía completa del módulo; la abre el botón global de ayuda
  (`components/tour/TourHelpButton.vue`), que primero ofrece los recorridos guiados de la pantalla. `PageHeader` acepta
  la prop `help` con la clave del módulo de la pantalla (ya no pinta un botón propio); su `h1` es `sr-only` salvo
  `show-title` (el título visible lo da el layout: sin títulos duplicados).
- **Añadir un módulo:** crear `help/<clave>.ts` con `export default {…} satisfies ModuleHelp`, registrar la clave en
  `loaders` de `index.ts` y pasar `help="<clave>"` al `PageHeader`.
  Si una funcionalidad cambia, actualizar su archivo de ayuda en el mismo cambio.

### Recorridos guiados (fase 5.1)

Los recorridos guiados sustituyen a la ayuda de texto como experiencia principal; la guía completa queda como
secundaria. Sin dependencias nuevas.

- **Archivos:** `resources/js/tours/types.ts` (tipos `Tour`/`TourStep`), `tours/index.ts` (registro: índice ligero
  síncrono para listar y carga perezosa de los pasos por módulo, como `help/index.ts`), un archivo por módulo
  (`tours/<módulo>.ts`, mismas claves que `help/`, más `app.ts` para la navegación general),
  `composables/useGuidedTour.ts` (estado global) y `components/tour/` (`TourHelpButton`, `TourOverlay`, `TourTooltip`,
  `TourLauncherList`). Se montan una sola vez en `layouts/app/AppSidebarLayout.vue`; ninguna página los monta.
- **Botón global de ayuda** (icono de brújula, «Ayuda y recorridos guiados»): flotante abajo a la derecha; pasa a la
  barra superior cuando hay un compositor acoplado (chat o chat interno) para no tapar «Enviar» ni «Ir al final», y
  sube sobre las barras de guardado fijas (`data-dock-bottom`, `composables/useDockedComposer.ts`). Su popover lista
  los recorridos de la pantalla (filtrados por permiso), marca los nuevos, ofrece «Ver la guía completa del módulo»
  (`ModuleHelpSheet`) y «Volver a mostrar los recorridos». Ningún recorrido se lanza solo.
- **Objetivos:** cada paso apunta a un atributo `data-tour="<área>.<elemento>"` (p. ej. `inbox.pending`,
  `inbox.filters`, `nav.sidebar`, `page.actions`). Un objetivo que no existe o no es visible tras 600 ms se salta; si
  ninguno resuelve, el recorrido se cierra con un aviso. Cada paso puede pedir permisos, una condición de pantalla o
  una variante móvil.
- **Overlay:** oscurece la página, resalta el objetivo (sigue su tamaño y desplazamiento), captura los clics y no se
  cierra al tocar fuera. Teclado: Escape cierra, ←/→ navegan; el foco queda dentro del tooltip y vuelve al elemento
  previo al cerrar; cada paso se anuncia («Paso 2 de 6: …»). Respeta `prefers-reduced-motion` y `forced-colors`. Una
  navegación Inertia cierra el recorrido sin marcarlo visto.
- **Visto:** `localStorage` `cm.tours.seen.v1.<userId>` → `{ tourId: versión }` (preferencia por dispositivo; en modo
  privado se comporta como «nunca visto» sin errores). Subir `version` de un recorrido lo vuelve a marcar como nuevo.
- **Añadir un recorrido:** crear o ampliar `tours/<módulo>.ts`, registrarlo en `tours/index.ts`, poner los
  `data-tour` en los elementos y mantener los textos cortos (título ≤ 40, cuerpo ≤ 180 caracteres). E2E:
  `tests/e2e/tours.mjs`.

### Sistema visual (fase 5.1)

Todo en `resources/css/app.css`; los componentes consumen nombres, no valores sueltos.

- **Tipografía:** `--text-xs` 13 px y `--text-sm` 15 px (cuerpo de la aplicación); nada crítico por debajo de 13 px;
  se retiraron los tamaños arbitrarios `text-[10px]`/`text-[11px]`. Los encabezados de grupo usan `section-title`
  (sin mayúsculas sostenidas). Cifras comparables con `tabular-nums`.
- **Layout:** utilidad `page-shell` (ancho máximo y márgenes por tamaño de pantalla) en lugar de la cadena repetida de
  clases; `--section-gap`, `--stack-gap`, `--control-h`, `--touch-min` (44 px táctil).
- **Superficies y color:** `--surface-sunken`, `--surface-raised`, `--border-strong`; pares semánticos suaves
  `--{success,warning,destructive,info}-soft` + `-soft-foreground` (AA ≥ 4,5:1) y variantes de `Badge`.
- **Movimiento:** `--duration-fast` (150 ms), `--duration-base` (200 ms), `--duration-panel` (280 ms),
  `--ease-standard`, `--ease-emphasized`; utilidades `interactive-surface` y `press`. Nada se mueve en reposo.
- **Gráficas:** `--chart-primary`, `--chart-secondary`, `--chart-comparison`, `--chart-grid`, `--chart-axis` y
  categóricos fijos `--chart-cat-1…8` (alias `--chart-1…5`; `app/Reports/Rendering/ReportPalette.php` usa los
  mismos colores en las exportaciones). Ver [reportes §5](reportes-y-exportaciones.md#5-interfaz).
- **Componentes nuevos:** `StatusNote.vue` (avisos informativos, de éxito, advertencia o error con icono y texto;
  sustituye los avisos ad hoc), `SectionHeading.vue` (título de sección fuera de tarjetas), `RoleSummaryCard.vue` y
  `RolePermissionsSheet.vue` (tarjeta ejecutiva del rol y detalle de permisos por módulo con búsqueda, sin mostrar
  claves internas), `DataScopeOption.vue` (alcance con icono, descripción y ejemplo, `DataScope::example()`/`icon()`),
  `InboxFilterSurface.vue` (panel de filtros, ver [bandejas](bandejas-y-asignacion.md#panel-de-filtros-fase-51)).
- **Terminología:** «Pendientes» en lugar de «cola» para las personas, «promedio» en lugar de «prom.», frases
  completas para la coincidencia de etiquetas en lugar de «(O)/(Y)», «Modo de prueba» / «No enviado (modo de prueba)» en lugar de
  «Simulación», y los nombres técnicos solo en bloques «Detalles técnicos». La regla: cada texto dice qué hace, qué
  pasará y qué sigue.
- E2E de diseño y responsive (320–1440 px, claro y oscuro, sin desborde horizontal): `tests/e2e/diseno.mjs`.

### Presentación de la bitácora (`app/Support/Audit/*`)

- `AuditPresenter` traduce cada entrada para `AuditLogResource` (pantalla) y `AuditReport` (exportación): resumen en
  una frase (qué, sobre qué, quién, cuándo), sujeto con tipo y enlace si quien mira puede abrirlo, cambios antes/después
  para cualquier forma guardada, detalles con etiquetas en español y resultado/error (p. ej.
  `connection.credentials_verified` muestra «Credenciales de la conexión verificadas» con su resultado, no
  `verification_result: ok`).
- Apoyos: `AuditFieldLabels` (nombres de campos), `AuditValueFormatter` (enums, fechas, booleanos), `AuditSubjectTypes`
  (alias del morph map → «Usuario», «Conexión»…, y su página).
- La sección **técnica** (clave de la acción, propiedades crudas saneadas otra vez con las reglas de `AuditLogger`,
  navegador, códigos de Meta e ids de `TECHNICAL_KEYS`) solo llega a quien tiene `system.view` o `audit.export`.
- Solo lectura: los eventos guardados no se modifican; los valores de `AuditAction` siguen siendo permanentes.

### Errores en lenguaje claro

- `App\Services\Meta\MetaErrorCatalog`: explicación en español por código de WhatsApp Cloud/Graph API (qué pasó y qué
  hacer), con grupos de limitación (`THROTTLING`) y fallas temporales (`TEMPORARY`). El texto en inglés de Meta nunca se
  muestra: el código queda en `messages.error_code` (`meta_N`) y en la bitácora, y el detalle crudo solo en el log.
  `separateTechnicalDetail()` (y `resources/js/lib/metaErrors.ts` en el cliente) separa la cola técnica de textos
  antiguos, que se muestra solo a administradores bajo «Detalles técnicos».
- `lang/es/validation.php`: todas las reglas y `attributes` en español, para que los formularios digan «El campo
  correo electrónico es obligatorio» en vez de claves técnicas. Las páginas de error (`resources/views/errors/*`: 403,
  404, 410, 419, 429, 500, 503) también están en español.

## Temas que viven en otros documentos

Webhooks (6), conexiones (7), bandejas y permisos (9), distribución (10), chat interno (12), WebSockets (13), adjuntos
(14), plantillas (15), reportes (16), exportaciones (17), Whaticket (18), seguridad (19), pruebas (20), infraestructura
(21), despliegue (22), respaldos (23) y limitaciones (24): ver el índice en [README.md](README.md). Chat web, widget y
API v1: [canal-chat-web.md](canal-chat-web.md), [widget-integracion.md](widget-integracion.md) y [api-v1.md](api-v1.md).
