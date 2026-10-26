/*
 * The inbox "Filtros" panel (phase 5.1). The old popover took its maximum
 * height from the viewport (75 dvh) but started ~170 px below the top, so on
 * short screens its end (order, search, «Limpiar filtros») was off-screen;
 * the whole panel scrolled as one block. Now (InboxFilterSurface) it is a
 * popover sized to the room the viewport really has, or a bottom sheet on
 * phones and very short windows, with a fixed header, its own scroll and a
 * fixed footer with «Limpiar» and «Aplicar». Checked here with the tallest
 * panel there is (superadministrator: departments, agents, connections of
 * three channels and many tags, tests/e2e/filtros-seed.php):
 *
 *   fit-*        320–1440 px × 568–900 px: the panel and its «Aplicar» are
 *                inside the viewport, the body scrolls inside to its last
 *                section, no horizontal scroll; Escape closes and returns
 *                the focus to «Filtros»
 *   keyboard-*   the sheet traps Tab / Shift+Tab, the popover is not modal;
 *                the first control takes the focus
 *   draft-*      nothing applies until «Aplicar» (URL and list unchanged),
 *                a click outside keeps the draft («Tienes cambios sin
 *                aplicar»), Escape discards it, «Aplicar» updates the URL and
 *                the list, «Limpiar» resets and applies (popover and sheet)
 *   zoom-*       200 % zoom (1280 × 800 seen at 640 × 400): the chat keeps
 *                a usable history between its header and the composer, and
 *                the panel still fits
 *
 *   E2E_PORT=8089 TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php filtros.mjs
 *
 * SIMULACIÓN: Meta is never called.
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
} from './lib.mjs';

const data = JSON.parse(php(join(HERE, 'filtros-seed.php'), 'prepare'));

const WIDTHS = [320, 360, 375, 390, 430, 768, 1024, 1280, 1440];
const HEIGHTS = [568, 667, 800, 900];

const TRIGGER = '[data-inbox-filters]:visible';
const SURFACE = '[data-inbox-filter-surface]';

async function openInbox(page, query = '') {
    await page.goto(`${BASE}/conversations${query}`);
    await page.locator(TRIGGER).first().waitFor();
    await page.waitForLoadState('networkidle');
}

async function openFilters(page) {
    await page.locator(TRIGGER).first().click();
    await page.locator(SURFACE).waitFor({ state: 'visible' });
    // Entry animations (zoom/slide) settle before measuring.
    await page.waitForTimeout(600);
}

/** Geometry of the open panel against the viewport. */
function measure(page) {
    return page.evaluate((selector) => {
        const surface = document.querySelector(selector);
        const body = surface.querySelector('[data-inbox-filter-body]');
        const apply = surface.querySelector('[data-inbox-filter-apply]');
        const clear = surface.querySelector('[data-inbox-filter-clear]');
        const rect = (element) => {
            const box = element.getBoundingClientRect();

            return {
                top: Math.round(box.top),
                bottom: Math.round(box.bottom),
                left: Math.round(box.left),
                right: Math.round(box.right),
                height: Math.round(box.height),
            };
        };
        const vw = document.documentElement.clientWidth;
        const vh = window.innerHeight;
        const inside = (box) =>
            box.top >= 0 &&
            box.left >= 0 &&
            box.right <= vw + 0.5 &&
            box.bottom <= vh + 0.5;
        const scrollable = body.scrollHeight > body.clientHeight + 1;
        body.scrollTop = body.scrollHeight;
        const last = [
            ...body.querySelectorAll('[data-inbox-filter-section]'),
        ].at(-1);
        const lastBox = last.getBoundingClientRect();
        const bodyBox = body.getBoundingClientRect();
        // Scrolled to its end, the body shows the end of its last section.
        const lastReachable =
            lastBox.bottom <= bodyBox.bottom + 1 &&
            lastBox.bottom > bodyBox.top;
        body.scrollTop = 0;

        return {
            mode: surface.dataset.inboxFilterSurface,
            vw,
            vh,
            surface: rect(surface),
            apply: rect(apply),
            surfaceInside: inside(rect(surface)),
            applyInside: inside(rect(apply)),
            clearInside: inside(rect(clear)),
            scrollable,
            lastReachable,
            sections: body.querySelectorAll('[data-inbox-filter-section]')
                .length,
            horizontalScroll: document.documentElement.scrollWidth > vw + 1,
            bodyOverflowY: getComputedStyle(body).overflowY,
        };
    }, SURFACE);
}

const focusInSurface = (page) =>
    page.evaluate(
        (selector) =>
            document
                .querySelector(selector)
                ?.contains(document.activeElement) ?? false,
        SURFACE,
    );

const focusOnTrigger = (page) =>
    page.evaluate(() =>
        document.activeElement?.hasAttribute('data-inbox-filters'),
    );

