import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import type { WAMessage } from 'baileys';
import {
    isIgnoredJid,
    normalizeMessage,
    phoneOfJid,
    phonesOfVcard,
    protocolChange,
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
        assert.equal(sticker?.media?.descriptor?.kind, 'sticker');
    });

    test('un archivo sin clave de descarga conserva su tipo y avisa que no hay archivo', async () => {
        const broken = await normalizeMessage(
            message({ imageMessage: { mimetype: 'image/jpeg' } }),
            noLookup,
        );

        assert.equal(broken?.type, 'image');
        assert.equal(broken?.media?.descriptor, null);
        assert.equal(broken?.media?.mime, 'image/jpeg');
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

    test('lo que la plataforma no dibuja dice qué era; lo que no es un mensaje, no llega', async () => {
        const call = await normalizeMessage(
            message({ call: { callKey: new Uint8Array(4) } }),
            noLookup,
        );

        assert.equal(call?.type, 'unsupported');
        assert.deepEqual(call?.unsupported, { kind: 'call', label: 'Llamada' });

        const event = await normalizeMessage(
            message({ eventMessage: { name: 'Junta de cobranza' } }),
            noLookup,
        );

        assert.equal(event?.type, 'unsupported');
        assert.equal(event?.unsupported?.label, 'Evento');
        assert.equal(event?.text, 'Junta de cobranza');

        const missed = await normalizeMessage(
            { ...message(undefined), messageStubType: 40 },
            noLookup,
        );

        assert.equal(missed?.type, 'unsupported');
        assert.equal(missed?.unsupported?.label, 'Llamada perdida');

        // Votes of a poll, pins and encrypted side traffic are not messages.
        for (const silent of [
            { pollUpdateMessage: {} },
            { pinInChatMessage: {} },
            { keepInChatMessage: {} },
            { secretEncryptedMessage: {} },
        ]) {
            assert.equal(
                await normalizeMessage(message(silent), noLookup),
                null,
            );
        }

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

    test('encuesta, respuestas a botones y listas, y mensajes con botones de una empresa', async () => {
        const poll = await normalizeMessage(
            message({
                pollCreationMessageV3: {
                    name: '¿Qué día te queda mejor?',
                    options: [
                        { optionName: 'Lunes' },
                        { optionName: 'Martes' },
                    ],
                    selectableOptionsCount: 1,
                },
            }),
            noLookup,
        );

        assert.equal(poll?.type, 'poll');
        assert.deepEqual(poll?.poll, {
            question: '¿Qué día te queda mejor?',
            options: ['Lunes', 'Martes'],
            multiple: false,
        });
        assert.equal(
            poll?.text,
            'Encuesta: ¿Qué día te queda mejor?\n• Lunes\n• Martes',
        );

        const button = await normalizeMessage(
            message({
                buttonsResponseMessage: {
                    selectedButtonId: 'yes',
                    selectedDisplayText: 'Sí, confirmo',
                    contextInfo: { stanzaId: 'ASKED' },
                },
            }),
            noLookup,
        );

        assert.equal(button?.type, 'interactive');
        assert.deepEqual(button?.interactive, {
            kind: 'button_reply',
            title: 'Sí, confirmo',
            description: null,
        });
        assert.equal(button?.text, 'Sí, confirmo');
        assert.equal(button?.quoted?.provider_message_id, 'ASKED');

        const list = await normalizeMessage(
            message({
                listResponseMessage: {
                    title: 'Plan mensual',
                    description: '12 pagos',
                    singleSelectReply: { selectedRowId: 'plan-12' },
                },
            }),
            noLookup,
        );

        assert.deepEqual(list?.interactive, {
            kind: 'list_reply',
            title: 'Plan mensual',
            description: '12 pagos',
        });

        const template = await normalizeMessage(
            message({
                templateButtonReplyMessage: {
                    selectedId: '1',
                    selectedDisplayText: 'Quiero más información',
                },
            }),
            noLookup,
        );

        assert.equal(template?.interactive?.title, 'Quiero más información');

        const buttons = await normalizeMessage(
            message({
                buttonsMessage: { contentText: 'Elige una opción' },
            }),
            noLookup,
        );

        assert.equal(buttons?.type, 'text');
        assert.equal(buttons?.text, 'Elige una opción');
    });

    test('GIF, sticker animado, miniatura, medidas y ubicación en tiempo real', async () => {
        const jpeg = new Uint8Array([0xff, 0xd8, ...new Array(30).fill(1)]);
        const gif = await normalizeMessage(
            message({
                videoMessage: {
                    mediaKey,
                    directPath: '/v/gif',
                    mimetype: 'video/mp4',
                    gifPlayback: true,
                    seconds: 3,
                    width: 320,
                    height: 240,
                    jpegThumbnail: jpeg,
                },
            }),
            noLookup,
        );

        assert.equal(gif?.type, 'video');
        assert.equal(gif?.media?.gif, true);
        assert.equal(gif?.media?.width, 320);
        assert.equal(gif?.media?.height, 240);
        assert.equal(
            gif?.media?.thumbnail,
            Buffer.from(jpeg).toString('base64'),
        );

        const sticker = await normalizeMessage(
            message({
                stickerMessage: {
                    mediaKey,
                    directPath: '/v/sticker',
                    mimetype: 'image/webp',
                    isAnimated: true,
                },
            }),
            noLookup,
        );

        assert.equal(sticker?.type, 'sticker');
        assert.equal(sticker?.media?.animated, true);

        const voice = await normalizeMessage(
            message({
                audioMessage: {
                    mediaKey,
                    directPath: '/v/voice',
                    mimetype: 'audio/ogg; codecs=opus',
                    ptt: true,
                    seconds: 7,
                },
            }),
            noLookup,
        );

        // The codec is not part of the type the platform stores.
        assert.equal(voice?.media?.mime, 'audio/ogg');
        assert.equal(voice?.media?.descriptor?.mime, 'audio/ogg; codecs=opus');
        assert.equal(voice?.media?.voice, true);
        assert.equal(voice?.media?.seconds, 7);

        const live = await normalizeMessage(
            message({
                liveLocationMessage: {
                    degreesLatitude: 19.4,
                    degreesLongitude: -99.1,
                    caption: 'Voy en camino',
                },
            }),
            noLookup,
        );

        assert.equal(live?.type, 'location');
        assert.equal(live?.location?.live, true);
        assert.equal(live?.text, 'Voy en camino');
    });

    test('envolturas: temporales, de una sola vista y documento con pie', async () => {
        const ephemeral = await normalizeMessage(
            message({
                ephemeralMessage: {
                    message: {
                        extendedTextMessage: { text: 'Se borra en 7 días' },
                    },
                },
            }),
            noLookup,
        );

        assert.equal(ephemeral?.type, 'text');
        assert.equal(ephemeral?.text, 'Se borra en 7 días');

        const once = await normalizeMessage(
            message({
                viewOnceMessageV2: {
                    message: { imageMessage: { mimetype: 'image/jpeg' } },
                },
            }),
            noLookup,
        );

        assert.equal(once?.type, 'image');
        assert.equal(once?.view_once, true);
        assert.equal(once?.media?.descriptor, null);

        const captioned = await normalizeMessage(
            message({
                documentWithCaptionMessage: {
                    message: {
                        documentMessage: {
                            mediaKey,
                            directPath: '/v/doc',
                            mimetype: 'application/pdf',
                            fileName: 'estado.pdf',
                            caption: 'Tu estado de cuenta',
                        },
                    },
                },
            }),
            noLookup,
        );

        assert.equal(captioned?.type, 'document');
        assert.equal(captioned?.media?.file_name, 'estado.pdf');
        assert.equal(captioned?.text, 'Tu estado de cuenta');
    });

    test('la cita lleva quién escribió el original y un extracto', async () => {
        const reply = await normalizeMessage(
            message({
                extendedTextMessage: {
                    text: 'Ese mismo',
                    contextInfo: {
                        stanzaId: 'ORIGINAL',
                        participant: '5215512345678@s.whatsapp.net',
                        quotedMessage: { conversation: '¿Cuál comprobante?' },
                    },
                },
            }),
            noLookup,
        );

        assert.deepEqual(reply?.quoted, {
            provider_message_id: 'ORIGINAL',
            from_me: true,
            text: '¿Cuál comprobante?',
        });

        const own = await normalizeMessage(
            message({
                extendedTextMessage: {
                    text: 'Este',
                    contextInfo: {
                        stanzaId: 'MINE',
                        participant: CUSTOMER,
                        quotedMessage: {
                            imageMessage: { mimetype: 'image/jpeg' },
                        },
                    },
                },
            }),
            noLookup,
        );

        assert.deepEqual(own?.quoted, {
            provider_message_id: 'MINE',
            from_me: false,
            text: 'Imagen',
        });
    });

    test('edición y «eliminar para todos» se reportan aparte, con el mensaje que cambian', async () => {
        const edit = protocolChange(
            message(
                {
                    protocolMessage: {
                        type: 14,
                        key: { id: 'TARGET', remoteJid: CUSTOMER },
                        editedMessage: { conversation: 'Texto corregido' },
                        timestampMs: 1_700_000_300_000,
                    },
                },
                { id: 'EDIT1' },
            ),
        );

        assert.deepEqual(edit, {
            kind: 'edit',
            data: {
                provider_message_id: 'TARGET',
                remote_jid: CUSTOMER,
                from_me: false,
                timestamp: 1_700_000_300,
                type: 'text',
                text: 'Texto corregido',
            },
        });

        // WhatsApp also wraps the edit in "editedMessage".
        const wrapped = protocolChange(
            message({
                editedMessage: {
                    message: {
                        protocolMessage: {
                            type: 14,
                            key: { id: 'TARGET2' },
                            editedMessage: {
                                imageMessage: { caption: 'Pie corregido' },
                            },
                        },
                    },
                },
            }),
        );

        assert.equal(wrapped?.kind, 'edit');
        assert.equal(wrapped?.data.provider_message_id, 'TARGET2');
        assert.equal(
            (wrapped?.data as { text: string | null }).text,
            'Pie corregido',
        );
        assert.equal(wrapped?.data.timestamp, 1_700_000_000);

        // The edit itself is never a message of its own.
        assert.equal(
            await normalizeMessage(
                message({
                    editedMessage: {
                        message: {
                            protocolMessage: {
                                type: 14,
                                key: { id: 'TARGET2' },
                                editedMessage: { conversation: 'x' },
                            },
                        },
                    },
                }),
                noLookup,
            ),
            null,
        );

        const revoke = protocolChange(
            message({
                protocolMessage: { type: 0, key: { id: 'GONE' } },
            }),
        );

        assert.deepEqual(revoke, {
            kind: 'revoke',
            data: {
                provider_message_id: 'GONE',
                remote_jid: CUSTOMER,
                from_me: false,
                timestamp: 1_700_000_000,
            },
        });

        assert.equal(protocolChange(message({ conversation: 'Hola' })), null);
        assert.equal(
            protocolChange(message({ protocolMessage: { type: 5 } })),
            null,
        );
    });

    test('historial: estado de entrega, reacciones y mensajes ya eliminados', async () => {
        const mine = await normalizeMessage(
            {
                ...message(
                    { conversation: 'Le confirmo el pago' },
                    { fromMe: true },
                ),
                status: 4,
                reactions: [
                    { key: { fromMe: false }, text: '👍' },
                    { key: { fromMe: true }, text: '' },
                ],
            },
            noLookup,
            { historical: true },
        );

        assert.equal(mine?.status, 'read');
        assert.deepEqual(mine?.reactions, [{ emoji: '👍', from_me: false }]);

        const revoked = await normalizeMessage(
            { ...message(undefined), messageStubType: 1 },
            noLookup,
            { historical: true },
        );

        assert.equal(revoked?.revoked, true);
        assert.equal(revoked?.type, 'text');
        assert.equal(revoked?.text, null);

        // Outside the history a deletion stub is not a message.
        assert.equal(
            await normalizeMessage(
                { ...message(undefined), messageStubType: 1 },
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
