/*
 * The attachment upload chain over real HTTP (phase 5.2.1): a real browser
 * sends files generated at run time (outside the repository) through the
 * composer to the local PHP server, which stores them, and the delivery job
 * uploads them with the real WhatsApp transport to a stand-in of the Graph
 * API (tests/e2e/fake-graph.php, which records each file's size and
 * SHA-256). Two passes, chosen by the server's PHP limits:
 *
 *   production limits (E2E_UPLOAD_MAX_FILESIZE=100M E2E_POST_MAX_SIZE=110M)
 *     images 1 / 2.2 / 4 / 4.9 MB, videos 3.7 / 10 / 15.9 MB, documents
 *     small / 10 / 50 / 99 MB are sent (with and without text) and arrive
 *     complete at the transport; 5.2 MB images, 16.5 MB videos and 101 MB
 *     documents are refused before uploading by the channel's limit; a PNG
 *     renamed .jpg is refused by the server on the attachment field; an
 *     upload can be cancelled; a double click sends once; 390 px layout
 *
 *   php.ini limits (2M / 8M, nothing set)
 *     a 2.2 MB image and a 3.7 MB video are refused before uploading with
 *     the server configuration explanation (never «Escribe un mensaje…» nor
 *     a WhatsApp rule); with the published limit hidden (a stale page), the
 *     same image reaches PHP (UPLOAD_ERR_INI_SIZE) and a 9 MB document
 *     exceeds post_max_size (413): both leave the draft marked as refused
 *     with the server's explanation; a 1 MB image still goes; 390 px layout
 *
 *   both passes, internal chat: with production limits a 2.2 MB image and a
 *   4 MB document without text reach the other participant (E2E Beto); with
 *   2M the server configuration is explained before uploading and, for a
 *   page that does not know the limit, by the server (422), never
 *   «Escribe un mensaje…»
 *
 *   Sizes are decimal (1 MB = 10^6 bytes), the unit of the channel limits:
 *   a 16.2 MB video (less than 16 MiB) is refused by WhatsApp's 16 MB.
 *
 *   E2E_PORT=8140 WHATSAPP_TRANSPORT=meta META_GRAPH_BASE_URL=http://127.0.0.1:8141 \
 *     QUEUE_CONNECTION=sync E2E_UPLOAD_MAX_FILESIZE=100M E2E_POST_MAX_SIZE=110M \
 *     TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php subidas.mjs
 *   (and the same line without E2E_UPLOAD_MAX_FILESIZE / E2E_POST_MAX_SIZE)
 *
 * SIMULACIÓN: Meta is never called (META_GRAPH_BASE_URL is the local stand-in).
 */
import { createHash } from 'node:crypto';
import {
    closeSync,
    createReadStream,
    existsSync,
    mkdtempSync,
    openSync,
    readFileSync,
    rmSync,
    statSync,
    writeSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { crc32 } from 'node:zlib';
import {
    BASE,
    HERE,
    USERS,
    exitWithSummary,
    launchBrowser,
    login,
    openPage,
    overflow,
    php,
    printProblems,
    recordPageProblems,
    shot,
    startProcess,
    step,
} from './lib.mjs';

const SEED = join(HERE, 'subidas-seed.php');
const MB = 1024 * 1024;
/* Channel limits are decimal (1 MB = 10^6 bytes, ChannelAttachmentPolicy): so are the test files. */
const D = 1_000_000;
const PRODUCTION = Boolean(process.env.E2E_UPLOAD_MAX_FILESIZE);
const PASS = PRODUCTION ? 'produccion' : 'phpini';
const GRAPH = process.env.META_GRAPH_BASE_URL ?? '';

if (
    process.env.WHATSAPP_TRANSPORT !== 'meta' ||
    !GRAPH.startsWith('http://127.0.0.1:')
) {
    console.error(
        'subidas.mjs needs WHATSAPP_TRANSPORT=meta and META_GRAPH_BASE_URL=http://127.0.0.1:<port> (the local stand-in).',
    );
    process.exit(1);
}

const DIR = mkdtempSync(join(tmpdir(), 'e2e-subidas-'));
const GRAPH_LOG = join(DIR, 'fake-graph.jsonl');
process.env.FAKE_GRAPH_LOG = GRAPH_LOG;
startProcess(
    [
        '-d',
        'upload_max_filesize=200M',
        '-d',
        'post_max_size=210M',
        '-S',
        GRAPH.replace('http://', ''),
        join(HERE, 'fake-graph.php'),
    ],
    'fake-graph',
);

const data = (() => {
    try {
        return JSON.parse(php(SEED, 'prepare'));
    } catch (error) {
        console.error(String(error.stderr ?? error.stdout ?? error));
        process.exit(1);
    }
})();
const CONVERSATION = data.conversation;
const THREAD = data.thread;

/* ---------------------------------------------------------------- */
/* Files generated at run time, in a temporary directory.            */
/* ---------------------------------------------------------------- */

/** Writes head + zero padding + tail, exactly `size` bytes. */
function padded(name, head, size, tail = Buffer.alloc(0)) {
    const path = join(DIR, name);
    const fd = openSync(path, 'w');
    writeSync(fd, head);
    const chunk = Buffer.alloc(4 * MB);
    let left = size - head.length - tail.length;

    while (left > 0) {
        const piece = Math.min(left, chunk.length);
        writeSync(fd, chunk, 0, piece);
        left -= piece;
    }

    writeSync(fd, tail);
    closeSync(fd);

    return path;
}

/* A genuine 8×8 PNG; the padding is a private ancillary chunk with its CRC. */
const TINY_PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFElEQVQImWOU64liwAaYsIoOWgkAz+QBFArsdHsAAAAASUVORK5CYII=',
    'base64',
);