const rowNames = (page) =>
    page.locator('[data-inbox-item]').evaluateAll((rows) =>
        rows.map((row) =>
            row
                .getAttribute('aria-label')
                .replace(/^Pendiente de aceptar: /, '')
                .split(',')[0]
                .trim(),
        ),
    );

const browser = await launchBrowser();

try {
    const page = await openPage(browser, {
        context: { viewport: { width: 1280, height: 800 } },
    });
    await login(page, USERS.admin);
    await openInbox(page);

    // fit-*: every size of the matrix.
    for (const width of WIDTHS) {
        for (const height of HEIGHTS) {
            const id = `fit-${width}x${height}`;

            await step(id, `${width}×${height}`, async () => {
                await page.setViewportSize({ width, height });
                await openInbox(page);
                await openFilters(page);
                const geometry = await measure(page);
                await shot(page, `${width}x${height}-open`, {
                    fullPage: false,
                });
                await page.keyboard.press('Escape');
                await page
                    .locator(SURFACE)
                    .waitFor({ state: 'detached', timeout: 5_000 })
                    .catch(() => {});
                const closed = (await page.locator(SURFACE).count()) === 0;
                const focusBack = await focusOnTrigger(page);
                const expectedMode =
                    width < 640 || height < 500 ? 'sheet' : 'popover';

                return [
                    geometry.mode === expectedMode &&
                        geometry.surfaceInside &&
                        geometry.applyInside &&
                        geometry.clearInside &&
                        geometry.lastReachable &&
                        geometry.sections === 4 &&
                        !geometry.horizontalScroll &&
                        closed &&
                        focusBack,
                    `${geometry.mode}; panel ${geometry.surface.top}–${geometry.surface.bottom} of ${geometry.vh} (h ${geometry.surface.height}); Aplicar ${geometry.apply.top}–${geometry.apply.bottom}; inner scroll=${geometry.scrollable} last section reachable=${geometry.lastReachable}; sections=${geometry.sections}; hscroll=${geometry.horizontalScroll}; Escape closed=${closed} focus on Filtros=${focusBack}`,
                ];
            });
        }
    }

    // keyboard-sheet: modal sheet, focus trapped.
    await step('keyboard-sheet', '375×667, keyboard', async () => {
        await page.setViewportSize({ width: 375, height: 667 });
        await openInbox(page);
        await page.locator(TRIGGER).first().focus();
        await page.keyboard.press('Enter');
        await page.locator(SURFACE).waitFor();
        await page.waitForTimeout(600);
        const first = await page.evaluate(() => ({
            tag: document.activeElement?.tagName,
            type: document.activeElement?.getAttribute('type'),
        }));
        let escaped = 0;

        for (let index = 0; index < 70; index++) {
            await page.keyboard.press('Tab');
            escaped += (await focusInSurface(page)) ? 0 : 1;
        }

        for (let index = 0; index < 20; index++) {
            await page.keyboard.press('Shift+Tab');
            escaped += (await focusInSurface(page)) ? 0 : 1;
        }

        const listInert = await page.evaluate(() => {
            const row = document.querySelector('[data-inbox-item]');

            return row ? row.closest('[aria-hidden="true"]') !== null : null;
        });
        await shot(page, 'keyboard-sheet', { fullPage: false });
        await page.keyboard.press('Escape');
        await page.locator(SURFACE).waitFor({ state: 'detached' });
        const focusBack = await focusOnTrigger(page);

        return [
            first.tag === 'INPUT' && escaped === 0 && focusBack,
            `initial focus ${first.tag}[${first.type}]; focus left the sheet ${escaped}× in 90 Tab/Shift+Tab; list hidden behind the modal=${listInert}; Escape → focus on Filtros=${focusBack}`,
        ];
    });

    // keyboard-popover: not modal, Escape returns the focus.
    await step('keyboard-popover', '1280×800, keyboard', async () => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await openInbox(page);
        await page.locator(TRIGGER).first().focus();
        await page.keyboard.press('Enter');
        await page.locator(SURFACE).waitFor();
        await page.waitForTimeout(400);
        const firstInside = await focusInSurface(page);
        const tag = await page.evaluate(() => document.activeElement?.tagName);
        const listAvailable = await page.evaluate(() => {
            const row = document.querySelector('[data-inbox-item]');

            return (
                row !== null &&
                row.closest('[aria-hidden="true"], [inert]') === null &&
                getComputedStyle(document.body).pointerEvents !== 'none'
            );
        });
        await page.keyboard.press('Escape');
        await page.locator(SURFACE).waitFor({ state: 'detached' });
        const focusBack = await focusOnTrigger(page);

        return [
            firstInside && tag === 'INPUT' && listAvailable && focusBack,
            `initial focus inside=${firstInside} (${tag}); list usable behind the popover=${listAvailable}; Escape → focus on Filtros=${focusBack}`,
        ];
    });

    // draft-popover: draft, Aplicar, outside click keeps, Escape discards, Limpiar.
    await step('draft-popover', '1280×800, mouse', async () => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await openInbox(page);
        const before = (await rowNames(page)).length;
        const notes = [];
        let requests = 0;
        const count = (request) => {
            if (
                new URL(request.url()).pathname === '/conversations' &&
                request.headers()['x-inertia']
            ) {
                requests++;
            }
        };
        page.on('request', count);

        await openFilters(page);
        const panel = page.locator(SURFACE);
        await panel.getByRole('checkbox', { name: data.tags.first }).click();
        await page.locator('#inbox-sort').selectOption('oldest');
        await page.waitForTimeout(800);
        const untouched = !new URL(page.url()).search && requests === 0;
        const applyLabel = await panel
            .locator('[data-inbox-filter-apply]')
            .innerText();
        notes.push(
            `before Aplicar: url="${new URL(page.url()).search}" requests=${requests} button="${applyLabel}"`,
        );
        await shot(page, 'draft-popover', { fullPage: false });
        await panel.locator('[data-inbox-filter-apply]').click();
        await page.waitForURL(/tags/);
        await page.waitForLoadState('networkidle');
        const applied = new URL(page.url()).searchParams;
        const closedAfterApply = await page
            .locator(SURFACE)
            .waitFor({ state: 'detached', timeout: 5_000 })
            .then(() => true)
            .catch(() => false);
        const rows = (await rowNames(page)).sort((a, b) => a.localeCompare(b));
        const chips = await page
            .locator('[data-inbox-applied]')
            .allInnerTexts();
        const announced = await page
            .locator('p[role="status"]')
            .allInnerTexts();
        notes.push(
            `applied url=${applied.toString()} rows=${rows.join('/')} chips=${chips.length} closed=${closedAfterApply} announced=${JSON.stringify(announced.filter(Boolean))}`,
        );

        // A click outside (an accident) keeps the draft and says so.
        await openFilters(page);
        await page.locator('#inbox-sort').selectOption('priority');
        await page.mouse.click(700, 30);
        await page
            .locator(SURFACE)
            .waitFor({ state: 'detached', timeout: 5_000 })
            .catch(() => {});
        const pendingDot =
            (await page.locator('[data-inbox-filter-pending-dot]').count()) > 0;
        const keptUrl = new URL(page.url()).searchParams.get('sort');
        await openFilters(page);
        const keptSort = await page.locator('#inbox-sort').inputValue();
        const pendingNote =
            (await page
                .locator(SURFACE)
                .locator('[data-inbox-filter-pending]')
                .count()) > 0;
        notes.push(
            `after click outside: url sort=${keptUrl}, reopened sort=${keptSort}, pending dot=${pendingDot} note=${pendingNote}`,
        );

        // Escape discards a draft.
        await page.keyboard.press('Escape');
        await page.waitForTimeout(500);
        const discardedUrl = new URL(page.url()).searchParams.get('sort');
        await openFilters(page);
        const reopenedSort = await page.locator('#inbox-sort').inputValue();
        notes.push(
            `after Escape: url sort=${discardedUrl}, reopened sort=${reopenedSort}`,
        );

        // Limpiar resets and applies.
        await page.locator('[data-inbox-filter-clear]').click();
        await page.waitForURL((url) => url.search === '');
        await page.waitForLoadState('networkidle');
        const cleared = (await rowNames(page)).length;
        const closedAfterClear = await page
            .locator(SURFACE)
            .waitFor({ state: 'detached', timeout: 5_000 })
            .then(() => true)
            .catch(() => false);
        notes.push(
            `cleared rows=${cleared} (before ${before}) closed=${closedAfterClear}`,
        );
        page.off('request', count);

        return [
            untouched &&
                /^Aplicar \(2\)$/.test(applyLabel) &&
                [...applied.keys()].some((key) => key.startsWith('tags')) &&
                applied.get('sort') === 'oldest' &&
                closedAfterApply &&
                rows.join('/') ===
                    [...data.tagged]
                        .sort((a, b) => a.localeCompare(b))
                        .join('/') &&
                chips.length === 2 &&
                announced.some((text) =>
                    text.includes('Filtros aplicados: 2'),
                ) &&
                keptUrl === 'oldest' &&
                keptSort === 'priority' &&
                pendingDot &&
                pendingNote &&
                discardedUrl === 'oldest' &&
                reopenedSort === 'oldest' &&
                cleared === before &&
                closedAfterClear,
            notes.join('; '),
        ];
    });

    // draft-sheet: the same on a phone.
    await step('draft-sheet', '375×667, touch-size', async () => {
        await page.setViewportSize({ width: 375, height: 667 });
        await openInbox(page);
        await openFilters(page);
        const options = await page
            .locator('#inbox-channel option')
            .allInnerTexts();
        await page.locator('#inbox-channel').selectOption('web_chat');
        await page.waitForTimeout(500);
        const untouched = new URL(page.url()).search === '';
        await page.locator('[data-inbox-filter-apply]').click();
        await page.waitForURL(/channel=web_chat/);
        await page.waitForLoadState('networkidle');
        const closed = await page
            .locator(SURFACE)
            .waitFor({ state: 'detached', timeout: 5_000 })
            .then(() => true)
            .catch(() => false);
        await shot(page, 'draft-sheet-applied', { fullPage: false });
        await openFilters(page);
        await page.locator('[data-inbox-filter-clear]').click();
        await page.waitForURL((url) => url.search === '');

        return [
            options.length >= 3 && untouched && closed,
            `channels=${JSON.stringify(options)}; untouched before Aplicar=${untouched}; applied+closed=${closed}; cleared url="${new URL(page.url()).search}"`,
        ];
    });

    await page.context().close();

    // zoom-*: a 1280 × 800 window at 200 % (and 400 %, reported).
    for (const zoom of [2, 4]) {
        await step(
            `zoom-${zoom * 100}`,
            `1280×800 at ${zoom * 100} %`,
            async () => {
                const zoomed = await openPage(browser, {
                    context: {
                        viewport: { width: 1280 / zoom, height: 800 / zoom },
                        deviceScaleFactor: zoom,
                    },
                });
                await login(zoomed, USERS.ana);
                await zoomed.goto(
                    `${BASE}/conversations?c=${data.ana_conversation}`,
                );
                const log = zoomed.getByRole('log', {
                    name: /Historial de la conversación/,
                });
                await log.waitFor();
                await zoomed.waitForTimeout(800);
                const layout = await zoomed.evaluate(() => {
                    const box = (selector) =>
                        document
                            .querySelector(selector)
                            ?.getBoundingClientRect();
                    const history = document.querySelector('[role="log"]');
                    const composer = document.querySelector('#chat-composer');
                    const send = [
                        ...document.querySelectorAll('button[type="submit"]'),
                    ].find((button) =>
                        /Enviar|Guardar nota/.test(button.textContent),
                    );
                    const header = history
                        ?.closest('section')
                        ?.querySelector('header');
                    const vh = window.innerHeight;
                    const inView = (rect) =>
                        rect && rect.top >= 0 && rect.bottom <= vh + 0.5;

                    return {
                        vh,
                        history: Math.round(
                            history.getBoundingClientRect().height,
                        ),
                        header: Math.round(
                            header?.getBoundingClientRect().height ?? 0,
                        ),
                        composer: composer
                            ? Math.round(
                                  composer
                                      .closest('.border-t')
                                      .getBoundingClientRect().height,
                              )
                            : null,
                        composerInView: inView(
                            composer?.getBoundingClientRect(),
                        ),
                        sendInView: inView(send?.getBoundingClientRect()),
                        hscroll:
                            document.documentElement.scrollWidth >
                            document.documentElement.clientWidth + 1,
                        box: box('[role="log"]')?.top,
                    };
                });
                await shot(zoomed, `zoom-${zoom * 100}-chat`, {
                    fullPage: false,
                });

                let panel = null;

                if (zoom === 2) {
                    await zoomed
                        .locator(
                            '[aria-label="Volver a la lista de conversaciones"]',
                        )
                        .click()
                        .catch(() => {});
                    await openFilters(zoomed);
                    panel = await measure(zoomed);
                    await shot(zoomed, 'zoom-200-filters', { fullPage: false });
                    await zoomed.keyboard.press('Escape');
                }

                await zoomed.context().close();
                const share = layout.history / layout.vh;
                const usable =
                    zoom === 2
                        ? share >= 0.3 &&
                          layout.composerInView &&
                          layout.sendInView &&
                          !layout.hscroll &&
                          panel.surfaceInside &&
                          panel.applyInside &&
                          panel.lastReachable
                        : layout.history > 0 && !layout.hscroll;

                return [
                    usable,
                    `viewport ${layout.vh}px: chat header ${layout.header}px, history ${layout.history}px (${Math.round(share * 100)} %), composer ${layout.composer}px; composer in view=${layout.composerInView} send in view=${layout.sendInView}; hscroll=${layout.hscroll}${panel ? `; filters ${panel.mode} ${panel.surface.top}–${panel.surface.bottom} Aplicar inside=${panel.applyInside} last section reachable=${panel.lastReachable}` : ''}`,
                ];
            },
        );
    }
} finally {
    await browser.close();
}

recordPageProblems();
printProblems();
exitWithSummary();
