import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import type { WAMessage } from 'baileys';
import {
    isIgnoredJid,
    normalizeMessage,
    phoneOfJid,
    phonesOfVcard,
} from '../src/normalize.js';

const CUSTOMER = '5215511112222@s.whatsapp.net';
const noLookup = async (): Promise<string | null> => null;

const message = (
    content: WAMessage['message'],
    key: Partial<WAMessage['key']> = {},
): WAMessage => ({
    key: { id: 'MSG1', remoteJid: CUSTOMER, fromMe: false, ...key },
    message: content,
    messageTimestamp: 1_700_000_000,
    pushName: 'María',
});

const mediaKey = new Uint8Array(32).fill(7);

describe('normalización de mensajes (§5)', () => {
    test('texto simple y texto extendido con cita', async () => {
        const plain = await normalizeMessage(
            message({ conversation: 'Hola' }),
            noLookup,
        );

        assert.equal(plain?.type, 'text');
        assert.equal(plain?.text, 'Hola');
        assert.equal(plain?.phone, '5215511112222');
        assert.equal(plain?.quoted, null);

        const reply = await normalizeMessage(
            message({
                extendedTextMessage: {
                    text: 'Sí, gracias',
                    contextInfo: { stanzaId: 'ORIGINAL' },
                },
            }),
            noLookup,
        );

        assert.equal(reply?.text, 'Sí, gracias');
        assert.deepEqual(reply?.quoted, { provider_message_id: 'ORIGINAL' });
    });

    test('imagen con pie de foto y descriptor para descargarla después', async () => {
        const image = await normalizeMessage(
            message({
                imageMessage: {
                    mediaKey,
                    directPath: '/v/t62/abc',
                    url: 'https://mmg.whatsapp.net/abc',
                    mimetype: 'image/jpeg',
                    fileLength: 48213,
                    caption: 'El comprobante',
                },
            }),
            noLookup,
        );

        assert.equal(image?.type, 'image');
        assert.equal(image?.text, 'El comprobante');
        assert.deepEqual(image?.media, {
            mime: 'image/jpeg',
            size: 48213,
            file_name: null,
            seconds: null,
            voice: false,
            descriptor: {
                kind: 'image',
                media_key: Buffer.from(mediaKey).toString('base64'),
                direct_path: '/v/t62/abc',
                url: 'https://mmg.whatsapp.net/abc',
                mime: 'image/jpeg',
                size: 48213,
            },
        });
    });

    test('nota de voz, video, documento y sticker', async () => {
        const voice = await normalizeMessage(
            message({
                audioMessage: {
                    mediaKey,
                    directPath: '/a',
                    mimetype: 'audio/ogg; codecs=opus',
                    seconds: 12,
                    ptt: true,
                    fileLength: 900,
                },
            }),
            noLookup,
        );

        assert.equal(voice?.type, 'audio');
        assert.equal(voice?.media?.voice, true);
        assert.equal(voice?.media?.seconds, 12);

        const video = await normalizeMessage(
            message({
                videoMessage: {
                    mediaKey,
                    directPath: '/v',
                    mimetype: 'video/mp4',
                    seconds: 30,
                    caption: 'Mira',
                },
            }),
            noLookup,
        );

        assert.equal(video?.type, 'video');
        assert.equal(video?.text, 'Mira');
        assert.equal(video?.media?.seconds, 30);

        const document = await normalizeMessage(
            message({
                documentMessage: {
                    mediaKey,
                    directPath: '/d',
                    mimetype: 'application/pdf',
                    fileName: 'estado-de-cuenta.pdf',
                    fileLength: 1024,
                },
            }),
            noLookup,
        );

        assert.equal(document?.type, 'document');
        assert.equal(document?.media?.file_name, 'estado-de-cuenta.pdf');

        // WhatsApp envuelve los documentos con pie en documentWithCaptionMessage.
        const captioned = await normalizeMessage(
            message({
                documentWithCaptionMessage: {
                    message: {
                        documentMessage: {
                            mediaKey,
                            directPath: '/d',
                            mimetype: 'application/pdf',
                            fileName: 'a.pdf',
                            caption: 'Revisar',
                        },
                    },
                },
            }),
            noLookup,
        );

        assert.equal(captioned?.type, 'document');
        assert.equal(captioned?.text, 'Revisar');

        const sticker = await normalizeMessage(
            message({
                stickerMessage: {
                    mediaKey,
                    directPath: '/s',
                    mimetype: 'image/webp',
                },
            }),
            noLookup,
        );

        assert.equal(sticker?.type, 'sticker');
        assert.equal(sticker?.media?.descriptor.kind, 'sticker');
    });

    test('un archivo sin clave de descarga queda como no soportado, no como archivo roto', async () => {
        const broken = await normalizeMessage(
            message({ imageMessage: { mimetype: 'image/jpeg' } }),
            noLookup,
        );

        assert.equal(broken?.type, 'unsupported');
        assert.equal(broken?.media, undefined);
    });

    test('ubicación, contactos y reacción', async () => {
        const location = await normalizeMessage(
            message({
                locationMessage: {
                    degreesLatitude: 19.43,
                    degreesLongitude: -99.13,
                    name: 'Oficina',
                    address: 'Centro',
                },
            }),
            noLookup,
        );

        assert.equal(location?.type, 'location');
        assert.deepEqual(location?.location, {
            latitude: 19.43,
            longitude: -99.13,
            name: 'Oficina',
            address: 'Centro',
        });

        const vcard =
            'BEGIN:VCARD\nVERSION:3.0\nFN:Lody\nTEL;type=CELL;type=VOICE;waid=5217341597873:+52 1 734 159 7873\nTEL;type=HOME:+52 55 5555 0000\nEND:VCARD';
        const contact = await normalizeMessage(
            message({ contactMessage: { displayName: 'Lody', vcard } }),
            noLookup,
        );

        assert.equal(contact?.type, 'contacts');
        assert.deepEqual(contact?.contacts, [
            { name: 'Lody', phones: ['5217341597873', '+525555550000'] },
        ]);

        const several = await normalizeMessage(
            message({
                contactsArrayMessage: {
                    contacts: [
                        { displayName: 'A', vcard },
                        { displayName: 'B', vcard: '' },
                    ],
                },
            }),
            noLookup,
        );

        assert.equal(several?.contacts?.length, 2);
        assert.deepEqual(several?.contacts?.[1], { name: 'B', phones: [] });

        const reaction = await normalizeMessage(
            message({
                reactionMessage: {
                    key: { id: 'TARGET', remoteJid: CUSTOMER },
                    text: '👍',
                },
            }),
            noLookup,
        );

        assert.equal(reaction?.type, 'reaction');
        assert.deepEqual(reaction?.reaction, {
            provider_message_id: 'TARGET',
            emoji: '👍',
        });
    });

    test('lo que la plataforma no entiende llega como no soportado; lo que no es un mensaje, no llega', async () => {
        const poll = await normalizeMessage(
            message({
                pollCreationMessage: {
                    name: '¿Opción?',
                    options: [{ optionName: 'A' }],
                },
            }),
            noLookup,
        );

        assert.equal(poll?.type, 'unsupported');
        assert.equal(poll?.text, null);

        assert.equal(
            await normalizeMessage(
                message({ protocolMessage: { type: 0 } }),
                noLookup,
            ),
            null,
        );
        assert.equal(
            await normalizeMessage(message(undefined), noLookup),
            null,
        );
        assert.equal(
            await normalizeMessage(
                message({ conversation: 'x' }, { id: undefined }),
                noLookup,
            ),
            null,
        );
        assert.equal(
            await normalizeMessage(
                message(
                    { conversation: 'x' },
                    { remoteJid: '120363025246125486@g.us' },
                ),
                noLookup,
            ),
            null,
        );
        assert.equal(
            await normalizeMessage(
                message(
                    { conversation: 'x' },
                    { remoteJid: 'status@broadcast' },
                ),
                noLookup,
            ),
            null,
        );
    });

    test('identificador @lid: usa el número alterno, lo consulta, o lo deja en nulo', async () => {
        const withAlt = await normalizeMessage(
            message(
                { conversation: 'x' },
                { remoteJid: '99887766554433@lid', remoteJidAlt: CUSTOMER },
            ),
            noLookup,
        );

        assert.equal(withAlt?.remote_jid, '99887766554433@lid');
        assert.equal(withAlt?.phone, '5215511112222');

        const looked = await normalizeMessage(
            message({ conversation: 'x' }, { remoteJid: '99887766554433@lid' }),
            async (lid) =>
                lid === '99887766554433@lid' ? '5215500009999' : null,
        );

        assert.equal(looked?.phone, '5215500009999');

        const unknown = await normalizeMessage(
            message({ conversation: 'x' }, { remoteJid: '99887766554433@lid' }),
            async () => {
                throw new Error('lookup failed');
            },
        );

        assert.equal(unknown?.phone, null);
        assert.equal(unknown?.remote_jid, '99887766554433@lid');
    });

    test('un mensaje propio no lleva nombre de perfil', async () => {
        const own = await normalizeMessage(
            message({ conversation: 'x' }, { fromMe: true }),
            noLookup,
        );

        assert.equal(own?.from_me, true);
        assert.equal(own?.push_name, null);
    });

    test('utilidades de identificadores', () => {
        assert.equal(
            phoneOfJid('5215511112222@s.whatsapp.net'),
            '5215511112222',
        );
        assert.equal(phoneOfJid('99887766554433@lid'), null);
        assert.equal(phoneOfJid(null), null);
        assert.equal(isIgnoredJid('5215511112222@s.whatsapp.net'), false);
        assert.equal(isIgnoredJid('99887766554433@lid'), false);
        assert.equal(isIgnoredJid('120363025246125486@g.us'), true);
        assert.equal(isIgnoredJid('status@broadcast'), true);
        assert.equal(isIgnoredJid('120363025246125486@newsletter'), true);
        assert.equal(isIgnoredJid('1234567890@broadcast'), true);
        assert.equal(isIgnoredJid(''), true);
        assert.deepEqual(phonesOfVcard(null), []);
    });
});
