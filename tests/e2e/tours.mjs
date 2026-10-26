/*
 * Phase 5.1 guided tours (C-TOURS): the global help button, the tour overlay
 * and its step card, signed in as the superadministrator of
 * tests/e2e/increment-b-seed.php (already run by tests/e2e/run.php).
 *
 *   help-button-<width>-<scheme>  the floating help button shows the "new"
 *                                 indicator and opens the popover listing the
 *                                 tours of the screen and the full guide
 *   spotlight-<tour>-<width>      every step frames its real control (the
 *                                 spotlight matches the target's bounding
 *                                 rect + 6 px) and the card stays inside the
 *                                 viewport
 *   controls                      Siguiente / Atrás / → / ← / Escape / Terminar
 *   restart                       a finished tour starts again from step 1
 *   missing-step-skipped          a step whose control is missing is skipped
 *   navigation-closes             an Inertia visit to another screen ends it
 *   no-overlay-left               after every end: no overlay, the page is
 *                                 no longer inert and takes clicks again
 *   reduced-motion                no spotlight / card transitions
 *   fab-clear-<width>             the floating button covers no control at
 *                                 the end of the page, rises above a sticky
 *                                 save bar and gives way to the top bar
 *                                 button when a chat composer is docked
 *   guide-secondary               the full guide opens from the popover and
 *                                 lists the tours of the screen first
 *
 *   E2E_PORT=8091 E2E_REVERB=1 E2E_REVERB_PORT=8141 \
 *     TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php tours.mjs
 *
 * Read-only apart from one simulated inbound WhatsApp message (to open a
 * chat with a docked composer); it never submits a form.
 */
import {
    BASE,
    SHOTS,
    USERS,
    exitWithSummary,
    inbound,
    launchBrowser,
    login,
    openConversation,
    openPage,
    printProblems,
    record,
    recordPageProblems,
    shot,
    step,
} from './lib.mjs';

const HELP = /^Ayuda y recorridos guiados/;

async function settle(page) {
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(200);
}

function helpButton(page) {
    return page.getByRole('button', { name: HELP }).locator('visible=true');
}

async function openHelp(page) {
    await helpButton(page).first().click();
    await helpPopover(page).waitFor();
}

function helpPopover(page) {
    return page
        .locator('[data-slot="popover-content"][role="dialog"]')
        .filter({ hasText: 'Ayuda de esta pantalla' });
}

async function startTour(page, title) {
    await openHelp(page);
    await page
        .locator('[data-tour-launch]')
        .filter({ hasText: title })
        .first()
        .click();
    await page.locator('[data-tour-tooltip]').waitFor({ timeout: 5_000 });
    return waitForSpot(page);
}

/** Current step as seen by the person, once the spotlight sits still. */
function tourState(page) {
    return page.evaluate(() => {
        const tip = document.querySelector('[data-tour-tooltip]');
        const spot = document.querySelector('[data-tour-spotlight]');

        if (!tip) {
            return null;
        }

        const name = tip.getAttribute('data-tour-target');
        const target = [
            ...document.querySelectorAll(`[data-tour="${name}"]`),
        ].find((element) => {
            const rect = element.getBoundingClientRect();

            return (
                rect.width > 0 &&
                rect.height > 0 &&
                (element.checkVisibility?.() ?? true)
            );
        });
        const box = (element) => {
            if (!element) {
                return null;
            }

            const rect = element.getBoundingClientRect();

            return {
                top: rect.top,
                left: rect.left,
                width: rect.width,
                height: rect.height,
                bottom: rect.bottom,
                right: rect.right,
            };
        };

        return {
            target: name,
            step: tip.getAttribute('data-tour-step'),
            progress: tip.querySelector('p')?.textContent.trim() ?? '',
            title: tip.querySelector('h2')?.textContent.trim() ?? '',
            focusInCard: tip.contains(document.activeElement),
            tip: box(tip),
            spot: box(spot),
            targetBox: box(target),
            viewport: { width: innerWidth, height: innerHeight },
            // The page is inert (its sidebar and content), the toasts not.
            inert: Boolean(
                document
                    .querySelector('#app main, #app [data-sidebar]')
                    ?.closest('[inert]'),
            ),
            toastsReachable: !document
                .querySelector(
                    '#app section[aria-live][aria-relevant="additions text"]',
                )
                ?.closest('[inert]'),
        };
    });
}

