# Mensajería con WhatsApp Cloud API

Integración oficial de Chat Manager con la API de WhatsApp Cloud de Meta (Graph API). No se usa WhatsApp Web, QR de
emparejamiento ni automatización de navegador. Este documento cubre el flujo de entrada y salida, los webhooks, las
conexiones, los estados de los mensajes, las plantillas, el multimedia, los errores y reintentos, y el procedimiento
de activación real.

> **Estado de validación:** todo el código está implementado y cubierto con pruebas contractuales (`Http::fake`) que
> reproducen los formatos documentados por Meta. **No se ha ejecutado ningún intercambio real de mensajes con Meta**
> porque la instalación no tiene credenciales de producción. Ver [Lo que no se ha validado](#10-lo-que-no-se-ha-validado-contra-meta-real).

Documentos relacionados: [bandejas-y-asignacion.md](bandejas-y-asignacion.md) (bandeja y compositor),
[tiempo-real.md](tiempo-real.md) (señales en vivo), [decisiones.md](decisiones.md) (ADR-META-*).

## 1. Piezas principales

| Pieza                | Ubicación                                                                         | Responsabilidad                                            |
| -------------------- | --------------------------------------------------------------------------------- | ---------------------------------------------------------- |
| Webhook público      | `routes/webhooks.php`, `App\Http\Controllers\Webhooks\WhatsAppWebhookController`  | Verificación (GET) y recepción firmada (POST) por conexión |
| Registro de webhooks | tabla `webhook_events`, `App\Models\WebhookEvent`                                 | Cuerpo crudo, deduplicado, estado y errores                |
| Procesamiento        | `App\Jobs\ProcessWhatsAppWebhook` → `App\Actions\Messaging\ProcessWebhookPayload` | Mensajes, estados, plantillas y avisos de Meta             |
| Lectura del cuerpo   | `App\Services\Meta\MetaInboundPayload`                                            | Mensajes de todos los tipos, `statuses[]`, otros campos    |
| Mensajes entrantes   | `App\Actions\Conversations\ProcessInboundMessages` / `ReceiveInboundMessage`      | Contacto, conversación, mensaje, adjuntos                  |
| Estados salientes    | `App\Actions\Messaging\RecordMessageStatus`                                       | Máquina de estados solo hacia adelante                     |
| Multimedia entrante  | `App\Jobs\DownloadInboundMedia`, `App\Services\Meta\MediaStorage`                 | Descarga segura y almacenamiento privado                   |
| Envío                | `App\Jobs\DeliverOutboundMessage` + `WhatsAppTransport`                           | Reclamo con bloqueo, envío, aceptación                     |
| Transporte real      | `App\Services\Meta\MetaCloudWhatsAppTransport` sobre `MetaGraphClient`            | Texto, multimedia, plantillas, respuestas                  |
| Transporte simulado  | `App\Services\Meta\SimulatedWhatsAppTransport`                                    | **Solo desarrollo local**, etiquetado «Simulación»         |
| Reintento manual     | `App\Actions\Messaging\RetryOutboundMessage`                                      | Reintento autorizado de un fallido                         |
| Plantillas           | `message_templates`, `App\Actions\Templates\*`, `pages/templates/*`               | Sincronización, consulta y envío                           |
| Salud de la conexión | `App\Services\Meta\ConnectionMonitor`, `ConnectionDiagnostics`                    | Evidencia y `ConnectionHealthChanged`                      |
| Mantenimiento        | `php artisan whatsapp:maintenance {tarea}`                                        | Reconciliación, retención, reintentos, sincronización      |

Configuración: `config/whatsapp.php` (transporte, límites, retención, reintentos, multimedia) y
`config/services.php` → `meta` (versión de Graph API `META_GRAPH_VERSION`, URL base y tiempo de espera). Las
credenciales viven **cifradas por conexión**; nunca en `.env`.

## 2. Conexiones y webhooks

### 2.1 URL por conexión

Cada conexión tiene una URL propia:

```
https://<dominio>/webhooks/whatsapp/{webhook_key}
```

`webhook_key` es un valor aleatorio de 48 caracteres generado al crear la conexión (columna `connections.webhook_key`,
única). No es un secreto (los eventos se autentican por firma), pero evita exponer o enumerar IDs internos. La URL se
muestra en la ficha de la conexión con un botón para copiarla.

Las rutas se cargan desde `routes/web.php` **sin el grupo `web`** (`Route::withoutMiddleware('web')`): no hay sesión,
cookies ni token CSRF. Tienen límite de solicitudes por URL (`throttle:whatsapp-webhooks`,
`WHATSAPP_WEBHOOK_RATE_LIMIT`, 1200/min por defecto).

### 2.2 Verificación (GET)

Meta llama con `hub.mode=subscribe`, `hub.verify_token` y `hub.challenge`. El token se compara en tiempo constante
(`hash_equals`) contra el token de verificación cifrado de la conexión. Si coincide se responde el `hub.challenge`
como `text/plain` con `nosniff` (solo si es alfanumérico, para no reflejar contenido) y se registra
`webhook_verified_at`; si no, `403` sin detalle. Una URL desconocida o de una conexión eliminada responde `404`.

### 2.3 Recepción (POST)

1. Límite de tamaño (`WHATSAPP_WEBHOOK_MAX_BYTES`, 3 MB) → `413`.
2. Sin secreto de la app guardado → `403` (no hay forma de autenticar).
3. `X-Hub-Signature-256` debe ser `sha256=` + HMAC-SHA256 del **cuerpo crudo** con el secreto de la app → si no, `401`.
4. JSON inválido → `400`.
5. Se guarda en `webhook_events` (deduplicado por `sha256` del cuerpo y conexión), se actualiza `last_webhook_at` y se
   encola `ProcessWhatsAppWebhook` en la cola `webhooks`. Respuesta `200` inmediata, sin trabajo costoso.

Un mismo cuerpo repetido por Meta se guarda una sola vez; solo se vuelve a encolar si su procesamiento anterior falló.
Un mismo mensaje dentro de cuerpos distintos tampoco se duplica: el ID de proveedor (`messages.whatsapp_message_id`)
es único.

### 2.4 Procesamiento asíncrono

`ProcessWhatsAppWebhook` (5 intentos, espera 10 s, 30 s, 2 min y 10 min) llama a `ProcessWebhookPayload`, que:

- ignora cambios de otra cuenta de WhatsApp Business (el `entry.id` debe ser el WABA de la conexión);
- acepta mensajes y estados **solo del Phone number ID de la conexión** por la que llegó el webhook: nunca se confía
  en el cuerpo para elegir otra conexión;
- `messages` → mensajes entrantes y `statuses`; errores a nivel de cambio se registran como error de la conexión;
- `message_template_status_update` / `message_template_quality_update` → plantillas;
- `phone_number_quality_update`, `account_update` y otros → se guardan como avisos en `connections.provider_signals`;
  bloqueos o restricciones de la cuenta marcan error en la conexión.

Al agotar los intentos el evento queda `failed` con un error saneado (sin el cuerpo ni datos SQL) y puede volver a
encolarse con `php artisan whatsapp:maintenance retry-webhooks` (revisión manual). Si el trabajo de un evento se
perdió (la cola estaba caída al despacharlo o el worker murió), el evento sigue `received`: se vuelve a encolar cuando
Meta reenvía el mismo cuerpo después de 2 minutos y, además, `whatsapp:maintenance recover-webhooks` (cada 10 minutos)
reencola los eventos `received` con más de 10 minutos de espera mientras les queden intentos (el procesamiento es
idempotente, así que un trabajo repetido no duplica nada). La retención del registro crudo es de 30 días para
procesados y 90 para fallidos (`whatsapp:maintenance prune-webhooks`, diario).

### 2.5 Política de conexión inactiva o eliminada

- **Eliminada:** la URL responde `404`; los eventos ya guardados se marcan procesados sin efecto; no se adjunta nuevo
  tráfico a su historial.
- **Inactiva (pausada, no eliminada):** se siguen **recibiendo y guardando** los mensajes de clientes para no perder
  ninguno mientras un administrador la pausa; **el envío queda bloqueado** (`connection_unavailable`).
- **En la bandeja:** la conversación lleva el estado de su conexión (`connection.state` = `active`, `inactive` o
  `deleted`; `can_send`, `send_block_reason`, `credentials_verified`; regla única en
  `Connection::sendingBlockReason()`). Con una conexión pausada, eliminada, sin credenciales guardadas o con
  credenciales rechazadas por Meta, el compositor explica el motivo en lugar del campo de respuesta, no ofrece
  adjuntos, plantillas ni reintentos, y deja ver el historial y agregar notas internas a quien tenga permiso. Con
  credenciales sin verificar (p. ej. conexión restaurada y reactivada) el envío se permite, pero el compositor muestra
  una advertencia.
- **Al encolar:** `QueueOutboundMessage` (texto o archivo), `QueueTemplateMessage` (plantilla) y
  `RetryOutboundMessage` (reintento manual) aplican la misma regla y rechazan con `409` y el mismo motivo un envío
  hacia una conexión que no puede enviar, sin guardar el mensaje ni el archivo, sin encolar nada y, en el reintento,
  sin registrar la auditoría de riesgo de duplicado; un reenvío de red con un `client_reference` ya guardado
  devuelve el mensaje existente. Ante un `409` el compositor recarga la conversación y conserva el borrador. El job
  vuelve a comprobar la conexión en el momento del envío (carrera entre la carga y el envío).
- **Tiempo real:** activar, desactivar, eliminar, restaurar, verificar o reemplazar credenciales emite
  `ConnectionStatusChanged`; `BroadcastConnectionChange` (también ante `ConnectionHealthChanged`) envía
  `conversation.changed` (solo `reason = connection_changed`, sin datos del cliente, en lotes de 100 canales) a cada conversación no
  cerrada de la conexión, y el chat abierto recarga su estado.

### 2.6 Salud y diagnóstico

La salud (`ConnectionHealth`) se deriva solo de evidencia: credenciales verificadas, webhook verificado, mensajes
recibidos, mensajes aceptados por Meta y errores. `ConnectionMonitor` registra esa evidencia y emite
`ConnectionHealthChanged` cuando la salud cambia (por ejemplo, token rechazado en tiempo de ejecución → «Credenciales
inválidas»). Las marcas de tráfico se escriben como máximo una vez por minuto para no bloquear la fila de la conexión
con alto volumen.

La ficha de la conexión muestra la tarjeta «Integración con WhatsApp Cloud API»: modo de transporte, URL del webhook,
secretos guardados (sí/no), verificación, suscripción de la app, eventos de las últimas 24 h, último error, mensajes
sin confirmación, mensajes con **entrega sin confirmar** (`delivery_unknown`) de los últimos 7 días con enlace a las
conversaciones afectadas que el usuario puede ver (máximo 10, respetando su visibilidad), el piloto controlado si
está configurado (§9), avisos de Meta y plantillas.

La salud «Operativa» exige además credenciales verificadas: el tráfico histórico no avala un token cambiado o una
conexión restaurada (se muestra «Recibiendo mensajes» o «Configurada sin verificar» hasta volver a verificar). Acciones: **Revisar suscripción** (`GET /{waba}/subscribed_apps`),
**Suscribir app** (`POST /{waba}/subscribed_apps`) y **Sincronizar plantillas**; requieren `connections.manage`
(y `templates.manage` para sincronizar) y quedan auditadas.

### 2.7 Administración de conexiones

Una conexión es un número de WhatsApp Business registrado en Meta (pantalla **Conexiones**, `routes/connections.php`,
`App\Actions\Connections\*`, `ConnectionPolicy`).

- **Permisos:** `connections.view` (consultar, incluido el diagnóstico) y `connections.manage` (crear, editar,
  credenciales, activar/desactivar, verificar, eliminar y restaurar); ambos exigen alcance global. Restaurar requiere
  además `records.view-deleted`.
- **Datos:** nombre, Phone number ID (único, también entre eliminadas), WABA ID, Meta App ID, departamento
  predeterminado y departamentos autorizados (`connection_department`), mensajes automáticos de bienvenida, fuera de
  horario y despedida, y ventana de reapertura en minutos.
- **Credenciales de solo escritura** (`ConnectionSecrets`): token de acceso, secreto de la app y token de verificación
  se guardan cifrados con `APP_KEY`, nunca vuelven al navegador ni a la bitácora (solo se audita que se reemplazaron).
  **Verificar credenciales** (`POST connections/{id}/verify`, 10/min) consulta el número en Meta y guarda su estado,
  nombre verificado y calidad.
- **Dos estados independientes:** `is_active` (Activar/Desactivar; activar exige identificadores y token guardados) y
  `deleted_at` (Eliminar/Restaurar, baja lógica). Desactivada: sigue recibiendo, no envía (§2.5). Eliminada: queda
  inactiva, conserva conversaciones e historial, reserva su Phone number ID, es de solo lectura y su URL de webhook
  responde 404. Una conexión restaurada vuelve **inactiva, con credenciales por verificar y sin la verificación del
  webhook ni la suscripción de la app** (se conserva el historial de tráfico). Al activarla sin credenciales
  verificadas el aviso es de advertencia, no de éxito.
- Un problema de salud (`error`, `credentials_invalid`) notifica a quienes tienen permisos de conexiones
  ([notificaciones-y-horarios.md](notificaciones-y-horarios.md)); la página Administración > Sistema permite probarla.

## 3. Mensajes entrantes

Tipos admitidos y texto legible guardado en `messages.body` (el contenido original de Meta se conserva en
`metadata.content`):

| Tipo de Meta                                 | `MessageType`          | Texto                                        |
| -------------------------------------------- | ---------------------- | -------------------------------------------- |
| `text`                                       | text                   | cuerpo                                       |
| `image`, `video`, `document`                 | image, video, document | pie de foto (si hay) + adjunto               |
| `audio` (incluye notas de voz), `sticker`    | audio, sticker         | adjunto                                      |
| `location`                                   | location               | nombre/dirección o «Ubicación: lat, long»    |
| `contacts`                                   | contacts               | «Contacto compartido: Nombre (+teléfono)»    |
| `interactive` (`button_reply`, `list_reply`) | interactive            | título elegido                               |
| `button` (respuesta rápida de plantilla)     | interactive            | texto del botón                              |
| `reaction`                                   | reaction               | emoji                                        |
| `unsupported`, `order`, otros                | unsupported            | se conserva la referencia y el error de Meta |

- **Respuestas:** `context.id` enlaza `reply_to_message_id` si el mensaje citado está guardado en la misma
  conversación del contacto; además se guarda `metadata.reply_to`.
- **Referencias de anuncios** (`referral`): las registra `RegisterInboundContact` como procedencia confirmada.
- **Duplicados:** un mensaje ya guardado se responde como duplicado **antes** de tocar el directorio de contactos, así
  que repetir un evento no altera fechas ni nombres. Si guardar el mensaje falla después de registrar el contacto, el
  trabajo de webhook reintenta todo; el registro de contacto es idempotente.
- **Eventos:** cada mensaje nuevo emite `MessageRecorded(…, 'inbound')` y `ConversationChanged(…, 'message')` después
  del commit; los duplicados no emiten nada.
- Los mensajes `system` de Meta se ignoran con registro en log (sin datos personales).

## 4. Multimedia

### 4.1 Entrante

1. Al guardar el mensaje se crea un `message_attachments` con `status = pending`, `whatsapp_media_id`, tipo declarado
   y nombre saneado (solo para mostrar).
2. `DownloadInboundMedia` (cola `media`, 4 intentos con espera) pide `GET /{media-id}` (URL de corta vida; se pide una
   nueva en cada intento), revisa el tamaño declarado y descarga contando los bytes mientras llegan: si el archivo
   supera el máximo de su tipo (aunque Meta no haya informado el tamaño), la transferencia se corta y el adjunto queda
   `too_large` sin guardarse; descarga con el token en la cabecera `Authorization` **solo
   desde hosts de Meta** (`*.fbsbx.com`, `*.facebook.com`, `*.fbcdn.net`, `*.whatsapp.net`). El token nunca va en la URL
   ni en logs.
3. `MediaStorage` valida el tipo real con `finfo` y el tamaño (límites en `config/whatsapp.php`, alineados a Meta:
   imagen JPEG/PNG 5 MB, sticker WebP 500 KB, audio AAC/MP4/MPEG/AMR/OGG 16 MB, video MP4/3GPP 16 MB, documento 16 MB
   configurable con `WHATSAPP_DOCUMENT_MAX_BYTES`) y lo guarda en el disco privado `filesystems.media_disk`
   (`MEDIA_DISK`, por defecto `local` = `storage/app/private`) en `media/{aaaa}/{mm}/{uuid}`, sin extensión ni nombre
   original.
4. Queda `available` con tipo real, tamaño y SHA-256. El archivo temporal se elimina siempre.

Fallos: archivo expirado o inexistente en Meta → `unavailable/expired`; demasiado grande → `too_large`; tipo no
permitido → `type_not_allowed`; token rechazado → `credentials_rejected`; fallas temporales → se reintenta; agotados
los intentos → `download_failed`. Un adjunto no disponible se muestra como tal en la bandeja; nunca se pierde el
mensaje. Las descargas para agentes se sirven solo por la ruta autorizada de la bandeja (ver
[bandejas-y-asignacion.md](bandejas-y-asignacion.md)).

### 4.2 Saliente

La bandeja guarda el archivo validado en el disco privado (`QueueOutboundMessage`). Al enviarlo, el transporte lo sube
a WhatsApp (`POST /{phone-number-id}/media`, multipart) y envía el mensaje por ID; el ID de Meta queda en
`whatsapp_media_id`, de modo que un reintento no lo vuelve a subir. Los documentos llevan su nombre; imagen, video y
documento admiten pie de foto.

## 5. Mensajes salientes y estados

### 5.1 Estados

| Estado                             | Significado                                      | Origen               |
| ---------------------------------- | ------------------------------------------------ | -------------------- |
| `queued` (Pendiente)               | Guardado, esperando un worker                    | aplicación           |
| `sending` (En proceso)             | Un worker lo reclamó y está llamando a Meta      | aplicación           |
| `accepted` (Aceptado por WhatsApp) | La API de Meta devolvió el `wamid`               | respuesta de Meta    |
| `sent` (Enviado)                   | Meta lo envió                                    | webhook de estado    |
| `delivered` (Entregado)            | Llegó al teléfono                                | webhook de estado    |
| `read` (Leído)                     | El cliente lo leyó                               | webhook de estado    |
| `failed` (Fallido)                 | No se envió; terminal salvo reintento autorizado | aplicación o webhook |

Reglas de `RecordMessageStatus`:

- solo avanza (`accepted → sent → delivered → read`); un evento anterior nunca retrocede el estado;
- tolera desorden: un `read` antes de `delivered` fija también `delivered_at` y `sent_at`; si luego llega el evento
  anterior con una hora más temprana, se corrige el momento sin retroceder el estado;
- un mismo evento repetido no cambia nada ni emite eventos;
- `failed` de Meta explica el código en español (`MetaErrorCatalog`); un `failed` después de `delivered/read` se
  guarda como evidencia (`metadata.late_failure`) sin deshacer la entrega;
- solo afecta mensajes salientes de la conexión por la que llegó el webhook;
- `biz_opaque_callback_data` (= `client_reference` del mensaje) permite reconciliar un mensaje cuyo `wamid` nunca se
  guardó (respuesta perdida) y corrige un `delivery_unknown` con evidencia real;
- `pricing` y `conversation` se guardan tal cual en `metadata.provider`; **nunca se calculan costos**.

Cada cambio emite `MessageStatusChanged(id, conversación, estado, anterior)`.

### 5.2 Entrega (`DeliverOutboundMessage`, cola `messages`)

1. **Reclamo:** bajo bloqueo de la fila, solo un mensaje `queued` pasa a `sending`. Dos workers con el mismo trabajo
   envían una sola vez (prueba de concurrencia incluida). Antes de reclamar se vuelve a comprobar que la conexión
   esté operativa, que quien escribió el mensaje (o quien lo reintentó por última vez) todavía pueda responder la
   conversación (`ConversationPolicy::reply`: conversación abierta, responsable y permiso; si no, `sender_not_allowed`;
   los mensajes automáticos sin autor están exentos), que la ventana de 24 h permita texto libre (las plantillas están exentas) y que la plantilla siga
   aprobada; si no, falla sin llegar a Meta y registra el evento `outbound_blocked`.
2. **Envío** fuera de transacciones: `POST /{phone-number-id}/messages` con `to`, contenido, `context.message_id` si
   responde a un mensaje y `biz_opaque_callback_data`.
3. **Aceptación:** con el `wamid` queda `accepted` (nunca «entregado» por suposición) y se actualiza
   `connections.last_outbound_at`.

### 5.3 Clasificación de errores (`MetaGraphClient`)

| Caso                                                                                                          | Tratamiento                                                                                                                                                              |
| ------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| DNS, conexión rechazada, TLS, tiempo de conexión agotado (cURL 6/7/35/60, «Connection timed out»)             | **No llegó a Meta:** vuelve a `queued` y reintenta con espera (10 s, 30 s, 90 s, 300 s), máximo `WHATSAPP_DELIVERY_MAX_ATTEMPTS` (5); al agotar → `provider_unreachable` |
| 429 y códigos de limitación (4, 17, 32, 613, 80007, 130429, 131056)                                           | Meta no lo procesó: reintento con espera; al agotar → `rate_limited`                                                                                                     |
| 5xx con cuerpo de error de Meta, códigos 1, 2, 131000, 131016                                                 | Falla temporal explícita: reintento; al agotar → `provider_unavailable`                                                                                                  |
| Tiempo de respuesta agotado, 5xx sin cuerpo de Meta, respuesta sin `wamid`                                    | **Ambiguo:** queda `sending` sin reenviar; se reconcilia por webhook o pasa a `delivery_unknown`                                                                         |
| 401 / código 190 / 0                                                                                          | `credentials_invalid`; la conexión pasa a «Credenciales inválidas» y se emite `ConnectionHealthChanged`                                                                  |
| 403 / códigos 3, 10, 200–299, 131005                                                                          | `permission_denied`; error en la conexión                                                                                                                                |
| 131xxx/132xxx/133xxx (p. ej. 131047 ventana, 131026 no entregable, 131030 número de prueba, 132001 plantilla) | `meta_{código}` con explicación en español                                                                                                                               |
| Código 100, 404                                                                                               | `provider_rejected`                                                                                                                                                      |

Los mensajes de error se sanean: nunca contienen el token (`[token]`), ni cadenas `EA…`, ni `access_token=`.

### 5.4 Mensajes atascados y reconciliación

`php artisan whatsapp:maintenance reconcile-deliveries` (cada 5 minutos) marca como `failed / delivery_unknown` los
mensajes `sending` sin evidencia después de `WHATSAPP_RECONCILE_AFTER_MINUTES` (15). **Nunca se reenvían
automáticamente**, porque Meta pudo haberlos aceptado. Si después llega un estado de Meta con su callback data, el
mensaje se corrige solo. En la conversación se muestran como **«Entrega sin confirmar»** (tono de advertencia, no de
fallo definitivo) con el motivo y el aviso de que el cliente pudo haberlos recibido; nunca se marcan como entregados o
leídos sin evidencia. La tarjeta de diagnóstico de la conexión los cuenta (§2.6).

### 5.5 Reintento manual

`POST conversations/{conversation}/messages/{message}/retry` (`conversations.messages.retry`), autorizado como
responder (agente responsable con `conversations.reply`, conversación abierta). Modelo elegido: **se vuelve a encolar
la misma fila** (conserva su `client_reference`, por lo que la idempotencia del compositor se mantiene); el intento
fallido queda en `metadata.delivery.history` (código, mensaje, `wamid`, quién y cuándo) y se registra el evento de
conversación `message_retried`. Reglas: solo mensajes `failed` de esa conversación (otra conversación → 404); para
`delivery_unknown` se exige `confirm=true` (riesgo de duplicado aceptado explícitamente); texto libre solo con la
ventana abierta; plantillas solo si siguen aprobadas; la conexión debe poder enviar. Aceptar el riesgo de duplicado
queda además en la bitácora central (`AuditAction::MessageRetriedWithDuplicateRisk`,
`conversation.message_retried_duplicate_risk`, con actor, conversación y mensaje). Cada reintento abre un nuevo ciclo
de reintentos automáticos.

## 6. Ventana de atención y costos

- La ventana de 24 h depende solo del último mensaje del cliente (`CustomerServiceWindow`); abrir o cerrar un ticket
  no la modifica. Se valida en la acción y otra vez en el job.
- Fuera de la ventana solo se envían **plantillas aprobadas**.
- La categoría de la plantilla (Marketing, Utilidad, Autenticación) y los bloques `pricing`/`conversation` de Meta se
  guardan como datos informativos. La aplicación **no calcula ni muestra costos**: las tarifas vigentes deben
  consultarse en Meta.

## 7. Plantillas

- **Modelo:** `message_templates` por conexión (WABA, ID de Meta, nombre, idioma, categoría, estado, calidad, formato
  de parámetros, componentes JSON, motivo de rechazo, fechas). Una plantilla que Meta ya no devuelve se elimina
  lógicamente (`deleted_at`) y se restaura si vuelve.
- **Sincronización:** `GET /{waba}/message_templates` con paginación por cursor (hasta `whatsapp.templates.max_pages`
  páginas; si el límite corta el listado, no se retira ninguna plantilla porque no hay prueba de que Meta la borró). Manual desde Plantillas o la ficha de
  la conexión (`templates.manage`, auditada), automática cada 6 horas (`whatsapp:maintenance sync-templates`) y al
  recibir un cambio de estado de una plantilla aún no sincronizada.
- **Webhooks:** `message_template_status_update` (APPROVED, REJECTED con motivo, PAUSED, DISABLED, REINSTATED…;
  FLAGGED solo se registra) y `message_template_quality_update` actualizan la copia. Nunca se marca aprobada sin que
  Meta lo diga.
- **Creación y edición:** se hacen en **WhatsApp Manager** (Meta Business Suite). La API permite crearlas
  (`POST /{waba}/message_templates`), pero exige revisión de Meta, ejemplos por variable y reglas por categoría; para
  no ofrecer un flujo a medias esta versión no las crea: las sincroniza.
- **Pantallas:** `Plantillas de WhatsApp` (`templates.view`): lista con filtros (texto, conexión, estado, categoría,
  idioma, eliminadas), indicadores, exportación a Excel/PDF (reporte `message-templates`) y detalle con vista previa,
  estado según Meta y variables. Usuarios sin alcance global solo ven las plantillas de las conexiones de sus
  departamentos.
- **Envío desde la conversación:** `GET conversations/{conversation}/templates` devuelve en JSON las plantillas
  aprobadas y enviables de la conexión de la conversación (solo a quien puede responder) junto con el estado de la
  ventana. `POST conversations/{conversation}/templates` con `template_id`, `client_reference` (UUID) y `parameters`
  (`{"header.1": "…", "body.1": "…", "button.0.1": "…", "header.media": "https://…"}`) valida cada variable (todas
  obligatorias, sin saltos de línea, tabuladores ni más de 4 espacios seguidos, encabezado ≤ 60 caracteres, cuerpo ≤
  1024 por variable, sin variables desconocidas), rechaza plantillas no aprobadas o de otra conexión, guarda el texto
  renderizado como cuerpo del mensaje y funciona fuera de la ventana. Si la conexión no puede enviar (desactivada,
  eliminada, sin credenciales guardadas o con credenciales rechazadas por Meta) responde `409` con el motivo de
  `Connection::sendingBlockReason()` y no guarda ni encola nada (§2.5); la interfaz ni siquiera abre el selector.
- **No enviables desde la bandeja** (se muestran con el motivo): autenticación (OTP), encabezados de ubicación,
  botones de copiar código, OTP, Flow o catálogo, carruseles y ofertas por tiempo limitado. Los encabezados de imagen,
  video o documento requieren una URL https pública del archivo.

## 8. Transportes

`WHATSAPP_TRANSPORT`:

- `meta` — Cloud API real (producción).
- `simulated` — **solo desarrollo local**. No sale nada a WhatsApp; los mensajes reciben IDs `sim.` y
  `metadata.simulated = true`; opcionalmente (`WHATSAPP_SIMULATE_STATUSES`, `WHATSAPP_SIMULATE_READ`) reporta
  enviado → entregado (→ leído) por el mismo procesamiento de estados de los webhooks. En producción se sustituye por
  `unavailable` aunque esté configurado. La ficha de la conexión muestra «Simulación».
- `unavailable` (por defecto) — todo envío falla explícitamente con `transport_unavailable`.

`WHATSAPP_PILOT_RECIPIENTS` (opcional, vacío = sin restricción): lista de números separados por comas. Con
`WHATSAPP_TRANSPORT=meta`, solo se envía a esos destinatarios (se comparan solo los dígitos, como los informa Meta en
`wa_id`); a cualquier otro el mensaje falla con `transport_unavailable` **antes** de subir archivos o llamar a Meta.
Aplica también a los mensajes automáticos. Ver §9.

### Simulador de eventos (`chat-manager:simulate-inbound`, solo local)

Requiere el escenario Demo B (`php artisan db:seed --class="Database\Seeders\Local\ManualValidationSeeder"`) y
`--confirm`. Solo funciona con `APP_ENV=local` sobre la base `chatmanager` (nunca en producción ni en pruebas), no expone
rutas HTTP, no llama a Meta y solo actúa sobre las líneas demo (Phone number ID `990000000000100` y `990000000000101`) y
los números ficticios `5215500000100`–`5215500000199` (o `laura`, `mario`, `sofia`). Cada llamada crea como mucho un
contacto. Todo pasa por `ProcessWebhookPayload`, como un webhook real. La lógica está en
`App\Support\ManualValidationSimulator` y sus ramas tienen pruebas en `tests/Feature/Local/ManualValidationSimulatorTest.php`.

| Escenario                          | Comando                                                                                                                                      | Resultado esperado                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| ---------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Contacto nuevo                     | `php artisan chat-manager:simulate-inbound 5215500000150 "Quiero una cotización" --name="Ana" --confirm`                                     | «Conversación nueva», contacto «Demo B · Ana (nuevo)», en _Demo B · Ventas_, pendiente                                                                                                                                                                                                                                                                                                                                                                                                             |
| Mensaje nuevo / contacto existente | `php artisan chat-manager:simulate-inbound laura "Sigo interesada" --confirm`                                                                | «Agregado a la conversación abierta», contacto «(existente)»                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| Dirigido a Ventas                  | `... laura "Hola" --department=ventas --confirm` (es el valor por defecto)                                                                   | Conversación en el departamento por defecto de la línea de Ventas                                                                                                                                                                                                                                                                                                                                                                                                                                  |
| Dirigido a Soporte                 | `php artisan chat-manager:simulate-inbound mario "Mi pedido llegó dañado" --department=soporte --create-support-line --confirm`              | La primera vez crea la línea _Demo B · WhatsApp simulado (Soporte)_ (auditada como `connection.created`); después basta `--department=soporte`. Conversación nueva en _Demo B · Soporte_                                                                                                                                                                                                                                                                                                           |
| Multimedia                         | `... mario "Recibo" --type=document --file=/ruta/recibo.pdf --confirm`                                                                       | «Archivo de muestra adjuntado (simulación)», validado como una descarga real. Sin `--file` queda «no disponible» (`simulated_media`)                                                                                                                                                                                                                                                                                                                                                               |
| Duplicado                          | `php artisan chat-manager:simulate-inbound laura --duplicate --confirm`                                                                      | «Duplicado: no se guardó otra vez» (mismo `wamid` del último mensaje del cliente)                                                                                                                                                                                                                                                                                                                                                                                                                  |
| Entregado / leído                  | `php artisan chat-manager:simulate-inbound --status=read --message=123 --out-of-order --confirm`                                             | Estados `read → delivered`; queda «Leído» con su hora de entrega                                                                                                                                                                                                                                                                                                                                                                                                                                   |
| Error de envío                     | `php artisan chat-manager:simulate-inbound --status=failed --message=123 --error-code=131047 --confirm`                                      | Estado «Fallido» con el texto de ventana de 24 h vencida (`meta_131047`). Códigos: 131026 (por defecto), 131047, 131049, 131050, 131056, 130429, 131000, 131031                                                                                                                                                                                                                                                                                                                                    |
| Ventana de 24 h vencida            | `php artisan chat-manager:simulate-inbound laura --expire-window --confirm`                                                                  | El último mensaje del cliente en su conversación demo de la línea pasa a hace 24 h y 5 min (o `--minutes-ago`, si es mayor); «Ventana 24 h: vencida». Se rechaza si eso alterara el orden del historial. En ese caso usa un número ficticio sin conversación, que abre una conversación nueva con la ventana ya vencida: `php artisan chat-manager:simulate-inbound 5215500000160 "Hola" --minutes-ago=1500 --confirm` (un mensaje atrasado en una conversación existente no retrocede su ventana) |
| Reapertura                         | Cierra la conversación desde la bandeja y, antes de 120 min (ventana de reapertura de las líneas demo), `... sofia "Una duda más" --confirm` | «Conversación reabierta». Pasada la ventana: «Conversación nueva»                                                                                                                                                                                                                                                                                                                                                                                                                                  |

En la base local `chatmanager` el departamento demo de Soporte se renombró a «Soporte» (slug `soporte`);
`ManualValidation::department()` lo sigue reconociendo por el agente demo que pertenece a él, sin renombrar nada ni
duplicarlo. La conexión demo de Ventas está **desactivada y sin credenciales guardadas**: sigue recibiendo los
mensajes simulados (política de conexión inactiva, §2.5), pero para responder desde la bandeja hay que guardar
credenciales desechables y activarla (ver [guia-pruebas-manuales.md](guia-pruebas-manuales.md)). El preflight
(`demo`) detecta ambas líneas (`ManualValidation::LINES`).

La línea de Soporte existe porque el enrutamiento solo usa el departamento por defecto de la conexión y nunca adivina el
departamento por el texto: es la opción menos invasiva que no añade atajos al enrutamiento real. `chat-manager:demo-cleanup`
elimina ambas líneas. `--status` acepta mensajes salientes de cualquiera de las dos líneas demo y `--duplicate` repite
el último estado.

**Transferencia entre departamentos.** No es un evento de Meta, sino una acción de un agente, así que el simulador no la
hace: entra como Valeria (_Demo B · Ventas_), acepta la conversación, usa **Transferir** y elige _Demo B · Soporte_
(cola o persona). Queda el evento `transferred` en la línea de tiempo y en la auditoría. El escenario ya incluye una
conversación transferida y cerrada (Sofía).

## 9. Activación real paso a paso

1. **App de Meta:** en developers.facebook.com crea una app de tipo _Business_ y agrega el producto **WhatsApp**.
   Anota el **App ID** y, en Configuración → Básica, el **App secret**.
2. **Cuenta y número:** en WhatsApp → Configuración de la API, identifica el **WhatsApp Business Account ID (WABA)**
   y el **Phone number ID**. Para pruebas puedes usar el número de prueba de Meta (solo envía a hasta 5 números
   autorizados; otros responden con el error 131030). Para producción registra y verifica tu número. En México, Meta
   informa en los webhooks el WhatsApp ID con el «1» heredado de móvil (`521` + 10 dígitos), pero la lista de números
   autorizados del número de prueba solo coincide con `52` + 10 dígitos; por eso el transporte envía siempre el `to`
   de un móvil mexicano en la forma `52` + 10 dígitos (conserva intacto el WhatsApp ID guardado) y deja tal cual los
   de cualquier otro país (`MetaCloudWhatsAppTransport::recipientNumber`).
3. **Token permanente:** en Business Manager → Usuarios del sistema crea un usuario del sistema (administrador),
   asígnale la app y la cuenta de WhatsApp con control total y genera un token con los permisos
   `whatsapp_business_messaging` y `whatsapp_business_management` (sin caducidad o con renovación documentada). No uses
   el token temporal de 24 h.
4. **Servidor:** publica Chat Manager con **HTTPS** y `APP_URL` con ese dominio. Configura `WHATSAPP_TRANSPORT=meta`,
   `META_GRAPH_VERSION` (vigente, p. ej. `v26.0`), colas y workers para `webhooks`, `messages`, `media` (ver
   [infraestructura-y-despliegue.md](infraestructura-y-despliegue.md)) y el programador (`schedule:run`).
5. **Conexión en Chat Manager:** Conexiones → Nueva. Captura Phone number ID, WABA ID, Meta App ID, token, App secret
   y un **token de verificación** inventado (≥ 16 caracteres, sin espacios). Guarda y pulsa **Verificar
   credenciales**; el número debe aparecer `CONNECTED`.
6. **Webhook en Meta:** en la app → WhatsApp → Configuración, edita la URL de devolución de llamada con la URL que
   muestra la ficha de la conexión y el mismo token de verificación. Meta hará el GET; la ficha debe marcar «Webhook
   verificado».
7. **Campos:** suscribe `messages` (obligatorio), `message_template_status_update`, `message_template_quality_update`,
   `phone_number_quality_update` y `account_update`.
8. **Suscripción de la app a la WABA:** en la ficha pulsa **Revisar suscripción** y, si no está, **Suscribir app**
   (equivale a `POST /{waba}/subscribed_apps`). Sin este paso Meta no envía webhooks de mensajes.
9. **Plantillas:** créalas en WhatsApp Manager y pulsa **Sincronizar plantillas**.
10. **Piloto controlado (recomendado):** antes de abrir el canal a todos los clientes, define
    `WHATSAPP_PILOT_RECIPIENTS` con los números del equipo de prueba (p. ej. `5215511112222,5215533334444`), ejecuta
    `php artisan config:cache` si la configuración está en caché y comprueba en la ficha el aviso «Piloto controlado».
    Al terminar la validación deja la variable vacía y vuelve a cachear la configuración.
11. **Activar** la conexión y asignar departamentos.
12. **Prueba de ida y vuelta:** escribe al número desde un teléfono; debe crearse la conversación (y la salud pasar a
    «Recibiendo mensajes»). Acepta y responde desde la bandeja: el mensaje debe pasar a «Aceptado por WhatsApp» y
    luego a «Enviado/Entregado/Leído» por los webhooks de estado; la conexión pasa a «Operativa». Envía una imagen
    desde el teléfono (debe descargarse) y prueba una plantilla con la ventana vencida.
13. Revisa la tarjeta de diagnóstico: eventos procesados, sin errores ni mensajes sin confirmación o con entrega sin
    confirmar.

## 10. Lo que no se ha validado contra Meta real

- No se ha intercambiado ningún mensaje auténtico con Meta; los formatos de solicitudes y respuestas provienen de la
  documentación pública y están fijados en pruebas contractuales (`tests/Feature/{Webhooks,Messaging,Templates,Connections}`).
- No se probaron con Meta: la firma real de `X-Hub-Signature-256` (se probó con el algoritmo documentado), la
  verificación GET real, la descarga desde `lookaside.fbsbx.com`, la carga multipart de multimedia, los formatos
  exactos de `subscribed_apps`, el comportamiento real de los límites (429/130429) y los códigos de error de cURL
  usados para distinguir «no llegó» de «ambiguo» en el servidor de producción.
- El corte de la descarga de multimedia al superar el tamaño máximo (cURL aborta con `CURLE_WRITE_ERROR` cuando el
  sumidero rechaza la escritura) se probó con `Http::fake` y con el cliente directo, no con una descarga real de Meta.
- El piloto controlado (`WHATSAPP_PILOT_RECIPIENTS`) solo se ha probado con pruebas automáticas.
- La comparación de `sha256` de Meta con el archivo descargado no se aplica (el formato del hash de Meta no está
  garantizado); se guarda el SHA-256 propio.
- La creación de plantillas desde la app no está implementada (se crean en WhatsApp Manager).
- Precios y conversaciones facturables: no se calculan.

## 11. Pruebas

```
TEST_DB_LOCK_WAIT=1800 php artisan test --compact tests/Feature/Webhooks tests/Feature/Messaging tests/Feature/Templates tests/Feature/Connections
```

Cubren verificación y firma, idempotencia de webhooks, procesamiento fallido y repetido, conexiones ajenas o de otra
cuenta, límites de tamaño y tasa, todos los tipos de mensaje, estados fuera de orden y repetidos, reconciliación por
callback data, cada llamada a Graph con `Http::fake` (texto, respuesta, multimedia, plantillas, suscripción,
sincronización paginada, descarga), clasificación de errores, reintentos acotados, ambigüedad sin reenvío,
concurrencia de workers, reintento manual con IDOR y confirmación, reglas de plantillas y pantallas con alcance.
En la estabilización se añadieron: bloqueo por conexión al encolar, reintentar y enviar plantillas
(`CustomerServiceWindowTest`, `MessageRetryTest`, `ConversationTemplateTest`), revalidación del autor en el job
(`sender_not_allowed`), auditoría del reintento con riesgo de duplicado, tope de multimedia durante el _streaming_
(`InboundMediaTest`), piloto controlado (`OutboundDeliveryTest`, `ConnectionDiagnosticsTest`), salud de una conexión
restaurada (`ConnectionDeletionTest`), señal `connection_changed` (`tests/Feature/Realtime/ConnectionChangeSignalTest.php`),
orden y antigüedad de las actualizaciones de plantillas (`TemplateSyncTest`) y las ramas del simulador
(`tests/Feature/Local/ManualValidationSimulatorTest.php`).
