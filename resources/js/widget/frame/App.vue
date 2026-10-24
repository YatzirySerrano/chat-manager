<script setup lang="ts">
import { WifiOff } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    shallowRef,
} from 'vue';
import { envelope, isEnvelope, readAppearance } from '../protocol';
import type { Appearance, HostToFrameType } from '../protocol';
import type {
    Availability,
    FrameConfig,
    LocalStatus,
    MessagePage,
    SignedIdentity,
    ThreadMessage,
    VisitorMessage,
    VisitorSession,
} from '../types';
import { ApiError, newClientMessageId, VisitorApi } from './api';
import ChatComposer from './components/ChatComposer.vue';
import ChatHeader from './components/ChatHeader.vue';
import MessageBubble from './components/MessageBubble.vue';
import PrechatForm from './components/PrechatForm.vue';
import StatusScreen from './components/StatusScreen.vue';
import { connectRealtime } from './realtime';
import type { RealtimeHandle } from './realtime';
import { createTokenStorage } from './storage';

/**
 * The visitor's chat inside the widget iframe. It resumes the session kept
 * in the iframe's storage (or starts one with the frame ticket), shows the
 * thread, sends messages idempotently and keeps up through Reverb signals
 * with a polling fallback (GET messages?after, ETag). It talks to the host
 * page only through the postMessage protocol, and only with its parent at
 * the host origin the server validated.
 *
 * cm:reset (the host's ChatManager('logout')) ends the session and starts
 * over. With identity_mode = required a stored session is only resumed
 * after the host identifies its user again on this page load, and through
 * POST session/identity, so a different person (or nobody signed in) never
 * sees the previous person's thread.
 */
const props = defineProps<{ config: FrameConfig }>();

type Phase =
    | 'loading'
    | 'unauthorized'
    | 'offline'
    | 'identity'
    | 'prechat'
    | 'ready'
    | 'expired'
    | 'error'
    | 'preview';

const HOST_TYPES: readonly HostToFrameType[] = [
    'cm:hello',
    'cm:open',
    'cm:close',
    'cm:identify',
    'cm:reset',
    'cm:preview-appearance',
];
const CONNECTED_POLL_SECONDS = 60;
const MAX_BACKOFF_SECONDS = 120;

const preview = props.config.state === 'preview';
const appearance = ref<Appearance>({ ...props.config.appearance });
const phase = ref<Phase>('loading');
const session = shallowRef<VisitorSession | null>(null);
const messages = ref<ThreadMessage[]>([]);
const errors = reactive<Record<string, string>>({});
const draft = ref('');
const online = ref(true);
const isOpen = ref(preview);
const unread = ref(0);
const announcement = ref('');
const busy = ref(false);
const scroller = ref<HTMLElement | null>(null);
const composer = ref<InstanceType<typeof ChatComposer> | null>(null);
const root = ref<HTMLElement | null>(null);

const storage = createTokenStorage(props.config.public_key);
const api = new VisitorApi(props.config, storage, onSessionLost);

let realtime: RealtimeHandle | null = null;
let realtimeChannel: string | null = null;
let pollTimer: ReturnType<typeof setTimeout> | undefined;
let backoffSeconds = 0;
let syncing = false;
let lastCursor: string | null = null;
let lastDelivered: string | null = null;
let lastRead: string | null = null;
let pendingIdentity: SignedIdentity | null = null;
let restarting = false;
/** Host commands that change the session run one after another. */
let hostWork: Promise<void> = Promise.resolve();

/* ---------- Appearance ---------- */

/** Page background of each theme (bg-white, dark:bg-gray-950). */
const SURFACE = { light: '#ffffff', dark: '#030712' } as const;
/** Readable text and focus color on that background (gray-800, gray-100). */
const FALLBACK_INK = { light: '#1f2937', dark: '#f3f4f6' } as const;

const darkQuery =
    typeof window.matchMedia === 'function'
        ? window.matchMedia('(prefers-color-scheme: dark)')
        : null;
const prefersDark = ref(darkQuery?.matches ?? false);

function onColorSchemeChange(event: MediaQueryListEvent): void {
    prefersDark.value = event.matches;
}

