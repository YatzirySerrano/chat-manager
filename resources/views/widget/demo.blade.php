<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="robots" content="noindex, nofollow">
        <title>Ejemplo del chat web · {{ $title }}</title>
        <style>
            body { font: 16px/1.5 system-ui, sans-serif; margin: 0 auto; max-width: 48rem; padding: 2rem 1rem 8rem; color: #111827; }
            h1 { font-size: 1.5rem; margin: 0 0 .5rem; }
            pre { background: #f3f4f6; border-radius: .5rem; overflow-x: auto; padding: 1rem; font-size: .8125rem; white-space: pre-wrap; word-break: break-all; }
            .actions { display: flex; flex-wrap: wrap; gap: .5rem; margin: 1rem 0; }
            button { font: inherit; padding: .5rem 1rem; border-radius: .5rem; border: 1px solid #d1d5db; background: #fff; cursor: pointer; }
            textarea { box-sizing: border-box; font: 13px ui-monospace, monospace; width: 100%; min-height: 9rem; }
            #log { font-size: .875rem; color: #374151; }
        </style>
    </head>
    <body>
        <h1>Página de ejemplo del chat web</h1>
        <p>Esta página solo existe en el entorno local. Para que el chat abra una sesión, el origen de esta página debe estar entre los dominios autorizados del widget: {{ implode(', ', $allowedOrigins) ?: 'ninguno' }}.</p>

        <h2>Snippet</h2>
        <pre>{{ $snippet }}</pre>

        <h2>API de control</h2>
        <div class="actions">
            <button type="button" data-command="open">Abrir</button>
            <button type="button" data-command="close">Cerrar</button>
            <button type="button" data-command="toggle">Alternar</button>
            <button type="button" data-command="identify">Identificar</button>
            <button type="button" data-command="destroy">Destruir</button>
        </div>
        <label for="identity">Identidad firmada por el backend del sitio (JSON con external_id, email, name, iat, nonce y signature):</label>
        <textarea id="identity" spellcheck="false">{"external_id":"","email":"","name":"","iat":0,"nonce":"","signature":""}</textarea>
        <p id="log" role="status" aria-live="polite"></p>

        {!! $snippet !!}
        <script nonce="{{ Vite::cspNonce() }}">
            (function () {
                var log = document.getElementById('log');
                ['ready', 'open', 'close', 'unread', 'error'].forEach(function (name) {
                    ChatManager('on', name, function (payload) {
                        log.textContent = 'Evento: ' + name + (payload !== undefined ? ' ' + JSON.stringify(payload) : '');
                    });
                });
                document.querySelectorAll('[data-command]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        var command = button.getAttribute('data-command');
                        if (command === 'identify') {
                            try {
                                ChatManager('identify', JSON.parse(document.getElementById('identity').value));
                            } catch (error) {
                                log.textContent = 'La identidad no es JSON válido.';
                            }
                            return;
                        }
                        ChatManager(command);
                    });
                });
            })();
        </script>
    </body>
</html>
