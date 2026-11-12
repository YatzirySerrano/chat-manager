# WhatsApp Web (QR): contrato entre componentes

Contrato técnico de la modalidad **WhatsApp Web (QR)**. Lo cumplen tres piezas: el servicio Node (`services/whatsapp-web`),
el backend Laravel y la interfaz. La guía operativa está en [whatsapp-web-qr.md](whatsapp-web-qr.md).

La modalidad es **no oficial** (protocolo de WhatsApp Web mediante Baileys). No sustituye a WhatsApp Cloud API: cada
conexión elige su modalidad con `connections.provider` (`meta_cloud_api` o `whatsapp_web`), ambas con
`channel = whatsapp`. Sin campañas, sin envíos masivos, sin extracción de contactos, sin sincronizar historial.

## 1. Piezas y límites

| Pieza         | Responsabilidad                                                                                                                                                            |
| ------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Servicio Node | Mantiene los sockets de WhatsApp Web, genera el QR, guarda las credenciales de sesión cifradas, envía, descarga medios y notifica eventos. No conoce usuarios ni permisos. |
| Laravel       | Fuente de verdad de conexiones, contactos, conversaciones, mensajes, permisos, auditoría y adjuntos (`MediaDisk`). Decide qué sesiones existen y quién puede operarlas.    |
| Interfaz      | Asistente de vinculación y estado. Solo habla con Laravel; nunca con el servicio Node.                                                                                     |

El servicio Node escucha **solo en `127.0.0.1`** (`WAWEB_HOST`, `WAWEB_PORT`, por defecto `127.0.0.1:3410`). Nginx no lo publica.

## 2. Autenticación interna (ambos sentidos)

Secreto compartido `WHATSAPP_WEB_SECRET` (mínimo 32 caracteres; en Laravel `config('whatsapp_web.secret')`, en Node
`WAWEB_SECRET`). Toda petición, salvo `GET /health`, lleva:

| Cabecera         | Valor                                                                                |
| ---------------- | ------------------------------------------------------------------------------------ |
| `X-CM-Timestamp` | Segundos Unix.                                                                       |
| `X-CM-Nonce`     | UUID v4, único por petición.                                                         |
| `X-CM-Signature` | `hex(HMAC_SHA256(secret, "{timestamp}.{nonce}.{METHOD}.{path}.{sha256_hex(body)}"))` |

- `path` incluye la cadena de consulta, tal como se envía. `body` es el cuerpo exacto en bytes (cadena vacía si no hay).
- Se rechaza con `401` si falta algo, si la firma no coincide (comparación en tiempo constante), si
  `|ahora - timestamp| > 60 s`, o si el `nonce` ya se vio en los últimos 5 minutos (protección contra reproducción).
- Sin secreto configurado, ambos lados rechazan todo: la modalidad queda deshabilitada.

## 3. Estados de la sesión

`state` es uno de:

| Estado         | Significado                                                               | ¿Puede enviar? |
| -------------- | ------------------------------------------------------------------------- | -------------- |
| `unconfigured` | Nunca se vinculó (o se eliminó la sesión).                                | No             |
| `starting`     | El servicio está abriendo el socket (generando QR o restaurando).         | No             |
| `qr_pending`   | Hay un QR vigente esperando escaneo.                                      | No             |
| `pairing`      | El teléfono escaneó; WhatsApp está terminando la vinculación.             | No             |
| `connected`    | Socket abierto y autenticado.                                             | Sí             |
| `reconnecting` | Se perdió el socket; hay reintentos con backoff y jitter.                 | No             |
| `disconnected` | Detenida a propósito desde ChatManager; las credenciales se conservan.    | No             |
| `logged_out`   | WhatsApp revocó la sesión (cierre desde el teléfono o desde ChatManager). | No             |
| `error`        | Se agotaron los reintentos o hubo un fallo no recuperable.                | No             |

Transiciones: `unconfigured|logged_out|error → starting → qr_pending → pairing → connected`;
`connected → reconnecting → connected|error`; `connected|reconnecting → logged_out` (revocada: **no** se regenera sola,
exige vincular de nuevo); `* → disconnected` (acción «Desconectar temporalmente»); `disconnected → starting → connected`
(«Reconectar», sin QR si las credenciales siguen vigentes). Un QR sin escanear caduca: tras `WAWEB_QR_MAX_REFRESH`
renovaciones (5 por defecto) la sesión vuelve a `unconfigured` con `reason = "qr_timeout"`.

Solo `connected` permite enviar. Que el proceso Node esté vivo no significa que una sesión esté disponible.

## 4. API de control: Laravel → Node

Base `http://{WAWEB_HOST}:{WAWEB_PORT}`. JSON, firmada (§2). `connectionId` es el `id` entero de `connections`.