/** The spotlight frames the target: its rect + 6 px (clipped when tall). */
function spotMatches(state) {
    if (!state?.spot || !state.targetBox) {
        return false;
    }

    const { spot, targetBox: t, viewport } = state;
    const near = (a, b) => Math.abs(a - b) <= 1.5;
    // Cut to the viewport (3 px) at the left and right edges.
    const left = Math.max(t.left - 6, 3);
    const right = Math.min(t.right + 6, viewport.width - 3);
    const horizontal = near(spot.left, left) && near(spot.width, right - left);

    if (t.height > viewport.height * 0.7) {
        return horizontal && spot.top >= 0 && spot.bottom <= viewport.height;
    }

    return (
        horizontal &&
        near(spot.top, t.top - 6) &&
        near(spot.height, t.height + 12)
    );
}

function tipInside(state) {
    const { tip, viewport } = state;

    return (
        tip.left >= 0 &&
        tip.top >= 0 &&
        tip.right <= viewport.width + 0.5 &&
        tip.bottom <= viewport.height + 0.5
    );
}

/** Waits (bounded) until the spotlight stops moving over its target. */
async function waitForSpot(page, timeout = 4_000) {
    const until = Date.now() + timeout;
    let state = await tourState(page);

    while (Date.now() < until) {
        if (spotMatches(state)) {
            await page.waitForTimeout(80);
            const again = await tourState(page);

            if (spotMatches(again)) {
                return again;
            }
        }

        await page.waitForTimeout(80);
        state = await tourState(page);
    }

    return state;
}

/** No overlay is left and the page takes pointer events again. */
async function overlayGone(page) {
    await page
        .locator('[data-tour-overlay], [data-tour-tooltip]')
        .first()
        .waitFor({ state: 'detached', timeout: 3_000 })
        .catch(() => {});

    return page.evaluate(() => {
        const app = document.getElementById('app');
        const probe = document.elementFromPoint(
            innerWidth / 2,
            innerHeight / 2,
        );

        return {
            overlay: document.querySelectorAll(
                '[data-tour-overlay], [data-tour-tooltip]',
            ).length,
            inert:
                (app?.hasAttribute('inert') ?? false) ||
                app?.querySelector('[inert]') !== null,
            probeInApp: Boolean(probe && app?.contains(probe)),
        };
    });
}

function gone(state) {
    return state.overlay === 0 && !state.inert && state.probeInApp;
}

/** Walks every step with Siguiente, checking each spotlight and card. */
async function walkTour(page, label, shotPrefix) {
    const notes = [];
    const seen = [];
    let state = await waitForSpot(page);

    for (let guard = 0; guard < 12 && state; guard += 1) {
        seen.push(state.step);

        if (!spotMatches(state)) {
            notes.push(
                `${state.step}: spot ${JSON.stringify(state.spot)} vs target ${JSON.stringify(state.targetBox)}`,
            );
        }

        if (!tipInside(state)) {
            notes.push(
                `${state.step}: card outside ${JSON.stringify(state.tip)}`,
            );
        }

        if (!state.inert) {
            notes.push(`${state.step}: page not inert`);
        }

        if (!state.toastsReachable) {
            notes.push(`${state.step}: toasts inert`);
        }

        await shot(page, `${shotPrefix}-${seen.length}-${state.step}`, {
            fullPage: false,
        });

        const last = await page
            .locator('[data-tour-next]')
            .innerText()
            .then((text) => text.includes('Terminar'));
        await page.locator('[data-tour-next]').click();

        if (last) {
            break;
        }

        await page.waitForTimeout(120);
        state = await waitForSpot(page);
    }

    const after = await overlayGone(page);

    if (!gone(after)) {
        notes.push(`after Terminar: ${JSON.stringify(after)}`);
    }

    return [
        notes.length === 0,
        `${label}: ${seen.join(' → ')}${notes.length ? `; ${notes.join('; ')}` : ''}`,
    ];
}

