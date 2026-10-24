# Integrar el widget de chat web en un sitio

Guía para quien inserta el chat web de Chat Manager en un sitio externo. El modelo del canal, la identidad firmada y la
privacidad están en [canal-chat-web.md](canal-chat-web.md); la API que usa el iframe, en [api-v1.md](api-v1.md).

## 1. Instalación

1. En **Conexiones › Nueva conexión › Chat web**, crea la conexión, escribe los **orígenes permitidos** del sitio
   (`https://tienda.example.com`, uno por campo con «Agregar dominio»; sin ruta) y guarda. Actívala.
2. Copia el **Código para insertar** de la tarjeta del widget y pégalo antes de `</body>` en cada página del sitio:

```html
<script>
    (function (w, d, s, u) {
        w.ChatManager =
            w.ChatManager ||
            function () {
                (w.ChatManager.q = w.ChatManager.q || []).push(arguments);
            };
        var j = d.createElement(s);
        j.async = true;
        j.src = u;
        d.head.appendChild(j);
    })(
        window,
        document,
        'script',
        'https://chat.example.com/widget/v1/chat-manager.js',
    );
    ChatManager('init', { key: 'CLAVE_PUBLICA_DE_48_CARACTERES' });
</script>
```

La URL es `{APP_URL}/widget/v1/chat-manager.js` y la clave es la `public_key` del widget: es **pública** (va en el HTML
de todas las páginas); lo que protege el chat es la lista de orígenes, no la clave. El fragmento es asíncrono: las
llamadas a `ChatManager(...)` hechas antes de que cargue el script se guardan en una cola y se ejecutan al cargar.

3. Abre el sitio: aparece el botón flotante. Si no aparece, revisa la consola (`[ChatManager] El chat no respondió.
Revisa que este dominio esté autorizado en la conexión.` a los 20 s) y el §6.

## 2. API JavaScript

`window.ChatManager(comando, ...args)` o `window.ChatManager.init(opciones)`, que devuelve la instancia.

| Opción                                        | Tipo                  | Por defecto     | Notas                                                |
| --------------------------------------------- | --------------------- | --------------- | ---------------------------------------------------- |
| `key`                                         | string                | (obligatoria)   | `[A-Za-z0-9]{32,64}`                                 |
| `container`                                   | Element               | `document.body` | Dónde montar el nodo `<chat-manager-widget>`         |
| `position`                                    | `'right'` \| `'left'` | la del widget   |                                                      |
| `offset`                                      | `{x, y}` en px        | `{0, 0}`        | Se suma al margen de 16 px y al área segura          |
| `zIndex`                                      | number                | `2147483000`    |                                                      |
| `launcher`                                    | boolean               | la del widget   | `false` para abrirlo solo desde un botón propio      |
| `open`                                        | boolean               | `false`         | Abrir al cargar                                      |
| `locale`                                      | string                | —               | Reservada: el widget está en español en esta versión |
| `onReady`, `onOpen`, `onClose`, `onUnread(n)` | function              | —               | Equivalen a los eventos                              |

Instancia: `open()`, `close()`, `toggle()`, `isOpen()`, `identify(identidad)`, `logout()`, `on(evento, fn)` (devuelve
la función para desuscribirse), `off(evento, fn)`, `destroy()`.

Comandos de la cola: `ChatManager('open' | 'close' | 'toggle' | 'destroy' | 'isOpen' | 'logout')` (`'reset'` es un
alias de `'logout'`), `ChatManager('identify', id)`,
`ChatManager('on' | 'off', evento, fn)` y `ChatManager('jquery', $)`.

Eventos: `ready` (el frame respondió), `open`, `close`, `unread` (número de respuestas sin leer con el panel cerrado),
`error` (`{code}`: `frame_timeout` si el frame no responde en 20 s, `identity_rejected` si la identidad firmada no es
válida, o el código que envíe el frame).

Reglas:

- **Una instancia por página.** `init` es idempotente: un segundo `init` avisa en la consola y devuelve la existente.
- `logout()` termina la sesión del visitante (`DELETE /api/v1/widget/session`), borra su token del iframe y el widget
  empieza de nuevo como un visitante nuevo. **El sitio debe llamarlo cuando su usuario cierra sesión**: si no, en un
  equipo compartido la siguiente persona reanudaría la conversación anterior (`destroy()` no borra el token).
- Con «Identificación obligatoria», una sesión guardada solo se reanuda cuando la página vuelve a llamar a `identify` en
  esa carga (con una firma nueva): si la identidad es de otra persona, el servidor revoca la sesión guardada y crea otra;
  si nadie la envía, el widget pide iniciar sesión y no muestra el historial.
- `destroy()` elimina los nodos, el iframe (y con él su WebSocket), el único listener `message` de `window`, los
  temporizadores y la cola. Después se puede volver a llamar a `init`.
