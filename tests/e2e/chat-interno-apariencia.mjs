/*
 * Fase 5.2.1: personal chat colors in the internal chat. Ana and Beto of
 * tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php) share
 * the group «E2E Apariencia» of tests/e2e/chat-interno-apariencia-seed.php;
 * Ana also has a direct chat with Carla.
 *
 *   a-saves-thread          Ana picks «Océano» for this chat only: the
 *                           history takes its colors at once (no reload)
 *   a-contrast-light        text on Ana's sent and received bubbles, measured
 *                           in the page, reaches 4.5:1
 *   a-persists-reload       after reloading, the colors are still there
 *   a-other-thread-theme    her direct chat with Carla keeps the theme
 *   b-does-not-see          Beto, in the same group (another session), sees
 *                           the theme, not Ana's colors
 *   a-persists-relogin      Ana signs out and in again: still her colors
 *   a-dark                  dark mode uses the dark set, readable
 *   matrix-<width>-<scheme> 360, 390, 430, 768, 1024 and 1440 px, light and
 *                           dark: colors applied, every bubble's text ≥ 4.5:1
 *                           against its bubble, no horizontal scroll, the
 *                           «Apariencia» button reachable; a screenshot each
 *                           (and the reaction/action icons beside the bubbles
 *                           ≥ 3:1 against what is behind them)
 *   unreadable-adjusted     a custom text color almost equal to its bubble is
 *                           made readable on save, and the user is told
 *   controls-on-dark-background  a black custom chat background in light
 *                           mode: the reaction/action icons still ≥ 3:1
 *   a-restore               «Restablecer predeterminados» brings back the
 *                           theme, also after reloading
 *
 *   E2E_PORT=8130 TEST_DB_LOCK_WAIT=3600 \
 *     php tests/e2e/run.php chat-interno-apariencia.mjs
 */
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
    toastText,
} from './lib.mjs';

const MATRIX_WIDTHS = [360, 390, 430, 768, 1024, 1440];
const OCEAN = { light: '#eef5fb', dark: '#0b1622' };

/** The history of the open internal chat. */
const history = (page) => page.getByRole('log').first();

/** --cm-chat-bg on the history ('' with the theme's colors). */
function chatBackground(page) {
    return history(page).evaluate((log) =>
        getComputedStyle(log)
            .getPropertyValue('--cm-chat-bg')
            .trim()
            .toLowerCase(),
    );
}

/**
 * WCAG contrast of every visible bubble's text (body and time) against the
 * bubble, computed in the page: colors are resolved to sRGB by painting
 * them on a canvas (theme tokens are OKLCH).
 */
function measureBubbles(page) {
    return page.evaluate(() => {
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = 1;
        const context = canvas.getContext('2d', { willReadFrequently: true });
        const rgb = (color) => {
            context.clearRect(0, 0, 1, 1);
            context.fillStyle = '#000';
            context.fillStyle = color;
            context.fillRect(0, 0, 1, 1);

            return [...context.getImageData(0, 0, 1, 1).data].slice(0, 3);
        };
        const luminance = (color) => {
            const [r, g, b] = rgb(color).map((channel) => {
                const value = channel / 255;

                return value <= 0.04045
                    ? value / 12.92
                    : ((value + 0.055) / 1.055) ** 2.4;
            });

            return 0.2126 * r + 0.7152 * g + 0.0722 * b;
        };
        const contrast = (a, b) => {
            const [x, y] = [luminance(a), luminance(b)];

            return (
                Math.round(
                    ((Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)) * 100,
                ) / 100
            );
        };
        const bubbles = [
            ...document.querySelectorAll(
                '[role="log"] [id^="team-message-"] div.rounded-2xl',
            ),
        ];

        const opaque = (color) => !/rgba\(.*,\s*0\)$|transparent/.test(color);
        const log = document.querySelector('[role="log"]');
        const chatBackground = getComputedStyle(log).backgroundColor;
        // Reaction and message-action buttons beside the bubbles: their icon
        // against what is really behind it (own background or the chat's).
        const controls = [
            ...log.querySelectorAll(
                'button[aria-label="Reaccionar"], button[aria-label="Acciones del mensaje"]',
            ),
        ].map((button) => {
            const own = getComputedStyle(button).backgroundColor;
            const behind = opaque(own) ? own : chatBackground;
            const icon = button.querySelector('svg');

            return contrast(getComputedStyle(icon ?? button).color, behind);
        });

        return {
            controls,
            dark: document.documentElement.classList.contains('dark'),
            scrollWidth: document.documentElement.scrollWidth,
            innerWidth: window.innerWidth,
            bubbles: bubbles.map((bubble) => {
                const background = getComputedStyle(bubble).backgroundColor;
                const body = bubble.querySelector('p.whitespace-pre-wrap');
                const time = bubble.querySelector('time')?.parentElement;

                return {
                    mine: bubble.className.includes('rounded-br-md'),
                    background,
                    body: body
                        ? contrast(getComputedStyle(body).color, background)
                        : null,
                    time: time
                        ? contrast(getComputedStyle(time).color, background)
                        : null,
                };
            }),
        };
    });
}

