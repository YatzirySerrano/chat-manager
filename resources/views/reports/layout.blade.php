<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    {{-- Nothing may be loaded from the network: inline styles and data: images only. --}}
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; img-src data:; font-src data:">
    <title>{{ $title }} · {{ $context->platformName }}</title>
    @include('reports.partials.styles')
</head>
<body class="{{ $orientation->value }}">
    <header class="masthead">
        <div class="brand">
            @include('reports.partials.logo')
            <div>
                <div class="brand-name">{{ $context->platformName }}</div>
                <div class="brand-tagline">Centro de atención · Reporte institucional</div>
            </div>
        </div>
        <div class="masthead-meta">
            <div>Generado el <strong>{{ $generatedAt }}</strong></div>
            <div>Por <strong>{{ $context->actor->name }}</strong> · {{ $context->timezone }}</div>
        </div>
    </header>

    @yield('content')
</body>
</html>
