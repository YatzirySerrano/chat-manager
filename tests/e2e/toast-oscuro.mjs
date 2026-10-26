/*
 * Fase 5.2: toasts readable in light and dark, and exports without a
 * module of their own. Signed in as the superadministrator of
 * tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php), with
 * three contacts from tests/e2e/toast-oscuro-seed.php.
 *
 *   sidebar-without-exports        the navigation has no «Exportaciones»
 *   toast-<scheme>-<width>         the exact toast «Preparando PDF…» with its
 *                                  description, at 390 and 1280 px, light and
 *                                  dark: the toaster follows the theme, title
 *                                  and description reach 4.5:1 on the toast
 *                                  background, the icon and the close button
 *                                  3:1 (also on hover), the toast stays in the
 *                                  viewport; a screenshot of the toast is kept
 *   toast-close-focus-<scheme>     keyboard focus on the close button shows a
 *                                  ring
 *   queued-export-notification     a large Excel (EXPORTS_XLSX_SYNC_ROWS=0) is
 *                                  prepared in the background: the notice says
 *                                  so without naming a module, and the bell
 *                                  notification «Descargar» downloads the file
 *   recent-files-in-menu           the «Exportar» menu lists it under
 *                                  «Archivos preparados recientemente» and
 *                                  downloads it from there
 *   old-exports-address            /exports still opens («Archivos preparados»)
 *
 *   E2E_REVERB=1 E2E_PORT=8110 E2E_REVERB_PORT=8171 WHATSAPP_TRANSPORT=simulated \
 *     QUEUE_CONNECTION=sync EXPORTS_XLSX_SYNC_ROWS=0 TEST_DB_LOCK_WAIT=3600 \
 *     php tests/e2e/run.php toast-oscuro.mjs
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

const PREPARING = 'Preparando PDF…';
const DESCRIPTION =
    'Si es pequeño, la descarga empezará en unos segundos. Si es grande, te avisaremos cuando esté listo; podrás descargarlo desde la notificación.';

/**
 * WCAG contrast of the toast parts, computed in the page: every color is
 * resolved to sRGB by painting it on a canvas (the tokens are OKLCH).
 */
function measureToast(page, text) {
    return page.evaluate((text) => {
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
        const toast = [
            ...document.querySelectorAll('[data-sonner-toast]'),
        ].find((element) => element.textContent.includes(text));

        if (!toast) {
            return null;
        }

        const color = (selector, property = 'color') => {
            const element = toast.querySelector(selector);

            return element ? getComputedStyle(element)[property] : null;
        };
        const background = getComputedStyle(toast).backgroundColor;
        const close = toast.querySelector('[data-close-button]');
        const box = toast.getBoundingClientRect();

        return {
            theme: toast
                .closest('[data-sonner-toaster]')
                ?.getAttribute('data-sonner-theme'),
            dark: document.documentElement.classList.contains('dark'),
            title: toast.querySelector('[data-title]')?.textContent.trim(),
            description: toast
                .querySelector('[data-description]')
                ?.textContent.trim(),
            background,
            titleContrast: contrast(color('[data-title]'), background),
            descriptionContrast: contrast(
                color('[data-description]'),
                background,
            ),
            iconContrast: contrast(color('[data-icon]'), background),
            closeContrast: close
                ? contrast(
                      getComputedStyle(close).color,
                      getComputedStyle(close).backgroundColor,
                  )
                : null,
            closeBackground: close
                ? getComputedStyle(close).backgroundColor
                : null,
            inViewport: box.left >= 0 && box.right <= window.innerWidth + 0.5,
        };
    }, text);
}

/** Opens the «Exportar» menu of the contacts list. */
async function openExportMenu(page) {
    await page
        .getByRole('button', { name: 'Exportar', exact: true })
        .first()
        .click();

    return page.getByRole('menu');
}

