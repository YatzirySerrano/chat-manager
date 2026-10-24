/*
 * The chat on every width (phase 4, "chat móvil sin overflow"): a stress
 * conversation (tests/e2e/chat-movil-seed.php: a PDF whose name has 120
 * characters, a quote and a note with a 90-character word, a 300-character
 * URL, 40 emojis, long contact and author names, more than 80 messages) is
 * measured with the reinforced detector of lib.mjs (layoutReport: document
 * against the configured width and zoom, offscreen, inner scroll, clipped
 * content, pieces out of their [data-layout-box], shadow roots and
 * same-origin iframes) in seven states:
 *
 *   carga      the chat just opened (history at the end)
 *   scroll     the history scrolled to its first message
 *   cita       the composer quoting the 90-character word
 *   adjunto    the composer with a file whose name is very long
 *   menu       "Más acciones de la conversación" open
 *   en-vivo    a customer message with a long word arriving live
 *   detalles   the details panel (a Sheet on phones and tablets)
 *
 * at 320, 360, 375, 390, 430, 768, 1024, 1280 and 1440 px, light and dark:
 * no doc, offscreen, innerScroll, clipped nor contained. Before that, the
 * detector is calibrated on the real PDF bubble: with the old implicit
 * "auto" grid track (max-content attachment) it must report the overflow.
 *
 *   E2E_REVERB=1 E2E_PORT=8070 E2E_REVERB_PORT=8131 WHATSAPP_TRANSPORT=simulated \
 *     TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php chat-movil.mjs
 *
 * Without E2E_REVERB the live message arrives through the 20 s polling
 * fallback (the scenario waits for it). SIMULACIÓN: Meta is never called.
 */
import { writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
    BASE,
    FALLBACK_MS,
    HERE,
    USERS,
    WIDTHS,
    calibrateLayout,
    exitWithSummary,
    launchBrowser,
    layoutMatrix,
    login,
    openPage,
    php,
    phpAsync,
    printProblems,
    recordPageProblems,
    shot,
    step,
} from './lib.mjs';

const SEED = join(HERE, 'chat-movil-seed.php');
const LONG_FILE = join(
    tmpdir(),
    `Cotizacion_Pedido_000123456789_Sucursal_Poniente_Version_Final_Revisada_Firmada_Definitiva_${process.pid}.pdf`,
);
writeFileSync(LONG_FILE, '%PDF-1.4\n%%EOF\n');

const data = JSON.parse(php(SEED, 'prepare'));
const CHAT = 'section[aria-label^="Conversación con"]';

const history = (page) =>
    page.getByRole('log', { name: /Historial de la conversación/ });

/** Waits for the Sheet's slide-in animation before measuring. */
async function settleAnimations(page) {
    await page
        .waitForFunction(
            () =>
                document
                    .getAnimations()
                    .every((animation) => animation.playState !== 'running'),
            null,
            { timeout: 5_000 },
        )
        .catch(() => {});
    await page.waitForTimeout(150);
}

/** The element is entirely inside the viewport (reachable without scrolling sideways). */
const inViewport = (locator) =>
    locator.evaluate((element) => {
        const rect = element.getBoundingClientRect();

        return (
            rect.width > 0 &&
            rect.left >= -0.5 &&
            rect.right <= document.documentElement.clientWidth + 0.5 &&
            rect.top >= -0.5 &&
            rect.bottom <= window.innerHeight + 0.5
        );
    });

/** Tags the open popups so the detector also measures them (they are fixed). */
const checkPopups = (page) =>
    page.evaluate(() =>
        document
            .querySelectorAll('[role="menu"], [role="dialog"]')
            .forEach((popup) => popup.setAttribute('data-layout-check', '')),
    );

let liveCount = 0;

