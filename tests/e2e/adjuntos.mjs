/*
 * The composer's attachment picker (phase 5.2), in a real browser with mouse
 * and keyboard, on top of tests/e2e/adjuntos-seed.php (a WhatsApp and a web
 * chat conversation assigned to E2E Ana, a third one closed behind her back,
 * and two unavailable inbound files):
 *
 *   menu-mouse / menu-keyboard   the clip opens a menu (Fotos e imágenes,
 *                                Videos, Documentos, Audio), closes with a
 *                                click outside and with Escape, arrows move,
 *                                focus returns to the clip
 *   accept-per-category          each entry opens the file chooser with the
 *                                `accept` of its kind on WhatsApp
 *   image / video / document     preview (thumbnail, first frame or icon),
 *                                name, size, kind, «Se envía como archivo»,
 *                                remove
 *   oversized / unsupported /    category-specific refusals; a photo chosen
 *   wrong-category               under «Videos» is never sent as a video
 *   zip-link-whatsapp            ZIP on WhatsApp: only as «Usar enlace
 *                                privado» (undoable), sent as a link
 *   zip-native-web-chat          the web chat carries ZIP natively: no link
 *   duplicate                    the same file chosen twice is not added again
 *   upload-progress              throttled upload: real progress, a second
 *                                submit is ignored, one message arrives
 *   send-conflict                the conversation closed meanwhile: the
 *                                server's refusal is explained (C1)
 *   unavailable-files            «Archivo histórico no disponible» and
 *                                «Archivo no disponible», without links
 *   layout-*                     320/375/768/1280 px, light and dark: menu,
 *                                link offer and refusal without overflow
 *
 *   E2E_REVERB=1 E2E_PORT=8130 E2E_REVERB_PORT=8191 WHATSAPP_TRANSPORT=simulated \
 *     QUEUE_CONNECTION=sync TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php adjuntos.mjs
 *
 * SIMULACIÓN: Meta is never called (simulated transport).
 */
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
    BASE,
    HERE,
    SHOTS,
    USERS,
    exitWithSummary,
    launchBrowser,
    layoutMatrix,
    login,
    openPage,
    php,
    printProblems,
    recordPageProblems,
    shot,
    step,
} from './lib.mjs';

const SEED = join(HERE, 'adjuntos-seed.php');
const data = (() => {
    try {
        return JSON.parse(php(SEED, 'prepare'));
    } catch (error) {
        console.error(String(error.stderr ?? error.stdout ?? error));
        process.exit(1);
    }
})();
const CHAT = 'section[aria-label^="Conversación con"]';
const MB = 1024 * 1024;

const PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFElEQVQImWOU64liwAaYsIoOWgkAz+QBFArsdHsAAAAASUVORK5CYII=',
    'base64',
);
const mp4 = (size) => {
    const head = Buffer.concat([
        Buffer.from([0, 0, 0, 0x18]),
        Buffer.from('ftypmp42', 'latin1'),
        Buffer.alloc(4),
        Buffer.from('mp42isom', 'latin1'),
    ]);

    return Buffer.concat([head, Buffer.alloc(Math.max(0, size - head.length))]);
};
const pdf = (size) => {
    const head = Buffer.from('%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n');
    const tail = Buffer.from('\ntrailer << /Root 1 0 R >>\n%%EOF\n');

    return Buffer.concat([
        head,
        Buffer.alloc(Math.max(0, size - head.length - tail.length), 0x20),
        tail,
    ]);
};
const ZIP = Buffer.concat([
    Buffer.from([0x50, 0x4b, 0x05, 0x06]),
    Buffer.alloc(18),
]);

/* Files on disk keep their modification time (the duplicate check uses it). */
const DIR = mkdtempSync(join(tmpdir(), 'e2e-adjuntos-'));
const onDisk = (name, buffer) => {
    const path = join(DIR, name);
    writeFileSync(path, buffer);

    return path;
};
const FILES = {
    png: onDisk('foto-producto.png', PNG),
    mp4: onDisk('video-entrega.mp4', mp4(4096)),
    bigMp4: onDisk('video-largo.mp4', mp4(17 * MB)),
    pdf: onDisk('cotizacion.pdf', pdf(2048)),
    slowPdf: onDisk('catalogo-grande.pdf', pdf(900 * 1024)),
    exe: onDisk(
        'instalador.exe',
        Buffer.concat([Buffer.from('MZ'), Buffer.alloc(256)]),
    ),
    zip: onDisk('respaldo-pedidos.zip', ZIP),
};