const run = async () => {
    console.log(php(join(HERE, 'toast-oscuro-seed.php')).trim());
    const browser = await launchBrowser();

    try {
        const first = await openPage(browser, { prefix: '[login] ' });
        await login(first, USERS.admin);
        const state = await first.context().storageState();

        await step('sidebar-without-exports', '1280px light', async () => {
            await first.goto(`${BASE}/dashboard`);
            await first.waitForLoadState('networkidle');
            const sidebar = first.locator('[data-sidebar="sidebar"]').first();
            const named = await sidebar
                .getByRole('link', { name: /Exportaciones/ })
                .count();
            const linked = await sidebar.locator('a[href$="/exports"]').count();
            const reports = await sidebar
                .getByRole('link', { name: /Reportes/ })
                .count();

            return [
                named === 0 && linked === 0 && reports > 0,
                `links named Exportaciones=${named}, to /exports=${linked}; Reportes still listed=${reports}`,
            ];
        });
        await first.context().close();

        for (const scheme of ['light', 'dark']) {
            for (const width of [390, 1280]) {
                await step(
                    `toast-${scheme}-${width}`,
                    `${width}px ${scheme}, Exportar → PDF on /contacts`,
                    async () => {
                        const phone = width <= 430;
                        const page = await openPage(browser, {
                            context: {
                                acceptDownloads: true,
                                viewport: { width, height: phone ? 844 : 900 },
                                colorScheme: scheme,
                                hasTouch: phone,
                                isMobile: phone,
                                storageState: state,
                            },
                            prefix: `[${width}${scheme}] `,
                        });

                        try {
                            await page.goto(`${BASE}/contacts`);
                            await page.waitForLoadState('networkidle');
                            const menu = await openExportMenu(page);
                            const download = page.waitForEvent('download', {
                                timeout: 60_000,
                            });
                            await menu
                                .getByRole('menuitem', { name: /^PDF/ })
                                .first()
                                .click();
                            const toast = page
                                .locator('[data-sonner-toast]', {
                                    hasText: PREPARING,
                                })
                                .first();
                            await toast.waitFor();
                            // Hovering pauses the timer while it is measured.
                            await toast.hover();
                            const measured = await measureToast(
                                page,
                                PREPARING,
                            );
                            await toast
                                .locator('[data-close-button]')
                                .hover({ force: true });
                            await page.waitForTimeout(250);
                            const hovered = await measureToast(page, PREPARING);
                            await shot(page, `toast-${scheme}-${width}`, {
                                fullPage: false,
                            });
                            await toast
                                .screenshot({
                                    path: process.env.E2E_SCREENSHOTS
                                        ? `${process.env.E2E_SCREENSHOTS}/toast-${scheme}-${width}-detalle.png`
                                        : undefined,
                                })
                                .catch(() => {});
                            const file = await download;

                            const ok =
                                measured !== null &&
                                measured.theme === scheme &&
                                measured.dark === (scheme === 'dark') &&
                                measured.title === PREPARING &&
                                measured.description === DESCRIPTION &&
                                measured.titleContrast >= 4.5 &&
                                measured.descriptionContrast >= 4.5 &&
                                measured.iconContrast >= 3 &&
                                measured.closeContrast >= 3 &&
                                hovered.closeContrast >= 3 &&
                                // Touch screens have no hover.
                                (phone ||
                                    hovered.closeBackground !==
                                        measured.closeBackground) &&
                                measured.inViewport &&
                                file.suggestedFilename().endsWith('.pdf');

                            return [
                                ok,
                                `theme=${measured?.theme} title ${measured?.titleContrast}:1, description ${measured?.descriptionContrast}:1, icon ${measured?.iconContrast}:1, close ${measured?.closeContrast}:1 (hover ${hovered?.closeContrast}:1, background ${measured?.closeBackground} → ${hovered?.closeBackground}), in viewport=${measured?.inViewport}; file ${file.suggestedFilename()}`,
                            ];
                        } finally {
                            await page.context().close();
                        }
                    },
                );
            }

            await step(
                `toast-close-focus-${scheme}`,
                `1280px ${scheme}, keyboard`,
                async () => {
                    const page = await openPage(browser, {
                        context: {
                            acceptDownloads: true,
                            colorScheme: scheme,
                            storageState: state,
                        },
                        prefix: `[focus${scheme}] `,
                    });

                    try {
                        await page.goto(`${BASE}/contacts`);
                        await page.waitForLoadState('networkidle');
                        const menu = await openExportMenu(page);
                        await menu
                            .getByRole('menuitem', { name: /^PDF/ })
                            .first()
                            .click();
                        const toast = page
                            .locator('[data-sonner-toast]', {
                                hasText: PREPARING,
                            })
                            .first();
                        await toast.waitFor();
                        // Keyboard modality (a key press) and then focus on
                        // the close button: what :focus-visible shows to a
                        // keyboard user tabbing into the toast.
                        await toast.hover();
                        await page.keyboard.press('Shift');
                        await toast.locator('[data-close-button]').focus();
                        const ring = await page.evaluate(() => {
                            const element = document.activeElement;

                            return element?.matches(
                                '[data-close-button]:focus-visible',
                            )
                                ? getComputedStyle(element).boxShadow
                                : null;
                        });

                        await toast
                            .screenshot({
                                path: process.env.E2E_SCREENSHOTS
                                    ? `${process.env.E2E_SCREENSHOTS}/toast-${scheme}-foco.png`
                                    : undefined,
                            })
                            .catch(() => {});

                        return [
                            ring !== null && ring !== 'none',
                            `close button :focus-visible=${ring !== null}; box-shadow ${ring}`,
                        ];
                    } finally {
                        await page.context().close();
                    }
                },
            );
        }

        const page = await openPage(browser, {
            context: {
                acceptDownloads: true,
                colorScheme: 'dark',
                viewport: { width: 1280, height: 900 },
                storageState: state,
            },
            prefix: '[queued] ',
            trackToasts: true,
        });

        await step(
            'queued-export-notification',
            'Exportar → Excel with EXPORTS_XLSX_SYNC_ROWS=0, then the bell',
            async () => {
                await page.goto(`${BASE}/contacts`);
                await page.waitForLoadState('networkidle');
                const menu = await openExportMenu(page);
                await menu
                    .getByRole('menuitem', { name: /^Excel/ })
                    .first()
                    .click();
                const notice = await toastText(
                    page,
                    'Estamos preparando el archivo',
                    20_000,
                );
                await shot(page, 'queued-notice', { fullPage: false });
                await page
                    .getByRole('button', { name: /^Notificaciones/ })
                    .first()
                    .click();
                const item = page
                    .getByRole('button', { name: /Tu exportación está lista/ })
                    .first();
                await item.waitFor({ timeout: 20_000 });
                const itemText = (await item.innerText()).replace(/\s+/g, ' ');
                await shot(page, 'notification-download', { fullPage: false });
                const [file] = await Promise.all([
                    page.waitForEvent('download', { timeout: 30_000 }),
                    item.click(),
                ]);

                return [
                    notice.includes('Te avisaremos cuando esté listo') &&
                        !notice.includes('Exportaciones') &&
                        itemText.includes('Descargar') &&
                        file.suggestedFilename().endsWith('.xlsx'),
                    `notice "${notice}"; notification "${itemText}"; file ${file.suggestedFilename()}`,
                ];
            },
        );

        await step(
            'recent-files-in-menu',
            'Exportar menu → Archivos preparados recientemente',
            async () => {
                await page.goto(`${BASE}/contacts`);
                await page.waitForLoadState('networkidle');
                const menu = await openExportMenu(page);
                const section = menu.locator('[data-recent-exports]');
                await section.waitFor({ timeout: 10_000 });
                const heading = await section
                    .getByText('Archivos preparados recientemente')
                    .count();
                const all = await section
                    .getByRole('menuitem', {
                        name: 'Ver todos tus archivos preparados',
                    })
                    .count();
                await shot(page, 'recent-files-menu', { fullPage: false });
                const [file] = await Promise.all([
                    page.waitForEvent('download', { timeout: 30_000 }),
                    section
                        .getByRole('menuitem', { name: /Descargar Excel/ })
                        .first()
                        .click(),
                ]);

                return [
                    heading === 1 &&
                        all === 1 &&
                        file.suggestedFilename().endsWith('.xlsx'),
                    `heading=${heading} «Ver todos»=${all}; file ${file.suggestedFilename()}`,
                ];
            },
        );

        await step('old-exports-address', 'GET /exports', async () => {
            const response = await page.goto(`${BASE}/exports`);
            await page.waitForLoadState('networkidle');
            const heading = await page
                .getByRole('heading', { name: 'Archivos preparados' })
                .count();
            const downloads = await page
                .getByRole('link', { name: /Descargar/ })
                .count();
            await shot(page, 'archivos-preparados');

            return [
                response?.status() === 200 && heading >= 1 && downloads >= 1,
                `status ${response?.status()}, heading=${heading}, download links=${downloads}`,
            ];
        });

        await page.context().close();
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
