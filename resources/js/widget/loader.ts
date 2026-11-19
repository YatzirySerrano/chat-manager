import { createJQueryAdapter } from './jquery';
import {
    DEFAULT_LAUNCHER_STYLE,
    launcherIconSvg,
    launcherMetrics,
} from './launcher';
import { envelope, isEnvelope, readLauncherAppearance } from './protocol';
import type { FrameToHostType, LauncherAppearance } from './protocol';
import type {
    SignedIdentity,
    WidgetEvent,
    WidgetInstance,
    WidgetListener,
    WidgetOptions,
} from './types';

/**
 * Chat Manager web chat loader (public/widget/v1/chat-manager.js), the only
 * script a host page loads. It draws the launcher inside a closed Shadow
 * DOM (the host's CSS cannot reach it) and an isolated iframe served by
 * Chat Manager with the visitor's UI; the visitor token, the API calls and
 * the WebSocket live in that iframe, out of reach of the host's JavaScript.
 *
 * Snippet (asynchronous, with a queue of earlier calls):
 *   ChatManager('init', {key}) · ChatManager('open') · ChatManager.init({...})
 *
 * ChatManager('logout') (alias 'reset') ends the visitor's session in the
 * frame (DELETE session, token removed) and starts over; the host site must
 * call it when its user signs out, so the next person on the same browser
 * never resumes the previous one's conversation.
 *
 * Its only global listener is window "message", added by init and removed
 * by destroy, which also removes the nodes, the iframe (and its socket),
 * the timers and the queue.
 */
type Api = {
    (command: string, ...args: unknown[]): unknown;
    init: (options: WidgetOptions) => WidgetInstance | null;
    jquery: (jquery: unknown) => boolean;
    version: string;
    q?: IArguments[] | unknown[][];
};

type GlobalWindow = Window & {
    ChatManager?: Partial<Api> & ((...args: unknown[]) => unknown);
    jQuery?: unknown;
};

const READY_TIMEOUT_MS = 20000;
const KEY_PATTERN = /^[A-Za-z0-9]{32,64}$/;
const FRAME_TYPES: readonly FrameToHostType[] = [
    'cm:ready',
    'cm:state',
    'cm:unread',
    'cm:close-request',
    'cm:error',
];

const win = window as GlobalWindow;
const previous = win.ChatManager;

function scriptOrigin(): string | null {
    const current = document.currentScript as HTMLScriptElement | null;
    const script =
        current?.src && /\/widget\/v1\/chat-manager\.js/.test(current.src)
            ? current
            : document.querySelector<HTMLScriptElement>(
                  'script[src*="/widget/v1/chat-manager.js"]',
              );

    try {
        return script?.src ? new URL(script.src).origin : null;
    } catch {
        return null;
    }
}

const origin = scriptOrigin();

function warn(message: string): void {
    if (typeof console !== 'undefined') {
        console.warn('[ChatManager] ' + message);
    }
}

/**
 * Styles of the closed shadow root. Everything the widget's settings change
 * (colors, size, shape, font, offsets, window size) arrives as custom
 * properties set on the wrapper by render(), so the sheet itself is static:
 * --a/--f button colors, --h height, --r radius, --w width, --i icon size,
 * --t text size, --x/--y offsets, --pw/--ph window size.
 */
