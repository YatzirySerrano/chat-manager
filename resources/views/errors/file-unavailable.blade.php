{{--
    Un archivo de una conversación no se pudo leer en este momento (el NAS no
    respondió o el almacenamiento falló). No es la pantalla de mantenimiento:
    el sistema sigue funcionando; esta página se recarga sola.
--}}
<!DOCTYPE html>
<html lang="es-MX">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta http-equiv="refresh" content="20">
    <title>Archivo no disponible por un momento · {{ config('app.name', 'Chat Manager') }}</title>
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f2f7f5; color: #12261d; padding: 24px; }
        main { max-width: 32rem; text-align: center; }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        p { margin: 0 0 .75rem; line-height: 1.5; color: #3d5a4d; }
        a { color: #00704a; font-weight: 600; }
    </style>
</head>
<body>
    <main>
        <h1>El archivo no se pudo abrir en este momento</h1>
        <p>{{ $message }}</p>
        <p>El sistema sigue funcionando con normalidad. Esta página vuelve a intentarlo sola en unos segundos.</p>
        <p><a href="javascript:location.reload()">Intentar de nuevo ahora</a></p>
    </main>
</body>
</html>
