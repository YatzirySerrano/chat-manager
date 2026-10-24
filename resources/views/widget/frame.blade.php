<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="robots" content="noindex, nofollow">
        <meta name="color-scheme" content="light dark">
        <title>{{ $title }}</title>

        {{-- Public configuration of the widget (no secrets or tokens); read by resources/js/widget/frame/main.ts. --}}
        <script type="application/json" id="cm-config" nonce="{{ Vite::cspNonce() }}">{!! json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}</script>

        @vite(['resources/css/widget.css', 'resources/js/widget/frame/main.ts'])
    </head>
    <body>
        <div id="app">
            <noscript>Activa JavaScript para usar el chat.</noscript>
        </div>
    </body>
</html>