- Escape dentro del panel lo cierra y devuelve el foco al botón; el botón lleva `aria-expanded` y `aria-controls`.

```html
<button type="button" id="ayuda">¿Necesitas ayuda?</button>
<script>
    ChatManager('init', { key: 'CLAVE', launcher: false });
    document.getElementById('ayuda').addEventListener('click', function () {
        ChatManager('open');
    });
    ChatManager('on', 'unread', function (count) {
        document.title = count ? '(' + count + ') Tienda' : 'Tienda';
    });
</script>
```

**Identificar al usuario del sitio** (si el widget no es «Solo visitantes anónimos»): el backend del sitio firma la
identidad ([canal-chat-web.md §5](canal-chat-web.md#5-identidad-firmada)) y la página la entrega:

```js
ChatManager('identify', {
    external_id: 'cliente-1842',
    email: 'ana@example.com',
    name: 'Ana Pérez',
    iat: 1792790400,
    nonce: 'Zq8nKx2LwP4rT6vY9bC1dF3h',
    signature: '9f2c…64 hex…',
});
```

Nunca pongas el secreto de identificación en el JavaScript del sitio.

## 3. Adaptador jQuery

El mismo archivo incluye un adaptador que no depende de jQuery. Si `window.jQuery` existe cuando carga el script, se
registra `$.fn.chatManager`; si jQuery llega después, regístralo con `ChatManager.jquery($)`.

```html
<div id="soporte"></div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://chat.example.com/widget/v1/chat-manager.js" async></script>
<script>
    $(window).on('load', function () {
        ChatManager.jquery($); // por si jQuery cargó después del widget
        $('#soporte').chatManager('init', { key: 'CLAVE', launcher: false });
        $('#abrir-chat').on('click', function () {
            $('#soporte').chatManager('open');
        });
    });
</script>
```

Comandos: `'init'` (con las opciones; el elemento pasa a ser `container`), `'open'`, `'close'`, `'toggle'`,
`'destroy'`, `'logout'` e `'identify'` (con la identidad). La instancia vive en un `WeakMap`, nunca en `$.data`, y solo
en el elemento donde se montó: como la página admite una sola instancia, `init` sobre varios elementos monta el widget
en el primero y los demás no quedan asociados (los comandos sobre ellos no hacen nada).

## 4. Protocolo `postMessage`

Entre la página (cargador) y el iframe solo viajan sobres `{v: 1, source: 'chat-manager', type, payload?}`, siempre con
`targetOrigin` explícito. **El token del visitante nunca viaja por `postMessage` ni por la URL.**

| Dirección      | Tipo                                                                                                                                                       |
| -------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Página → frame | `cm:hello`, `cm:open`, `cm:close`, `cm:identify {identity}`, `cm:reset` (`logout`), `cm:preview-appearance` (solo vista previa, desde el origen de la app) |
| Frame → página | `cm:ready {appearance}`, `cm:state {open}`, `cm:unread {count}`, `cm:close-request` (Escape), `cm:error {code}`                                            |

- El cargador acepta solo mensajes cuyo `source` es la ventana de su iframe y cuyo `origin` es el del script.
- El frame acepta solo mensajes de su `parent` y del origen del sitio que el servidor validó; responde `cm:ready` por su
  cuenta al cargar y otra vez a cada `cm:hello`.
- `appearance` solo lleva título, color `#rrggbb`, posición, botón y avatar `data:image/...`; se valida al recibirla.

## 5. Aislamiento

- El botón vive en un **Shadow DOM cerrado** con `:host { all: initial !important }`: el CSS del sitio no lo alcanza y
  el del widget no se filtra al sitio.
- La conversación vive en un `<iframe>` de Chat Manager con
  `sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox"`,
  `referrerpolicy="origin"`, `allow=""` y `title="Chat: {título}"`. El token, la API y el WebSocket están en ese
  iframe; la same-origin policy impide que el JavaScript del sitio (o un XSS del sitio) los lea.
- El frame envía su propia CSP: `frame-ancestors 'self'` más los orígenes permitidos (también fuera de producción),
  `script-src 'self' 'nonce-…'`, `connect-src 'self'` más el `wss` de Reverb, `worker-src 'none'`, `frame-src 'none'`,
  `base-uri 'none'`, `object-src 'none'`. Es la única respuesta de la aplicación sin `X-Frame-Options`.

## 6. CSP del sitio

Si el sitio publica una `Content-Security-Policy`, debe permitir:

```text
script-src  … https://chat.example.com 'nonce-…'   ← el cargador y el fragmento en línea (o muévelo a un archivo propio)
frame-src   … https://chat.example.com             ← el iframe del chat (o child-src si no hay frame-src)
style-src   … 'unsafe-inline'                       ← la hoja <style> del botón dentro del Shadow DOM
```

No hace falta `connect-src` hacia Chat Manager en el sitio: las llamadas y el WebSocket salen del iframe. Sin
`'unsafe-inline'` en `style-src` el botón aparece sin estilos (limitación conocida, ver
[limitaciones.md](limitaciones.md#6-no-implementado--trabajo-futuro)). Si el fragmento en línea está bloqueado, colócalo
en un archivo `.js` del propio sitio.

## 7. Móviles, áreas seguras y viewport

- A 480 px o menos, el panel ocupa toda la pantalla (`100dvh`) y oculta el botón mientras está abierto.
- Los márgenes usan `max(16px, env(safe-area-inset-*))`. Para que el notch y la barra de inicio de iOS se respeten, el
  sitio debe declarar `viewport-fit=cover`:

```html
<meta
    name="viewport"
    content="width=device-width, initial-scale=1, viewport-fit=cover"
/>
```

- Respeta `prefers-reduced-motion` y `prefers-color-scheme`. Verificado sin desbordes a 320, 360, 375, 390, 430, 768, 1024,
  1280 y 1440 px, también con CSS hostil del sitio (`tests/e2e/hosts/host-hostile.html`).

## 8. Caché del cargador

`/widget/v1/chat-manager.js` no lleva hash en el nombre (el fragmento de los sitios no cambia), así que se sirve con una
caché corta. La plantilla versionada `deploy/nginx/chat-manager.conf` ya incluye este bloque en el `server` de Chat
Manager:

```nginx
location = /widget/v1/chat-manager.js {
    access_log off;
    add_header Cache-Control "public, max-age=300, stale-while-revalidate=86400" always;
    add_header X-Content-Type-Options nosniff always;
    try_files $uri =404;
}
```

Una versión nueva llega a todos los sitios en unos 5 minutos. El frame (`/widget/v1/frame/{key}`) se responde con
`Cache-Control: no-store`; sus scripts y estilos están en `/build/` con hash y caché inmutable. `public/widget` lo genera
`npm run build` (`vp build --config vite.widget.config.ts`) y está en `.gitignore`: en el despliegue siempre hay que
construir ([infraestructura-y-despliegue.md §16](infraestructura-y-despliegue.md#16-despliegue)).

## 9. Tamaños medidos

Medidos sobre `npm run build` de esta fase (gzip con `gzip -c`):

| Recurso                                                    | Sin comprimir | gzip    |
| ---------------------------------------------------------- | ------------- | ------- |
| Cargador `public/widget/v1/chat-manager.js` (IIFE, es2019) | 9,6 KB        | 4,1 KB  |
| UI del frame, código propio (`main-*.js`)                  | 29,6 KB       | 10,6 KB |
| Fragmento compartido con la app (Vue y pusher-js)          | 176 KB        | 61 KB   |
| Estilos del frame (`widget-*.css`)                         | 14,4 KB       | 3,9 KB  |

El sitio solo descarga el cargador al cargar la página; el frame y sus recursos se cargan en el iframe y quedan en
caché.

## 10. Página de ejemplo local

Con `APP_ENV=local` y `WEBCHAT_DEMO_ENABLED=true` en `.env` (y `php artisan config:clear`), existe
`GET /widget/v1/demo/{publicKey}`: una página con el fragmento real de esa conexión. La ruta **solo se registra** en
local con esa variable, así que nunca existe en producción ni en las pruebas. Para que el widget monte, el origen de la
aplicación (por ejemplo `http://localhost:8000`) debe estar en los orígenes permitidos de la conexión. Las páginas
host que usan las pruebas E2E (otro origen, CSS hostil, jQuery, un intruso) están en `tests/e2e/hosts/`.

## 11. Problemas frecuentes

| Síntoma                                             | Causa probable                                                             |
| --------------------------------------------------- | -------------------------------------------------------------------------- |
| No aparece nada y la consola dice `frame-ancestors` | El origen del sitio no está en la lista (revisa `www.`, puerto y `https`)  |
| `frame_timeout` a los 20 s                          | Origen no permitido, CSP del sitio sin `frame-src`, o la app no responde   |
| El botón aparece sin estilos                        | CSP del sitio sin `'unsafe-inline'` en `style-src`                         |
| «El chat no está disponible»                        | Conexión inactiva o sin configurar (409 `widget_unavailable`)              |
| Tras rotar la clave el widget desaparece            | El fragmento del sitio aún lleva la clave anterior                         |
| `identity_rejected`                                 | Firma con otro secreto, `iat` desfasado más de 300 s o `nonce` reutilizado |
| Las respuestas tardan unos segundos                 | Sin Reverb: el widget usa sondeo (4 s visible, 20 s oculto)                |