function png(name, size) {
    const iend = TINY_PNG.subarray(TINY_PNG.length - 12);
    const body = TINY_PNG.subarray(0, TINY_PNG.length - 12);
    const dataLength = size - TINY_PNG.length - 12;
    const type = Buffer.from('prVt', 'latin1');
    const payload = Buffer.alloc(dataLength);
    const length = Buffer.alloc(4);
    length.writeUInt32BE(dataLength);
    const crc = Buffer.alloc(4);
    crc.writeUInt32BE(crc32(Buffer.concat([type, payload])) >>> 0);
    const path = join(DIR, name);
    const fd = openSync(path, 'w');

    for (const part of [body, length, type, payload, crc, iend]) {
        writeSync(fd, part);
    }

    closeSync(fd);

    return path;
}

const MP4_HEAD = Buffer.concat([
    Buffer.from([0, 0, 0, 0x18]),
    Buffer.from('ftypmp42', 'latin1'),
    Buffer.alloc(4),
    Buffer.from('mp42isom', 'latin1'),
]);
const mp4 = (name, size) => padded(name, MP4_HEAD, size);
const pdf = (name, size) =>
    padded(
        name,
        Buffer.from('%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n'),
        size,
        Buffer.from('\ntrailer << /Root 1 0 R >>\n%%EOF\n'),
    );

async function sha256(path) {
    const hash = createHash('sha256');

    for await (const chunk of createReadStream(path)) {
        hash.update(chunk);
    }

    return hash.digest('hex');
}

const sizeOf = (path) => statSync(path).size;

/* ---------------------------------------------------------------- */
/* Browser helpers (same selectors as adjuntos.mjs).                  */
/* ---------------------------------------------------------------- */

const clip = (page) =>
    page.getByRole('button', { name: 'Adjuntar archivo', exact: true });
const card = (page) =>
    page.locator('[role="group"][aria-label="Archivo adjunto"]');
const send = (page) =>
    page.getByRole('button', { name: 'Enviar', exact: true });
const composerText = (page) => page.locator('#chat-composer');

async function choose(page, label, file) {
    await clip(page).click();
    await page.getByRole('menu').waitFor();
    const [chooser] = await Promise.all([
        page.waitForEvent('filechooser'),
        page.getByRole('menuitem', { name: new RegExp(`^${label}`) }).click(),
    ]);
    await chooser.setFiles(file);
    await card(page).waitFor();
    await page
        .getByRole('menu')
        .waitFor({ state: 'detached' })
        .catch(() => {});
}

async function removeAttachment(page) {
    if ((await card(page).count()) > 0) {
        await page
            .getByRole('button', { name: /^Quitar el archivo adjunto/ })
            .click();
        await card(page).waitFor({ state: 'detached' });
    }
}

async function cardState(page) {
    const box = card(page);

    return {
        state: await box.getAttribute('data-attachment-state'),
        reason: await box.getAttribute('data-attachment-reason'),
        alert: (
            await box
                .locator('[role="alert"]')
                .allInnerTexts()
                .catch(() => [])
        ).join(' '),
        canSend: await send(page).isEnabled(),
    };
}

/** Text the user can read in the composer area (alerts, card, hints). */
const composerArea = (page) =>
    page.locator('#chat-composer').locator('xpath=ancestor::form[1]');

