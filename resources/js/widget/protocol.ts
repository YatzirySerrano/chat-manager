/**
 * postMessage protocol between the loader (host page) and the widget frame.
 * Every message is an envelope {v: 1, source: 'chat-manager', type,
 * payload?}, always sent with an explicit targetOrigin. The loader only
 * accepts envelopes from its iframe's window and the script's origin; the
 * frame only from its parent and the host origin the server validated. The
 * visitor's token never travels through this channel. Shared by both
 * bundles, so it stays dependency free and small.
 */
export const PROTOCOL_VERSION = 1;
export const PROTOCOL_SOURCE = 'chat-manager';

export type HostToFrameType =
    | 'cm:hello'
    | 'cm:open'
    | 'cm:close'
    | 'cm:identify'
    | 'cm:reset'
    | 'cm:preview-appearance';

export type FrameToHostType =
    | 'cm:ready'
    | 'cm:state'
    | 'cm:unread'
    | 'cm:close-request'
    | 'cm:error';

export type Envelope<T extends string = string> = {
    v: typeof PROTOCOL_VERSION;
    source: typeof PROTOCOL_SOURCE;
    type: T;
    payload?: unknown;
};

export type Appearance = {
    title: string;
    subtitle: string | null;
    welcome_text: string | null;
    offline_text: string | null;
    accent_color: string;
    position: 'right' | 'left';
    launcher_enabled: boolean;
    launcher_label: string | null;
    avatar: string | null;
};

/** What the loader needs from cm:ready to draw the launcher. */
export type LauncherAppearance = Pick<
    Appearance,
    | 'title'
    | 'accent_color'
    | 'position'
    | 'launcher_enabled'
    | 'launcher_label'
>;

/**
 * Light validation of the launcher's part of an appearance (the loader
 * must stay small; the frame validates the whole appearance).
 */
export function readLauncherAppearance(
    value: unknown,
): LauncherAppearance | null {
    const a = value as Partial<LauncherAppearance> | null;

    return a &&
        typeof a.title === 'string' &&
        typeof a.accent_color === 'string' &&
        /^#[0-9a-fA-F]{6}$/.test(a.accent_color) &&
        (a.position === 'right' || a.position === 'left') &&
        typeof a.launcher_enabled === 'boolean'
        ? {
              title: a.title.slice(0, 120),
              accent_color: a.accent_color,
              position: a.position,
              launcher_enabled: a.launcher_enabled,
              launcher_label:
                  typeof a.launcher_label === 'string'
                      ? a.launcher_label.slice(0, 120)
                      : null,
          }
        : null;
}

export function envelope<T extends string>(
    type: T,
    payload?: unknown,
): Envelope<T> {
    return payload === undefined
        ? { v: PROTOCOL_VERSION, source: PROTOCOL_SOURCE, type }
        : { v: PROTOCOL_VERSION, source: PROTOCOL_SOURCE, type, payload };
}

/**
 * Whether the data is a protocol envelope of one of the given types.
 */
export function isEnvelope<T extends string>(
    data: unknown,
    types: readonly T[],
): data is Envelope<T> {
    if (typeof data !== 'object' || data === null) {
        return false;
    }

    const message = data as Partial<Envelope>;

    return (
        message.v === PROTOCOL_VERSION &&
        message.source === PROTOCOL_SOURCE &&
        typeof message.type === 'string' &&
        (types as readonly string[]).includes(message.type)
    );
}

function isObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

function isNullableString(value: unknown, max: number): boolean {
    return (
        value === null ||
        value === undefined ||
        (typeof value === 'string' && value.length <= max)
    );
}

/**
 * Validates an appearance payload (cm:ready and cm:preview-appearance),
 * returning a clean copy or null. Colors are #rrggbb; avatars only data:
 * images.
 */
export function readAppearance(value: unknown): Appearance | null {
    if (!isObject(value)) {
        return null;
    }

    const {
        title,
        subtitle,
        welcome_text,
        offline_text,
        accent_color,
        position,
        launcher_enabled,
        launcher_label,
        avatar,
    } = value;

    if (
        typeof title !== 'string' ||
        title.length > 120 ||
        !isNullableString(subtitle, 240) ||
        !isNullableString(welcome_text, 2000) ||
        !isNullableString(offline_text, 2000) ||
        !isNullableString(launcher_label, 120) ||
        typeof accent_color !== 'string' ||
        !/^#[0-9a-fA-F]{6}$/.test(accent_color) ||
        (position !== 'right' && position !== 'left') ||
        typeof launcher_enabled !== 'boolean' ||
        !(
            avatar === null ||
            avatar === undefined ||
            (typeof avatar === 'string' &&
                /^data:image\/(png|jpeg|webp);base64,/.test(avatar))
        )
    ) {
        return null;
    }

    return {
        title,
        subtitle: (subtitle as string | null | undefined) ?? null,
        welcome_text: (welcome_text as string | null | undefined) ?? null,
        offline_text: (offline_text as string | null | undefined) ?? null,
        accent_color,
        position,
        launcher_enabled,
        launcher_label: (launcher_label as string | null | undefined) ?? null,
        avatar: (avatar as string | null | undefined) ?? null,
    };
}
