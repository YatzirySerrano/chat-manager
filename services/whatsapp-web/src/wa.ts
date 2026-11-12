import type { Readable } from 'node:stream';
import type { WAMessage } from 'baileys';
import type { EncryptedAuthState } from './auth-state.js';
import type { MediaDescriptor, OutboundKind } from './types.js';

/**
 * The narrow surface of a WhatsApp Web connection the service depends on.
 * src/baileys.ts implements it with Baileys; the tests implement it with a
 * double, so the session logic is exercised without a network.
 */
export type WaEvent =
    | { type: 'qr'; value: string; ttlMs: number }
    | { type: 'pairing' }
    | { type: 'open'; me: { jid: string; name: string | null } }
    | { type: 'close'; statusCode: number | null; detail: string }
    | { type: 'messages'; upsert: 'notify' | 'append'; messages: WAMessage[] }
    | {
          type: 'statuses';
          updates: {
              id: string;
              remoteJid: string;
              fromMe: boolean;
              status: number;
          }[];
      };

export type WaOutgoing = {
    kind: OutboundKind;
    text: string | null;
    /** Local temporary file of the media, already downloaded and size-checked. */
    filePath: string | null;
    mime: string | null;
    fileName: string | null;
    voice: boolean;
};

export type WaQuoted = {
    id: string;
    remoteJid: string;
    fromMe: boolean;
    text: string | null;
};

export interface WaConnection {
    send(
        jid: string,
        content: WaOutgoing,
        options: { messageId: string; quoted: WaQuoted | null },
    ): Promise<{ id: string; timestamp: number }>;
    /** The WhatsApp JID registered for a phone number, or null when it has no account. */
    resolveJid(digits: string): Promise<string | null>;
    /** Phone number (digits) behind an @lid identifier, when WhatsApp shared the mapping. */
    phoneForLid(lid: string): Promise<string | null>;
    /** Tells WhatsApp to unlink this device. */
    logout(): Promise<void>;
    /** Closes the socket without unlinking; no further events are emitted. */
    close(): void;
}

export type WaFactory = (options: {
    connectionId: number;
    auth: EncryptedAuthState;
    onEvent: (event: WaEvent) => void;
}) => Promise<WaConnection>;

/** Downloads and decrypts a received file (it needs no live socket). */
export type MediaDownloader = (
    descriptor: MediaDescriptor,
) => Promise<Readable>;

/** WhatsApp status codes carried by a closed connection (Baileys DisconnectReason). */
export const CLOSE = {
    loggedOut: 401,
    forbidden: 403,
    timedOut: 408,
    multideviceMismatch: 411,
    connectionClosed: 428,
    connectionReplaced: 440,
    badSession: 500,
    unavailableService: 503,
    restartRequired: 515,
} as const;

/** proto.WebMessageInfo.Status. */
export const WA_STATUS = {
    error: 0,
    pending: 1,
    serverAck: 2,
    deliveryAck: 3,
    read: 4,
    played: 5,
} as const;
