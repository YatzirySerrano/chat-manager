<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-palette="{{ $page['props']['platform']['palette'] ?? 'emerald' }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="theme-color" content="#f5f7f9" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#0a0e12" media="(prefers-color-scheme: dark)">
        <meta name="application-name" content="Chat Manager">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="Chat Manager">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script nonce="{{ Vite::cspNonce() }}">
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(0.975 0.004 247);
            }

            html.dark {
                background-color: oklch(0.16 0.01 255);
            }

            /* viewport-fit=cover: keep content clear of a landscape notch. */
            body {
                padding-left: env(safe-area-inset-left, 0px);
                padding-right: env(safe-area-inset-right, 0px);
            }
        </style>

        {{-- Search engines and link previews (WhatsApp, Facebook, X, Slack). Only the
             sign-in page is public; everything else requires a session. --}}
        @php
            $seoName = $page['props']['platform']['name'] ?? config('app.name', 'Chat Manager');
            $seoBase = app()->environment('production') ? rtrim((string) config('app.url'), '/') : url('/');
            $seoTitle = $seoName.' | Centro de atención omnicanal';
            $seoDescription = $seoName.': WhatsApp, Messenger, Instagram y chat web en una sola bandeja. Asigna, responde y da seguimiento a cada conversación con todo tu equipo.';
            $seoImage = $seoBase.'/og-image.png';
            $seoPublic = ($page['component'] ?? '') === 'auth/Login';
            // Keys are concatenated so Blade does not read them as directives.
            $seoSchema = json_encode([
                '@'.'context' => 'https://schema.org',
                '@'.'type' => 'WebApplication',
                'name' => $seoName,
                'url' => $seoBase,
                'description' => $seoDescription,
                'applicationCategory' => 'BusinessApplication',
                'operatingSystem' => 'Web',
                'inLanguage' => 'es-MX',
                'image' => $seoImage,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        @endphp
        <meta name="description" content="{{ $seoDescription }}">
        <meta name="robots" content="{{ $seoPublic ? 'index, follow, max-image-preview:large' : 'noindex, nofollow' }}">
        <link rel="canonical" href="{{ $seoPublic ? $seoBase.'/login' : url()->current() }}">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ $seoName }}">
        <meta property="og:locale" content="es_MX">
        <meta property="og:title" content="{{ $seoTitle }}">
        <meta property="og:description" content="{{ $seoDescription }}">
        <meta property="og:url" content="{{ $seoBase }}/login">
        <meta property="og:image" content="{{ $seoImage }}">
        <meta property="og:image:secure_url" content="{{ $seoImage }}">
        <meta property="og:image:type" content="image/png">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="{{ $seoName }}: cada conversación, atendida a tiempo.">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $seoTitle }}">
        <meta name="twitter:description" content="{{ $seoDescription }}">
        <meta name="twitter:image" content="{{ $seoImage }}">
        <script type="application/ld+json" nonce="{{ Vite::cspNonce() }}">{!! $seoSchema !!}</script>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png" sizes="180x180">
        <link rel="manifest" href="/manifest.webmanifest">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