function luminanceOf(hex: string): number {
    const value = parseInt(hex.slice(1), 16);
    const channels = [value >> 16, (value >> 8) & 255, value & 255].map(
        (channel) => {
            const c = channel / 255;

            return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
        },
    );

    return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
}

function contrastRatio(first: string, second: string): number {
    const [light, dark] = [luminanceOf(first), luminanceOf(second)].sort(
        (a, b) => b - a,
    );

    return (light + 0.05) / (dark + 0.05);
}

/**
 * The accent paints buttons and the header (with --cm-accent-fg on it);
 * as text on the page (--cm-accent-text, 4.5:1) or as a focus ring and
 * border (--cm-ring, 3:1) it is only used when it contrasts enough with the
 * background of the visitor's theme, otherwise a neutral ink is.
 */
const themeStyle = computed(() => {
    const accent = appearance.value.accent_color;
    const theme = prefersDark.value ? 'dark' : 'light';
    const contrast = contrastRatio(accent, SURFACE[theme]);

    return {
        '--cm-accent': accent,
        '--cm-accent-fg': luminanceOf(accent) > 0.4 ? '#111827' : '#ffffff',
        '--cm-accent-text': contrast >= 4.5 ? accent : FALLBACK_INK[theme],
        '--cm-ring': contrast >= 3 ? accent : FALLBACK_INK[theme],
    };
});

const availability = computed<Availability>(
    () =>
        session.value?.availability ??
        props.config.availability ?? { online: true, message: null },
);

const statusText = computed(() => {
    if (phase.value === 'offline' || phase.value === 'unauthorized') {
        return null;
    }

    const agent = session.value?.conversation.agent_name;

    if (agent && session.value?.conversation.status === 'open') {
        return `Te atiende ${agent}`;
    }

    return availability.value.online ? 'En línea' : 'Fuera de línea';
});

const offlineText = computed(
    () =>
        appearance.value.offline_text ??
        'En este momento no estamos disponibles.',
);

/* ---------- Host messaging ---------- */

function postToHost(type: string, payload?: unknown): void {
    const target = props.config.host_origin;

    if (target && window.parent !== window) {
        window.parent.postMessage(envelope(type, payload), target);
    }
}

function announceReady(): void {
    postToHost('cm:ready', {
        appearance: {
            title: appearance.value.title,
            accent_color: appearance.value.accent_color,
            position: appearance.value.position,
            launcher_enabled: appearance.value.launcher_enabled,
            launcher_label: appearance.value.launcher_label,
            avatar: appearance.value.avatar,
        },
    });
}

function setUnread(count: number): void {
    if (unread.value !== count) {
        unread.value = count;
        postToHost('cm:unread', { count });
    }
}

function onHostMessage(event: MessageEvent): void {
    if (
        event.source !== window.parent ||
        event.origin !== props.config.host_origin ||
        !props.config.allowed_origins.includes(event.origin) ||
        !isEnvelope(event.data, HOST_TYPES)
    ) {
        return;
    }

    const payload = event.data.payload as Record<string, unknown> | undefined;

    switch (event.data.type) {
        case 'cm:hello':
            announceReady();
            break;
        case 'cm:open':
            isOpen.value = true;
            postToHost('cm:state', { open: true });
            onBecameVisible();
            nextTick(() => {
                composer.value?.focus();
                scrollToEnd();
            });
            break;
        case 'cm:close':
            isOpen.value = false;
            postToHost('cm:state', { open: false });
            break;
        case 'cm:identify':
            if (
                payload &&
                typeof payload.identity === 'object' &&
                payload.identity !== null
            ) {
                const identity = payload.identity as SignedIdentity;

                runHostWork(() => identify(identity));
            }

            break;
        case 'cm:reset':
            runHostWork(resetSession);
            break;
        case 'cm:preview-appearance': {
            const next = preview ? readAppearance(payload) : null;

            if (next) {
                appearance.value = next;
            }

            break;
        }
    }
}

function runHostWork(task: () => Promise<void>): void {
    hostWork = hostWork.then(task).catch(() => undefined);
}

function requestClose(): void {
    postToHost('cm:close-request');
}

/* ---------- Thread ---------- */