const clip = (page) =>
    page.getByRole('button', { name: 'Adjuntar archivo', exact: true });
const card = (page) =>
    page.locator('[role="group"][aria-label="Archivo adjunto"]');
const menu = (page) => page.getByRole('menu');
const send = (page) =>
    page.getByRole('button', { name: 'Enviar', exact: true });

/** Opens the menu with the mouse and picks an entry; returns the input's `accept`. */
async function choose(page, label, file = null) {
    await clip(page).click();
    await menu(page).waitFor();
    const [chooser] = await Promise.all([
        page.waitForEvent('filechooser'),
        page.getByRole('menuitem', { name: new RegExp(`^${label}`) }).click(),
    ]);
    const accept =
        (await page
            .locator('input[data-attachment-input]')
            .getAttribute('accept')) ?? '';

    if (file) {
        await chooser.setFiles(file);
        await card(page).waitFor();
    }

    return accept;
}

async function removeAttachment(page) {
    if ((await card(page).count()) > 0) {
        await page
            .getByRole('button', { name: /^Quitar el archivo adjunto/ })
            .click();
        await card(page).waitFor({ state: 'detached' });
    }
}

async function cardFacts(page) {
    const box = card(page);

    return {
        text: (await box.innerText()).replace(/\s+/g, ' '),
        kind: await box.locator('[data-attachment-kind]').innerText(),
        size: await box.locator('[data-attachment-size]').innerText(),
        delivery: await box
            .locator('[data-attachment-delivery-label]')
            .innerText()
            .catch(() => ''),
        image: await box.locator('img[alt^="Vista previa"]').count(),
        video: await box.locator('video').count(),
        icon: await box.locator('svg').count(),
        remove: await box
            .getByRole('button', { name: /^Quitar el archivo adjunto/ })
            .count(),
    };
}

async function openChat(page, id) {
    await page.goto(`${BASE}/conversations?c=${id}`);
    await page.locator('#chat-composer').waitFor();
    await page.waitForLoadState('networkidle');
}

const onFailure = (page, name) => () => shot(page, `adjuntos-${name}`);