function styles(zIndex: number): string {
    const bottom =
        'calc(max(16px, env(safe-area-inset-bottom)) + var(--y,0px))';
    const end = 'calc(max(16px, env(safe-area-inset-right)) + var(--x,0px))';
    const start = 'calc(max(16px, env(safe-area-inset-left)) + var(--x,0px))';

    return `:host{all:initial!important;display:block!important;position:fixed!important;inset:auto!important;width:0!important;height:0!important;margin:0!important;padding:0!important;border:0!important;transform:none!important;filter:none!important;opacity:1!important;visibility:visible!important;overflow:visible!important;z-index:${zIndex}!important}
*,*::before,*::after{box-sizing:border-box}
.l{position:fixed;inset-block-end:${bottom};inset-inline-end:${end};display:inline-flex;align-items:center;justify-content:center;gap:8px;min-width:var(--h,56px);width:var(--w,auto);max-width:calc(100vw - 32px);height:var(--h,56px);margin:0;padding:0;border:0;border-radius:var(--r,28px);background:var(--a,#2563eb);color:var(--f,#fff);font:600 var(--t,15px)/1 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;letter-spacing:normal;text-transform:none;white-space:nowrap;cursor:pointer;box-shadow:0 6px 20px rgba(0,0,0,.22);transition:transform .15s ease;-webkit-tap-highlight-color:transparent}
.l.t{padding:0 16px}
.l>span:not(.b){overflow:hidden;text-overflow:ellipsis}
.l:hover{transform:translateY(-1px)}
.l:focus-visible{outline:3px solid var(--a,#2563eb);outline-offset:3px}
.l svg{width:var(--i,24px);height:var(--i,24px);flex:none}
.l[hidden],.b[hidden],.l>span[hidden]{display:none}
.u .l::after{content:"";position:absolute;inset:0;border-radius:inherit;box-shadow:0 0 0 0 var(--a,#2563eb);animation:cm-pulse 2s ease-out infinite;pointer-events:none}
@keyframes cm-pulse{0%{box-shadow:0 0 0 0 var(--a,#2563eb);opacity:.55}100%{box-shadow:0 0 0 14px var(--a,#2563eb);opacity:0}}
.b{position:absolute;top:-4px;inset-inline-end:-4px;min-width:20px;height:20px;padding:0 6px;border-radius:10px;background:#dc2626;color:#fff;font:700 12px/20px system-ui,sans-serif;text-align:center}
.p{position:fixed;inset-block-end:calc(${bottom} + var(--h,56px) + 12px);inset-inline-end:${end};width:var(--pw,380px);max-width:calc(100vw - 32px);height:min(var(--ph,600px),calc(100vh - 120px));height:min(var(--ph,600px),calc(100dvh - 120px));border-radius:16px;overflow:hidden;background:#fff;box-shadow:0 12px 48px rgba(0,0,0,.28);display:none}
.n .p{inset-block-end:${bottom};height:min(var(--ph,600px),calc(100dvh - 48px))}
.s .l,.s .p{inset-inline-end:auto;inset-inline-start:${start}}
.o .p{display:block}
.p iframe{display:block;width:100%;height:100%;border:0;margin:0;padding:0;background:transparent}
@media (prefers-color-scheme:dark){.p{background:#030712}}
@media (max-width:480px){.p,.n .p{inset:0;width:100%;max-width:none;height:100%;height:100dvh;border-radius:0;padding:env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);background:linear-gradient(var(--c,#2563eb) 50%,#fff 50%)}.o .l{display:none}}
@media (max-width:480px) and (prefers-color-scheme:dark){.p,.n .p{background:linear-gradient(var(--c,#2563eb) 50%,#030712 50%)}}
@media (prefers-reduced-motion:reduce){.l{transition:none}.l:hover{transform:none}.u .l::after{animation:none}}`;
}

let instance: (WidgetInstance & { destroyed: boolean }) | null = null;