/** Message id behind a server cursor (base64url of "id|mac"), for ordering. */
function orderOf(cursor: string): number {
    try {
        const decoded = atob(cursor.replace(/-/g, '+').replace(/_/g, '/'));
        const id = parseInt(decoded.split('|')[0], 10);

        return Number.isFinite(id) ? id : 0;
    } catch {
        return 0;
    }
}

function sortThread(): void {
    messages.value.sort((a, b) => a.order - b.order);
}

/**
 * Adds or refreshes server messages, deduplicated by cursor (GET
 * messages?after may repeat replies that became visible late). Returns the
 * agent messages that were new.
 */
function merge(incoming: VisitorMessage[]): ThreadMessage[] {
    const fresh: ThreadMessage[] = [];

    incoming.forEach((message) => {
        const order = orderOf(message.cursor);
        const existing = messages.value.find(
            (m) => m.cursor === message.cursor,
        );

        if (existing) {
            Object.assign(existing, message);

            return;
        }

        const entry: ThreadMessage = { ...message, order };
        messages.value.push(entry);

        if (message.direction === 'outbound') {
            fresh.push(entry);
        }
    });

    sortThread();

    const latest = messages.value
        .filter((m) => m.cursor !== '')
        .reduce<ThreadMessage | null>(
            (max, m) => (max === null || m.order > max.order ? m : max),
            null,
        );

    lastCursor = latest?.cursor ?? lastCursor;

    return fresh;
}

const latestAgentOrder = computed(() =>
    messages.value
        .filter((m) => m.direction === 'outbound' && m.author === 'agent')
        .reduce((max, m) => Math.max(max, m.order), 0),
);

/**
 * What the visitor sees on their own messages. The API stores visitor
 * messages without a delivery status, so: Enviado once stored, Entregado
 * when an agent is attending the conversation, Leído once an agent replied
 * after it.
 */
function statusOf(message: ThreadMessage): LocalStatus | null {
    if (message.direction !== 'inbound') {
        return null;
    }

    if (message.localStatus === 'sending' || message.localStatus === 'failed') {
        return message.localStatus;
    }

    if (latestAgentOrder.value > message.order) {
        return 'read';
    }

    const status = session.value?.conversation.status;

    return status === 'open' || status === 'on_hold' || status === 'closed'
        ? 'delivered'
        : 'sent';
}

function scrollToEnd(): void {
    nextTick(() => {
        const element = scroller.value;

        if (element) {
            element.scrollTop = element.scrollHeight;
        }
    });
}

function nearBottom(): boolean {
    const element = scroller.value;

    return (
        !element ||
        element.scrollHeight - element.scrollTop - element.clientHeight < 120
    );
}

function visibleToVisitor(): boolean {
    return isOpen.value && document.visibilityState === 'visible';
}

function afterIncoming(fresh: ThreadMessage[]): void {
    const agentMessages = fresh.filter((m) => m.author !== 'visitor');

    if (agentMessages.length === 0) {
        return;
    }

    const last = agentMessages[agentMessages.length - 1];
    announcement.value = `Nuevo mensaje${last.agent_name ? ` de ${last.agent_name}` : ''}: ${last.body ?? 'archivo adjunto'}`;

    if (nearBottom()) {
        scrollToEnd();
    }

    if (!visibleToVisitor()) {
        setUnread(unread.value + agentMessages.length);
    }
}

/** Delivery receipt for what arrived, and read receipt when the visitor sees it. */
function sendReceipts(): void {
    const latestOutbound = messages.value
        .filter((m) => m.direction === 'outbound' && m.cursor !== '')
        .reduce<ThreadMessage | null>(
            (max, m) => (max === null || m.order > max.order ? m : max),
            null,
        );

    if (!latestOutbound || !session.value) {
        return;
    }

    const receipt: { delivered_up_to?: string; read_up_to?: string } = {};

    if (lastDelivered !== latestOutbound.cursor) {
        receipt.delivered_up_to = latestOutbound.cursor;
    }

    if (visibleToVisitor() && lastRead !== latestOutbound.cursor) {
        receipt.read_up_to = latestOutbound.cursor;
    }

    if (!receipt.delivered_up_to && !receipt.read_up_to) {
        return;
    }

    api.receipts(receipt)
        .then(() => {
            lastDelivered = latestOutbound.cursor;

            if (receipt.read_up_to) {
                lastRead = latestOutbound.cursor;
            }
        })
        .catch(() => undefined);
}