async function openChat(page) {
    await page.goto(`${BASE}/conversations?c=${CONVERSATION}`);
    await composerText(page).waitFor();
    await page.waitForLoadState('networkidle');
}

const messages = () => JSON.parse(php(SEED, 'messages', String(CONVERSATION)));

function graphCalls() {
    if (!existsSync(GRAPH_LOG)) {
        return [];
    }

    return readFileSync(GRAPH_LOG, 'utf8')
        .split('\n')
        .filter(Boolean)
        .map((line) => JSON.parse(line));
}

async function waitForMessages(count, timeoutMs = 120_000) {
    const started = Date.now();

    while (Date.now() - started < timeoutMs) {
        const list = messages();

        if (list.length >= count) {
            return list;
        }

        await new Promise((resolve) => setTimeout(resolve, 500));
    }

    return messages();
}

/**
 * Sends the file (with an optional caption) and checks the whole chain: one
 * new message with the stored file of the right size, delivered by the
 * real transport, whose upload reached the stand-in complete (size and
 * SHA-256 of the local file).
 */
async function sendAndVerify(page, label, path, caption = '') {
    const before = messages().length;
    await choose(page, label, path);
    const ready = await cardState(page);
    await composerText(page).fill(caption);
    await send(page).click();
    await card(page).waitFor({ state: 'detached', timeout: 180_000 });
    const list = await waitForMessages(before + 1);
    const message = list.at(-1);
    const attachment = message?.attachments?.[0];
    const upload = graphCalls().find(
        (call) =>
            call.call === 'media' && call.id === attachment?.whatsapp_media_id,
    );
    const size = sizeOf(path);
    const hash = await sha256(path);
    const ok =
        ready.state === 'ready' &&
        list.length === before + 1 &&
        message.status === 'accepted' &&
        (message.body ?? '') === caption &&
        attachment?.size_bytes === size &&
        attachment?.stored_bytes === size &&
        attachment?.sha256 === hash &&
        upload?.size === size &&
        upload?.sha256 === hash &&
        upload?.authorized === true;

    return [
        ok,
        `state=${ready.state} msgs ${before}->${list.length} type=${message?.type} status=${message?.status} body=${JSON.stringify(message?.body)} stored=${attachment?.stored_bytes} db=${attachment?.size_bytes} transport=${upload?.size ?? 'none'} file=${size} sha=${upload?.sha256 === hash ? 'match' : 'MISMATCH'}`,
    ];
}

/** A file refused before uploading: nothing reaches the server. */
async function refusedLocally(page, label, path, expectedReason, expectedText) {
    const before = messages().length;
    await choose(page, label, path);
    const facts = await cardState(page);
    const area = await composerArea(page).innerText();
    await removeAttachment(page);
    const after = messages().length;

    return [
        facts.state === 'invalid' &&
            facts.reason === expectedReason &&
            facts.alert.includes(expectedText) &&
            !facts.canSend &&
            !area.includes('Escribe un mensaje o adjunta un archivo') &&
            after === before,
        `state=${facts.state} reason=${facts.reason} send=${facts.canSend} msgs ${before}->${after} "${facts.alert.slice(0, 220)}"`,
    ];
}

/*
 * Internal chat (team chat): a file without text reaches the other
 * participant; with the php.ini limits the server configuration is
 * explained (before uploading, and by the server for a page that does not
 * know the limit), never «Escribe un mensaje o adjunta un archivo».
 */
const teamMessages = () => JSON.parse(php(SEED, 'team', String(THREAD)));
const teamLog = (page) =>
    page.locator('section[aria-label^="Chat: "] [role="log"]');
const teamComposer = (page) => page.locator('[data-tour="team-chat.composer"]');

async function openThread(page) {
    await page.goto(`${BASE}/team-chat/threads/${THREAD}`);
    await teamComposer(page).locator('textarea').waitFor();
    await page.waitForLoadState('networkidle');
}

async function attachInTeamChat(page, path) {
    const [chooser] = await Promise.all([
        page.waitForEvent('filechooser'),
        teamComposer(page)
            .getByRole('button', { name: 'Adjuntar archivos' })
            .click(),
    ]);
    await chooser.setFiles(path);
}

