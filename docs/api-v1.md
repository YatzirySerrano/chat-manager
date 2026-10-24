# API v1

Chat Manager expone dos superficies bajo `/api/v1`, con principales, límites y respuestas separados. El contrato
completo, verificado contra `php artisan route:list --path=api/v1`, los Form Requests y los Resources, está en
[openapi/v1.json](openapi/v1.json) (OpenAPI 3.1, en JSON porque el proyecto no tiene un parser YAML como dependencia).
`tests/Feature/Api/OpenApiDocumentTest.php` falla si una ruta `api/v1` falta en el documento o si el documento describe
una ruta que no existe.

| Superficie             | Rutas                                      | Principal y middleware                                                    | Quién la llama                           |
| ---------------------- | ------------------------------------------ | ------------------------------------------------------------------------- | ---------------------------------------- |
| Integración            | `/api/v1/health`, `/api/v1/conversations…` | Token `cm_live_…`; `api.token` y `api.ability:{capacidad}`                | Sistemas propios, servidor a servidor    |
| Visitante del chat web | `/api/v1/widget/…`                         | Token `wvs_…`; `webchat.session` (salvo crear sesión y adjuntos firmados) | Solo el iframe del widget (mismo origen) |

Cada middleware rechaza el prefijo del otro con 401: un token filtrado de una superficie no sirve en la otra. Ninguna
ruta usa sesión, cookies ni CSRF (grupo `api`), y `$request->user()` es siempre `null`: el token **es** el principal.

## 1. Rutas

Integración (`routes/api/integration.php`):

| Método | Ruta                                            | Nombre                                | Capacidad            | Límite         |
| ------ | ----------------------------------------------- | ------------------------------------- | -------------------- | -------------- |
| GET    | `/api/v1/health`                                | `api.v1.health`                       | (solo token válido)  | `api-v1-read`  |
| GET    | `/api/v1/conversations`                         | `api.v1.conversations.index`          | `conversations:read` | `api-v1-read`  |
| GET    | `/api/v1/conversations/{conversation}`          | `api.v1.conversations.show`           | `conversations:read` | `api-v1-read`  |
| GET    | `/api/v1/conversations/{conversation}/messages` | `api.v1.conversations.messages.index` | `messages:read`      | `api-v1-read`  |
| POST   | `/api/v1/conversations/{conversation}/messages` | `api.v1.conversations.messages.store` | `messages:send`      | `api-v1-write` |

Visitante (`routes/api/widget.php`):

| Método | Ruta                                              | Nombre                                   | Autenticación     | Límite                 |
| ------ | ------------------------------------------------- | ---------------------------------------- | ----------------- | ---------------------- |
| POST   | `/api/v1/widget/{publicKey}/sessions`             | `api.v1.widget.sessions.store`           | ticket del frame  | `webchat-sessions`     |
| GET    | `/api/v1/widget/session`                          | `api.v1.widget.session.show`             | `webchat.session` | `webchat-reads`        |
| DELETE | `/api/v1/widget/session`                          | `api.v1.widget.session.destroy`          | `webchat.session` | —                      |
| POST   | `/api/v1/widget/session/identity`                 | `api.v1.widget.session.identity.store`   | `webchat.session` | `webchat-sessions`     |
| GET    | `/api/v1/widget/session/messages`                 | `api.v1.widget.session.messages.index`   | `webchat.session` | `webchat-reads`        |
| POST   | `/api/v1/widget/session/messages`                 | `api.v1.widget.session.messages.store`   | `webchat.session` | `webchat-messages`     |
| POST   | `/api/v1/widget/session/receipts`                 | `api.v1.widget.session.receipts.store`   | `webchat.session` | `webchat-reads`        |
| GET    | `/api/v1/widget/session/attachments/{attachment}` | `api.v1.widget.session.attachments.show` | URL firmada       | —                      |
| POST   | `/api/v1/widget/broadcasting/auth`                | `api.v1.widget.broadcasting.auth`        | `webchat.session` | `webchat-broadcasting` |