function onBecameVisible(): void {
    if (visibleToVisitor()) {
        setUnread(0);
        sendReceipts();
    }
}

/* ---------- Session lifecycle ---------- */

function ticketExpired(): boolean {
    const expires = Number((props.config.ticket ?? '').split('.')[0]);

    return !Number.isFinite(expires) || expires * 1000 <= Date.now() + 5000;
}

function resetThread(): void {
    messages.value = [];
    lastCursor = null;
    lastDelivered = null;
    lastRead = null;
    setUnread(0);
}

function handleStartError(error: unknown): void {
    if (error instanceof ApiError) {
        if (error.status === 403 || error.status === 404) {
            phase.value = 'expired';
        } else if (error.status === 409) {
            phase.value = 'offline';
        } else if (error.status === 401) {
            phase.value = 'identity';
        } else {
            phase.value = 'error';
        }

        return;
    }

    online.value = false;
    phase.value = 'error';
}

/** Starts a new session with the frame ticket (and profile or identity). */
async function startSession(profile?: {
    name?: string;
    email?: string;
}): Promise<void> {
    if (!props.config.ticket || ticketExpired()) {
        phase.value = 'expired';

        return;
    }

    busy.value = true;

    try {
        const identity =
            props.config.identity_mode !== 'anonymous' ? pendingIdentity : null;
        const started = await api.start({
            ticket: props.config.ticket,
            ...(profile && Object.keys(profile).length > 0 ? { profile } : {}),
            ...(identity ? { identity } : {}),
        });

        if (identity) {
            pendingIdentity = null;
        }

        resetThread();
        await onSession(started);
    } catch (error) {
        handleStartError(error);
    } finally {
        busy.value = false;
    }
}

/** What happens when no session is stored (or it was lost). */
async function beginNewSession(): Promise<void> {
    if (props.config.identity_mode === 'required' && !pendingIdentity) {
        phase.value = 'identity';

        return;
    }

    if (props.config.prechat.fields.length > 0 && !pendingIdentity) {
        phase.value = 'prechat';

        return;
    }

    await startSession();
}

async function onSession(next: VisitorSession): Promise<void> {
    online.value = true;
    session.value = next;
    phase.value = 'ready';

    const page = await api.history();
    merge(page.data);
    lastCursor = page.meta.next_cursor ?? lastCursor;
    scrollToEnd();
    sendReceipts();
    connect(next);
    schedulePoll();
}

function onSessionLost(): void {
    if (restarting || preview) {
        return;
    }

    restarting = true;
    stopSync();
    session.value = null;
    resetThread();

    beginNewSession().finally(() => {
        restarting = false;
    });
}

async function identify(identity: SignedIdentity): Promise<void> {
    if (props.config.identity_mode === 'anonymous' || preview) {
        return;
    }

    if (!session.value) {
        pendingIdentity = identity;

        if (phase.value === 'identity' && api.hasToken) {
            await resumeIdentified();
        } else if (phase.value === 'identity' || phase.value === 'prechat') {
            await startSession();
        }

        return;
    }

    try {
        const before = session.value.id;
        const next = await api.identify(identity);

        if (next.id !== before) {
            stopSync();
            resetThread();
            await onSession(next);
        } else {
            session.value = next;
        }
    } catch {
        postToHost('cm:error', { code: 'identity_rejected' });
    }
}

/**
 * identity_mode = required with a stored token: the session is resumed
 * through POST session/identity with the identity the host just sent. The
 * same person keeps their session; another person gets a new one (the
 * server revokes the stored one); an expired or revoked token (401) starts
 * a new session with that identity (onSessionLost).
 */
async function resumeIdentified(): Promise<void> {
    const identity = pendingIdentity;

    if (!identity) {
        phase.value = 'identity';

        return;
    }

    busy.value = true;
    phase.value = 'loading';

    try {
        const next = await api.identify(identity);

        pendingIdentity = null;
        resetThread();
        await onSession(next);
    } catch (error) {
        if (error instanceof ApiError && error.status === 401) {
            return; // onSessionLost already started over.
        }

        handleStartError(error);
    } finally {
        busy.value = false;
    }
}