async function teamChat(browser) {
    const options = (prefix) => ({
        prefix,
        context: {
            viewport: { width: 1280, height: 900 },
            serviceWorkers: 'block',
        },
        ignoreConsole: /status of (403|404|409|413|422|429)/,
    });
    const ana = await openPage(browser, options('[team-ana] '));
    await login(ana, USERS.ana);
    await openThread(ana);
    const send = () =>
        teamComposer(ana).getByRole('button', { name: 'Enviar mensaje' });
    const composerText = async () =>
        (await teamComposer(ana).innerText()).replace(/\s+/g, ' ');

    if (PRODUCTION) {
        const beto = await openPage(browser, options('[team-beto] '));
        await login(beto, USERS.beto);
        const image = png('equipo-foto-2.2mb.png', Math.round(2.2 * D));
        const document = pdf('equipo-reporte-4mb.pdf', 4 * D);

        for (const [id, path] of [
            ['image-2.2mb', image],
            ['document-4mb', document],
        ]) {
            await step(
                `team-chat-${id}-no-text`,
                `chat interno → ${sizeOf(path)} B sin texto, Beto lo ve`,
                async () => {
                    const before = teamMessages().length;
                    const name = path.split('/').at(-1);
                    await attachInTeamChat(ana, path);
                    await teamComposer(ana).getByText(name).waitFor();
                    await send().click();
                    await teamComposer(ana)
                        .getByText(name)
                        .waitFor({ state: 'detached', timeout: 60_000 });
                    const list = teamMessages();
                    const file = list.at(-1)?.attachments?.[0];
                    const hash = await sha256(path);
                    await openThread(beto);
                    const seen = await teamLog(beto)
                        .getByText(name, { exact: false })
                        .first()
                        .waitFor({ timeout: 20_000 })
                        .then(() => true)
                        .catch(() => false);
                    const seenImage =
                        id.startsWith('image') &&
                        (await teamLog(beto).locator('img').count()) > 0;
                    await shot(beto, `subidas-${PASS}-team-${id}`);

                    return [
                        list.length === before + 1 &&
                            list.at(-1).author === USERS.ana &&
                            list.at(-1).body === null &&
                            file?.size_bytes === sizeOf(path) &&
                            file?.stored_bytes === sizeOf(path) &&
                            file?.sha256 === hash &&
                            (seen || seenImage),
                        `msgs ${before}->${list.length} body=${JSON.stringify(list.at(-1)?.body)} stored=${file?.stored_bytes} file=${sizeOf(path)} sha=${file?.sha256 === hash ? 'match' : 'MISMATCH'} beto sees name=${seen} image=${seenImage}`,
                    ];
                },
                onFailure(beto, `team-${id}`),
            );
        }

        return;
    }

    const image = png('equipo-foto-2.2mb.png', Math.round(2.2 * D));

    await step(
        'team-chat-server-limit-before-upload',
        'chat interno → 2.2 MB con upload_max_filesize=2M',
        async () => {
            const before = teamMessages().length;
            await attachInTeamChat(ana, image);
            await teamComposer(ana)
                .getByText('y el servidor de Chat Manager acepta hasta', {
                    exact: false,
                })
                .waitFor();
            const text = await composerText();
            const attached = await teamComposer(ana)
                .getByRole('button', { name: /^Quitar equipo-foto/ })
                .count();
            await shot(ana, `subidas-${PASS}-team-server-limit`);

            return [
                attached === 0 &&
                    text.includes(
                        '«equipo-foto-2.2mb.png» pesa 2.2 MB y el servidor de Chat Manager acepta hasta 2.1 MB; un administrador puede ampliar el límite.',
                    ) &&
                    !text.includes('Escribe un mensaje o adjunta un archivo') &&
                    teamMessages().length === before,
                `attached=${attached} "${text.slice(0, 240)}"`,
            ];
        },
        onFailure(ana, 'team-server-limit'),
    );

    const stale = await openPage(browser, options('[team-stale] '));
    await stale.route(
        (url) => url.pathname.startsWith('/team-chat'),
        async (route) => {
            if (route.request().method() !== 'GET') {
                return route.continue();
            }

            const response = await route.fetch();
            const body = (await response.text()).replace(
                /("max_file_bytes":)\d+/g,
                '$1null',
            );
            await route.fulfill({ response, body });
        },
    );
    await login(stale, USERS.ana);
    await openThread(stale);

    await step(
        'team-chat-server-refusal',
        'chat interno, página sin el límite → 422 del servidor',
        async () => {
            const before = teamMessages().length;
            await attachInTeamChat(stale, image);
            const [response] = await Promise.all([
                stale.waitForResponse(
                    (candidate) =>
                        candidate.request().method() === 'POST' &&
                        candidate.url().includes('/messages'),
                ),
                teamComposer(stale)
                    .getByRole('button', { name: 'Enviar mensaje' })
                    .click(),
            ]);
            const raw = await response.text();
            let json = {};

            try {
                json = JSON.parse(raw.slice(raw.indexOf('{')));
            } catch {
                json = {};
            }

            await teamComposer(stale)
                .getByText(
                    'El servidor de Chat Manager no pudo recibir el archivo',
                    { exact: false },
                )
                .waitFor();
            const text = (await teamComposer(stale).innerText()).replace(
                /\s+/g,
                ' ',
            );
            const failedChip = await teamComposer(stale)
                .locator('[data-attachment-failed]')
                .count();
            await shot(stale, `subidas-${PASS}-team-server-refusal`);
            await teamComposer(stale)
                .getByRole('button', { name: /^Quitar equipo-foto/ })
                .click();
            const leftover = await teamComposer(stale)
                .getByText('El servidor de Chat Manager no pudo recibir', {
                    exact: false,
                })
                .count();

            return [
                response.status() === 422 &&
                    json.code === 'server_upload_limit' &&
                    failedChip === 1 &&
                    leftover === 0 &&
                    !text.includes('Escribe un mensaje o adjunta un archivo') &&
                    teamMessages().length === before,
                `HTTP ${response.status()} code=${json.code} failed chip=${failedChip} error after removing=${leftover} "${text.slice(0, 240)}"`,
            ];
        },
        onFailure(stale, 'team-server-refusal'),
    );
}

