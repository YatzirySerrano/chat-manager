/*
 * Phase 5.1 design sweep (C-TOKENS): every main page of the platform at 320,
 * 360, 375, 390, 430, 768, 1024, 1280 and 1440 px, light and dark, and at
 * 200 % browser zoom (zoom-200-<scheme>: a 1280×900 window at 200 % is a
 * 640×450 CSS viewport with device scale factor 2), signed in as the
 * superadministrator of tests/e2e/increment-b-seed.php (already run by
 * tests/e2e/run.php), so every module is reachable.
 *
 *   responsive-<width>-<scheme>  no page scrolls horizontally (document width
 *                                within the viewport) and the theme class
 *                                matches the color scheme; a screenshot of
 *                                every page is kept in E2E_SCREENSHOTS; at
 *                                320 px no metric card (StatCard) cuts its
 *                                label or hint
 *   min-font-size                no visible text below 13 px (text-xs) on the
 *                                pages owned by the shared design system
 *   no-page-errors               no console errors or uncaught exceptions
 *
 *   E2E_REVERB=1 E2E_PORT=8088 E2E_REVERB_PORT=8138 \
 *     TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php diseno.mjs
 *
 * Read-only: the scenario only opens pages; it never submits a form.
 */
import {
    BASE,
    SHOTS,
    USERS,
    WIDTHS,
    exitWithSummary,
    launchBrowser,
    login,
    openPage,
    printProblems,
    recordPageProblems,
    record,
    shot,
    step,
} from './lib.mjs';

/** 200 % zoom of a 1280×900 window: half the CSS pixels, twice the density. */
const ZOOM_200 = { width: 640, height: 450, deviceScaleFactor: 2 };

/** [screenshot name, path] of every page in the sweep. */
const PAGES = [
    ['panel', '/dashboard'],
    ['conversaciones', '/conversations'],
    ['contactos', '/contacts'],
    ['contactos-revision', '/contacts/reviews'],
    ['etiquetas', '/tags'],
    ['respuestas-rapidas', '/quick-replies'],
    ['departamentos', '/departments'],
    ['conexiones', '/connections'],
    ['plantillas', '/templates'],
    ['reportes', '/reports'],
    ['exportaciones', '/exports'],
    ['chat-interno', '/team-chat'],
    ['usuarios', '/admin/users'],
    ['roles', '/admin/roles'],
    ['auditoria', '/admin/audit'],
    ['configuracion', '/admin/settings'],
    ['personalizacion', '/admin/appearance'],
    ['sistema', '/admin/system'],
    ['integraciones', '/admin/integrations/tokens'],
    ['elementos-personales', '/admin/personal-items'],
    ['cuenta-perfil', '/settings/profile'],
    ['cuenta-seguridad', '/settings/security'],
    ['cuenta-notificaciones', '/settings/notifications'],
];

/** Pages whose detail record is opened from the first link of their list. */
const DETAILS = [
    ['contacto', '/contacts', /\/contacts\/\d+$/],
    ['departamento', '/departments', /\/departments\/\d+$/],
];

/** Pages whose typography belongs to agents other than the design system. */
const FONT_EXEMPT = new Set(['panel', 'reportes', 'conversaciones']);

const smallText = [];

async function settle(page) {
    await page.waitForLoadState('networkidle').catch(() => {});
    await page
        .waitForFunction(() => !document.querySelector('[aria-busy="true"]'), {
            timeout: 5_000,
        })
        .catch(() => {});
    await page.waitForTimeout(150);
}

/**
 * Metric cards (StatCard) whose label or hint is cut: clamped, overflowing
 * its box or running past the card's edge. Touch users never see a
 * tooltip, so these texts must always show whole.
 */
function cutStatTexts(page) {
    return page.evaluate(() => {
        const found = [];

        for (const card of document.querySelectorAll('[data-stat-card]')) {
            const box = card.getBoundingClientRect();

            if (box.width === 0) {
                continue;
            }

            for (const text of card.querySelectorAll(
                '[data-stat-label], [data-stat-hint]',
            )) {
                const rect = text.getBoundingClientRect();
                const style = getComputedStyle(text);
                const clamped =
                    style.webkitLineClamp !== 'none' &&
                    style.webkitLineClamp !== '';

                if (
                    clamped ||
                    text.scrollWidth > text.clientWidth + 1 ||
                    text.scrollHeight > text.clientHeight + 1 ||
                    rect.right > box.right + 0.5 ||
                    rect.left < box.left - 0.5
                ) {
                    found.push(text.textContent.trim().slice(0, 40));
                }
            }
        }

        return found;
    });
}

/** Visible text nodes rendered below 12.9 px (13 px is text-xs). */
function tinyText(page) {
    return page.evaluate(() => {
        const found = [];
        const walker = document.createTreeWalker(
            document.body,
            NodeFilter.SHOW_TEXT,
        );

        while (walker.nextNode()) {
            const node = walker.currentNode;
            const text = node.textContent.trim();
            const element = node.parentElement;

            if (
                !text ||
                !element ||
                element.closest('svg, [aria-hidden="true"]')
            ) {
                continue;
            }

            const style = getComputedStyle(element);
            const rect = element.getBoundingClientRect();

            if (
                rect.width <= 1 ||
                rect.height <= 1 ||
                style.visibility === 'hidden' ||
                style.clipPath === 'inset(50%)'
            ) {
                continue;
            }

            const size = parseFloat(style.fontSize);

            if (size < 12.9) {
                found.push(`${size}px "${text.slice(0, 30)}"`);
            }
        }

        return [...new Set(found)].slice(0, 5);
    });
}