/**
 * The host's user signed out (ChatManager('logout')): the session is ended
 * on the server, the token and the thread are dropped and the widget starts
 * over as a new visitor.
 */
async function resetSession(): Promise<void> {
    if (preview) {
        return;
    }

    const live = props.config.state === 'ready';

    restarting = true;
    pendingIdentity = null;
    stopSync();
    session.value = null;
    resetThread();
    draft.value = '';
    Object.keys(errors).forEach((key) => delete errors[key]);

    if (live) {
        phase.value = 'loading';
    }

    try {
        if (api.hasToken) {
            await api.end();
        }
    } catch {
        // api.end() always drops the local token.
    } finally {
        restarting = false;
    }

    if (live) {
        await beginNewSession();
    }
}

async function boot(): Promise<void> {
    switch (props.config.state) {
        case 'preview':
            phase.value = 'preview';
            announceReady();

            return;
        case 'unauthorized':
            phase.value = 'unauthorized';

            return;
        case 'offline':
            phase.value = 'offline';
            announceReady();

            return;
    }

    announceReady();

    if (api.hasToken && props.config.identity_mode === 'required') {
        if (pendingIdentity) {
            await resumeIdentified();
        } else {
            phase.value = 'identity';
        }

        return;
    }

    if (api.hasToken) {
        try {
            await onSession(await api.session());

            return;
        } catch (error) {
            if (error instanceof ApiError && error.status === 401) {
                return; // onSessionLost already started over.
            }

            if (!(error instanceof ApiError)) {
                online.value = false;
                phase.value = 'error';

                return;
            }
        }
    }

    await beginNewSession();
}

/* ---------- Sync: realtime and polling ---------- */

function connect(current: VisitorSession): void {
    if (realtime && realtimeChannel === current.realtime.channel) {
        return;
    }

    realtime?.disconnect();
    realtimeChannel = current.realtime.channel;
    realtime = connectRealtime(current.realtime, () => api.token, {
        onSignal: (type) => {
            if (type === 'conversation') {
                void refreshSession();
            }

            void sync();
        },
        onConnected: () => void sync(),
        onStateChange: () => schedulePoll(),
    });
}

function stopSync(): void {
    clearTimeout(pollTimer);
    realtime?.disconnect();
    realtime = null;
    realtimeChannel = null;
}

async function refreshSession(): Promise<void> {
    try {
        session.value = await api.session();
    } catch {
        // Polling retries; a 401 already started a new session.
    }
}

async function sync(): Promise<void> {
    if (syncing || !session.value) {
        return;
    }

    syncing = true;

    try {
        let page: MessagePage | null;

        if (lastCursor) {
            page = await api.since(lastCursor);
        } else {
            page = await api.history();
        }

        online.value = true;
        backoffSeconds = 0;

        if (page) {
            afterIncoming(merge(page.data));
            sendReceipts();
            void refreshSession();
        }
    } catch (error) {
        if (error instanceof ApiError) {
            if (error.status === 429) {
                backoffSeconds = Math.min(
                    MAX_BACKOFF_SECONDS,
                    Math.max(error.retryAfter ?? 0, (backoffSeconds || 4) * 2),
                );
            }
        } else {
            online.value = false;
            backoffSeconds = Math.min(
                MAX_BACKOFF_SECONDS,
                (backoffSeconds || 2) * 2,
            );
        }
    } finally {
        syncing = false;
    }
}

function pollDelaySeconds(): number {
    const poll = session.value?.poll ?? props.config.poll;
    const base = realtime?.isConnected()
        ? CONNECTED_POLL_SECONDS
        : document.visibilityState === 'visible'
          ? poll.visible_seconds
          : poll.hidden_seconds;

    return Math.max(base, backoffSeconds);
}

function schedulePoll(): void {
    clearTimeout(pollTimer);

    if (!session.value || phase.value !== 'ready') {
        return;
    }

    pollTimer = setTimeout(async () => {
        await sync();
        schedulePoll();
    }, pollDelaySeconds() * 1000);
}

function onVisibilityChange(): void {
    if (document.visibilityState === 'visible') {
        void sync();
        onBecameVisible();
    }

    schedulePoll();
}