const onFailure = (page, name) => () => shot(page, `subidas-${PASS}-${name}`);

const files = {};

const run = async () => {
    const browser = await launchBrowser();

    try {
        const ana = await openPage(browser, {
            prefix: '[ana] ',
            context: { viewport: { width: 1280, height: 900 } },
            ignoreConsole: /status of (403|404|409|413|422|429)/,
        });
        await login(ana, USERS.ana);
        await openChat(ana);

        await step('seed', `subidas-seed.php (${PASS})`, async () => [
            CONVERSATION > 0,
            JSON.stringify(data),
        ]);

        if (PRODUCTION) {
            Object.assign(files, {
                img1: png('foto-1mb.png', 1 * D),
                img22: png('captura-2.2mb.png', Math.round(2.2 * D)),
                img4: png('foto-4mb.png', 4 * D),
                img49: png('foto-4.9mb.png', Math.round(4.9 * D)),
                img52: png('foto-5.2mb.png', Math.round(5.2 * D)),
                vid37: mp4('video-3.7mb.mp4', Math.round(3.7 * D)),
                vid10: mp4('video-10mb.mp4', 10 * D),
                vid159: mp4('video-15.9mb.mp4', Math.round(15.9 * D)),
                vid162: mp4('video-16.2mb.mp4', Math.round(16.2 * D)),
                vid165: mp4('video-16.5mb.mp4', Math.round(16.5 * D)),
                pdfSmall: pdf('cotizacion.pdf', 4096),
                pdf10: pdf('catalogo-10mb.pdf', 10 * D),
                pdf50: pdf('manual-50mb.pdf', 50 * D),
                pdf99: pdf('planos-99mb.pdf', 99 * D),
                pdf101: pdf('archivo-101mb.pdf', 101 * D),
                renamed: png('foto-renombrada.jpg', 300 * 1024),
                dbl: pdf('doble-clic.pdf', 10 * MB + 77),
            });

            const sends = [
                ['image-1mb-no-text', 'Fotos e imágenes', files.img1, ''],
                ['image-2.2mb-no-text', 'Fotos e imágenes', files.img22, ''],
                [
                    'image-4mb-text',
                    'Fotos e imágenes',
                    files.img4,
                    'Foto del producto',
                ],
                ['image-4.9mb-no-text', 'Fotos e imágenes', files.img49, ''],
                ['video-3.7mb-no-text', 'Videos', files.vid37, ''],
                [
                    'video-10mb-text',
                    'Videos',
                    files.vid10,
                    'Video de la entrega',
                ],
                ['video-15.9mb-no-text', 'Videos', files.vid159, ''],
                [
                    'document-small-text',
                    'Documentos',
                    files.pdfSmall,
                    'Su cotización',
                ],
                ['document-10mb-no-text', 'Documentos', files.pdf10, ''],
                ['document-50mb-no-text', 'Documentos', files.pdf50, ''],
                [
                    'document-99mb-text',
                    'Documentos',
                    files.pdf99,
                    'Planos completos',
                ],
            ];

            for (const [id, label, path, caption] of sends) {
                await step(
                    `send-${id}`,
                    `${label} → ${sizeOf(path)} B${caption ? ' + texto' : ''}`,
                    () => sendAndVerify(ana, label, path, caption),
                    onFailure(ana, id),
                );
            }

            for (const [id, label, path, text] of [
                [
                    'image-5.2mb',
                    'Fotos e imágenes',
                    files.img52,
                    'Esta imagen supera el tamaño permitido para WhatsApp (5 MB)',
                ],
                [
                    'video-16.2mb-under-16-MiB',
                    'Videos',
                    files.vid162,
                    'Este video supera el tamaño permitido para WhatsApp (16 MB): pesa 16.2 MB',
                ],
                [
                    'video-16.5mb',
                    'Videos',
                    files.vid165,
                    'Este video supera el tamaño permitido para WhatsApp (16 MB)',
                ],
                [
                    'document-101mb',
                    'Documentos',
                    files.pdf101,
                    'Este documento supera el tamaño permitido para WhatsApp (100 MB)',
                ],
            ]) {
                await step(
                    `channel-limit-${id}`,
                    `${label} → ${sizeOf(path)} B`,
                    () =>
                        refusedLocally(
                            ana,
                            label,
                            path,
                            'channel_size_limit',
                            text,
                        ),
                    onFailure(ana, id),
                );
            }

            await step(
                'renamed-extension',
                'PNG named .jpg → server refusal on the card',
                async () => {
                    const before = messages().length;
                    await choose(ana, 'Fotos e imágenes', files.renamed);
                    const ready = await cardState(ana);
                    await send(ana).click();
                    await card(ana)
                        .locator('[data-attachment-rejection]')
                        .waitFor({ timeout: 30_000 });
                    const facts = await cardState(ana);
                    const area = await composerArea(ana).innerText();
                    await shot(ana, `subidas-${PASS}-renamed-1280`);
                    await removeAttachment(ana);

                    return [
                        ready.state === 'ready' &&
                            facts.state === 'rejected' &&
                            facts.alert.includes(
                                'no corresponde a su contenido real',
                            ) &&
                            !facts.canSend &&
                            !area.includes(
                                'Escribe un mensaje o adjunta un archivo',
                            ) &&
                            messages().length === before,
                        `before=${ready.state} after=${facts.state} send=${facts.canSend} "${facts.alert.slice(0, 200)}"`,
                    ];
                },
                onFailure(ana, 'renamed'),
            );

            await step(
                'cancel-upload',
                '50 MB at 2 MB/s, «Cancelar envío»',
                async () => {
                    const before = messages().length;
                    const cdp = await ana.context().newCDPSession(ana);
                    await cdp.send('Network.enable');
                    await cdp.send('Network.emulateNetworkConditions', {
                        offline: false,
                        latency: 20,
                        downloadThroughput: -1,
                        uploadThroughput: 2 * MB,
                    });
                    let progress = null;
                    let facts = null;

                    try {
                        await choose(ana, 'Documentos', files.pdf50);
                        await send(ana).click();
                        const bar = card(ana).getByRole('progressbar');
                        await bar.waitFor();
                        await ana.waitForTimeout(2_000);
                        progress = await bar.getAttribute('aria-valuenow');
                        await ana
                            .getByRole('button', { name: 'Cancelar envío' })
                            .click();
                        await ana
                            .getByText('Se canceló el envío', { exact: false })
                            .waitFor();
                        facts = await cardState(ana);
                    } finally {
                        await cdp.send('Network.emulateNetworkConditions', {
                            offline: false,
                            latency: 0,
                            downloadThroughput: -1,
                            uploadThroughput: -1,
                        });
                    }

                    await ana.waitForTimeout(3_000);
                    const after = messages().length;
                    await removeAttachment(ana);

                    return [
                        Number(progress) > 0 &&
                            Number(progress) < 100 &&
                            facts?.state === 'ready' &&
                            facts?.canSend === true &&
                            after === before,
                        `progress at cancel=${progress}% state=${facts?.state} send=${facts?.canSend} msgs ${before}->${after}`,
                    ];
                },
                onFailure(ana, 'cancel'),
            );

            await step(
                'double-click',
                '10 MB document, double click + Enter',
                async () => {
                    const before = messages().length;
                    await choose(ana, 'Documentos', files.dbl);
                    await send(ana).dblclick();
                    await composerText(ana)
                        .press('Enter')
                        .catch(() => {});
                    await card(ana).waitFor({
                        state: 'detached',
                        timeout: 120_000,
                    });
                    await ana.waitForTimeout(2_000);
                    const list = messages();
                    const uploads = graphCalls().filter(
                        (call) =>
                            call.call === 'media' &&
                            call.size === sizeOf(files.dbl),
                    );

                    return [
                        list.length === before + 1 && uploads.length === 1,
                        `msgs ${before}->${list.length}, uploads of that size=${uploads.length}`,
                    ];
                },
                onFailure(ana, 'double'),
            );

            const phone = await openPage(browser, {
                prefix: '[ana-390] ',
                context: {
                    viewport: { width: 390, height: 844 },
                    isMobile: true,
                    hasTouch: true,
                },
                ignoreConsole: /status of (403|404|409|413|422|429)/,
            });
            await login(phone, USERS.ana);
            await openChat(phone);

            await step(
                'phone-390-image-2.2mb',
                '390 px: card fits, sent with text',
                async () => {
                    await choose(phone, 'Fotos e imágenes', files.img22);
                    const by = await overflow(phone);
                    await shot(phone, `subidas-${PASS}-card-390`);
                    await removeAttachment(phone);
                    const [ok, note] = await sendAndVerify(
                        phone,
                        'Fotos e imágenes',
                        files.img22,
                        'Desde el teléfono',
                    );

                    return [ok && by <= 0, `overflow=${by}; ${note}`];
                },
                onFailure(phone, 'phone'),
            );
        } else {
            Object.assign(files, {
                img1: png('foto-1mb.png', 1 * D),
                img22: png('captura-2.2mb.png', Math.round(2.2 * D)),
                vid37: mp4('video-3.7mb.mp4', Math.round(3.7 * D)),
                pdf9: pdf('manual-9mb.pdf', 9 * D),
            });
            /* Before uploading (present tense, the file's own size). */
            const serverText =
                'y el servidor de Chat Manager acepta hasta 2.1 MB; un administrador puede ampliar el límite.';
            /* After the server refused it. */
            const refusedText =
                'El servidor de Chat Manager no pudo recibir el archivo: acepta hasta 2.1 MB';

            await step(
                'menu-announces-server-limit',
                'menú del clip con upload_max_filesize=2M',
                async () => {
                    await clip(ana).click();
                    await ana.getByRole('menu').waitFor();
                    const items = (
                        await ana.getByRole('menuitem').allInnerTexts()
                    ).map((item) => item.replace(/\s+/g, ' '));
                    await shot(ana, `subidas-${PASS}-menu-server-limit`, {
                        fullPage: false,
                    });
                    await ana.keyboard.press('Escape');
                    const video =
                        items.find((item) => item.startsWith('Videos')) ?? '';

                    return [
                        video.includes('hasta 2.1 MB en este servidor') &&
                            !video.includes('16 MB') &&
                            !items.join(' ').includes('Meta'),
                        JSON.stringify(items),
                    ];
                },
                onFailure(ana, 'menu'),
            );

            await step(
                'server-limit-image-2.2mb',
                'Fotos e imágenes → 2.2 MB with upload_max_filesize=2M',
                async () => {
                    const [ok, note] = await refusedLocally(
                        ana,
                        'Fotos e imágenes',
                        files.img22,
                        'server_upload_limit',
                        serverText,
                    );
                    await choose(ana, 'Fotos e imágenes', files.img22);
                    const alert = (await cardState(ana)).alert;
                    await shot(ana, `subidas-${PASS}-server-limit-1280`);
                    await removeAttachment(ana);

                    return [
                        ok &&
                            !alert.includes('WhatsApp (5 MB)') &&
                            !alert.includes('regla de Meta'),
                        note,
                    ];
                },
                onFailure(ana, 'image-2.2'),
            );

            await step(
                'server-limit-video-3.7mb',
                'Videos → 3.7 MB with upload_max_filesize=2M',
                () =>
                    refusedLocally(
                        ana,
                        'Videos',
                        files.vid37,
                        'server_upload_limit',
                        serverText,
                    ),
                onFailure(ana, 'video-3.7'),
            );

            await step(
                'image-1mb-still-sent',
                'Fotos e imágenes → 1 MB',
                () => sendAndVerify(ana, 'Fotos e imágenes', files.img1, ''),
                onFailure(ana, 'image-1'),
            );

            /*
             * A page that does not know the server limit (opened before an
             * administrator lowered it): the upload reaches PHP and the
             * server's refusal is what the user reads.
             */
            const stale = await openPage(browser, {
                prefix: '[ana-stale] ',
                // The PWA service worker would answer the page itself, out of reach of route().
                context: {
                    viewport: { width: 1280, height: 900 },
                    serviceWorkers: 'block',
                },
                ignoreConsole: /status of (403|404|409|413|422|429)/,
            });
            const hidden = { pages: 0, limits: 0 };
            await stale.route(
                (url) => url.pathname === '/conversations',
                async (route) => {
                    const response = await route.fetch();
                    const original = await response.text();
                    const body = original.replace(
                        /("max_file_bytes":)\d+/g,
                        '$1null',
                    );
                    hidden.pages++;
                    hidden.limits += (
                        original.match(/"max_file_bytes":\d+/g) ?? []
                    ).length;
                    await route.fulfill({ response, body });
                },
            );
            await login(stale, USERS.ana);
            await openChat(stale);
            console.log(
                `stale page: ${hidden.pages} responses rewritten, ${hidden.limits} limits hidden`,
            );

            for (const [id, label, path, status, text] of [
                [
                    'ini-size-image-2.2mb',
                    'Fotos e imágenes',
                    files.img22,
                    422,
                    `${refusedText}, menos de lo que permite WhatsApp`,
                ],
                [
                    'post-too-large-document-9mb',
                    'Documentos',
                    files.pdf9,
                    413,
                    refusedText,
                ],
            ]) {
                await step(
                    `server-refusal-${id}`,
                    `${label} → ${sizeOf(path)} B, limit unknown to the page → ${status}`,
                    async () => {
                        const before = messages().length;
                        await choose(stale, label, path);
                        const ready = await cardState(stale);
                        const [response] = await Promise.all([
                            stale.waitForResponse(
                                (candidate) =>
                                    candidate.request().method() === 'POST' &&
                                    candidate.url().includes('/messages'),
                                { timeout: 60_000 },
                            ),
                            send(stale).click(),
                        ]);
                        /* A development PHP may print its warning before the JSON. */
                        const raw = await response.text();
                        const warned = !raw.trimStart().startsWith('{');
                        let json = {};

                        try {
                            json = JSON.parse(raw.slice(raw.indexOf('{')));
                        } catch {
                            json = {};
                        }

                        await card(stale)
                            .locator('[data-attachment-rejection]')
                            .waitFor({ timeout: 15_000 });
                        const facts = await cardState(stale);
                        const area = await composerArea(stale).innerText();
                        await shot(stale, `subidas-${PASS}-${id}`);
                        await removeAttachment(stale);

                        return [
                            ready.state === 'ready' &&
                                response.status() === status &&
                                json.code === 'server_upload_limit' &&
                                !JSON.stringify(json.errors ?? {}).includes(
                                    '"body"',
                                ) &&
                                facts.state === 'rejected' &&
                                facts.alert.includes(text) &&
                                !facts.canSend &&
                                !area.includes(
                                    'Escribe un mensaje o adjunta un archivo',
                                ) &&
                                messages().length === before,
                            `HTTP ${response.status()} code=${json.code}${warned ? ' (PHP warning before JSON)' : ''} state=${facts.state} send=${facts.canSend} "${facts.alert.slice(0, 200)}"`,
                        ];
                    },
                    onFailure(stale, id),
                );
            }

            const phone = await openPage(browser, {
                prefix: '[ana-390] ',
                context: {
                    viewport: { width: 390, height: 844 },
                    isMobile: true,
                    hasTouch: true,
                },
                ignoreConsole: /status of (403|404|409|413|422|429)/,
            });
            await login(phone, USERS.ana);
            await openChat(phone);

            await step(
                'phone-390-server-limit',
                '390 px: 2.2 MB image refused by the server limit, no overflow',
                async () => {
                    await choose(phone, 'Fotos e imágenes', files.img22);
                    const facts = await cardState(phone);
                    const by = await overflow(phone);
                    await shot(phone, `subidas-${PASS}-server-limit-390`);
                    await removeAttachment(phone);

                    return [
                        facts.state === 'invalid' &&
                            facts.reason === 'server_upload_limit' &&
                            by <= 0,
                        `state=${facts.state} reason=${facts.reason} overflow=${by}`,
                    ];
                },
                onFailure(phone, 'phone'),
            );
        }

        await teamChat(browser);

        recordPageProblems();
    } finally {
        await browser.close();
        console.log(
            `cleanup: ${php(SEED, 'cleanup', String(CONVERSATION), String(THREAD)).trim()}`,
        );
        rmSync(DIR, { recursive: true, force: true });
    }
};

run()
    .catch((error) => {
        console.error(error);
        process.exitCode = 1;
    })
    .finally(() => {
        printProblems();
        exitWithSummary();
    });