| Método y ruta                               | Cuerpo                       | Respuesta `200`                                                                     |
| ------------------------------------------- | ---------------------------- | ----------------------------------------------------------------------------------- |
| `GET /health`                               | —                            | `{"status":"ok"}` (sin firma, sin datos)                                            |
| `GET /v1/status`                            | —                            | `Status` (abajo)                                                                    |
| `GET /v1/sessions/{connectionId}`           | —                            | `Session`                                                                           |
| `POST /v1/sessions/{connectionId}/start`    | `{"mode":"link"\|"restore"}` | `Session`                                                                           |
| `POST /v1/sessions/{connectionId}/stop`     | —                            | `Session` (`disconnected`, conserva credenciales)                                   |
| `POST /v1/sessions/{connectionId}/logout`   | —                            | `Session` (`logged_out`, borra credenciales)                                        |
| `DELETE /v1/sessions/{connectionId}`        | —                            | `{"deleted":true}` (cierra y borra todo)                                            |
| `POST /v1/sessions/{connectionId}/messages` | `OutboundMessage`            | `{"provider_message_id":"…","timestamp":1700000000}`                                |
| `POST /v1/sessions/{connectionId}/media`    | `{"descriptor":{…}}`         | El archivo descifrado, en streaming (`Content-Type`, `Content-Length` si se conoce) |

`start` con `mode = "link"` exige que no haya credenciales válidas (si las hay responde la sesión actual);
`mode = "restore"` nunca genera QR: sin credenciales responde `409 {"error":"not_linked"}`.

```jsonc
// Session
{
  "connection_id": 12,
  "state": "qr_pending",
  "phone": null,                    // dígitos E.164 sin "+", cuando se conoce
  "push_name": null,
  "qr": { "value": "https://…", "expires_at": 1700000020 },   // solo en qr_pending; se dibuja tal cual
  "connected_since": null,          // segundos Unix
  "last_event_at": 1700000000,
  "last_inbound_at": null,
  "last_outbound_at": null,
  "reconnects": 0,
  "last_error": null                // {"code":"…","message":"…"} sin datos personales
}

// Status
{ "service": "chatmanager-whatsapp-web", "version": "1.0.0", "library": "baileys x.y.z",
  "node": "v22.x", "uptime_seconds": 1234, "sessions": [ Session, … ] }

// OutboundMessage
{
  "idempotency_key": "cm-msg-48213",          // único por mensaje de ChatManager
  "to": "5215512345678",                      // dígitos, o un JID completo si el contacto no tiene teléfono (…@lid)
  "type": "text",                             // text | image | video | audio | document | sticker
  "text": "Hola",                             // texto o pie de foto
  "quoted": { "provider_message_id": "3EB0…", "from_me": false, "remote_jid": "5215512345678@s.whatsapp.net",
              "text": "Texto del mensaje citado" },   // text es opcional; sin él la cita sale vacía
  "media": { "url": "http://127.0.0.1/internal/whatsapp-web/media/…", "mime": "image/jpeg",
             "file_name": "foto.jpg", "voice": false }
}
```

- `idempotency_key`: el servicio recuerda (en disco, 48 h) el `provider_message_id` de cada clave. Repetir la misma
  clave **no** reenvía: devuelve el resultado anterior. Así un reintento de Laravel tras un corte nunca duplica.
- `media.url` es una URL interna firmada por Laravel (`GET`, firmada con §2 en la consulta: ver §6); el servicio la
  descarga y la envía. Nunca se le pasan rutas de disco. El servicio ignora el host de la URL y siempre pide a
  `WAWEB_LARAVEL_URL` (la ruta debe contener `/internal/whatsapp-web/media/`), y trata cualquier redirección como fallo.
- Errores: `409 {"error":"not_connected","state":"…"}` (no reintentar a ciegas), `404 {"error":"unknown_session"}`,
  `422 {"error":"invalid_request"|"invalid_recipient"|"unsupported_type"|"media_too_large"|"media_unavailable"}`,
  `504 {"error":"send_timeout"}` (resultado **ambiguo**: Laravel deja el mensaje como «entrega sin confirmar», no lo
  reenvía solo), `500 {"error":"internal"}`.

## 5. Eventos: Node → Laravel

`POST {WAWEB_LARAVEL_URL}/internal/whatsapp-web/events`, firmada (§2). Un evento por petición:

```jsonc
{ "event_id": "uuid-v4", "connection_id": 12, "type": "…", "occurred_at": 1700000000, "data": { … } }
```

Entrega **al menos una vez**: el servicio guarda cada evento en una cola en disco y lo reintenta con backoff hasta
recibir `2xx`; conserva el orden por conexión. Laravel responde `200 {"status":"ok"}` (también ante duplicados, que
descarta por `event_id` y por `provider_message_id`), `401` si la firma falla, `404` si la conexión no existe o no es
`whatsapp_web` (el servicio descarta el evento y detiene esa sesión) y `5xx` para reintentar.