La superficie del visitante es interna del widget: su forma puede cambiar junto con el widget, y un sistema externo debe
usar la de integración. El detalle de sus cuerpos está en el OpenAPI y su uso en
[canal-chat-web.md](canal-chat-web.md#4-ciclo-de-vida-del-visitante). El resto de este documento trata la API de
integración salvo donde se indica.

## 2. Tokens de integración

**Formato:** `cm_live_{public_id}_{secreto}`: `public_id` es un ULID en minúsculas (26 caracteres) y el secreto tiene 40
caracteres aleatorios. Solo se guarda `sha256(secreto)`; la verificación busca por `public_id` y compara con
`hash_equals`. El token en claro se muestra **una sola vez**, al emitirlo. Los tokens se enmascaran en logs y
exportaciones (`SensitiveText`).

**Emisión** en **Administración › Integraciones** (`admin/integrations/tokens`, permiso `integrations.manage`):

| Campo          | Reglas                                                                                                                                                         |
| -------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Nombre         | Obligatorio, ≤ 100. Aparece como autor de los mensajes que envíe la integración                                                                                |
| Capacidades    | Al menos una: `conversations:read`, `messages:read`, `messages:send`                                                                                           |
| Conexiones     | Obligatorio, 1–100 conexiones no eliminadas: la **allowlist**                                                                                                  |
| Departamentos  | Opcional, ≤ 100 departamentos activos: si se indica, también acota                                                                                             |
| IPs permitidas | Opcional, ≤ 50 IPs o rangos CIDR IPv4/IPv6 (una por línea o separadas por comas). Vacío = cualquier IP                                                         |
| Expira         | Fecha `Y-m-d` posterior a hoy y como máximo a 365 días (`integrations.tokens.max_days`); por defecto 90 días. Vence al final de ese día. **Todo token expira** |

**Revocación:** desde la misma página, con motivo opcional (≤ 255); surte efecto en la siguiente solicitud. Emisión y
revocación quedan en Auditoría (`api_token.issued`, `api_token.revoked`), sin el secreto. Los tokens vencidos o
revocados siguen listados como «Vencido» o «Revocado». `last_used_at` se actualiza como mucho una vez por minuto.

**Alcance (IDOR):** la visibilidad sale de `ApiToken::scopeConversations()`: conversaciones de sus conexiones y, si los
tiene, de sus departamentos. No hay binding implícito: una conversación inexistente y una fuera de alcance responden el
mismo 404 con el mismo cuerpo, así que la API no permite enumerar ids.

**Permisos de la interfaz:** `integrations.view` (ver la lista) e `integrations.manage` (emitir y revocar; sensible, no
se concede de forma automática). Ambos exigen alcance global, porque un token abarca conexiones de toda la
organización. `deploy.sh` ejecuta `php artisan app:sync-permissions` en cada despliegue (idempotente) para crearlos.

## 3. Autenticación y seguridad

- `Authorization: Bearer cm_live_…` en cada solicitud. Cualquier fallo de autenticación (sin cabecera, otro formato
  como un token `wvs_…`, token desconocido, revocado o vencido, **IP fuera de la lista**) responde el mismo **401**
  `unauthenticated` con `WWW-Authenticate: Bearer` y sin explicar el motivo.
- Token válido sin la capacidad de la ruta: **403** `forbidden`.
- **CORS: ninguno.** `config/cors.php` tiene `paths => []`: ningún navegador de otro origen puede leer las respuestas.
  La API de integración es servidor a servidor; no pongas el token en código de navegador.
- La autenticación corre **antes** que el límite con nombre (prioridad de middleware), así que los límites cuentan por
  token y no por IP.
- Detrás de Nginx, `TRUSTED_PROXIES` debe estar configurado para que la lista de IPs vea la IP real
  ([infraestructura §5](infraestructura-y-despliegue.md#5-variables-de-entorno-de-producción)).

## 4. Respuestas y errores

Todas las respuestas de `/api/v1` son JSON (aunque falte `Accept`), llevan `Cache-Control: no-store, private` y
`X-Request-Id` (cítalo al reportar un problema). Los errores tienen siempre la forma `{message, code}`, más `errors` en
el 422:

| HTTP | `code`                                                                                                                                | Cuándo                                                                                          |
| ---- | ------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| 400  | `bad_request`                                                                                                                         | Reservado para errores HTTP del framework; ninguna ruta lo produce a propósito hoy              |
| 401  | `unauthenticated`                                                                                                                     | Ver §3. Visitante: sesión vencida o revocada (el widget crea otra), firma de identidad inválida |
| 403  | `forbidden`                                                                                                                           | Falta la capacidad. Visitante: ticket inválido o vencido, canal ajeno, URL firmada inválida     |
| 404  | `not_found`                                                                                                                           | Inexistente o fuera de alcance, sin distinción                                                  |
| 405  | `method_not_allowed`                                                                                                                  | Método no admitido en la ruta                                                                   |
| 409  | `conflict`, `idempotency_key_reused`, `widget_unavailable`, `conversation_closed`, `connection_unavailable`, `customer_window_closed` | Estado del recurso                                                                              |
| 422  | `validation_failed`                                                                                                                   | Datos no válidos (incluye un cursor manipulado)                                                 |
| 429  | `rate_limited`                                                                                                                        | Límite superado; respeta `Retry-After`                                                          |
| 500  | `server_error`                                                                                                                        | Error interno, nunca con detalles                                                               |
| 503  | `service_unavailable`                                                                                                                 | Modo mantenimiento                                                                              |
| 503  | `temporarily_unavailable`                                                                                                             | Bloqueo o deadlock transitorio en la base; reintenta con la misma llave tras `Retry-After: 1`   |

```json
{
    "message": "La clave de idempotencia ya se usó con otra solicitud.",
    "code": "idempotency_key_reused"
}
```

Los textos de `message` están en español y pueden cambiar: programa contra `code`.

## 5. Recursos

**Conversation:** `id`, `status` (`pending`, `open`, `on_hold`, `closed`), `status_label`, `connection {id, name,
channel}`, `department {id, name} | null`, `contact {id, display_name}`, `assigned_agent {id, name} | null`,
`last_message_at`, `created_at`, `updated_at`, `closed_at`. Nunca el teléfono, el correo ni las notas del contacto.

**Message:** `id`, `conversation_id`, `direction` (`inbound`, `outbound`), `type`, `body`, `status`, `status_label`
(«Recibido», «Enviando», «Enviado», «Entregado», «Leído», «Fallido» o «Entrega no confirmada»), `author` (`contact`,
`agent`, `system`, `integration`), `error_code`, `attachments [{id, file_name, mime_type, size_bytes}]`, `created_at`.
Nunca la metadata ni el texto de error del proveedor. Las notas internas viven en otra tabla y nunca se listan.

**Health** (`GET /api/v1/health`, sin capacidad): `{status: 'ok', api_version: 'v1', server_time, token {id, name,
abilities, expires_at}, connections [{id, name, channel, operational}]}`. Úsalo para vigilar la expiración del token y
si sus conexiones están operativas. Nunca devuelve identificadores del proveedor, credenciales, teléfonos ni correos.

## 6. Paginación

Lista: `{data: [...], meta: {next_cursor, prev_cursor, limit}}`. `limit` 1–100 (por defecto 25).

- **Conversaciones:** orden `updated_at` descendente, `id` descendente, con cursor opaco (`?cursor=` con
  `meta.next_cursor` o `meta.prev_cursor`). Filtros: `status`, `connection_id`, `department_id`, `updated_since`
  (ISO 8601, incluye ese instante). Para sincronizar, guarda el `updated_at` mayor que viste y pide
  `updated_since` con él. El recorrido no es una foto fija: una conversación que se actualiza mientras paginas salta
  por delante del cursor y no aparece en esa pasada, pero sí en la siguiente con `updated_since`.
- **Conexiones eliminadas:** al eliminar una conexión de forma lógica, el token deja de alcanzar sus conversaciones y
  mensajes (404, igual que `/health` deja de listarla), aunque siga en su allowlist.
- **Mensajes:** del más antiguo al más nuevo; los cursores son ids de mensaje. `?after={id}` devuelve los siguientes
  (`next_cursor` se usa como `after`); `?before={id}` los últimos anteriores (`prev_cursor` se usa como `before`).
  `after` y `before` son excluyentes. Un cursor es `null` cuando no queda nada en esa dirección.

## 7. Envío e idempotencia

`POST /api/v1/conversations/{id}/messages` con `{"text": "…"}` (1–4096 caracteres) y la cabecera **obligatoria**
`Idempotency-Key` (`^[A-Za-z0-9_-]{8,64}$`, por ejemplo un UUID).

- El mensaje pasa por la misma cola que los de los agentes (`QueueOutboundMessage::handleForIntegration`): mismo bloqueo
  por conexión y por ventana de 24 h de WhatsApp, `sender_user_id` nulo, autor `integration` y el nombre del token en la
  bandeja. **No cambia la asignación** ni cuenta como primera respuesta.
- 201 con el mensaje encolado. El envío real es asíncrono: consulta su estado con `GET …/messages`.
- La llave se guarda como `client_reference = UUIDv5(namespace de la app, "{token public_id}:{Idempotency-Key}")`, así
  que cada token tiene su propio espacio. Repetir la misma llave con el mismo texto (huella sha256) en la misma
  conversación responde **200** con el mismo mensaje, aunque la conversación se haya cerrado después. Otra huella u otra
  conversación: **409** `idempotency_key_reused`.
- 409 `conversation_closed` si está cerrada; `connection_unavailable` si la conexión no puede enviar;
  `customer_window_closed` si la ventana de 24 h de WhatsApp está cerrada (en el chat web no aplica).
- Reintenta ante errores de red, 429 y 5xx **con la misma llave**; nunca generes una llave nueva para un reintento.

## 8. Límites

| Limitador              | Valor por defecto                                                        | Variable                                                                      |
| ---------------------- | ------------------------------------------------------------------------ | ----------------------------------------------------------------------------- |
| `api-v1-read`          | 600/min por token                                                        | `INTEGRATIONS_READS_PER_MINUTE`                                               |
| `api-v1-write`         | 60/min por token                                                         | `INTEGRATIONS_WRITES_PER_MINUTE`                                              |
| `webchat-frame`        | 120/min por clave e IP                                                   | `WEBCHAT_FRAME_PER_MINUTE`                                                    |
| `webchat-sessions`     | 10/min por clave e IP; 300/min por widget (solo con ticket válido)       | `WEBCHAT_SESSIONS_PER_IP_PER_MINUTE`, `…_PER_WIDGET_…`                        |
| `webchat-messages`     | 20/min por sesión; 60/min por IP; 6000/min por conexión (0 lo desactiva) | `WEBCHAT_MESSAGES_PER_SESSION_PER_MINUTE`, `…_PER_IP_…`, `…_PER_CONNECTION_…` |
| `webchat-reads`        | 60/min por sesión                                                        | `WEBCHAT_READS_PER_MINUTE`                                                    |
| `webchat-broadcasting` | 30/min por sesión                                                        | `WEBCHAT_BROADCASTING_PER_MINUTE`                                             |

Las IPs de los visitantes se cuentan por su hash; las IPv6 se agrupan por su red /64. El tope por widget de
`webchat-sessions` solo cuenta las sesiones que presentaron un ticket del frame válido, así que las peticiones basura no
lo agotan. El tope por conexión de `webchat-messages` es un techo de coste alto a propósito: un tope compartido bajo
permitiría que unas pocas IP dejaran sin chat a todos los visitantes. Otros límites: texto de integración ≤ 4096
(`integrations.message_max_length`), mensaje del visitante ≤ 2000 (`webchat.message_max_length`), historial del
visitante ≤ 50 por página. Bajo simultaneidad exacta entre procesos, el limitador del framework puede dejar pasar algunas
solicitudes de más (medido: 25 de 30 con 10 procesos sobre un límite fresco); ver
[limitaciones.md](limitaciones.md#7-riesgos-residuales-y-comportamientos-observados).

## 9. Ejemplos con `curl`

Los valores son ficticios.

```bash
API=https://chat.example.com/api/v1
TOKEN=cm_live_01jcz5q4m7v8w9x0y1z2a3b4c5_EjemploNoRealEjemploNoRealEjemploNoReal0

# Estado del token y sus conexiones
curl -sS "$API/health" -H "Authorization: Bearer $TOKEN"

# Conversaciones pendientes actualizadas desde una fecha
curl -sS -G "$API/conversations" -H "Authorization: Bearer $TOKEN" \
  --data-urlencode "status=pending" --data-urlencode "updated_since=2026-10-01T00:00:00-06:00" -d limit=50

# Siguiente página
curl -sS -G "$API/conversations" -H "Authorization: Bearer $TOKEN" --data-urlencode "cursor=eyJ1cGRhdGVkX2F0Ijo..."

# Mensajes nuevos desde el id 1842
curl -sS "$API/conversations/317/messages?after=1842" -H "Authorization: Bearer $TOKEN"

# Enviar (repetir este mismo comando devuelve 200 con el mismo mensaje)
curl -sS -X POST "$API/conversations/317/messages" \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -H "Idempotency-Key: 3f1c2a9e-7b4d-4e8a-9c21-5d6f7a8b9c0d" \
  -d '{"text":"Tu pedido 1029 ya salió. Te avisamos cuando llegue."}'
```

Respuesta del envío (201):

```json
{
    "data": {
        "id": 1851,
        "conversation_id": 317,
        "direction": "outbound",
        "type": "text",
        "body": "Tu pedido 1029 ya salió. Te avisamos cuando llegue.",
        "status": "queued",
        "status_label": "Enviando",
        "author": "integration",
        "error_code": null,
        "attachments": [],
        "created_at": "2026-10-23T12:04:11-06:00"
    }
}
```

## 10. Pruebas

`tests/Feature/Api/ApiErrorFormatTest.php`, `ApiTokenModelTest.php`, `OpenApiDocumentTest.php` y
`tests/Feature/Api/V1/*` (autenticación, conversaciones, límites, envío), además de `tests/Feature/WebChat/*` para la
superficie del visitante. Comandos en [pruebas.md](pruebas.md#2-pest).