function init(options: WidgetOptions): WidgetInstance | null {
    if (instance && !instance.destroyed) {
        warn('init ya se llamó; se devuelve la instancia existente.');

        return instance;
    }

    if (
        !options ||
        typeof options.key !== 'string' ||
        !KEY_PATTERN.test(options.key)
    ) {
        warn(
            'La opción "key" es obligatoria y debe ser la clave pública del widget.',
        );

        return null;
    }

    if (!origin) {
        warn('No se pudo determinar el origen del script del widget.');

        return null;
    }

    const frameOrigin = origin;
    const listeners = new Map<WidgetEvent, Set<WidgetListener>>();
    const pendingIdentities: SignedIdentity[] = [];
    let pendingReset = false;
    const zIndex =
        typeof options.zIndex === 'number' && isFinite(options.zIndex)
            ? Math.round(options.zIndex)
            : 2147483000;
    // An offset given by the host page wins over the widget's settings.
    const hostOffsetX = Number.isFinite(Number(options.offset?.x))
        ? Number(options.offset?.x)
        : null;
    const hostOffsetY = Number.isFinite(Number(options.offset?.y))
        ? Number(options.offset?.y)
        : null;
    let iconName: string | null = null;
    let everOpened = false;

    let opened = false;
    let ready = false;
    let appearance: LauncherAppearance | null = null;
    let unread = 0;
    let mounted = false;

    const host = document.createElement('chat-manager-widget');
    const root = host.attachShadow({ mode: 'closed' });
    const style = document.createElement('style');
    const wrapper = document.createElement('div');
    const launcher = document.createElement('button');
    const badge = document.createElement('span');
    const icon = document.createElement('span');
    const label = document.createElement('span');
    const panel = document.createElement('div');
    const iframe = document.createElement('iframe');

    style.textContent = styles(zIndex);
    wrapper.className = 'w';
    launcher.type = 'button';
    launcher.className = 'l';
    launcher.hidden = true;
    icon.style.display = 'contents';
    launcher.setAttribute('aria-expanded', 'false');
    launcher.setAttribute('aria-controls', 'cm-panel');
    badge.className = 'b';
    badge.hidden = true;
    badge.setAttribute('aria-hidden', 'true');
    launcher.append(icon, label, badge);
    panel.className = 'p';
    panel.id = 'cm-panel';
    iframe.title = 'Chat';
    iframe.setAttribute(
        'sandbox',
        'allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox',
    );
    iframe.setAttribute('referrerpolicy', 'origin');
    iframe.setAttribute('allow', '');
    iframe.src = `${frameOrigin}/widget/v1/frame/${options.key}`;
    panel.append(iframe);
    wrapper.append(launcher, panel);
    root.append(style, wrapper);

    function emit(event: WidgetEvent, payload?: unknown): void {
        listeners.get(event)?.forEach((listener) => {
            try {
                listener(payload);
            } catch (error) {
                setTimeout(() => {
                    throw error;
                });
            }
        });
    }

    function post(type: string, payload?: unknown): void {
        if (ready && iframe.contentWindow) {
            iframe.contentWindow.postMessage(
                envelope(type, payload),
                frameOrigin,
            );
        }
    }

    function render(): void {
        const title = appearance?.title ?? 'Chat';
        const look = appearance ?? DEFAULT_LAUNCHER_STYLE;
        const metrics = launcherMetrics(
            appearance?.accent_color ?? '#2563eb',
            look,
            appearance?.launcher_label ?? null,
        );
        const text = metrics.showLabel
            ? (appearance?.launcher_label ?? '')
            : '';
        const position = options.position ?? appearance?.position ?? 'right';
        const showLauncher =
            ready && (options.launcher ?? appearance?.launcher_enabled ?? true);

        wrapper.className =
            'w' +
            (opened ? ' o' : '') +
            (position === 'left' ? ' s' : '') +
            (showLauncher ? '' : ' n') +
            (look.launcher_pulse && !opened && (!everOpened || unread > 0)
                ? ' u'
                : '');
        launcher.className = text !== '' ? 'l t' : 'l';

        if (iconName !== look.launcher_icon) {
            iconName = look.launcher_icon;
            icon.innerHTML = launcherIconSvg(look.launcher_icon);
        }
        launcher.hidden = !showLauncher;
        launcher.setAttribute('aria-expanded', String(opened));
        launcher.setAttribute(
            'aria-label',
            (opened ? 'Cerrar chat: ' : 'Abrir chat: ') +
                title +
                (unread > 0 && !opened ? ` (${unread} sin leer)` : ''),
        );
        label.textContent = text;
        label.hidden = text === '';
        badge.textContent = unread > 99 ? '99+' : String(unread);
        badge.hidden = unread === 0 || opened;
        iframe.title = 'Chat: ' + title;

        const vars: Record<string, string> = {
            '--a': metrics.background,
            '--f': metrics.color,
            '--c': appearance?.accent_color ?? '#2563eb',
            '--h': `${metrics.height}px`,
            '--r': `${metrics.radius}px`,
            '--w': metrics.width !== null ? `${metrics.width}px` : 'auto',
            '--i': `${metrics.iconSize}px`,
            '--t': `${metrics.fontSize}px`,
            '--x': `${hostOffsetX ?? look.launcher_offset.x}px`,
            '--y': `${hostOffsetY ?? look.launcher_offset.y}px`,
            '--pw': `${look.panel.width}px`,
            '--ph': `${look.panel.height}px`,
        };

        Object.keys(vars).forEach((name) =>
            wrapper.style.setProperty(name, vars[name]),
        );
    }

    function open(): void {
        if (current.destroyed || opened) {
            return;
        }

        opened = true;
        everOpened = true;
        unread = 0;
        render();
        post('cm:open');
        iframe.focus();
        options.onOpen?.();
        emit('open');
    }

    function close(): void {
        if (current.destroyed || !opened) {
            return;
        }

        const hadFocus = document.activeElement === host;

        opened = false;
        render();
        post('cm:close');

        if (hadFocus && !launcher.hidden) {
            launcher.focus();
        }

        options.onClose?.();
        emit('close');
    }

    function onMessage(event: MessageEvent): void {
        if (
            event.source !== iframe.contentWindow ||
            event.origin !== frameOrigin ||
            !isEnvelope(event.data, FRAME_TYPES)
        ) {
            return;
        }

        const payload = event.data.payload as
            | Record<string, unknown>
            | undefined;

        switch (event.data.type) {
            case 'cm:ready': {
                const next = readLauncherAppearance(payload?.appearance);

                if (!next) {
                    return;
                }

                const first = !ready;

                appearance = next;
                ready = true;
                clearTimeout(readyTimer);
                render();

                if (first) {
                    if (opened) {
                        post('cm:open');
                    }

                    if (pendingReset) {
                        pendingReset = false;
                        post('cm:reset');
                    }

                    pendingIdentities
                        .splice(0)
                        .forEach((identity) =>
                            post('cm:identify', { identity }),
                        );
                    options.onReady?.();
                    emit('ready');
                }

                return;
            }

            case 'cm:unread': {
                const count = Number(payload?.count);

                if (!Number.isInteger(count) || count < 0) {
                    return;
                }

                unread = opened ? 0 : count;
                render();
                options.onUnread?.(unread);
                emit('unread', unread);

                return;
            }

            case 'cm:close-request':
                close();

                return;

            case 'cm:error':
                emit('error', {
                    code:
                        typeof payload?.code === 'string'
                            ? payload.code
                            : 'error',
                });

                return;

            default:
                return;
        }
    }

    function mount(): void {
        if (mounted || current.destroyed) {
            return;
        }

        mounted = true;
        (options.container ?? document.body).appendChild(host);
    }

    const readyTimer = setTimeout(() => {
        warn(
            'El chat no respondió. Revisa que este dominio esté autorizado en la conexión.',
        );
        emit('error', { code: 'frame_timeout' });
    }, READY_TIMEOUT_MS);

    const current: WidgetInstance & { destroyed: boolean } = {
        destroyed: false,
        open,
        close,
        toggle: () => (opened ? close() : open()),
        isOpen: () => opened,
        identify(identity: SignedIdentity): void {
            if (
                current.destroyed ||
                typeof identity !== 'object' ||
                identity === null
            ) {
                return;
            }

            if (ready) {
                post('cm:identify', { identity });
            } else {
                pendingIdentities.push(identity);
            }
        },
        logout(): void {
            if (current.destroyed) {
                return;
            }

            if (ready) {
                post('cm:reset');
            } else {
                pendingIdentities.length = 0;
                pendingReset = true;
            }
        },
        on(event: WidgetEvent, listener: WidgetListener): () => void {
            if (!listeners.has(event)) {
                listeners.set(event, new Set());
            }

            listeners.get(event)!.add(listener);

            return () => current.off(event, listener);
        },
        off(event: WidgetEvent, listener: WidgetListener): void {
            listeners.get(event)?.delete(listener);
        },
        destroy(): void {
            if (current.destroyed) {
                return;
            }

            current.destroyed = true;
            clearTimeout(readyTimer);
            window.removeEventListener('message', onMessage);
            document.removeEventListener('DOMContentLoaded', mount);
            host.remove();
            listeners.clear();
            pendingIdentities.length = 0;
            pendingReset = false;
            api.q = [];

            if (instance === current) {
                instance = null;
            }
        },
    };

    launcher.addEventListener('click', () => current.toggle());
    window.addEventListener('message', onMessage);
    render();

    if (options.open) {
        opened = true;
        render();
    }

    if (options.container || document.body) {
        mount();
    } else {
        document.addEventListener('DOMContentLoaded', mount);
    }

    instance = current;

    return current;
}