| `type`                  | `data`                                                                                                                                  |
| ----------------------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| `session.state`         | `Session` (§4) más `reason` opcional (`qr_timeout`, `logged_out_from_phone`, `replaced`, `banned`, `bad_session`, `network`, `restart`) |
| `message.inbound`       | `InboundMessage`                                                                                                                        |
| `message.outbound_echo` | `InboundMessage` con `from_me: true`: lo escribió alguien en el propio teléfono vinculado                                               |
| `message.status`        | `{"provider_message_id":"…","status":"sent"\|"delivered"\|"read"\|"failed","timestamp":1700000000,"error":null}`                        |

```jsonc
// InboundMessage
{
  "provider_message_id": "3EB0…",
  "remote_jid": "5215512345678@s.whatsapp.net",   // chat (siempre individual)
  "phone": "5215512345678",                       // dígitos; null si WhatsApp solo dio un identificador @lid
  "push_name": "María",
  "from_me": false,
  "timestamp": 1700000000,
  "type": "text",         // text | image | video | audio | document | sticker | location | contacts | reaction | unsupported
  "text": "Hola",         // texto o pie de foto; null si no hay
  "quoted": { "provider_message_id": "3EB0…" },   // o null
  "media": {              // solo tipos con archivo
    "mime": "image/jpeg", "size": 48213, "file_name": null, "seconds": null, "voice": false,
    "descriptor": { … }   // opaco para Laravel: lo devuelve tal cual en POST /v1/sessions/{id}/media
  },
  "location": { "latitude": 19.4, "longitude": -99.1, "name": null, "address": null },
  "contacts": [ { "name": "…", "phones": ["…"] } ],
  "reaction": { "provider_message_id": "3EB0…", "emoji": "👍" }   // solo type = reaction
}
```

- Grupos, estados (`status@broadcast`), canales y difusiones **no** se notifican.
- El historial que WhatsApp ofrece al vincular **no** se sincroniza (`syncFullHistory: false`) y los mensajes anteriores
  a la vinculación se descartan: solo se notifican mensajes nuevos (`type = notify`).
- `descriptor` contiene lo que Baileys necesita para descargar y descifrar (clave de medio, ruta directa, tipo). Laravel
  lo guarda cifrado junto al adjunto y nunca lo entrega al navegador.

## 6. Archivos

- **Entrantes:** al recibir `message.inbound` con `media`, Laravel crea el adjunto pendiente y encola la descarga; el
  job llama a `POST /v1/sessions/{id}/media` con el `descriptor`, valida tipo y tamaño con las reglas existentes y guarda
  con `MediaDisk`. Si el NAS no está disponible y es obligatorio, el adjunto queda pendiente de reintento: nunca se
  guarda en el VPS en silencio.
- **Salientes:** Laravel expone `GET /internal/whatsapp-web/media/{attachment}` solo para el servicio, firmada con §2
  usando parámetros de consulta (`ts`, `nonce`, `sig` en lugar de cabeceras; mismo mensaje a firmar, con `path` sin
  esos tres parámetros) y vigencia de 60 s. Sirve el archivo en streaming desde `MediaDisk`.

## 7. Sesiones en disco

`WAWEB_DATA_DIR` (producción: `/var/lib/chatmanager/whatsapp-web`), fuera de las versiones desplegadas y fuera de
cualquier carpeta pública o compartida:

```
sessions/{connectionId}/   credenciales y claves de Baileys, cada archivo cifrado con AES-256-GCM
outbox/                    eventos pendientes de entregar a Laravel
sent/                      claves de idempotencia → provider_message_id (48 h)
tmp/                       archivos salientes en tránsito
service.lock               bloqueo de instancia única
```

Clave de cifrado `WAWEB_SESSION_KEY` (32 bytes en base64), solo en el entorno del servicio. Permisos `0700`, dueño el
usuario del servicio. Un despliegue nunca toca este directorio. El bloqueo de instancia única impide dos procesos
sobre las mismas sesiones.

## 8. API para la interfaz: navegador → Laravel

Rutas con sesión de usuario, bajo el permiso de gestionar conexiones, solo para conexiones `whatsapp_web`:

