{{--
    Pantalla de mantenimiento (503). Se muestra con `php artisan down` en la web
    y en la PWA instalada (el service worker deja pasar la respuesta del
    servidor tal cual en las navegaciones). Es autocontenida: sin Vite, sin
    fuentes externas ni JavaScript de la app, para que funcione aunque el build
    esté a medio desplegar. deploy.sh la muestra con
    `php artisan down --retry=30 --refresh=15`; a mano se recomienda
    `php artisan down --render="errors::503" --retry=60` (pre-renderiza esta
    vista y no toca la base de datos mientras dure).
--}}
<!DOCTYPE html>
<html lang="es-MX">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#004d31">
    <meta http-equiv="refresh" content="60">
    <title>En mantenimiento · {{ config('app.name', 'Chat Manager') }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <style>
        :root {
            --pino: #004d31;
            --pino-medio: #00704a;
            --pino-oscuro: #002b1c;
            --menta: #5fd3a3;
            --menta-clara: #c9f2df;
            --niebla: #f2f7f5;
            --texto-suave: #cfe5dc;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: var(--niebla);
            background: radial-gradient(120% 90% at 50% 0%, var(--pino-medio) 0%, var(--pino) 55%, var(--pino-oscuro) 100%);
            display: grid;
            place-items: center;
            overflow: hidden;
            padding: max(24px, env(safe-area-inset-top)) max(20px, env(safe-area-inset-right)) max(24px, env(safe-area-inset-bottom)) max(20px, env(safe-area-inset-left));
        }

        /* Globos de mensaje que suben por el fondo. */
        .fondo { position: fixed; inset: 0; pointer-events: none; overflow: hidden; z-index: 0; }
        .globo {
            position: absolute; bottom: -120px;
            border-radius: 18px 18px 18px 4px;
            background: linear-gradient(135deg, rgba(201, 242, 223, .28), rgba(95, 211, 163, .08));
            animation: subir linear infinite;
        }
        .globo.saliente { border-radius: 18px 18px 4px 18px; background: linear-gradient(135deg, rgba(95, 211, 163, .3), rgba(201, 242, 223, .06)); }
        .globo:nth-child(1) { left: 6%;  width: 86px;  height: 40px; animation-duration: 16s; }
        .globo:nth-child(2) { left: 24%; width: 48px;  height: 26px; animation-duration: 11s; animation-delay: -4s; }
        .globo:nth-child(3) { left: 46%; width: 120px; height: 52px; animation-duration: 21s; animation-delay: -9s; }
        .globo:nth-child(4) { left: 66%; width: 60px;  height: 30px; animation-duration: 13s; animation-delay: -2s; }
        .globo:nth-child(5) { left: 82%; width: 96px;  height: 44px; animation-duration: 18s; animation-delay: -12s; }
        .globo:nth-child(6) { left: 92%; width: 40px;  height: 22px; animation-duration: 14s; animation-delay: -7s; }
        @keyframes subir {
            0%   { transform: translateY(0) scale(.9); opacity: 0; }
            10%  { opacity: 1; }
            100% { transform: translateY(-120vh) scale(1.1); opacity: 0; }
        }

        main {
            position: relative; z-index: 1; width: 100%; max-width: 40rem; text-align: center;
            display: grid; gap: 1.1rem; justify-items: center;
            animation: aparecer .8s cubic-bezier(.2, .8, .2, 1) both;
        }
        @keyframes aparecer { from { transform: translateY(18px) scale(.98); } to { transform: none; } }

        .escena { position: relative; width: min(78vw, 300px); height: min(78vw, 300px); display: grid; place-items: center; }
        .halo {
            position: absolute; inset: 0; border-radius: 50%;
            border: 2px solid rgba(201, 242, 223, .25);
            animation: halo 2.8s ease-out infinite;
        }
        .halo.dos { animation-delay: 1.4s; }
        @keyframes halo { from { transform: scale(.7); opacity: .9; } to { transform: scale(1.35); opacity: 0; } }
        .disco {
            width: 72%; height: 72%; border-radius: 50%;
            background: var(--niebla);
            box-shadow: 0 18px 40px rgba(0, 0, 0, .35), inset 0 0 0 4px rgba(95, 211, 163, .35);
            display: grid; place-items: center;
            animation: flotar 4s ease-in-out infinite;
        }
        .disco svg { width: 62%; height: auto; animation: latir 2.4s ease-in-out infinite; }
        @keyframes latir { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.06); } }
        @keyframes flotar { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-8px); } }

        /* Globo «escribiendo…» con los tres puntos. */
        .escribiendo {
            position: absolute; right: -2%; top: 10%;
            display: flex; gap: 6px; align-items: center;
            padding: 12px 16px; border-radius: 20px 20px 20px 6px;
            background: #ffffff; box-shadow: 0 8px 20px rgba(0, 0, 0, .3);
            animation: flotar 3.2s ease-in-out infinite reverse;
        }
        .escribiendo span {
            width: 9px; height: 9px; border-radius: 50%; background: var(--pino-medio);
            animation: punto 1.2s ease-in-out infinite;
        }
        .escribiendo span:nth-child(2) { animation-delay: .15s; }
        .escribiendo span:nth-child(3) { animation-delay: .3s; }
        @keyframes punto { 0%, 60%, 100% { transform: translateY(0); opacity: .45; } 30% { transform: translateY(-6px); opacity: 1; } }

        .engrane {
            position: absolute; left: 2%; bottom: 6%; width: 24%; height: 24%;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, .35));
            animation: girar 6s linear infinite;
        }
        .engrane.chico { left: auto; right: 10%; bottom: 2%; width: 15%; height: 15%; animation-direction: reverse; animation-duration: 4s; }
        @keyframes girar { to { transform: rotate(360deg); } }

        .etiqueta {
            font-size: .72rem; letter-spacing: .18em; text-transform: uppercase; font-weight: 700;
            color: var(--menta-clara); background: rgba(95, 211, 163, .16); border: 1px solid rgba(201, 242, 223, .25);
            padding: .35rem .8rem; border-radius: 999px;
        }
        h1 { margin: 0; font-size: clamp(2rem, 7vw, 3.2rem); line-height: 1.15; font-weight: 800; text-wrap: balance; }
        p { margin: 0; color: var(--texto-suave); line-height: 1.6; max-width: 32rem; font-size: clamp(1rem, 3.6vw, 1.2rem); text-wrap: pretty; }

        .barra { width: min(100%, 24rem); height: 8px; border-radius: 999px; background: rgba(255, 255, 255, .12); overflow: hidden; }
        .barra span {
            display: block; height: 100%; width: 40%; border-radius: inherit;
            background: linear-gradient(90deg, var(--menta), var(--menta-clara));
            animation: cargar 1.8s ease-in-out infinite;
        }
        @keyframes cargar { 0% { transform: translateX(-110%); } 100% { transform: translateX(260%); } }

        .boton {
            margin-top: .3rem; appearance: none; border: 0; cursor: pointer;
            font: inherit; font-weight: 700; color: var(--pino-oscuro);
            background: linear-gradient(180deg, var(--menta-clara), var(--menta));
            padding: 1rem 2.2rem; border-radius: 999px; min-height: 52px; font-size: 1.05rem;
            box-shadow: 0 8px 20px rgba(0, 0, 0, .25);
            transition: transform .15s ease, box-shadow .15s ease;
            text-decoration: none; display: inline-block;
        }
        .boton:hover { transform: translateY(-2px); box-shadow: 0 12px 26px rgba(0, 0, 0, .3); }
        .boton:active { transform: translateY(0) scale(.98); }
        .boton:focus-visible { outline: 3px solid var(--menta-clara); outline-offset: 3px; }
        .nota { font-size: .8rem; color: rgba(207, 229, 220, .75); }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation: none !important; transition: none !important; }
            .globo { display: none; }
        }
    </style>