const run = async () => {
    const browser = await launchBrowser();

    try {
        const ana = await openPage(browser, { prefix: '[ana] ' });
        await login(ana, USERS.ana);
        const storageState = await ana.context().storageState();
        await openChat(ana, data.whatsapp);

        await step('seed', 'adjuntos-seed.php', async () => [
            data.whatsapp > 0 && data.web > 0 && data.conflict > 0,
            JSON.stringify(data),
        ]);

        await step(
            'menu-mouse',
            'click clip / click outside',
            async () => {
                await clip(ana).click();
                await menu(ana).waitFor();
                const items = await ana.getByRole('menuitem').allInnerTexts();
                const labels = items.map((item) => item.split('\n')[0].trim());
                await ana.locator('#chat-composer').click({ force: true });
                const closed = await menu(ana)
                    .waitFor({ state: 'detached', timeout: 3_000 })
                    .then(() => true)
                    .catch(() => false);

                return [
                    JSON.stringify(labels) ===
                        JSON.stringify([
                            'Fotos e imágenes',
                            'Videos',
                            'Documentos',
                            'Audio',
                        ]) && closed,
                    `items=${JSON.stringify(items.map((item) => item.replace(/\s+/g, ' ')))}; closed by outside click=${closed}`,
                ];
            },
            onFailure(ana, 'menu-mouse'),
        );

        await step(
            'menu-keyboard',
            'Enter / arrows / Escape',
            async () => {
                await clip(ana).focus();
                await ana.keyboard.press('Enter');
                await menu(ana).waitFor();
                await ana.waitForTimeout(150);
                const first = await ana.evaluate(() =>
                    document.activeElement?.getAttribute('data-category'),
                );
                await ana.keyboard.press('ArrowDown');
                const second = await ana.evaluate(() =>
                    document.activeElement?.getAttribute('data-category'),
                );
                await ana.keyboard.press('Escape');
                await menu(ana).waitFor({ state: 'detached' });
                await ana.waitForTimeout(100);
                const back = await ana.evaluate(() =>
                    document.activeElement?.getAttribute('aria-label'),
                );
                const role = await ana.evaluate(() =>
                    document.activeElement?.getAttribute('aria-haspopup'),
                );

                return [
                    first === 'image' &&
                        second === 'video' &&
                        back === 'Adjuntar archivo',
                    `focused=${first}→${second}; after Escape focus on "${back}" (aria-haspopup=${role})`,
                ];
            },
            onFailure(ana, 'menu-keyboard'),
        );

        await step('accept-per-category', 'WhatsApp', async () => {
            const accepts = {};

            for (const label of [
                'Fotos e imágenes',
                'Videos',
                'Documentos',
                'Audio',
            ]) {
                accepts[label] = (await choose(ana, label)).split(',');
            }

            const has = (label, ...values) =>
                values.every((value) => accepts[label].includes(value));
            const lacks = (label, ...values) =>
                values.every((value) => !accepts[label].includes(value));
            const ok =
                has(
                    'Fotos e imágenes',
                    'image/jpeg',
                    'image/png',
                    '.jpg',
                    '.png',
                ) &&
                lacks(
                    'Fotos e imágenes',
                    'video/mp4',
                    'application/pdf',
                    'image/webp',
                ) &&
                has('Videos', 'video/mp4', 'video/3gpp', '.mp4', '.3gp') &&
                lacks('Videos', 'image/png', 'application/pdf') &&
                has(
                    'Documentos',
                    'application/pdf',
                    '.docx',
                    '.xlsx',
                    'text/plain',
                    'application/zip',
                ) &&
                lacks('Documentos', 'image/png', 'video/mp4') &&
                has('Audio', 'audio/mpeg', 'audio/ogg', 'audio/aac', '.m4a') &&
                lacks('Audio', 'video/mp4', 'image/png');

            return [
                ok,
                Object.entries(accepts)
                    .map(([label, list]) => `${label}: ${list.join(',')}`)
                    .join(' | '),
            ];
        });

        await step(
            'image',
            'Fotos e imágenes → PNG',
            async () => {
                await choose(ana, 'Fotos e imágenes', FILES.png);
                const facts = await cardFacts(ana);
                await ana
                    .getByAltText('Vista previa de foto-producto.png')
                    .waitFor();
                await removeAttachment(ana);

                return [
                    facts.image === 1 &&
                        facts.kind === 'Imagen' &&
                        facts.size === '1 KB' &&
                        facts.text.includes('foto-producto.png') &&
                        facts.delivery === 'Se envía como archivo' &&
                        facts.remove === 1 &&
                        (await card(ana).count()) === 0,
                    facts.text,
                ];
            },
            onFailure(ana, 'image'),
        );

        await step(
            'video',
            'Videos → MP4',
            async () => {
                await choose(ana, 'Videos', FILES.mp4);
                await ana.waitForTimeout(400);
                const facts = await cardFacts(ana);
                await removeAttachment(ana);

                // Synthetic bytes do not decode: the first frame falls back to an icon.
                return [
                    (facts.video === 1 || facts.icon >= 2) &&
                        facts.kind === 'Video' &&
                        facts.size === '4 KB' &&
                        facts.delivery === 'Se envía como archivo',
                    `${facts.text}; video element=${facts.video}`,
                ];
            },
            onFailure(ana, 'video'),
        );

        await step(
            'document',
            'Documentos → PDF',
            async () => {
                await choose(ana, 'Documentos', FILES.pdf);
                const facts = await cardFacts(ana);
                const canSend = await send(ana).isEnabled();
                await removeAttachment(ana);

                return [
                    facts.image === 0 &&
                        facts.kind === 'Documento' &&
                        facts.size === '2 KB' &&
                        facts.delivery === 'Se envía como archivo' &&
                        canSend,
                    `${facts.text}; send enabled=${canSend}`,
                ];
            },
            onFailure(ana, 'document'),
        );

        await step(
            'oversized-video',
            'Videos → 17 MB',
            async () => {
                await choose(ana, 'Videos', FILES.bigMp4);
                const alert = await card(ana)
                    .locator('[role="alert"]')
                    .innerText();
                const canSend = await send(ana).isEnabled();
                await removeAttachment(ana);

                return [
                    alert.startsWith(
                        'Este video supera 16 MB, el máximo que admite WhatsApp',
                    ) && !canSend,
                    `"${alert}"; send enabled=${canSend}`,
                ];
            },
            onFailure(ana, 'oversized'),
        );

        await step(
            'unsupported-document',
            'Documentos → .exe',
            async () => {
                await choose(ana, 'Documentos', FILES.exe);
                const alert = await card(ana)
                    .locator('[role="alert"]')
                    .innerText();
                const link = await ana
                    .getByRole('button', {
                        name: 'Usar enlace privado',
                    })
                    .count();
                const canSend = await send(ana).isEnabled();
                await removeAttachment(ana);

                return [
                    alert.startsWith(
                        'Este tipo de archivo no se puede enviar como documento por WhatsApp.',
                    ) &&
                        link === 0 &&
                        !canSend,
                    `"${alert}"; link option=${link}; send enabled=${canSend}`,
                ];
            },
            onFailure(ana, 'unsupported'),
        );

        await step(
            'wrong-category',
            'Videos → PNG',
            async () => {
                await choose(ana, 'Videos', FILES.png);
                const alert = await card(ana)
                    .locator('[role="alert"]')
                    .innerText();
                const kind = await card(ana)
                    .locator('[data-attachment-kind]')
                    .innerText();
                const canSend = await send(ana).isEnabled();
                await removeAttachment(ana);

                return [
                    alert.includes('Este archivo es una imagen, no un video') &&
                        alert.includes('«Fotos e imágenes»') &&
                        !canSend,
                    `"${alert}"; kind=${kind}; send enabled=${canSend}`,
                ];
            },
            onFailure(ana, 'wrong-category'),
        );

        await step(
            'zip-link-whatsapp',
            'Documentos → ZIP → enlace',
            async () => {
                await choose(ana, 'Documentos', FILES.zip);
                const offer = ana.getByRole('button', {
                    name: 'Usar enlace privado',
                });
                await offer.waitFor();
                const before = {
                    send: await send(ana).isEnabled(),
                    text: (await card(ana).innerText()).replace(/\s+/g, ' '),
                };
                await offer.click();
                // The choice can be undone («No usar enlace») and taken again.
                await ana
                    .getByRole('button', { name: 'No usar enlace' })
                    .click();
                const undone = {
                    send: await send(ana).isEnabled(),
                    offer: await offer.count(),
                };
                await offer.click();
                const after = {
                    send: await send(ana).isEnabled(),
                    delivery: await card(ana)
                        .locator('[data-attachment-delivery-label]')
                        .innerText(),
                };
                await send(ana).click();
                await ana
                    .locator('[data-message-id]', {
                        hasText: 'respaldo-pedidos.zip',
                    })
                    .last()
                    .waitFor();
                const stored = JSON.parse(php(SEED, 'last', data.whatsapp));

                return [
                    !before.send &&
                        before.text.includes(
                            'Puedes enviarlo como enlace privado solo si lo eliges',
                        ) &&
                        !undone.send &&
                        undone.offer === 1 &&
                        after.send &&
                        after.delivery === 'Se envía como enlace privado' &&
                        stored.attachments[0]?.delivery_method === 'link',
                    `before: send=${before.send} "${before.text.slice(0, 220)}"; undone: send=${undone.send} offer=${undone.offer}; after: send=${after.send} "${after.delivery}"; stored=${JSON.stringify(stored)}`,
                ];
            },
            onFailure(ana, 'zip-link'),
        );

        await step(
            'duplicate',
            'same PDF twice',
            async () => {
                await choose(ana, 'Documentos', FILES.pdf);
                await choose(ana, 'Documentos', FILES.pdf);
                const cards = await card(ana).count();
                const notice = await card(ana)
                    .locator('[data-attachment-notice]')
                    .innerText()
                    .catch(() => '');
                await removeAttachment(ana);

                return [
                    cards === 1 &&
                        notice.startsWith('Este archivo ya está adjunto'),
                    `cards=${cards}; notice="${notice}"`,
                ];
            },
            onFailure(ana, 'duplicate'),
        );

        await step(
            'upload-progress',
            '900 KB at 96 KB/s, double submit',
            async () => {
                const cdp = await ana.context().newCDPSession(ana);
                await cdp.send('Network.enable');
                await cdp.send('Network.emulateNetworkConditions', {
                    offline: false,
                    latency: 20,
                    downloadThroughput: -1,
                    uploadThroughput: 96 * 1024,
                });

                try {
                    await choose(ana, 'Documentos', FILES.slowPdf);
                    const bubbles = () =>
                        ana
                            .locator('[data-message-id]', {
                                hasText: 'catalogo-grande.pdf',
                            })
                            .count();
                    const bubblesBefore = await bubbles();
                    await send(ana).click();
                    // A second submit while uploading (Enter in the composer).
                    await ana.locator('#chat-composer').focus();
                    await ana.keyboard.press('Enter');
                    const seen = new Set();
                    const bar = card(ana).getByRole('progressbar');
                    const started = Date.now();

                    while (Date.now() - started < 30_000) {
                        const value = await bar
                            .getAttribute('aria-valuenow', { timeout: 500 })
                            .catch(() => null);

                        if (value !== null) {
                            seen.add(Number(value));
                        }

                        if ((await card(ana).count()) === 0) {
                            break;
                        }

                        await ana.waitForTimeout(150);
                    }

                    await ana
                        .locator('[data-message-id]', {
                            hasText: 'catalogo-grande.pdf',
                        })
                        .last()
                        .waitFor();
                    await ana.waitForTimeout(800);
                    const bubblesAfter = await bubbles();
                    const values = [...seen].sort((a, b) => a - b);
                    const partial = values.filter(
                        (value) => value > 0 && value < 100,
                    );

                    return [
                        partial.length >= 2 &&
                            bubblesAfter === bubblesBefore + 1,
                        `progress values=${values.join(',')}; bubbles ${bubblesBefore}→${bubblesAfter}`,
                    ];
                } finally {
                    await cdp.send('Network.emulateNetworkConditions', {
                        offline: false,
                        latency: 0,
                        downloadThroughput: -1,
                        uploadThroughput: -1,
                    });
                    await cdp.detach();
                }
            },
            onFailure(ana, 'progress'),
        );

        await step(
            'resend-warning',
            'the PDF just sent, again',
            async () => {
                await choose(ana, 'Documentos', FILES.slowPdf);
                const notice = await card(ana)
                    .locator('[data-attachment-notice]')
                    .innerText()
                    .catch(() => '');
                await removeAttachment(ana);

                return [
                    notice.startsWith('Ya enviaste este mismo archivo'),
                    `notice="${notice}"`,
                ];
            },
            onFailure(ana, 'resend'),
        );

        await step(
            'unavailable-files',
            'historical_missing / storage_unavailable',
            async () => {
                const box = (code) =>
                    ana.locator(`[data-attachment-unavailable="${code}"]`);
                await box('historical_missing').scrollIntoViewIfNeeded();
                const historical = (
                    await box('historical_missing').innerText()
                ).replace(/\s+/g, ' ');
                const storage = (
                    await box('storage_unavailable').innerText()
                ).replace(/\s+/g, ' ');
                const links =
                    (await box('historical_missing').locator('a').count()) +
                    (await box('storage_unavailable').locator('a').count());

                return [
                    historical.startsWith('Archivo histórico no disponible') &&
                        storage.startsWith('Archivo no disponible') &&
                        links === 0,
                    `historical="${historical}"; storage="${storage}"; links=${links}`,
                ];
            },
            onFailure(ana, 'unavailable'),
        );

        await step(
            'zip-native-web-chat',
            'Chat web → Documentos → ZIP',
            async () => {
                await openChat(ana, data.web);
                await clip(ana).click();
                await menu(ana).waitFor();
                const items = (
                    await ana.getByRole('menuitem').allInnerTexts()
                ).map((item) => item.split('\n')[0].trim());
                await ana.keyboard.press('Escape');
                await choose(ana, 'Documentos', FILES.zip);
                const facts = await cardFacts(ana);
                const link = await ana
                    .getByRole('button', {
                        name: 'Usar enlace privado',
                    })
                    .count();
                const canSend = await send(ana).isEnabled();
                await removeAttachment(ana);

                return [
                    items.includes('Audio') &&
                        facts.kind === 'Archivo ZIP' &&
                        facts.delivery === 'Se envía como archivo' &&
                        link === 0 &&
                        canSend,
                    `items=${items.join(', ')}; ${facts.text}; link option=${link}; send=${canSend}`,
                ];
            },
            onFailure(ana, 'zip-web'),
        );

        await step(
            'send-conflict',
            'conversation closed meanwhile',
            async () => {
                await openChat(ana, data.conflict);
                await ana.locator('#chat-composer').click();
                await ana.keyboard.type('¿Sigues ahí?');
                php(SEED, 'close', data.conflict);
                const [response] = await Promise.all([
                    ana.waitForResponse(
                        (candidate) =>
                            candidate.request().method() === 'POST' &&
                            candidate.url().includes('/messages'),
                    ),
                    send(ana).click(),
                ]);
                const alert = ana.locator(CHAT).locator('[role="alert"]', {
                    hasText:
                        /Esta conversación (cambió de estado|(ya )?está cerrada)|Otro agente ya está atendiendo|No tienes autorización/,
                });
                const shown = await alert
                    .first()
                    .waitFor({ timeout: 8_000 })
                    .then(() => true)
                    .catch(() => false);
                const text = shown
                    ? (await alert.first().innerText()).replace(/\s+/g, ' ')
                    : (
                          await ana
                              .locator(CHAT)
                              .locator('[role="alert"], [role="status"]')
                              .allInnerTexts()
                      ).join(' | ');

                return [
                    response.status() === 409 && shown,
                    `status=${response.status()}; "${text.slice(0, 200)}"`,
                ];
            },
            onFailure(ana, 'conflict'),
        );

        await ana.context().close();

        const STATES = [
            {
                name: 'menu',
                prepare: async (page) => {
                    await clip(page).click();
                    await menu(page).waitFor();
                    await page.waitForTimeout(300);
                    await page.evaluate(() =>
                        document
                            .querySelectorAll('[role="menu"]')
                            .forEach((popup) =>
                                popup.setAttribute('data-layout-check', ''),
                            ),
                    );
                },
                cleanup: async (page) => {
                    await page.keyboard.press('Escape');
                    await menu(page)
                        .waitFor({ state: 'detached' })
                        .catch(() => {});
                },
            },
            {
                name: 'link-offer',
                prepare: (page) => choose(page, 'Documentos', FILES.zip),
                cleanup: removeAttachment,
            },
            {
                name: 'refusal',
                prepare: (page) => choose(page, 'Videos', FILES.bigMp4),
                cleanup: removeAttachment,
            },
            {
                name: 'image',
                prepare: (page) => choose(page, 'Fotos e imágenes', FILES.png),
                cleanup: removeAttachment,
            },
        ];

        for (const scheme of ['light', 'dark']) {
            for (const width of [320, 375, 768, 1280]) {
                await step(
                    `layout-${width}-${scheme}`,
                    `${width}px ${scheme}`,
                    async () => {
                        const phone = width <= 430;
                        const page = await openPage(browser, {
                            context: {
                                viewport: { width, height: phone ? 844 : 900 },
                                colorScheme: scheme,
                                hasTouch: phone,
                                isMobile: phone,
                                storageState,
                            },
                            prefix: `[${width}${scheme}] `,
                        });

                        try {
                            await openChat(page, data.whatsapp);
                            const measured = await layoutMatrix(page, STATES);
                            const notes = [];

                            for (const state of measured) {
                                await shot(
                                    page,
                                    `adjuntos-${width}-${scheme}-${state.name}`,
                                    {
                                        fullPage: false,
                                    },
                                );

                                if (state.problems) {
                                    notes.push(
                                        `${state.name}: ${state.problems}`,
                                    );
                                }
                            }

                            return [
                                notes.length === 0,
                                notes.join(' | ') ||
                                    `${measured.length} states without overflow`,
                            ];
                        } finally {
                            await page.context().close();
                        }
                    },
                );
            }
        }
    } finally {
        await browser.close();
    }

    if (SHOTS) {
        console.log(`screenshots in ${SHOTS}`);
    }

    recordPageProblems(800);
    printProblems();
    exitWithSummary();
};

run().catch((error) => {
    console.error(error);
    process.exit(1);
});