| Método y ruta                                           | Efecto                                                               |
| ------------------------------------------------------- | -------------------------------------------------------------------- |
| `GET connections/{connection}/whatsapp-web/session`     | `LinkState` (sondeo cada 2 s durante la vinculación, 10 s en reposo) |
| `POST connections/{connection}/whatsapp-web/link`       | Inicia la vinculación (genera QR). Responde `LinkState`.             |
| `POST connections/{connection}/whatsapp-web/refresh`    | Pide un QR nuevo cuando el anterior caducó.                          |
| `POST connections/{connection}/whatsapp-web/disconnect` | Desconectar temporalmente (conserva credenciales).                   |
| `POST connections/{connection}/whatsapp-web/reconnect`  | Reconectar sin QR.                                                   |
| `POST connections/{connection}/whatsapp-web/logout`     | Cerrar sesión del dispositivo (exige vincular de nuevo).             |

```jsonc
// LinkState
{
    "state": "qr_pending",
    "state_label": "Esperando escaneo",
    "tone": "warning", // success | warning | danger | neutral | info
    "can_send": false,
    "service_available": true, // false: el servicio Node no responde
    "phone": null, // "+52 55 1234 5678" cuando se conoce
    "push_name": null,
    "qr": {
        "svg": "<svg…>",
        "expires_at": "2026-11-12T18:00:20Z",
        "seconds_left": 17,
    }, // solo en qr_pending
    "connected_since": null, // ISO 8601
    "last_event_at": "2026-11-12T18:00:00Z",
    "last_inbound_at": null,
    "last_outbound_at": null,
    "reconnects": 0,
    "last_error": null, // texto legible en español, o null
    "actions": {
        "link": false,
        "refresh": true,
        "disconnect": false,
        "reconnect": false,
        "logout": false,
    },
}
```

El QR viaja como SVG generado en el servidor, solo en esta respuesta, solo a quien puede gestionar la conexión; no se
guarda en la base ni se registra. Las credenciales de la sesión nunca llegan al navegador.

Crear la conexión: el formulario de conexiones acepta `provider = "whatsapp_web"` con nombre, departamentos y mensajes
automáticos; no pide credenciales de Meta. La vinculación es un paso posterior a guardarla.

## 9. Variables de entorno

| Laravel (`.env`)       | Servicio Node (`services/whatsapp-web/.env`)                       | Uso                                                  |
| ---------------------- | ------------------------------------------------------------------ | ---------------------------------------------------- |
| `WHATSAPP_WEB_ENABLED` | —                                                                  | Muestra la modalidad en la interfaz.                 |
| `WHATSAPP_WEB_URL`     | `WAWEB_HOST`, `WAWEB_PORT`                                         | Dónde escucha el servicio (`http://127.0.0.1:3410`). |
| `WHATSAPP_WEB_SECRET`  | `WAWEB_SECRET`                                                     | Secreto compartido (§2). El mismo valor.             |
| `WHATSAPP_WEB_TIMEOUT` | —                                                                  | Segundos de espera de Laravel al servicio (15).      |
| —                      | `WAWEB_LARAVEL_URL`                                                | Base de Laravel para eventos y medios.               |
| —                      | `WAWEB_DATA_DIR`, `WAWEB_SESSION_KEY`                              | Sesiones cifradas (§7).                              |
| —                      | `WAWEB_LOG_LEVEL`, `WAWEB_QR_MAX_REFRESH`, `WAWEB_MAX_MEDIA_BYTES` | Operación.                                           |

## 10. Precisiones de la implementación

- `LinkState` incluye además `state_description` (frase para la interfaz) y `linked_at`. `GET …/session` lo puede leer
  quien puede ver la conexión; sin permiso de gestión llega sin QR y con todas las `actions` en `false`.
- Los conflictos de estado de las rutas del §8 (ya vinculada, nada que reconectar) responden `422` con
  `errors.session[0]`; `503` significa servicio caído o modalidad deshabilitada.
- Laravel guarda los identificadores de mensaje como `wweb.{connection_id}.{id}`: dos números vinculados ven el mismo id
  de WhatsApp cuando se escriben entre sí. Un contacto sin teléfono se identifica como `lid:{id}`.
- Variables adicionales de Laravel: `WHATSAPP_WEB_CALLBACK_URL` (base con la que se construyen las URLs de medios para
  el servicio) y `WHATSAPP_WEB_ALLOWED_IPS` (orígenes aceptados en `/internal/whatsapp-web/*`; vacío acepta cualquiera,
  la firma siempre se exige). Del servicio: `WAWEB_LIBRARY_LOG_LEVEL`, `WAWEB_RECONNECT_MAX_ATTEMPTS`,
  `WAWEB_SEND_TIMEOUT_MS`, `WAWEB_EVENT_TIMEOUT_MS`, `WAWEB_ENV_FILE`.
- Las conexiones `whatsapp_web` no envían mensajes automáticos (bienvenida, ausencia, despedida): el servidor los omite
  aunque existan textos configurados.
- Señal de tiempo real `connection.session` con `{ connection_id }` al canal personal de quien puede ver o gestionar
  conexiones; la interfaz sondea además como respaldo.