const run = async () => {
    const browser = await launchBrowser();

    try {
        const first = await openPage(browser, { prefix: '[login] ' });
        await login(first, USERS.admin);
        const state = await first.context().storageState();
        const details = [];

        for (const [name, list, pattern] of DETAILS) {
            await first.goto(`${BASE}${list}`);
            await first.waitForLoadState('networkidle');
            const hrefs = await first
                .locator('a[href]')
                .evaluateAll((links) => links.map((link) => link.href));
            const href = hrefs.find((candidate) =>
                pattern.test(new URL(candidate).pathname),
            );

            if (href) {
                details.push([name, new URL(href).pathname]);
            }
        }

        await first.context().close();
        record(
            'details-found',
            'detail pages reachable from their list',
            details.length > 0,
            `${JSON.stringify(details)}; not in the seed: ${
                DETAILS.filter(
                    ([name]) => !details.some(([found]) => found === name),
                )
                    .map(([name]) => name)
                    .join(', ') || 'none'
            }`,
        );

        const pages = [...PAGES, ...details];

        const sizes = [...WIDTHS, 'zoom-200'];

        for (const scheme of ['light', 'dark']) {
            for (const size of sizes) {
                const zoomed = size === 'zoom-200';
                const width = zoomed ? ZOOM_200.width : size;
                await step(
                    zoomed
                        ? `zoom-200-${scheme}`
                        : `responsive-${width}-${scheme}`,
                    `${zoomed ? '1280×900 at 200 % (640 CSS px)' : `${width}px`} ${scheme}, ${pages.length} pages`,
                    async () => {
                        const phone = !zoomed && width <= 430;
                        const page = await openPage(browser, {
                            context: {
                                viewport: zoomed
                                    ? {
                                          width: ZOOM_200.width,
                                          height: ZOOM_200.height,
                                      }
                                    : { width, height: phone ? 844 : 900 },
                                deviceScaleFactor: zoomed
                                    ? ZOOM_200.deviceScaleFactor
                                    : 1,
                                colorScheme: scheme,
                                hasTouch: phone,
                                isMobile: phone,
                                storageState: state,
                            },
                            prefix: `[${size}${scheme}] `,
                        });
                        const notes = [];

                        try {
                            for (const [name, path] of pages) {
                                try {
                                    await page.goto(`${BASE}${path}`);
                                    await settle(page);
                                    const report = await page.evaluate(() => ({
                                        scrollWidth:
                                            document.documentElement
                                                .scrollWidth,
                                        innerWidth: window.innerWidth,
                                        dark: document.documentElement.classList.contains(
                                            'dark',
                                        ),
                                    }));

                                    if (
                                        report.scrollWidth > report.innerWidth
                                    ) {
                                        notes.push(
                                            `${name}: scrollWidth ${report.scrollWidth} > ${report.innerWidth}`,
                                        );
                                    }

                                    if (report.dark !== (scheme === 'dark')) {
                                        notes.push(
                                            `${name}: dark=${report.dark}`,
                                        );
                                    }

                                    if (width === 320) {
                                        const cut = await cutStatTexts(page);

                                        if (cut.length > 0) {
                                            notes.push(
                                                `${name}: metric text cut ${cut.map((text) => `"${text}"`).join(', ')}`,
                                            );
                                        }
                                    }

                                    if (
                                        scheme === 'light' &&
                                        (width === 375 || width === 1280) &&
                                        !FONT_EXEMPT.has(name)
                                    ) {
                                        const tiny = await tinyText(page);

                                        if (tiny.length > 0) {
                                            smallText.push(
                                                `${width} ${name}: ${tiny.join(', ')}`,
                                            );
                                        }
                                    }

                                    await shot(
                                        page,
                                        `${zoomed ? 'zoom200' : width}-${scheme}-${name}`,
                                        { fullPage: false },
                                    );
                                } catch (error) {
                                    notes.push(
                                        `${name}: ${String(error).split('\n')[0]}`,
                                    );
                                    await shot(
                                        page,
                                        `${zoomed ? 'zoom200' : width}-${scheme}-${name}-error`,
                                        { fullPage: false },
                                    );
                                }
                            }
                        } finally {
                            await page.context().close();
                        }

                        return [notes.length === 0, notes.join('; ')];
                    },
                );
            }
        }
    } finally {
        await browser.close();
    }

    record(
        'min-font-size',
        'visible text >= 13 px',
        smallText.length === 0,
        smallText.join(' | ').slice(0, 1200),
    );

    if (SHOTS) {
        console.log(`\nscreenshots: ${SHOTS}`);
    }

    recordPageProblems(800);
    printProblems();
    exitWithSummary();
};

run().catch((error) => {
    console.error(error);
    process.exit(1);
});
