/*
 * WhatsApp stickers in the inbox and the automatic messages diagnostics of a
 * connection, on top of tests/e2e/stickers-seed.php (a conversation of E2E
 * Ana with a static sticker, an animated one larger than 500 KiB and one
 * refused as too_large; the connection's away message enabled without an
 * applied schedule).
 *
 *   static-sticker          the static WebP is shown as a sticker (decoded,
 *                           sticker size, no image frame)
 *   animated-keeps-moving   the animated WebP (> 500 KiB) is decoded and its
 *                           frames change over time in Chromium (successive
 *                           screenshots of the <img> differ; the static
 *                           one's do not)
 *   refused-reason          the refused sticker explains its own reason
 *   layout-{width}-{scheme} 390 and 1280 px, light and dark: no horizontal
 *                           overflow, stickers inside the conversation
 *   automation-diagnostics  the connection page (superadmin) explains the
 *                           automatic messages and warns that the away
 *                           message is never sent without a schedule
 *
 * SIMULACIÓN: the stickers are files written by the seed; Meta is not called.
 *
 *   E2E_PORT=8165 TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php stickers.mjs
 */
import { createHash } from 'node:crypto';
import { join } from 'node:path';
import {
    BASE,
    HERE,
    USERS,
    exitWithSummary,
    launchBrowser,
    login,
    openPage,
    php,
    printProblems,
    recordPageProblems,
    shot,
    step,
} from './lib.mjs';

const data = (() => {
    try {
        return JSON.parse(php(join(HERE, 'stickers-seed.php'), 'prepare'));
    } catch (error) {
        console.error(String(error.stderr ?? error.stdout ?? error));
        process.exit(1);
    }
})();

async function openChat(page) {
    await page.goto(`${BASE}/conversations?c=${data.conversation}`);
    // The seed's connection has no Meta identifiers, so there is no composer.
    await page
        .locator('img[data-sticker="static"]')
        .waitFor()
        .catch(async (error) => {
            await shot(page, 'stickers-open-chat');
            throw error;
        });
    await page.waitForLoadState('networkidle');
}

/** Decoded size of a sticker image, once it finished loading. */
async function decoded(page, kind) {
    const image = page.locator(`img[data-sticker="${kind}"]`);
    await image.scrollIntoViewIfNeeded();
    await image.evaluate((element) =>
        element.complete
            ? null
            : new Promise((resolve) =>
                  element.addEventListener('load', resolve, { once: true }),
              ),
    );

    return image.evaluate((element) => ({
        natural: element.naturalWidth,
        shown: Math.round(element.getBoundingClientRect().width),
        alt: element.alt,
    }));
}

/**
 * What the screen shows of the sticker right now. A canvas would not do: the
 * HTML spec makes drawImage() use the first frame of an animated image, so
 * only real screenshots of the element reveal its movement.
 */
async function frameHash(page, kind) {
    const pixels = await page
        .locator(`img[data-sticker="${kind}"]`)
        .screenshot({ animations: 'allow' });

    return createHash('sha1').update(pixels).digest('hex');
}

const run = async () => {
    const browser = await launchBrowser();

    try {
        const ana = await openPage(browser, { prefix: '[ana] ' });
        await login(ana, USERS.ana);
        const state = await ana.context().storageState();
        await openChat(ana);

        await step('seed', 'stickers-seed.php', async () => [
            data.conversation > 0 && data.animated_bytes > 512_000,
            JSON.stringify(data),
        ]);

        await step(
            'static-sticker',
            'static WebP sticker',
            async () => {
                const info = await decoded(ana, 'static');

                return [
                    info.natural > 0 &&
                        info.shown > 0 &&
                        info.shown <= 160 &&
                        info.alt === 'Sticker',
                    JSON.stringify(info),
                ];
            },
            () => shot(ana, 'stickers-static'),
        );

        await step(
            'animated-keeps-moving',
            `animated WebP of ${data.animated_bytes} B`,
            async () => {
                const info = await decoded(ana, 'animated');
                const hashes = new Set();

                for (let sample = 0; sample < 8; sample++) {
                    hashes.add(await frameHash(ana, 'animated'));
                    await ana.waitForTimeout(90);
                }

                const still = new Set();

                for (let sample = 0; sample < 4; sample++) {
                    still.add(await frameHash(ana, 'static'));
                    await ana.waitForTimeout(90);
                }

                return [
                    info.natural > 0 &&
                        info.alt === 'Sticker animado' &&
                        hashes.size > 1 &&
                        still.size === 1,
                    `${JSON.stringify(info)} distinct animated frames=${hashes.size}, static frames=${still.size}`,
                ];
            },
            () => shot(ana, 'stickers-animated'),
        );

        await step(
            'refused-reason',
            'sticker refused as too_large',
            async () => {
                const text = await ana
                    .locator('[data-attachment-unavailable="too_large"]')
                    .innerText();

                return [
                    text.includes('supera el tamaño que Chat Manager acepta'),
                    text.replace(/\s+/g, ' '),
                ];
            },
        );

        for (const width of [390, 1280]) {
            for (const scheme of ['light', 'dark']) {
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
                                storageState: state,
                            },
                            prefix: `[${width}${scheme}] `,
                        });

                        try {
                            await openChat(page);
                            const info = await decoded(page, 'animated');
                            const overflow = await page.evaluate(
                                () =>
                                    document.documentElement.scrollWidth -
                                    document.documentElement.clientWidth,
                            );
                            await shot(page, `stickers-${width}-${scheme}`);

                            return [
                                overflow <= 0 &&
                                    info.natural > 0 &&
                                    info.shown > 0 &&
                                    info.shown < width,
                                `overflow=${overflow} ${JSON.stringify(info)}`,
                            ];
                        } finally {
                            await page.context().close();
                        }
                    },
                );
            }
        }

        const admin = await openPage(browser, { prefix: '[admin] ' });
        await login(admin, USERS.superadmin);

        await step(
            'automation-diagnostics',
            `connection ${data.connection}`,
            async () => {
                await admin.goto(`${BASE}/connections/${data.connection}`);
                const section = admin.locator(
                    '[data-diagnostics="automation"]',
                );
                await section.waitFor();
                const text = (await section.innerText()).replace(/\s+/g, ' ');

                return [
                    text.includes('Atención continua') &&
                        text.includes('nunca se envía') &&
                        text.includes('una vez por conversación'),
                    text.slice(0, 400),
                ];
            },
            () => shot(admin, 'stickers-automation'),
        );
    } finally {
        await browser.close();
    }

    recordPageProblems();
    printProblems();
    exitWithSummary();
};

run().catch((error) => {
    console.error(error);
    process.exit(1);
});