/** Interactive controls covered by the floating help button. */
function coveredByFab(page) {
    return page.evaluate(() => {
        const fab = document.querySelector(
            '[data-tour-layer] [data-tour="shell.help"]',
        );

        if (!fab || fab.closest('header')) {
            return { fab: null, covered: [] };
        }

        const f = fab.getBoundingClientRect();
        const covered = [];

        for (const element of document.querySelectorAll(
            '#app a[href], #app button, #app input, #app textarea, #app select, #app [role="button"]',
        )) {
            const r = element.getBoundingClientRect();

            if (
                r.width === 0 ||
                r.height === 0 ||
                !(element.checkVisibility?.() ?? true) ||
                element.closest('[data-sonner-toaster], [data-tour-layer]')
            ) {
                continue;
            }

            if (
                r.left < f.right &&
                r.right > f.left &&
                r.top < f.bottom &&
                r.bottom > f.top
            ) {
                covered.push(
                    (
                        element.getAttribute('aria-label') ||
                        element.textContent ||
                        element.tagName
                    )
                        .trim()
                        .slice(0, 30),
                );
            }
        }

        return {
            fab: { top: f.top, bottom: f.bottom, left: f.left },
            covered,
        };
    });
}

async function scrollToEnd(page) {
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(300);
}

const run = async () => {
    const browser = await launchBrowser();

    try {
        const first = await openPage(browser, { prefix: '[login] ' });
        await login(first, USERS.admin);
        const storageState = await first.context().storageState();
        await first.context().close();

        for (const scheme of ['light', 'dark']) {
            for (const width of [1280, 375]) {
                const phone = width < 768;
                const page = await openPage(browser, {
                    context: {
                        viewport: { width, height: phone ? 780 : 860 },
                        colorScheme: scheme,
                        hasTouch: phone,
                        isMobile: phone,
                        storageState,
                    },
                    prefix: `[${width}${scheme}] `,
                });
                const tag = `${width}-${scheme}`;

                try {
                    await page.goto(`${BASE}/contacts`);
                    await settle(page);

                    await step(`help-button-${tag}`, '/contacts', async () => {
                        const dot = await page
                            .locator('[data-tour-new]')
                            .locator('visible=true')
                            .count();
                        await openHelp(page);
                        const popover = helpPopover(page);
                        const titles = await popover
                            .locator('[data-tour-launch]')
                            .allInnerTexts();
                        const guide = await popover
                            .getByRole('button', {
                                name: 'Ver la guía completa del módulo',
                            })
                            .count();
                        await shot(page, `${tag}-popover`, {
                            fullPage: false,
                        });
                        await page.keyboard.press('Escape');

                        return [
                            dot === 1 &&
                                titles.some((text) =>
                                    text.includes('Conoce Contactos'),
                                ) &&
                                titles.some((text) =>
                                    text.includes('Conoce la plataforma'),
                                ) &&
                                guide === 1,
                            `dot=${dot} tours=${titles.map((text) => text.split('\n')[0]).join(' | ')} guide=${guide}`,
                        ];
                    });

                    await step(
                        `spotlight-contactos-${tag}`,
                        'Conoce Contactos, Siguiente hasta Terminar',
                        async () => {
                            await startTour(page, 'Conoce Contactos');

                            return walkTour(
                                page,
                                'contactos',
                                `${tag}-contactos`,
                            );
                        },
                    );

                    if (scheme === 'light') {
                        await step(
                            `spotlight-plataforma-${tag}`,
                            'Conoce la plataforma (menú, notificaciones, tema, ayuda)',
                            async () => {
                                await startTour(page, 'Conoce la plataforma');

                                return walkTour(
                                    page,
                                    'plataforma',
                                    `${tag}-plataforma`,
                                );
                            },
                        );
                    }

                    await step(
                        `fab-clear-${tag}`,
                        '/contacts end of page, /admin/settings save bar',
                        async () => {
                            const notes = [];
                            await scrollToEnd(page);
                            const contacts = await coveredByFab(page);

                            if (!contacts.fab) {
                                notes.push('no floating button on /contacts');
                            } else if (contacts.covered.length > 0) {
                                notes.push(
                                    `/contacts covers ${contacts.covered.join(', ')}`,
                                );
                            }

                            await page.goto(`${BASE}/admin/settings`);
                            await settle(page);
                            const bar = await page
                                .locator('[data-dock-bottom]')
                                .boundingBox();
                            await scrollToEnd(page);
                            const settings = await coveredByFab(page);
                            await shot(page, `${tag}-settings-fab`, {
                                fullPage: false,
                            });

                            if (
                                !bar ||
                                !settings.fab ||
                                settings.fab.bottom > bar.y + 0.5
                            ) {
                                notes.push(
                                    `settings fab ${JSON.stringify(settings.fab)} bar ${JSON.stringify(bar)}`,
                                );
                            }

                            if (settings.covered.length > 0) {
                                notes.push(
                                    `/admin/settings covers ${settings.covered.join(', ')}`,
                                );
                            }

                            return [
                                notes.length === 0,
                                notes.join('; ') ||
                                    `contacts fab ${JSON.stringify(contacts.fab)}, above save bar`,
                            ];
                        },
                    );

                    if (scheme === 'light') {
                        await step(
                            `spotlight-configuracion-${tag}`,
                            'Conoce Configuración (save bar step)',
                            async () => {
                                await page.evaluate(() =>
                                    window.scrollTo(0, 0),
                                );
                                await startTour(page, 'Conoce Configuración');

                                return walkTour(
                                    page,
                                    'configuracion',
                                    `${tag}-configuracion`,
                                );
                            },
                        );
                    }
                } finally {
                    await page.context().close();
                }
            }
        }

        // Controls, restart, skipped step and navigation (desktop, light).
        const page = await openPage(browser, {
            context: { viewport: { width: 1280, height: 860 }, storageState },
            prefix: '[controls] ',
        });

        try {
            await page.goto(`${BASE}/contacts`);
            await settle(page);

            await step(
                'controls',
                'Siguiente / Atrás / → / ← / Escape',
                async () => {
                    const notes = [];
                    const one = await startTour(page, 'Conoce Contactos');

                    if (!one.progress.startsWith('Paso 1 de')) {
                        notes.push(`start: ${one.progress}`);
                    }

                    if (!one.focusInCard) {
                        notes.push('focus not in the card');
                    }

                    if (
                        !(await page.locator('[data-tour-back]').isDisabled())
                    ) {
                        notes.push('Atrás enabled on step 1');
                    }

                    await page.locator('[data-tour-next]').click();
                    const two = await waitForSpot(page);
                    await page.locator('[data-tour-back]').click();
                    const back = await waitForSpot(page);
                    await page.keyboard.press('ArrowRight');
                    const arrow = await waitForSpot(page);
                    await page.keyboard.press('ArrowLeft');
                    const arrowBack = await waitForSpot(page);

                    if (!two.progress.startsWith('Paso 2')) {
                        notes.push(`Siguiente: ${two.progress}`);
                    }

                    if (back.step !== one.step) {
                        notes.push(`Atrás: ${back.step}`);
                    }

                    if (
                        arrow.step !== two.step ||
                        arrowBack.step !== one.step
                    ) {
                        notes.push(`arrows: ${arrow.step}/${arrowBack.step}`);
                    }

                    // Clicking the dimmed page does nothing (no accidental close).
                    await page.mouse.click(5, 400);
                    const still = await tourState(page);

                    if (!still) {
                        notes.push('a click outside closed the tour');
                    }

                    await page.keyboard.press('Escape');
                    const after = await overlayGone(page);
                    const focusOnHelp = await page.evaluate(
                        () =>
                            document.activeElement?.closest(
                                '[data-tour="shell.help"]',
                            ) != null,
                    );

                    if (!gone(after)) {
                        notes.push(`Escape left ${JSON.stringify(after)}`);
                    }

                    if (!focusOnHelp) {
                        notes.push('focus did not return to the help button');
                    }

                    // The page works again: the search box takes a click.
                    await page
                        .locator('[data-tour="contacts.search"] input')
                        .first()
                        .click();
                    const typed = await page.evaluate(
                        () => document.activeElement?.tagName,
                    );

                    if (typed !== 'INPUT') {
                        notes.push(`page click after close focused ${typed}`);
                    }

                    return [
                        notes.length === 0,
                        notes.join('; ') ||
                            `${one.step} → ${two.step} → back, arrows, Escape (focus back on help=${focusOnHelp})`,
                    ];
                },
            );

            await step('restart', 'start the closed tour again', async () => {
                const again = await startTour(page, 'Conoce Contactos');
                const ok = again.progress.startsWith('Paso 1 de');
                await page.keyboard.press('Escape');
                const after = await overlayGone(page);

                return [
                    ok && gone(after),
                    `${again.progress}; ${JSON.stringify(after)}`,
                ];
            });

            await step(
                'missing-step-skipped',
                'contacts.search removed before starting',
                async () => {
                    const full = await startTour(page, 'Conoce Contactos');
                    const fullTotal = Number(
                        full.progress.match(/de (\d+)/)?.[1],
                    );
                    await page.keyboard.press('Escape');
                    await overlayGone(page);
                    await page.evaluate(() =>
                        document
                            .querySelector('[data-tour="contacts.search"]')
                            ?.setAttribute('data-tour', 'removed-by-test'),
                    );
                    const reduced = await startTour(page, 'Conoce Contactos');
                    const total = Number(
                        reduced.progress.match(/de (\d+)/)?.[1],
                    );
                    const steps = [];
                    let state = reduced;

                    for (let guard = 0; guard < 10 && state; guard += 1) {
                        steps.push(state.step);
                        await page.keyboard.press('ArrowRight');
                        await page.waitForTimeout(150);
                        state = await tourState(page);

                        if (state) {
                            state = await waitForSpot(page);
                        }
                    }

                    const after = await overlayGone(page);
                    await page.evaluate(() =>
                        document
                            .querySelector('[data-tour="removed-by-test"]')
                            ?.setAttribute('data-tour', 'contacts.search'),
                    );

                    return [
                        total === fullTotal - 1 &&
                            !steps.includes('buscar') &&
                            gone(after),
                        `full=${fullTotal} without search=${total}: ${steps.join(' → ')}; ${JSON.stringify(after)}`,
                    ];
                },
            );

            await step(
                'missing-tour-unavailable',
                'every target missing → info toast, no overlay',
                async () => {
                    await page.evaluate(() => {
                        for (const element of document.querySelectorAll(
                            '[data-tour^="contacts."]',
                        )) {
                            element.setAttribute(
                                'data-tour',
                                `hidden-${element.getAttribute('data-tour')}`,
                            );
                        }
                    });
                    await openHelp(page);
                    await page
                        .locator('[data-tour-launch]')
                        .filter({ hasText: 'Conoce Contactos' })
                        .click();
                    const toast = await page
                        .locator('[data-sonner-toast]', {
                            hasText:
                                'Este recorrido no está disponible en esta vista.',
                        })
                        .first()
                        .waitFor({ timeout: 5_000 })
                        .then(() => true)
                        .catch(() => false);
                    const after = await overlayGone(page);
                    await page.reload();
                    await settle(page);

                    return [
                        toast && gone(after),
                        `toast=${toast}; ${JSON.stringify(after)}`,
                    ];
                },
            );

            await step(
                'navigation-closes',
                'Inertia visit to Etiquetas, tour, history back',
                async () => {
                    const notes = [];
                    await page
                        .locator('[data-tour="shell.nav"] a[href$="/tags"]')
                        .first()
                        .click();
                    await page.waitForURL(/\/tags$/);
                    await settle(page);
                    const tags = await startTour(page, 'Conoce Etiquetas');

                    if (!spotMatches(tags)) {
                        notes.push(`tags spot ${JSON.stringify(tags)}`);
                    }

                    await shot(page, 'navigation-tags-tour', {
                        fullPage: false,
                    });
                    await page.goBack();
                    await page.waitForURL(/\/contacts/);
                    const after = await overlayGone(page);

                    if (!gone(after)) {
                        notes.push(`after back: ${JSON.stringify(after)}`);
                    }

                    return [
                        notes.length === 0,
                        notes.join('; ') ||
                            `${tags.step} then back to /contacts: closed`,
                    ];
                },
            );

            await step(
                'guide-secondary',
                'popover → Ver la guía completa del módulo',
                async () => {
                    await openHelp(page);
                    await page
                        .getByRole('button', {
                            name: 'Ver la guía completa del módulo',
                        })
                        .click();
                    const sheet = page.getByRole('dialog').filter({
                        hasText: 'Guía completa del módulo',
                    });
                    await sheet.waitFor();
                    const list = await sheet
                        .getByText('Recorridos de esta pantalla')
                        .count();
                    await shot(page, 'guide-sheet', { fullPage: false });
                    // Starting a tour from the guide closes it first.
                    await sheet
                        .locator('[data-tour-launch]')
                        .filter({ hasText: 'Conoce Contactos' })
                        .click();
                    const state = await waitForSpot(page);
                    await page.keyboard.press('Escape');
                    const after = await overlayGone(page);

                    return [
                        list === 1 && spotMatches(state) && gone(after),
                        `tours block=${list}; started ${state?.step}`,
                    ];
                },
            );

            await step(
                'fab-clear-chat',
                'open chat: the floating button gives way to the top bar',
                async () => {
                    const receipts = inbound(
                        '5215599990001',
                        'Cliente Recorridos',
                        'Hola, ¿me ayudan?',
                    );
                    await openConversation(page, receipts[0].conversation);
                    await page.waitForTimeout(600);
                    const report = await page.evaluate(() => ({
                        composer: [
                            ...document.querySelectorAll('textarea'),
                        ].some((area) => area.offsetParent !== null),
                        floating: Boolean(
                            document.querySelector(
                                'body > [data-tour-layer] [data-tour="shell.help"], #app [data-tour-layer]:not(header *) [data-tour="shell.help"]',
                            ),
                        ),
                        inline: Boolean(
                            document.querySelector(
                                'header [data-tour="shell.help"]',
                            ),
                        ),
                    }));
                    const covered = await coveredByFab(page);
                    await shot(page, 'chat-help-button', { fullPage: false });

                    if (report.composer) {
                        return [
                            report.inline && covered.fab === null,
                            JSON.stringify(report),
                        ];
                    }

                    return [
                        covered.covered.length === 0,
                        `no composer for this account (${JSON.stringify(report)}); covered=${covered.covered.join(', ')}`,
                    ];
                },
            );

            // Modules whose controls were tagged last (inbox with an open
            // chat, roles, users, dashboard and reports).
            for (const [name, path, title] of [
                ['bandeja', null, 'Conoce la bandeja'],
                ['roles', '/admin/roles', 'Conoce Roles y permisos'],
                ['usuarios', '/admin/users', 'Conoce Usuarios'],
                ['panel', '/dashboard', 'Conoce tu panel'],
                ['reportes', '/reports', 'Conoce Reportes'],
            ]) {
                await step(`spotlight-${name}-1280`, title, async () => {
                    if (path) {
                        await page.goto(`${BASE}${path}`);
                        await settle(page);
                    }

                    await startTour(page, title);

                    return walkTour(page, name, `1280-light-${name}`);
                });
            }
        } finally {
            await page.context().close();
        }

        await step(
            'short-viewport-header',
            '1280×400 (≈400 % zoom): compact 48 px top bar, no page scroll',
            async () => {
                const short = await openPage(browser, {
                    context: {
                        viewport: { width: 1280, height: 400 },
                        storageState,
                    },
                    prefix: '[short] ',
                });
                const notes = [];

                try {
                    for (const path of ['/conversations', '/team-chat']) {
                        await short.goto(`${BASE}${path}`);
                        await settle(short);
                        const report = await short.evaluate(() => ({
                            header: Math.round(
                                document
                                    .querySelector('header.sticky')
                                    ?.getBoundingClientRect().height ?? 0,
                            ),
                            scroll:
                                document.documentElement.scrollHeight -
                                innerHeight,
                        }));
                        await shot(short, `short${path.replace('/', '-')}`, {
                            fullPage: false,
                        });

                        if (report.header !== 48 || report.scroll > 1) {
                            notes.push(`${path} ${JSON.stringify(report)}`);
                        }
                    }
                } finally {
                    await short.context().close();
                }

                return [
                    notes.length === 0,
                    notes.join('; ') || 'header 48 px, no scroll',
                ];
            },
        );

        await step(
            'reduced-motion',
            'prefers-reduced-motion: reduce',
            async () => {
                const reduced = await openPage(browser, {
                    context: {
                        viewport: { width: 1280, height: 860 },
                        reducedMotion: 'reduce',
                        storageState,
                    },
                    prefix: '[reduced] ',
                });

                try {
                    await reduced.goto(`${BASE}/tags`);
                    await settle(reduced);
                    const state = await startTour(reduced, 'Conoce Etiquetas');
                    const motion = await reduced.evaluate(() => {
                        const spot = document.querySelector(
                            '[data-tour-spotlight]',
                        );
                        const tip = document.querySelector(
                            '[data-tour-tooltip]',
                        );

                        return {
                            spot: spot
                                ? getComputedStyle(spot).transitionDuration
                                : null,
                            tip: tip
                                ? getComputedStyle(tip).transitionDuration
                                : null,
                            ping: [
                                ...document.querySelectorAll('.animate-ping'),
                            ].map(
                                (element) =>
                                    getComputedStyle(element).animationName,
                            ),
                        };
                    });
                    await reduced.keyboard.press('Escape');
                    const after = await overlayGone(reduced);
                    const instant = (value) =>
                        value === null ||
                        value
                            .split(',')
                            .every((part) => parseFloat(part) <= 0.001);

                    return [
                        spotMatches(state) &&
                            instant(motion.spot) &&
                            instant(motion.tip) &&
                            motion.ping.every((name) => name === 'none') &&
                            gone(after),
                        JSON.stringify(motion),
                    ];
                } finally {
                    await reduced.context().close();
                }
            },
        );
    } finally {
        await browser.close();
    }

    if (SHOTS) {
        console.log(`\nscreenshots: ${SHOTS}`);
    }

    recordPageProblems(800);
    printProblems();
    exitWithSummary();
};

run().catch((error) => {
    console.error(error);
    record('scenario', null, false, String(error).split('\n')[0]);
    process.exit(1);
});