function onOnline(): void {
    backoffSeconds = 0;
    void sync();
    schedulePoll();
}

/* ---------- Sending ---------- */

async function deliver(entry: ThreadMessage): Promise<void> {
    entry.localStatus = 'sending';
    delete errors[entry.clientId!];

    try {
        const stored = await api.send(entry.clientId!, entry.body ?? '');
        const duplicate = messages.value.find(
            (m) => m.cursor === stored.cursor && m !== entry,
        );

        if (duplicate) {
            messages.value = messages.value.filter((m) => m !== entry);
        } else {
            Object.assign(entry, stored, {
                order: orderOf(stored.cursor),
                localStatus: 'sent',
            });
        }

        sortThread();
        online.value = true;
        void refreshSession();
    } catch (error) {
        entry.localStatus = 'failed';

        if (error instanceof ApiError) {
            if (error.code === 'widget_unavailable') {
                errors[entry.clientId!] = 'el chat no está disponible ahora';
            } else if (error.status === 429) {
                errors[entry.clientId!] =
                    'estás enviando muy rápido, espera un momento';
            } else if (error.status === 422) {
                errors[entry.clientId!] = 'el mensaje no es válido';
            }
        } else {
            online.value = false;
            errors[entry.clientId!] = 'sin conexión';
        }
    }
}

function send(body: string): void {
    if (!session.value) {
        return;
    }

    const entry = reactive<ThreadMessage>({
        cursor: '',
        direction: 'inbound',
        author: 'visitor',
        agent_name: null,
        body,
        attachments: [],
        created_at: new Date().toISOString(),
        clientId: newClientMessageId(),
        localStatus: 'sending',
        order: Number.MAX_SAFE_INTEGER,
    }) as ThreadMessage;

    messages.value.push(entry);
    scrollToEnd();
    void deliver(messages.value[messages.value.length - 1]);
}

function retry(message: ThreadMessage): void {
    if (message.clientId) {
        void deliver(message);
    }
}

