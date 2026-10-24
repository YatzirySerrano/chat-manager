# Canal Chat web

El chat web es el segundo canal de Chat Manager, junto a WhatsApp. Un sitio externo inserta un fragmento de código, sus
visitantes escriben desde un widget y los agentes los atienden en la misma bandeja, con la misma asignación,
distribución, transferencias, reportes y auditoría que WhatsApp. No es un sistema aparte: los mensajes del visitante
entran por el mismo `ReceiveInboundMessage` y las respuestas salen por el mismo `DeliverOutboundMessage`.

Documentos relacionados: integración en el sitio en [widget-integracion.md](widget-integracion.md), la API del visitante
y la de integración en [api-v1.md](api-v1.md) (con el contrato en [openapi/v1.json](openapi/v1.json)), tiempo real en
[tiempo-real.md](tiempo-real.md#canal-del-visitante-del-chat-web), seguridad en
[seguridad-y-auditoria.md](seguridad-y-auditoria.md#chat-web-y-api-v1) y decisiones en
[decisiones.md](decisiones.md#adr-web-1--widget-en-iframe-servido-por-chat-manager).

## 1. Arquitectura del canal

```text
 Sitio del cliente (https://tienda.example.com)              Chat Manager (APP_URL)
 ┌────────────────────────────────────────────┐
 │ <script> fragmento → /widget/v1/chat-manager.js (cargador, ~4 KB gzip, sin dependencias)
 │   Shadow DOM cerrado: botón flotante         │
 │   <iframe sandbox src=/widget/v1/frame/{key}>─────────▶ WidgetFrameController
 │   ┌──────────────────────────────────────┐   │          valida el Referer contra allowed_origins,
 │   │ UI del visitante (Vue 3, sin Inertia)│   │          CSP frame-ancestors propia, ticket firmado
 │   │ token en localStorage del iframe     │──────HTTP──▶ /api/v1/widget/… (webchat.session, mismo origen)
 │   │ pusher-js                            │──────WSS───▶ Reverb: private-webchat.sessions.{id}
 │   └──────────────────────────────────────┘   │
 │  postMessage {v:1, source:'chat-manager'} ◀─▶│ (solo apariencia y estado; nunca el token)
 └────────────────────────────────────────────┘

 POST messages ─▶ ReceiveVisitorMessage ─▶ ReceiveInboundMessage ─▶ contacto, conversación, distribución, bandeja
 respuesta del agente ─▶ QueueOutboundMessage ─▶ DeliverOutboundMessage ─▶ ChannelTransports ─▶ WebChatTransport
   (sin red: accepted al instante) ─▶ MessageStatusChanged ─▶ SignalVisitorSessions ─▶ 'webchat.signal' {type, cursor}
```

Piezas por capa:

| Capa          | Piezas                                                                                                                                                        |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Enums         | `ConnectionChannel::WebChat` (`web_chat`, «Chat web»), `ConnectionProvider::WebWidget`, `ContactSource::WebChat`, `CustomerWindowStatus::NotApplicable`       |
| Modelos       | `Connection` (`isWebChat()`, `isConfigured()`, `webChatWidget()`), `WebChatWidget`, `WebChatSession`                                                          |
| Servicios     | `FrameTicket`, `VisitorIdentity`, `VisitorSessions`, `VisitorCursor`, `VisitorRealtimeConfig`, `WebChatAvailability`, `WebChatTransport`, `ChannelTransports` |
| Acciones      | `ReceiveVisitorMessage`, `UpdateWidgetSettings`, `CreateConnection` (crea el widget)                                                                          |
| HTTP          | `WidgetFrameController`, `WidgetDemoController`, `App\Http\Controllers\WebChat\Api\*`, `WebChatWidgetController` (administración)                             |
| Middleware    | `webchat.session` (`AuthenticateVisitorSession`), `ApiResponseHeaders`, `SecurityHeaders` (atributo `security.allow_framing`)                                 |
| Tiempo real   | `VisitorSessionSignal` (`webchat.signal`), `SignalVisitorSessions` (cola `realtime`)                                                                          |
| Front         | `resources/js/widget/loader.ts`, `jquery.ts`, `protocol.ts`, `frame/*` (Vue), `resources/css/widget.css`                                                      |
| Programador   | `webchat:prune-sessions` diario a las 04:20                                                                                                                   |
| Configuración | `config/webchat.php` (límites, vidas, sondeo) y `config/cors.php` (`paths => []`)                                                                             |

El chat web no tiene ventana de 24 horas ni plantillas: `CustomerServiceWindow` devuelve «No aplica» (`not_applicable`),
que permite mensajes libres en todo momento, y la sincronización de plantillas se niega para canales sin
`supportsTemplates()`. El compositor, el panel de detalles y el mensaje al reabrir se adaptan al canal.

## 2. Modelo

| Tabla                | Qué guarda                                                                                                                                                                                                                                       |
| -------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `connections`        | La conexión, con `channel = web_chat` y `provider = web_widget`. Sin credenciales de Meta: `ConnectionRequest` las prohíbe fuera de WhatsApp y el canal es inmutable.                                                                            |
| `web_chat_widgets`   | Uno por conexión: `public_key` (48 caracteres, va en el fragmento), `allowed_origins`, apariencia, `identity_mode`, `identity_secret` (cifrado, oculto), `prechat`, `availability_mode`, `session_idle_days` (30 por defecto, 1–90), rotaciones. |
| `web_chat_sessions`  | Una por visitante y navegador: `public_id` (ULID), `visitor_id`, `token_hash` (sha256), `contact_id` (tras el primer mensaje), `profile`, `origin`, `landing_url`, `ip_hash`, `user_agent`, `expires_at`, `revoked_at`, `revoke_reason`.         |
| `contact_identities` | Identidad del visitante con `channel = web_chat` y `external_id = visitor_id`. Nunca se fusiona con WhatsApp ni con otra conexión.                                                                                                               |

Estados de configuración y salud de una conexión web (`ConnectionResource`): «Sin configurar» (sin orígenes),
«Configurada», «Recibiendo» (ya llegaron mensajes), «Operativa» (entradas y salidas), «Error» e «Inactiva».
`Connection::isConfigured()` es, para el chat web, que exista el widget con al menos un origen;
`hasRequiredCredentials()` conserva su significado (credenciales de Meta) y es falso para el chat web.

## 3. Configuración de conexiones

En **Conexiones › Nueva conexión** se elige el canal (WhatsApp Cloud API o Chat web). Con el permiso
`connections.manage`:

1. Nombre, departamento predeterminado y estado, como cualquier conexión.
2. **Orígenes permitidos:** protocolo y dominio, sin ruta (`https://tienda.example.com`). Hasta 20. Solo `https`; `http`
   únicamente para `localhost`, `127.0.0.1` y `[::1]`. Se normalizan en minúsculas y sin el puerto por defecto; un
   duplicado tras normalizar se rechaza. Sin orígenes la conexión queda «Sin configurar» y no se puede activar.
3. **Apariencia:** título (60), subtítulo (120), bienvenida y texto fuera de línea (500), posición, color de acento
   (`#rrggbb` con contraste mínimo con texto blanco), botón flotante y su etiqueta, avatar PNG, JPG o WebP de hasta
   128 KB (se valida por sus bytes y se guarda en el disco privado).
4. **Identificación:** «Solo visitantes anónimos», «Identificación opcional» o «Identificación obligatoria»; el
   secreto de identificación se genera o rota con confirmación y se muestra **una sola vez** (§5).
5. **Formulario previo:** nombre y/o correo, obligatorio o no, y aviso de privacidad (500).
6. **Disponibilidad:** «Siempre en línea» o «Según el horario de atención» del departamento predeterminado
   (`BusinessHours`). Fuera de línea el visitante puede escribir igual; su mensaje espera en la cola.
7. **Días de inactividad de la sesión** (1–90).
8. **Código para insertar** (copiar) y **vista previa real**: el mismo frame con `?preview=1`, que solo se honra si el
   Referer es la propia aplicación y nunca emite ticket ni crea sesiones.
9. **Rotar clave:** genera otra `public_key` y revoca todas las sesiones (`key_rotated`); hay que actualizar el
   fragmento en el sitio.

Rutas de administración (`routes/connections.php`, `auth` y `verified`, política `manageWidget`):
`PUT connections/{connection}/widget`, `POST connections/{connection}/widget/rotate-key` y
`POST connections/{connection}/widget/rotate-identity-secret` (ambas `throttle:10,1`). Cada cambio queda en Auditoría:
`web_chat.widget_updated`, `web_chat.key_rotated`, `web_chat.identity_secret_rotated`. Verificar credenciales y la
suscripción de la app de Meta solo existen para WhatsApp; el webhook de WhatsApp responde 404 a una conexión web.

**Multiconexión.** Cada conexión web tiene su widget, su clave y sus orígenes. Un sitio puede tener varias (por marca o
por departamento) y varios sitios pueden compartir una. Las identidades y los contactos son por conexión: la misma
persona en dos conexiones son dos contactos, y un `external_id` firmado para una conexión no sirve en otra.

## 4. Ciclo de vida del visitante

1. **Carga.** El cargador crea el iframe con `referrerpolicy="origin"`. `WidgetFrameController` compara el origen del
   Referer con `allowed_origins`: si coincide, incrusta un **ticket de frame** (HMAC-SHA256 con `app.key` sobre
   `v1|widget_id|public_key|origin|exp`, 600 s; rotar la clave invalida los tickets anteriores); si no, muestra «no autorizado» sin ticket. La barrera dura es la CSP
   `frame-ancestors` del frame, que el navegador aplica: un sitio no permitido ni siquiera ve el widget.
2. **Sesión.** El frame llama a `POST /api/v1/widget/{publicKey}/sessions` con el ticket (y, si aplica, el perfil del
   formulario previo o la identidad firmada) y recibe el token `wvs_{public_id}_{secreto}`, que guarda en
   `localStorage['cm:v1:{publicKey}']` del iframe (con respaldo en memoria si el almacenamiento falla). Si hay token
   guardado, la sesión se reanuda (con identificación obligatoria, solo después de que la página vuelva a enviar
   `identify` en esa carga); si no lo hay y el widget tiene formulario previo o identificación obligatoria, la sesión
   espera a ese formulario o a `identify`. `ChatManager('logout')` termina la sesión y borra el token: el sitio debe
   llamarlo al cerrar la sesión de su usuario. Si el ticket venció, el widget muestra «La sesión del chat caducó» con «Iniciar de
   nuevo».
3. **Primer mensaje.** El contacto (`source = web_chat`, nombre «Visitante web {últimos 6}» si no dio nombre) y la
   conversación se crean **solo** con el primer mensaje, por el pipeline normal: distribución, horario, mensajes
   automáticos, notificaciones y la bandeja general. Sesiones sin mensajes no dejan contactos basura.
4. **Respuestas.** El agente responde desde la bandeja; `WebChatTransport` acepta el mensaje sin red
   (`web.out.{ulid}`) y el visitante lo recibe por la señal de tiempo real o por el sondeo. El widget envía acuses de
   entrega y lectura (`POST receipts`), que pasan por el mismo `RecordMessageStatus` que los webhooks de Meta: solo
   hacia adelante y de forma idempotente (un acuse de lectura ya implica la entrega, así que no se escribe dos veces).
   Si un worker muere con una respuesta en `sending`, `whatsapp:maintenance reconcile-deliveries` la **reencola**
   (el visitante solo ve respuestas desde `accepted`, así que no hay riesgo de duplicado) mientras queden intentos;
   después falla con `delivery_error` y un texto propio del chat web, nunca con el aviso de duplicado de WhatsApp.
   Quitar un dominio de `allowed_origins` termina las sesiones abiertas desde él (401 en la siguiente petición).
   Cuando el contacto de una persona identificada se crea, sus otras sesiones identificadas en la conexión (otros
   dispositivos) lo reciben también, y leen el historial y las señales desde ese momento.
5. **Cierre.** Cerrar la conversación no termina la sesión: el siguiente mensaje reabre la conversación o crea otra
   según `reopen_window_minutes`, igual que WhatsApp. El visitante puede terminar la sesión con
   `DELETE /api/v1/widget/session` (`visitor_closed`); la UI actual no lo ofrece.

### Política de inactividad (WC-09)

- **Expiración deslizante:** `expires_at = min(ahora + session_idle_days, created_at + webchat.session.absolute_days)`
  (90 días por defecto, `WEBCHAT_SESSION_ABSOLUTE_DAYS`). Se renueva con el uso, como mucho una vez por minuto.
- **Vencida o revocada:** responde 401 y el widget crea otra sesión; el historial anterior no se muestra en la nueva
  sesión anónima.
- **Conexión desactivada:** no revoca; el widget queda fuera de línea y `POST messages` responde 409
  `widget_unavailable`. **Conexión eliminada:** 404 en el frame y 401 en la API del visitante.
- **Rotar la clave pública:** revoca todas las sesiones (`key_rotated`).
- **Purga:** `webchat:prune-sessions` borra a diario las sesiones vencidas o revocadas hace más de
  `webchat.prune_after_days` (30). Los contactos y conversaciones se conservan.

Es una política distinta de la de WhatsApp (ADR-META-5): en WhatsApp la identidad es el teléfono y no caduca.

## 5. Identidad firmada

Visitante **anónimo**: `visitor_id = 'wv:' + ULID`, uno por sesión. Visitante **identificado**:
`visitor_id = 'wi:' + substr(sha256(connection_id + '|' + external_id), 0, 48)`, el mismo para la misma persona en la
misma conexión, en cualquier dispositivo: una sesión identificada retoma su contacto y su historial. El prefijo no
numérico impide la vinculación por teléfono de `RegisterInboundContact`, que además solo aplica a WhatsApp.

El **backend** del sitio (nunca el navegador) firma la identidad de su usuario con el secreto de identificación:

```text
signature = hex( HMAC-SHA256( identity_secret,
    "v1\n{public_key}\n{external_id}\n{email}\n{name}\n{iat}\n{nonce}" ) )
```

- `email` y `name` vacíos si no se envían (deben ser exactamente los mismos valores que viajan en la identidad).
- `iat`: segundos Unix; se acepta con ±300 s de desfase (`webchat.identity.max_clock_skew_seconds`).
- `nonce`: 16–64 caracteres `[A-Za-z0-9_-]`, de un solo uso (`Cache::add`, 600 s). Firma una identidad nueva en cada
  carga de página.
- `external_id`: 1–191 caracteres, el id estable del usuario en el sitio. `name` ≤ 80, `email` ≤ 191.

PHP (el secreto, en una variable de entorno del sitio, por ejemplo `CHAT_MANAGER_IDENTITY_SECRET`):

```php
function chatManagerIdentity(string $publicKey, string $secret, string $externalId, ?string $email, ?string $name): array
{
    $iat = time();
    $nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $message = implode("\n", ['v1', $publicKey, $externalId, $email ?? '', $name ?? '', (string) $iat, $nonce]);

    return [
        'external_id' => $externalId,
        'email' => $email,
        'name' => $name,
        'iat' => $iat,
        'nonce' => $nonce,
        'signature' => hash_hmac('sha256', $message, $secret),
    ];
}

// En la plantilla: ChatManager('identify', <?= json_encode(chatManagerIdentity(...), JSON_HEX_TAG) ?>);
```

Node.js:

```js
import { createHmac, randomBytes } from 'node:crypto';

export function chatManagerIdentity(
    publicKey,
    secret,
    externalId,
    email = null,
    name = null,
) {
    const iat = Math.floor(Date.now() / 1000);
    const nonce = randomBytes(24).toString('base64url');
    const message = [
        'v1',
        publicKey,
        externalId,
        email ?? '',
        name ?? '',
        String(iat),
        nonce,
    ].join('\n');

    return {
        external_id: externalId,
        email,
        name,
        iat,
        nonce,
        signature: createHmac('sha256', secret).update(message).digest('hex'),
    };
}
```

Reglas del servidor (`VisitorIdentity`, `VisitorSessions::identify`):

- Firma inválida, `iat` fuera de margen o nonce repetido: 401. Con `identity_mode = required`, crear sesión sin
  identidad válida también es 401. Con `anonymous`, la identidad se ignora al crear la sesión y
  `POST session/identity` responde 409 `conflict`.
- Identificar una sesión anónima **sin** mensajes cambia su identidad (200, mismo token). **Con** mensajes, la sesión
  se revoca (`identified`) y se emite una nueva identificada (201 con su token): el historial anónimo nunca se adjunta
  a la persona.
- El correo del formulario previo o de la identidad solo se escribe en el contacto si estaba vacío: nunca pisa lo que
  editaron los empleados.
- Rotar el secreto invalida de inmediato las firmas nuevas hechas con el anterior; las sesiones ya identificadas siguen
  vigentes.

## 6. Adjuntos

- **Del agente al visitante: sí.** `VisitorMessage.attachments[].url` es una URL temporal firmada (10 min,
  `webchat.attachments.url_ttl_seconds`) a `GET /api/v1/widget/session/attachments/{id}?s={sesión}`, que solo sirve
  archivos de mensajes salientes visibles para esa sesión (si no, 404). Imágenes JPEG, PNG, WebP y GIF en línea; el
  resto como descarga, con `nosniff`, CSP `sandbox` y `Referrer-Policy: no-referrer`.
- **Del visitante al agente: no en esta fase.** Es una limitación documentada
  ([limitaciones.md](limitaciones.md#6-no-implementado--trabajo-futuro)): el registro de multimedia entrante depende de
  un media id de Meta y abrir una subida anónima exige cuotas y antivirus que esta fase no cubre.

## 7. Privacidad

- La IP del visitante nunca se guarda en claro: `ip_hash = HMAC-SHA256(app.key, ip)`, que sirve para los límites y la
  investigación de abuso. El `user_agent` se recorta a 255 caracteres; `origin` y `landing_url` se guardan en la sesión.
- El widget no usa cookies (ni propias ni de terceros): solo el `localStorage` del iframe, particionado por sitio en los
  navegadores actuales.
- El formulario previo muestra el aviso de privacidad configurado. Su texto, y la retención de sesiones y contactos
  web, son decisiones legales y de producto pendientes (ver
  [limitaciones.md](limitaciones.md#8-antes-de-producción)).
- La API del visitante nunca devuelve notas, eventos, metadata, códigos de error, ids de usuario ni datos de otros
  visitantes; del agente solo su nombre.
- Los tokens `wvs_…` y `cm_live_…` se enmascaran en logs y exportaciones (`SensitiveText`).

## 8. En la bandeja

Las conversaciones del chat web aparecen en la bandeja general junto a WhatsApp, con el icono y la etiqueta del canal y
un filtro «Canal» (cuando hay conexiones visibles de los dos canales). El panel de detalles muestra «Visitante web», el
canal y la ventana «No aplica». Ver [bandejas-y-asignacion.md](bandejas-y-asignacion.md#vista-general-chips-y-contadores).

## 9. Pruebas

Pest: `tests/Feature/WebChat/*` (sesiones, identidad, mensajes, límites, tiempo real, adjuntos, frame),
`tests/Feature/Concurrency/WebWidgetConcurrencyTest.php` y las pruebas de conexiones. E2E: `tests/e2e/widget.mjs` con
las páginas host de `tests/e2e/hosts/`. Carga: escenario `widget` de `tests/load/run.php`. Comandos en
[pruebas.md §6](pruebas.md#6-escenarios-de-navegador-e2e) y pasos manuales en
[guia-pruebas-manuales.md §28](guia-pruebas-manuales.md#28-chat-web-e-integraciones).