const api: Api = Object.assign(
    function chatManager(command: string, ...args: unknown[]): unknown {
        if (command === 'init') {
            return init(args[0] as WidgetOptions);
        }

        if (command === 'jquery') {
            return api.jquery(args[0]);
        }

        if (!instance) {
            warn(`"${command}" requiere llamar antes a init.`);

            return undefined;
        }

        switch (command) {
            case 'open':
            case 'close':
            case 'toggle':
            case 'destroy':
                return instance[command]();
            case 'logout':
            case 'reset':
                return instance.logout();
            case 'isOpen':
                return instance.isOpen();
            case 'identify':
                return instance.identify(args[0] as SignedIdentity);
            case 'on':
                return instance.on(
                    args[0] as WidgetEvent,
                    args[1] as WidgetListener,
                );
            case 'off':
                return instance.off(
                    args[0] as WidgetEvent,
                    args[1] as WidgetListener,
                );
            default:
                warn(`Comando desconocido: "${command}".`);

                return undefined;
        }
    },
    {
        init,
        jquery: createJQueryAdapter(init, () =>
            instance && !instance.destroyed ? instance : null,
        ),
        version: '1',
    },
);

function boot(): Api {
    if (previous && previous.version) {
        return previous as Api;
    }

    const queued = (previous?.q ?? []) as ArrayLike<unknown>[];

    api.jquery(win.jQuery);

    queued.forEach((args) => {
        const list = Array.prototype.slice.call(args) as unknown[];

        api(list[0] as string, ...list.slice(1));
    });

    return api;
}

export default boot();