/* ---------- Focus trap and keyboard ---------- */

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        event.preventDefault();
        requestClose();

        return;
    }

    if (event.key !== 'Tab' || !root.value) {
        return;
    }

    const focusable = Array.from(
        root.value.querySelectorAll<HTMLElement>(
            'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
    ).filter((element) => element.offsetParent !== null);

    if (focusable.length === 0) {
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

/* ---------- Preview sample ---------- */

const previewMessages = computed<ThreadMessage[]>(() => [
    {
        cursor: 'preview-1',
        direction: 'inbound',
        author: 'visitor',
        agent_name: null,
        body: 'Hola, ¿tienen envíos a domicilio?',
        attachments: [],
        created_at: null,
        order: 1,
        localStatus: 'sent',
    },
    {
        cursor: 'preview-2',
        direction: 'outbound',
        author: 'agent',
        agent_name: 'Ana',
        body: '¡Hola! Sí, enviamos a todo el país. ¿En qué te ayudo?',
        attachments: [],
        created_at: null,
        order: 2,
    },
]);

const thread = computed(() =>
    preview ? previewMessages.value : messages.value,
);

const conversationClosed = computed(
    () => session.value?.conversation.status === 'closed',
);

onMounted(() => {
    darkQuery?.addEventListener('change', onColorSchemeChange);
    window.addEventListener('message', onHostMessage);
    document.addEventListener('visibilitychange', onVisibilityChange);
    window.addEventListener('online', onOnline);
    void boot();
});

onBeforeUnmount(() => {
    stopSync();
    darkQuery?.removeEventListener('change', onColorSchemeChange);
    window.removeEventListener('message', onHostMessage);
    document.removeEventListener('visibilitychange', onVisibilityChange);
    window.removeEventListener('online', onOnline);
});

function reload(): void {
    location.reload();
}
</script>

<template>
    <div
        ref="root"
        class="flex h-dvh min-h-0 flex-col bg-white text-gray-900 dark:bg-gray-950 dark:text-gray-100"
        :style="themeStyle"
        :data-phase="phase"
        @keydown="onKeydown"
    >
        <ChatHeader
            :appearance="appearance"
            :status-text="statusText"
            :online="availability.online"
            :closable="!preview"
            @close="requestClose"
        />

        <p
            v-if="!online && phase !== 'unauthorized'"
            class="flex shrink-0 items-center gap-2 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100"
            role="status"
        >
            <WifiOff class="size-4 shrink-0" aria-hidden="true" />
            Sin conexión. Reintentando…
        </p>

        <div
            v-if="phase === 'loading'"
            class="grid flex-1 content-start gap-3 p-4"
            aria-busy="true"
            aria-label="Cargando el chat"
        >
            <div
                class="h-14 w-3/4 animate-pulse rounded-2xl bg-gray-100 dark:bg-gray-800"
            />
            <div
                class="h-10 w-1/2 animate-pulse justify-self-end rounded-2xl bg-gray-100 dark:bg-gray-800"
            />
            <div
                class="h-12 w-2/3 animate-pulse rounded-2xl bg-gray-100 dark:bg-gray-800"
            />
        </div>

        <StatusScreen
            v-else-if="phase === 'unauthorized'"
            title="Chat no disponible en este sitio"
            description="Este dominio no está autorizado para mostrar el chat."
        />

        <StatusScreen
            v-else-if="phase === 'offline'"
            title="Fuera de línea"
            :description="offlineText"
        />

        <StatusScreen
            v-else-if="phase === 'expired'"
            title="La sesión del chat caducó"
            description="Vuelve a iniciar el chat para seguir escribiendo."
            action="Iniciar de nuevo"
            @action="reload"
        />

        <StatusScreen
            v-else-if="phase === 'identity'"
            title="Inicia sesión para chatear"
            description="Inicia sesión en este sitio para escribirnos."
        />

        <StatusScreen
            v-else-if="phase === 'error'"
            title="No se pudo cargar el chat"
            description="Revisa tu conexión e inténtalo otra vez."
            action="Reintentar"
            @action="boot"
        />

        <div
            v-else-if="phase === 'prechat'"
            class="min-h-0 flex-1 overflow-y-auto"
        >
            <p
                v-if="appearance.welcome_text"
                class="px-4 pt-4 text-[15px] whitespace-pre-line text-gray-700 dark:text-gray-300"
            >
                {{ appearance.welcome_text }}
            </p>
            <PrechatForm
                :fields="config.prechat.fields"
                :required="config.prechat.required"
                :privacy-notice="config.prechat.privacy_notice"
                :busy="busy"
                @submit="startSession"
            />
        </div>

        <template v-else>
            <p
                v-if="!availability.online && availability.message"
                class="shrink-0 bg-gray-50 px-4 py-2 text-sm text-gray-700 dark:bg-gray-900 dark:text-gray-300"
            >
                {{ availability.message }}
            </p>
            <div
                ref="scroller"
                class="min-h-0 flex-1 overflow-x-hidden overflow-y-auto overscroll-contain px-4 py-3"
            >
                <p
                    v-if="appearance.welcome_text"
                    class="mb-3 max-w-[85%] rounded-2xl rounded-bl-md bg-gray-100 px-3.5 py-2 text-[15px] leading-snug wrap-anywhere whitespace-pre-line text-gray-900 dark:bg-gray-800 dark:text-gray-100"
                >
                    {{ appearance.welcome_text }}
                </p>
                <ol class="grid grid-cols-1 gap-3" aria-label="Mensajes">
                    <MessageBubble
                        v-for="message in thread"
                        :key="message.clientId ?? message.cursor"
                        :message="message"
                        :status="preview ? 'read' : statusOf(message)"
                        :error="
                            message.clientId
                                ? (errors[message.clientId] ?? null)
                                : null
                        "
                        @retry="retry(message)"
                    />
                </ol>
                <p
                    v-if="conversationClosed"
                    class="mt-3 text-center text-xs text-gray-500 dark:text-gray-400"
                >
                    La conversación terminó. Escribe si necesitas algo más.
                </p>
            </div>
            <ChatComposer
                ref="composer"
                v-model="draft"
                :max-length="config.message_max_length"
                :disabled="preview || !session"
                @send="send"
            />
        </template>

        <div
            class="sr-only"
            role="status"
            aria-live="polite"
            aria-atomic="true"
        >
            {{ announcement }}
        </div>
    </div>
</template>
