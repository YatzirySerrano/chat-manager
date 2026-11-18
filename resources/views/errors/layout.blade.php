<!DOCTYPE html>
<html lang="es-MX">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>@yield('title') | {{ config('app.name', 'Chat Manager') }}</title>
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        {{--
          Self-contained on purpose: an error page must render even when the
          compiled assets or the database are what failed. Colours mirror the
          default palette of resources/css/app.css (pine green on mist).
        --}}
        <style>
            :root {
                color-scheme: light dark;
                --bg: oklch(0.968 0.006 166);
                --card: oklch(1 0 0);
                --fg: oklch(0.205 0.014 166);
                --muted: oklch(0.495 0.018 166);
                --border: oklch(0.912 0.008 166);
                --primary: oklch(0.5 0.105 166);
                --primary-fg: oklch(0.99 0 0);
            }

            @media (prefers-color-scheme: dark) {
                :root {
                    --bg: oklch(0.165 0.012 166);
                    --card: oklch(0.205 0.013 166);
                    --fg: oklch(0.955 0.006 166);
                    --muted: oklch(0.71 0.016 166);
                    --border: oklch(0.285 0.014 166);
                    --primary: oklch(0.74 0.115 166);
                    --primary-fg: oklch(0.18 0.03 166);
                }
            }

            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100svh;
                display: grid;
                place-items: center;
                padding: 1.5rem;
                background: var(--bg);
                color: var(--fg);
                font: 15px/1.5 'Hanken Grotesk', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
                -webkit-font-smoothing: antialiased;
            }

            main {
                width: 100%;
                max-width: 30rem;
                padding: 2rem;
                border: 1px solid var(--border);
                border-radius: 10px;
                background: var(--card);
            }

            .code {
                margin: 0 0 1.25rem;
                font-size: 0.8125rem;
                font-weight: 600;
                color: var(--muted);
                font-variant-numeric: tabular-nums;
            }

            .code span {
                display: inline-block;
                padding: 0.125rem 0.5rem;
                border: 1px solid var(--border);
                border-radius: 4px;
            }

            h1 {
                margin: 0 0 0.5rem;
                font-size: 1.5rem;
                line-height: 2rem;
                font-weight: 600;
                letter-spacing: -0.015em;
                text-wrap: balance;
            }

            p.message {
                margin: 0;
                color: var(--muted);
                text-wrap: pretty;
            }

            .action {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                margin-top: 1.75rem;
                height: 2.25rem;
                padding: 0 1rem;
                border-radius: 6px;
                background: var(--primary);
                color: var(--primary-fg);
                font-weight: 500;
                text-decoration: none;
            }

            .action:hover {
                background: color-mix(in oklch, var(--primary) 90%, black);
            }

            .action:focus-visible {
                outline: 2px solid var(--primary);
                outline-offset: 2px;
            }

            .action svg {
                width: 1rem;
                height: 1rem;
            }
        </style>
    </head>
    <body>
        <main>
            <p class="code"><span>Error @yield('code')</span></p>
            <h1>@yield('title')</h1>
            <p class="message">@yield('message')</p>

            @hasSection('action')
                <a class="action" href="@yield('action-href', '/')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        @hasSection('reload')
                            <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>
                        @else
                            <path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>
                        @endif
                    </svg>
                    @yield('action')
                </a>
            @endif
        </main>
    </body>
</html>
