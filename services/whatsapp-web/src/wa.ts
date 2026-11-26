import type { Readable } from 'node:stream';
import type { proto, WAMessage } from 'baileys';
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
    /** The sender edited messages: each one with its new content. */
    | {
          type: 'edits';
          updates: {
              id: string;
              remoteJid: string;
              fromMe: boolean;
              timestamp: number | null;
              message: proto.IMessage;
          }[];
      }
    /** The sender deleted messages for everyone. */
    | {
          type: 'revokes';
          updates: { id: string; remoteJid: string; fromMe: boolean }[];
      }
    /** A slice of the history WhatsApp hands to a newly linked device. */
    | {
          type: 'history';
          messages: WAMessage[];
          /** Names of the chats in the phone's address book, by JID. */
          names: Record<string, string>;
          progress: number | null;
          syncType: string | null;
      }
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

/** One day of the business hours of a business account. */
export type WaBusinessHours = {
    /** sun | mon | tue | wed | thu | fri | sat, as WhatsApp sends it. */
    day: string;
    /** open_24h | appointment_only | specific_hours. */
    mode: string;
    /** Minutes after midnight (only with specific_hours). */
    open: number | null;
    close: number | null;
};

/** What a business account publishes about itself. */
export type WaBusinessProfile = {
    category: string | null;
    description: string | null;
    email: string | null;
    website: string[];
    address: string | null;
    hours: { timezone: string | null; days: WaBusinessHours[] } | null;
};

/**
 * What WhatsApp shares about a contact. Each part is null when the contact
 * keeps it private, never set it, or WhatsApp did not answer.
 */
export type WaProfile = {
    /** Temporary URL of the full picture on WhatsApp's CDN. */
    picture_url: string | null;
    /**
     * Present (true) when WhatsApp did not answer about the picture: a null
     * `picture_url` then means "unknown", not "has none", and the stored
     * copy is kept.
     */
    picture_unavailable?: true;
    /** The contact's "about" text. */
    about: string | null;
    /** Unix seconds when the "about" was set. */
    about_set_at: number | null;
    business: WaBusinessProfile | null;
};

export type WaChange = { kind: 'edit'; text: string } | { kind: 'delete' };

export interface WaConnection {
    send(
        jid: string,
        content: WaOutgoing,
        options: { messageId: string; quoted: WaQuoted | null },
    ): Promise<{ id: string; timestamp: number }>;
    /**
     * Changes a message this device sent: a new text ("edit") or "delete
     * for everyone" ("delete"), as the WhatsApp apps do.
     */
    modify(jid: string, messageId: string, change: WaChange): Promise<void>;
    /** The WhatsApp JID registered for a phone number, or null when it has no account. */
    resolveJid(digits: string): Promise<string | null>;
    /** Phone number (digits) behind an @lid identifier, when WhatsApp shared the mapping. */
    phoneForLid(lid: string): Promise<string | null>;
    /** Picture, "about" and business profile of a contact; never throws for privacy. */
    profile(jid: string): Promise<WaProfile>;
    /** Tells WhatsApp to unlink this device. */
    logout(): Promise<void>;
    /** Closes the socket without unlinking; no further events are emitted. */
    close(): void;
}

export type WaFactory = (options: {
    connectionId: number;
    auth: EncryptedAuthState;
    /**
     * Ask WhatsApp for the chat history when the device is linked. It is
     * part of how the device registers, so a session keeps for life the
     * value it was linked with.
     */
    history: boolean;
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