</head>
<body>
    <div class="fondo" aria-hidden="true">
        <span class="globo"></span><span class="globo saliente"></span><span class="globo"></span><span class="globo saliente"></span><span class="globo"></span><span class="globo saliente"></span>
    </div>

    <main>
        <div class="escena" aria-hidden="true">
            <span class="halo"></span><span class="halo dos"></span>
            <div class="disco">
                {{-- El ícono de la aplicación (public/favicon.svg). --}}
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                    <rect width="512" height="512" rx="112" fill="#008251"/>
                    <g transform="translate(96 96) scale(13.333)" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M16 10a2 2 0 0 1-2 2H6.828a2 2 0 0 0-1.414.586l-2.202 2.202A.71.71 0 0 1 2 14.286V4a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>
                        <path d="M20 9a2 2 0 0 1 2 2v10.286a.71.71 0 0 1-1.212.502l-2.202-2.202A2 2 0 0 0 17.172 19H10a2 2 0 0 1-2-2v-1"/>
                    </g>
                </svg>
            </div>
            <div class="escribiendo"><span></span><span></span><span></span></div>
            <svg class="engrane" viewBox="0 0 24 24"><path fill="#C9F2DF" d="M19.4 13a7.5 7.5 0 0 0 0-2l2.1-1.6-2-3.5-2.5 1a7.3 7.3 0 0 0-1.7-1L15 3.3h-4l-.4 2.6a7.3 7.3 0 0 0-1.7 1l-2.5-1-2 3.5L6.5 11a7.5 7.5 0 0 0 0 2l-2.1 1.6 2 3.5 2.5-1a7.3 7.3 0 0 0 1.7 1l.4 2.6h4l.4-2.6a7.3 7.3 0 0 0 1.7-1l2.5 1 2-3.5zM13 15.5A3.5 3.5 0 1 1 13 8.5a3.5 3.5 0 0 1 0 7z" transform="translate(-1 0)"/></svg>
            <svg class="engrane chico" viewBox="0 0 24 24"><path fill="#5FD3A3" d="M19.4 13a7.5 7.5 0 0 0 0-2l2.1-1.6-2-3.5-2.5 1a7.3 7.3 0 0 0-1.7-1L15 3.3h-4l-.4 2.6a7.3 7.3 0 0 0-1.7 1l-2.5-1-2 3.5L6.5 11a7.5 7.5 0 0 0 0 2l-2.1 1.6 2 3.5 2.5-1a7.3 7.3 0 0 0 1.7 1l.4 2.6h4l.4-2.6a7.3 7.3 0 0 0 1.7-1l2.5 1 2-3.5zM13 15.5A3.5 3.5 0 1 1 13 8.5a3.5 3.5 0 0 1 0 7z" transform="translate(-1 0)"/></svg>
        </div>

        <span class="etiqueta">{{ config('app.name', 'Chat Manager') }}</span>
        <h1>Estamos mejorando los mensajes</h1>
        <p>Estamos aplicando una actualización para que tus conversaciones funcionen mejor. Vuelve en unos minutos; tus chats y mensajes están seguros.</p>
        <div class="barra" aria-hidden="true"><span></span></div>
        <a class="boton" href="{{ url()->current() }}">Volver a intentar</a>
        <p class="nota">Esta página se actualiza sola cada minuto.</p>
    </main>
</body>
</html>