/** The seven states of one width and color scheme. */
function states(width) {
    const composerIntact = async (page, problems) => {
        if (!(await inViewport(page.locator('#chat-composer')))) {
            problems.push('composer out of the viewport');
        }
    };

    return [
        // Fonts are measured on the freshly loaded chat (>= 12 px).
        { name: 'carga', options: { fontRoots: [CHAT] } },
        {
            name: 'scroll',
            prepare: async (page) => {
                await history(page).evaluate((log) => (log.scrollTop = 0));
                await page.waitForTimeout(400);
            },
            cleanup: (page) =>
                history(page).evaluate(
                    (log) => (log.scrollTop = log.scrollHeight),
                ),
        },
        {
            name: 'cita',
            prepare: async (page) => {
                await page
                    .locator('[data-layout-box="bubble"]', {
                        hasText: data.word,
                    })
                    .filter({ hasNotText: 'Nota interna' })
                    .first()
                    .getByRole('button', {
                        name: 'Responder citando este mensaje',
                    })
                    .click({ force: true });
                await page
                    .getByRole('button', { name: 'Quitar la cita' })
                    .waitFor();
            },
        },
        {
            name: 'adjunto',
            prepare: async (page) => {
                await page
                    .locator('input[type="file"]')
                    .first()
                    .setInputFiles(LONG_FILE);
                await page
                    .getByRole('button', { name: 'Quitar el archivo adjunto' })
                    .waitFor();
                const problems = [];
                await composerIntact(page, problems);

                for (const name of [
                    'Quitar la cita',
                    'Quitar el archivo adjunto',
                ]) {
                    if (
                        !(await inViewport(page.getByRole('button', { name })))
                    ) {
                        problems.push(`"${name}" out of reach`);
                    }
                }

                page.extra$ = problems;
            },
            cleanup: async (page) => {
                await page
                    .getByRole('button', { name: 'Quitar el archivo adjunto' })
                    .click();
                await page
                    .getByRole('button', { name: 'Quitar la cita' })
                    .click();
            },
        },
        {
            name: 'menu',
            prepare: async (page) => {
                await page
                    .getByRole('button', {
                        name: 'Más acciones de la conversación',
                    })
                    .click();
                await page.getByRole('menu').waitFor();
                await settleAnimations(page);
                await checkPopups(page);
            },
            cleanup: async (page) => {
                await page.keyboard.press('Escape');
                await page
                    .getByRole('menu')
                    .waitFor({ state: 'detached' })
                    .catch(() => {});
            },
        },
        {
            name: 'en-vivo',
            prepare: async (page) => {
                liveCount++;
                const text = `En vivo ${width}-${liveCount}: ${'XYZ'.repeat(30)}`;
                await phpAsync(SEED, 'live', data.wa_id, text);
                await page
                    .locator('[data-layout-box="bubble"]', { hasText: text })
                    .waitFor({ timeout: FALLBACK_MS + 10_000 });
                await page.waitForTimeout(300);
            },
        },
        {
            name: 'detalles',
            prepare: async (page) => {
                const show = page.getByRole('button', {
                    name: 'Mostrar el panel de detalles',
                });

                if ((await show.count()) > 0) {
                    await show.click();
                }

                // A column on wide screens, a Sheet (dialog) below 1280 px.
                await page
                    .locator(
                        '[role="dialog"], button[aria-label="Ocultar el panel de detalles"]',
                    )
                    .first()
                    .waitFor();
                await settleAnimations(page);
                await checkPopups(page);
            },
            cleanup: async (page) => {
                if ((await page.getByRole('dialog').count()) > 0) {
                    await page.keyboard.press('Escape');
                    await page
                        .getByRole('dialog')
                        .waitFor({ state: 'detached' })
                        .catch(() => {});
                }
            },
        },
    ];
}

async function openChat(browser, width, scheme, storageState) {
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
    await page.goto(`${BASE}/conversations?c=${data.conversation}`);
    await history(page).waitFor();
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(300);

    return page;
}

const run = async () => {
    const browser = await launchBrowser();

    try {
        const first = await openPage(browser);
        await login(first, USERS.ana);
        const storageState = await first.context().storageState();
        await first.context().close();

        await step('seed', 'chat-movil-seed.php', async () => [
            data.messages > 80 &&
                data.pdf.length === 120 &&
                data.word.length === 90 &&
                data.url.length >= 300,
            `conversation ${data.conversation}: ${data.messages} messages, PDF name ${data.pdf.length} chars, word ${data.word.length}, URL ${data.url.length}; contact "${data.contact}"; author "${data.author}"`,
        ]);

        // The detector must see the overflow the old grid caused on the
        // real PDF bubble, and nothing on the fixed one.
        await step('detector-calibration', '375px PDF bubble', async () => {
            const page = await openChat(browser, 375, 'light', storageState);

            try {
                await page
                    .locator('[data-layout-box="attachment"]', {
                        hasText: data.pdf.slice(0, 20),
                    })
                    .first()
                    .scrollIntoViewIfNeeded();
                const result = await calibrateLayout(
                    page,
                    `[role="log"] .grid { grid-template-columns: auto !important; }
                     [data-layout-box="attachment"] { min-width: max-content !important; }`,
                );

                return [
                    result.ok,
                    `clean="${result.clean}"; with the old grid: ${result.broken.slice(0, 200)}`,
                ];
            } finally {
                await page.context().close();
            }
        });

        for (const scheme of ['light', 'dark']) {
            for (const width of WIDTHS) {
                await step(
                    `chat-${width}-${scheme}`,
                    `${width}px ${scheme}`,
                    async () => {
                        const page = await openChat(
                            browser,
                            width,
                            scheme,
                            storageState,
                        );

                        try {
                            const notes = [];
                            const dark = await page.evaluate(() =>
                                document.documentElement.classList.contains(
                                    'dark',
                                ),
                            );

                            if (dark !== (scheme === 'dark')) {
                                notes.push(`dark class=${dark}`);
                            }

                            const header = page
                                .locator(CHAT)
                                .locator('[data-channel-indicator]');

                            if ((await header.count()) === 0) {
                                notes.push(
                                    'no channel indicator in the header',
                                );
                            }

                            const measured = await layoutMatrix(
                                page,
                                states(width),
                            );

                            for (const state of measured) {
                                await shot(
                                    page,
                                    `chat-movil-${width}-${scheme}-${state.name}`,
                                    { fullPage: false },
                                );

                                if (state.problems) {
                                    notes.push(
                                        `${state.name}: ${state.problems}`,
                                    );
                                }
                            }

                            notes.push(...(page.extra$ ?? []));

                            return [
                                notes.length === 0,
                                notes.join(' | ') ||
                                    `${measured.length} states without overflow (${measured.map((state) => state.name).join(', ')})`,
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

    recordPageProblems(800);
    printProblems();
    exitWithSummary();
};

run().catch((error) => {
    console.error(error);
    process.exit(1);
});