/**
 * Every bubble's text reaches 4.5:1. With personal colors the time line is
 * checked too (it uses the chosen text color); the theme's own bubbles are
 * judged by their body text only.
 */
function readable(report, { withTime = true, withControls = true } = {}) {
    const sent = report.bubbles.filter((bubble) => bubble.mine);
    const received = report.bubbles.filter((bubble) => !bubble.mine);
    const ratios = report.bubbles.flatMap((bubble) =>
        [bubble.body, withTime ? bubble.time : null].filter(
            (ratio) => ratio !== null,
        ),
    );
    const minimum = Math.min(...ratios);

    const controls = Math.min(...report.controls);

    return {
        ok:
            sent.length > 0 &&
            received.length > 0 &&
            minimum >= 4.5 &&
            (!withControls || (report.controls.length > 0 && controls >= 3)),
        note: `sent=${sent.length} received=${received.length} min=${minimum} controls=${report.controls.length}@${controls} sentBg=${sent[0]?.background} receivedBg=${received[0]?.background}`,
    };
}

async function openThread(page, id) {
    await page.goto(`${BASE}/team-chat/threads/${id}`);
    await history(page).waitFor();
    await page.waitForLoadState('networkidle');
}

async function openAppearance(page) {
    await page
        .getByRole('button', { name: 'Cambiar los colores de tu chat' })
        .click();
    const dialog = page.getByRole('dialog', { name: 'Apariencia del chat' });
    await dialog.waitFor();

    return dialog;
}

async function signOut(page, menu) {
    await page.getByRole('button', { name: menu }).first().click();
    await page.getByRole('menuitem', { name: 'Cerrar sesión' }).click();
    const dialog = page.getByRole('alertdialog').or(page.getByRole('dialog'));
    await dialog.getByText('¿Cerrar sesión?').waitFor();
    await dialog.getByRole('button', { name: 'Cerrar sesión' }).click();
    await page.waitForURL((url) => url.pathname.startsWith('/login'));
}

const run = async () => {
    const browser = await launchBrowser();
    let data = null;

    try {
        await step('seed', 'chat-interno-apariencia-seed.php', async () => {
            data = JSON.parse(
                php(join(HERE, 'chat-interno-apariencia-seed.php')).trim(),
            );

            return [data.group > 0 && data.direct > 0, JSON.stringify(data)];
        });

        const ana = await openPage(browser, {
            context: { colorScheme: 'light' },
            trackToasts: true,
            prefix: '[ana] ',
        });
        await login(ana, USERS.ana);
        await openThread(ana, data.group);

        await step('a-saves-thread', 'dialog + mouse', async () => {
            const before = await chatBackground(ana);
            const dialog = await openAppearance(ana);
            const scope = await dialog
                .getByRole('radio', { name: 'Solo este chat' })
                .isChecked();
            await dialog.getByRole('button', { name: /Océano/ }).click();
            await dialog
                .getByRole('button', { name: 'Guardar', exact: true })
                .click();
            const toast = await toastText(
                ana,
                'Apariencia guardada para este chat.',
            );
            await dialog.waitFor({ state: 'detached' });
            const after = await chatBackground(ana);
            await shot(ana, 'a-saved-light');

            return [
                before === '' &&
                    scope &&
                    after === OCEAN.light &&
                    Boolean(toast),
                `before="${before}" scopeThisChat=${scope} after=${after} toast=${toast}`,
            ];
        });

        await step('a-contrast-light', 'computed colors', async () => {
            const report = await measureBubbles(ana);
            const { ok, note } = readable(report);

            return [ok && !report.dark, note];
        });

        await step('a-persists-reload', 'reload', async () => {
            await ana.reload();
            await history(ana).waitFor();

            return [
                (await chatBackground(ana)) === OCEAN.light,
                await chatBackground(ana),
            ];
        });

        await step('a-other-thread-theme', 'direct chat', async () => {
            await openThread(ana, data.direct);
            const value = await chatBackground(ana);
            await openThread(ana, data.group);

            return [value === '', `direct --cm-chat-bg="${value}"`];
        });

        await step('b-does-not-see', 'second session', async () => {
            const beto = await openPage(browser, { prefix: '[beto] ' });
            await login(beto, USERS.beto);
            await openThread(beto, data.group);
            const value = await chatBackground(beto);
            const report = await measureBubbles(beto);
            const custom =
                await history(beto).getAttribute('data-custom-colors');
            await shot(beto, 'b-theme');
            await beto.context().close();

            return [
                value === '' && custom === null && report.bubbles.length > 0,
                `beto --cm-chat-bg="${value}" sentBg=${report.bubbles.find((bubble) => bubble.mine)?.background}`,
            ];
        });

        await step('a-persists-relogin', 'sign out + sign in', async () => {
            await signOut(ana, /E2E Ana/);
            await login(ana, USERS.ana);
            await openThread(ana, data.group);

            return [
                (await chatBackground(ana)) === OCEAN.light,
                await chatBackground(ana),
            ];
        });

        const storageState = await ana.context().storageState();

        await step('a-dark', 'prefers-color-scheme: dark', async () => {
            const dark = await openPage(browser, {
                context: { colorScheme: 'dark', storageState },
                prefix: '[ana-dark] ',
            });
            await openThread(dark, data.group);
            const report = await measureBubbles(dark);
            const { ok, note } = readable(report);
            const value = await history(dark).evaluate(
                (log) => getComputedStyle(log).backgroundColor,
            );
            await shot(dark, 'a-saved-dark');
            await dark.context().close();

            return [
                ok && report.dark && value === 'rgb(11, 22, 34)',
                `${note} chatBg=${value}`,
            ];
        });

        for (const scheme of ['light', 'dark']) {
            for (const width of MATRIX_WIDTHS) {
                await step(
                    `matrix-${width}-${scheme}`,
                    'viewport',
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
                        await openThread(page, data.group);
                        const button = page.getByRole('button', {
                            name: 'Cambiar los colores de tu chat',
                        });
                        const visible = await button.isVisible();
                        const box = await button.boundingBox();
                        const report = await measureBubbles(page);
                        const { ok, note } = readable(report);
                        const value = await chatBackground(page);
                        await shot(page, `matrix-${width}-${scheme}`, {
                            fullPage: false,
                        });
                        await page.context().close();
                        const inside =
                            box !== null &&
                            box.x >= 0 &&
                            box.x + box.width <= width;

                        return [
                            ok &&
                                visible &&
                                inside &&
                                value === OCEAN.light &&
                                report.dark === (scheme === 'dark') &&
                                report.scrollWidth <= report.innerWidth,
                            `${note} button=${visible && inside} scroll=${report.scrollWidth}/${report.innerWidth}`,
                        ];
                    },
                );
            }
        }

        await step(
            'unreadable-adjusted',
            'custom colors + keyboard',
            async () => {
                const dialog = await openAppearance(ana);
                await dialog
                    .getByRole('button', { name: /Personalizado/ })
                    .click();
                const sentBg = (
                    await dialog
                        .getByLabel('Fondo de tus mensajes', { exact: true })
                        .inputValue()
                ).toUpperCase();
                const text = dialog.getByLabel('Texto de tus mensajes', {
                    exact: true,
                });
                await text.fill('#CCE6FA');
                const warned = await dialog
                    .getByRole('status')
                    .getByText(/Al guardar lo ajustaremos/)
                    .first()
                    .isVisible();
                await dialog
                    .getByRole('button', { name: 'Guardar', exact: true })
                    .click();
                const toast = await toastText(
                    ana,
                    'Ajustamos el color del texto',
                );
                await dialog.waitFor({ state: 'detached' });
                const report = await measureBubbles(ana);
                const { ok, note } = readable(report);
                await shot(ana, 'unreadable-adjusted');

                return [
                    ok && warned && Boolean(toast),
                    `sentBg=${sentBg} warned=${warned} toast=${toast} ${note}`,
                ];
            },
        );

        await step(
            'controls-on-dark-background',
            'custom chat background',
            async () => {
                // A black chat background in light mode: the reaction and action
                // icons (theme tones) must still reach 3:1.
                const dialog = await openAppearance(ana);
                await dialog
                    .getByRole('button', { name: /Personalizado/ })
                    .click();
                await dialog
                    .getByLabel('Fondo del chat', { exact: true })
                    .fill('#000000');
                await dialog
                    .getByRole('button', { name: 'Guardar', exact: true })
                    .click();
                await dialog.waitFor({ state: 'detached' });
                const report = await measureBubbles(ana);
                const { ok, note } = readable(report);
                await shot(ana, 'controls-on-dark-background');

                return [ok && (await chatBackground(ana)) === '#000000', note];
            },
        );

        await step('a-restore', 'Restablecer predeterminados', async () => {
            const dialog = await openAppearance(ana);
            await dialog
                .getByRole('button', { name: 'Restablecer predeterminados' })
                .click();
            const toast = await toastText(
                ana,
                'Restablecimos los colores de este chat.',
            );
            await dialog.waitFor({ state: 'detached' });
            const now = await chatBackground(ana);
            await ana.reload();
            await history(ana).waitFor();
            const reloaded = await chatBackground(ana);
            const theme = readable(await measureBubbles(ana), {
                withTime: false,
                withControls: false,
            });
            await shot(ana, 'a-restored');

            return [
                now === '' && reloaded === '' && Boolean(toast) && theme.ok,
                `now="${now}" reloaded="${reloaded}" toast=${toast} ${theme.note}`,
            ];
        });

        recordPageProblems();
    } finally {
        await browser.close();
    }

    printProblems();
    exitWithSummary();
};

run().catch((error) => {
    console.error(error);
    process.exit(1);
});
