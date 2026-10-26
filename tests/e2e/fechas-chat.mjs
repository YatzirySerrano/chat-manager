/*
 * Day separators of the chat history (phase 5.1): a conversation with
 * messages on five calendar days of the platform time zone, more than one
 * page of history and a day change at local midnight
 * (tests/e2e/fechas-chat-seed.php) must show exactly one separator per day,
 * labelled «Hoy», «Ayer» or the full date («Jueves, 22 de octubre de 2026»),
 * and no two separators may ever overlap, wherever the history is scrolled
 * (the old sticky separators all stuck at the top of the history and piled
 * up). Checked at 320, 390, 768 and 1280 px, light and dark, at 200 % and
 * 400 % zoom (a 1280 px window seen at 640 and 320 CSS px), after loading
 * the older pages, after a message arrives live and after midnight passes
 * with the chat open (the browser clock is moved forward one day). At 400 %
 * zoom the history must also stay usable: a real height, «Ir al final»
 * inside it (never over the header actions) and the composer reachable.
 *
 *   E2E_REVERB=1 E2E_PORT=8087 E2E_REVERB_PORT=8137 WHATSAPP_TRANSPORT=simulated \
 *     TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php fechas-chat.mjs
 *
 * Without E2E_REVERB the live message arrives through the 20 s polling
 * fallback (the scenario waits for it). SIMULACIÓN: Meta is never called.
 */
import { join } from 'node:path';
import {
    BASE,
    FALLBACK_MS,
    HERE,
    USERS,
    exitWithSummary,
    launchBrowser,
    login,
    openPage,
    php,
    phpAsync,
    printProblems,
    recordPageProblems,
    shot,
    step,
} from './lib.mjs';

const SEED = join(HERE, 'fechas-chat-seed.php');
const data = JSON.parse(php(SEED, 'prepare'));
const DAYS = Object.keys(data.days).sort();

/** The calendar day of an instant in the platform time zone. */
function dayOf(date) {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: data.timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(date);
    const part = (type) => parts.find((item) => item.type === type).value;

    return `${part('year')}-${part('month')}-${part('day')}`;
}

function shiftDay(day, delta) {
    const date = new Date(`${day}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + delta);

    return date.toISOString().slice(0, 10);
}

/** The label the separator of a day must show, relative to today. */
function expectedLabel(day, today) {
    if (day === today) {
        return 'Hoy';
    }

    if (day === shiftDay(today, -1)) {
        return 'Ayer';
    }

    const text = new Intl.DateTimeFormat('es-MX', {
        timeZone: 'UTC',
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(new Date(`${day}T12:00:00Z`));

    return text.charAt(0).toUpperCase() + text.slice(1);
}

const history = (page) =>
    page.getByRole('log', { name: /Historial de la conversación/ });

/**
 * Separators, sections and overlaps as the reader sees them: every pair of
 * separator pills that intersect while visible inside the history, and any
 * pill cut by the edges of the history.
 */
const inspect = (page) =>
    history(page).evaluate((log) => {
        const box = log.getBoundingClientRect();
        const pills = [...log.querySelectorAll('[data-day-separator]')].map(
            (separator) => {
                const pill = separator.querySelector('span') ?? separator;
                const rect = pill.getBoundingClientRect();

                return {
                    // A level-3 heading, so screen readers list the days.
                    label:
                        separator.tagName === 'H3'
                            ? separator.textContent.trim()
                            : null,
                    text: pill.textContent.trim(),
                    rect,
                    visible: rect.bottom > box.top && rect.top < box.bottom,
                    cut:
                        rect.left < box.left - 0.5 ||
                        rect.right > box.right + 0.5,
                };
            },
        );
        const overlaps = [];

        for (let a = 0; a < pills.length; a++) {
            for (let b = a + 1; b < pills.length; b++) {
                const one = pills[a].rect;
                const two = pills[b].rect;
                const width =
                    Math.min(one.right, two.right) -
                    Math.max(one.left, two.left);
                const height =
                    Math.min(one.bottom, two.bottom) -
                    Math.max(one.top, two.top);

                if (
                    pills[a].visible &&
                    pills[b].visible &&
                    width > 0.5 &&
                    height > 0.5
                ) {
                    overlaps.push(`${pills[a].label} × ${pills[b].label}`);
                }
            }
        }

        return {
            labels: pills.map((pill) => pill.label),
            mismatched: pills
                .filter((pill) => pill.label !== pill.text)
                .map((pill) => pill.text),
            cut: pills
                .filter((pill) => pill.visible && pill.cut)
                .map((pill) => pill.label),
            days: [...log.querySelectorAll('section[data-day]')].map(
                (section) => section.dataset.day,
            ),
            overlaps,
            horizontal:
                document.documentElement.scrollWidth >
                document.documentElement.clientWidth + 1,
        };
    });

/** Loads every older page by scrolling to the top until the start shows. */
async function loadAll(page) {
    for (let attempt = 0; attempt < 20; attempt++) {
        if ((await page.getByText('Inicio de la conversación').count()) > 0) {
            return true;
        }

        await history(page).evaluate((log) => (log.scrollTop = 0));
        await page.waitForTimeout(700);
    }

    return false;
}

/** Overlaps found while scrolling the whole history in small steps. */
async function scrollSweep(page) {
    const found = new Set();
    const cut = new Set();
    const steps = await history(page).evaluate((log) =>
        Math.ceil(log.scrollHeight / Math.max(40, log.clientHeight / 4)),
    );

    for (let index = 0; index <= steps; index++) {
        await history(page).evaluate((log, i) => {
            log.scrollTop = i * Math.max(40, log.clientHeight / 4);
        }, index);
        await page.waitForTimeout(30);
        const report = await inspect(page);
        report.overlaps.forEach((overlap) => found.add(overlap));
        report.cut.forEach((label) => cut.add(label));
    }

    return { overlaps: [...found], cut: [...cut], steps };
}

/** Scrolls so a day's section starts just under the top: the boundary. */
const showBoundary = (page, day) =>
    history(page).evaluate((log, target) => {
        const section = log.querySelector(`section[data-day="${target}"]`);

        if (section) {
            log.scrollTop +=
                section.getBoundingClientRect().top -
                log.getBoundingClientRect().top -
                12;
        }
    }, day);

/** The day section that holds a message. */
const sectionOf = (page, text) =>
    history(page)
        .locator('section[data-day]', { hasText: text })
        .first()
        .getAttribute('data-day');

function checkLabels(report, today, notes, expectedDays = DAYS) {
    const expected = expectedDays.map((day) => expectedLabel(day, today));

    if (JSON.stringify(report.days) !== JSON.stringify(expectedDays)) {
        notes.push(`days ${report.days.join(',')} ≠ ${expectedDays.join(',')}`);
    }

    if (JSON.stringify(report.labels) !== JSON.stringify(expected)) {
        notes.push(
            `labels ${report.labels.join(' / ')} ≠ ${expected.join(' / ')}`,
        );
    }

    if (new Set(report.labels).size !== report.labels.length) {
        notes.push('duplicate separators');
    }

    if (report.mismatched.length > 0) {
        notes.push(`label/text mismatch: ${report.mismatched.join(', ')}`);
    }
}

async function openChat(browser, storageState, viewport, scheme, options = {}) {
    const phone = viewport.width <= 430 && !options.zoom;
    const page = await openPage(browser, {
        context: {
            viewport,
            deviceScaleFactor: options.zoom ?? 1,
            colorScheme: scheme,
            hasTouch: phone,
            isMobile: phone,
            storageState,
        },
        prefix: `[${viewport.width}${scheme}] `,
    });

    if (options.clock) {
        await page.clock.install({ time: new Date() });
    }

    await page.goto(`${BASE}/conversations?c=${data.conversation}`);
    await history(page).waitFor();
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(300);

    return page;
}

const MATRIX = [
    { name: '320', viewport: { width: 320, height: 640 } },
    { name: '390', viewport: { width: 390, height: 844 } },
    { name: '768', viewport: { width: 768, height: 1024 } },
    { name: '1280', viewport: { width: 1280, height: 800 } },
    // A 1280 × 800 window at 200 % and 400 % zoom: 640 and 320 CSS px.
    { name: '1280-zoom200', viewport: { width: 640, height: 400 }, zoom: 2 },
    { name: '1280-zoom400', viewport: { width: 320, height: 200 }, zoom: 4 },
];

const run = async () => {
    const browser = await launchBrowser();
    const today = dayOf(new Date());

    try {
        const first = await openPage(browser);
        await login(first, USERS.ana);
        const storageState = await first.context().storageState();
        await first.context().close();

        await step('seed', 'fechas-chat-seed.php', async () => [
            DAYS.length === 5 && DAYS.at(-1) === today && data.messages > 40,
            `conversation ${data.conversation}: ${data.messages} messages on ${DAYS.join(', ')} (${data.timezone}); today ${today}`,
        ]);

        /*
         * The detector must see the old defect: with the day sections
         * flattened (display: contents), every sticky separator belongs to
         * the whole history again, as before the fix, and they pile up.
         */
        await step(
            'detector-calibration',
            '1280 light, old layout',
            async () => {
                const page = await openChat(
                    browser,
                    storageState,
                    { width: 1280, height: 800 },
                    'light',
                );

                try {
                    await loadAll(page);
                    await page.addStyleTag({
                        content: 'section[data-day] { display: contents; }',
                    });
                    const broken = await scrollSweep(page);
                    await showBoundary(page, DAYS[3]);
                    await history(page).evaluate(
                        (log) => (log.scrollTop += 400),
                    );
                    await page.waitForTimeout(80);
                    await shot(page, 'fechas-calibracion-defecto-anterior', {
                        fullPage: false,
                    });

                    return [
                        broken.overlaps.length > 0,
                        `old layout overlaps: ${broken.overlaps.slice(0, 6).join(', ')}`,
                    ];
                } finally {
                    await page.context().close();
                }
            },
        );

        for (const scheme of ['light', 'dark']) {
            for (const variant of MATRIX) {
                if (variant.zoom && scheme === 'dark') {
                    continue;
                }

                const id = `${variant.name}-${scheme}`;

                await step(`separators-${id}`, id, async () => {
                    const page = await openChat(
                        browser,
                        storageState,
                        variant.viewport,
                        scheme,
                        { zoom: variant.zoom },
                    );
                    const notes = [];

                    try {
                        // First page: the newest days only, one separator each.
                        const firstPage = await inspect(page);
                        await shot(page, `fechas-${id}-carga`, {
                            fullPage: false,
                        });
                        checkLabels(
                            firstPage,
                            today,
                            notes,
                            DAYS.slice(DAYS.length - firstPage.days.length),
                        );

                        if (!(await loadAll(page))) {
                            notes.push('older pages never finished loading');
                        }

                        const all = await inspect(page);
                        checkLabels(all, today, notes);
                        await shot(page, `fechas-${id}-inicio`, {
                            fullPage: false,
                        });

                        for (const [text, day] of [
                            [data.edge.shifted, DAYS[2]],
                            [data.edge.late, DAYS[2]],
                            [data.edge.early, DAYS[3]],
                        ]) {
                            const found = await sectionOf(page, text);

                            if (found !== day) {
                                notes.push(
                                    `"${text}" in ${found}, expected ${day}`,
                                );
                            }
                        }

                        const sweep = await scrollSweep(page);

                        if (sweep.overlaps.length > 0) {
                            notes.push(
                                `overlaps: ${sweep.overlaps.join(', ')}`,
                            );
                        }

                        if (sweep.cut.length > 0) {
                            notes.push(`cut pills: ${sweep.cut.join(', ')}`);
                        }

                        for (const day of [DAYS[2], DAYS[3], DAYS[4]]) {
                            await showBoundary(page, day);
                            await page.waitForTimeout(80);
                            const boundary = await inspect(page);

                            if (boundary.overlaps.length > 0) {
                                notes.push(
                                    `overlap at ${day}: ${boundary.overlaps.join(', ')}`,
                                );
                            }

                            await shot(page, `fechas-${id}-limite-${day}`, {
                                fullPage: false,
                            });
                        }

                        if (all.horizontal) {
                            notes.push('horizontal page scroll');
                        }

                        return [
                            notes.length === 0,
                            notes.join(' | ') ||
                                `${all.labels.join(' / ')}; first page ${firstPage.labels.length} separators; ${sweep.steps} scroll positions without overlaps`,
                        ];
                    } finally {
                        await page.context().close();
                    }
                });
            }
        }

        /*
         * WCAG 1.4.10 at 400 % zoom (1280 × 800 seen as 320 × 200 CSS px):
         * the history keeps a usable height (the pane scrolls as a whole),
         * «Ir al final» stays inside the history instead of covering the
         * header actions, and the composer can still be reached.
         */
        await step('zoom400-usable', '1280 × 800 at 400 % zoom', async () => {
            const page = await openChat(
                browser,
                storageState,
                { width: 320, height: 200 },
                'light',
                { zoom: 4 },
            );
            const notes = [];

            try {
                const opened = await history(page).evaluate(
                    (log) => log.clientHeight,
                );

                // About half of the 200 px screen, under the top bar.
                if (opened < 96) {
                    notes.push(`history ${opened}px tall`);
                }

                // Away from the end, so «Ir al final» shows.
                await history(page).evaluate(
                    (log) => (log.scrollTop = log.scrollHeight / 2),
                );
                await page.waitForTimeout(300);
                const jump = page
                    .locator('[data-chat-pane]')
                    .getByRole('button', { name: /Ir al final|nuevos?$/ });
                const layout = await page
                    .locator('[data-chat-pane]')
                    .evaluate((pane) => {
                        const rect = (element) => {
                            const box = element.getBoundingClientRect();

                            return {
                                top: box.top,
                                bottom: box.bottom,
                                left: box.left,
                                right: box.right,
                            };
                        };
                        const log = pane.querySelector('[role="log"]');
                        const jumpButton = [
                            ...pane.querySelectorAll('button'),
                        ].find((button) =>
                            /Ir al final|nuevos?$/.test(
                                button.textContent.trim(),
                            ),
                        );

                        return {
                            log: rect(log),
                            jump: jumpButton ? rect(jumpButton) : null,
                            actions: [
                                ...pane.querySelectorAll('header button'),
                            ].map((button) => ({
                                name:
                                    button.getAttribute('aria-label') ??
                                    button.textContent.trim(),
                                ...rect(button),
                            })),
                        };
                    });
                const intersects = (one, two) =>
                    Math.min(one.right, two.right) -
                        Math.max(one.left, two.left) >
                        0.5 &&
                    Math.min(one.bottom, two.bottom) -
                        Math.max(one.top, two.top) >
                        0.5;

                if (!layout.jump) {
                    notes.push('«Ir al final» not shown away from the end');
                } else {
                    const covered = layout.actions.filter((action) =>
                        intersects(action, layout.jump),
                    );

                    if (covered.length > 0) {
                        notes.push(
                            `«Ir al final» covers ${covered.map((action) => action.name).join(', ')}`,
                        );
                    }

                    if (
                        layout.jump.top < layout.log.top - 0.5 ||
                        layout.jump.bottom > layout.log.bottom + 0.5
                    ) {
                        notes.push('«Ir al final» outside the history');
                    }
                }

                await shot(page, 'fechas-zoom400-ir-al-final', {
                    fullPage: false,
                });
                await jump.click();
                await page.waitForTimeout(600);

                const composer = page.locator('[data-chat-pane] textarea');
                await composer.focus();
                await page.waitForTimeout(200);
                const reach = await composer.evaluate((field) => {
                    const box = field.getBoundingClientRect();

                    return {
                        inside:
                            box.top >= -0.5 &&
                            box.bottom <= window.innerHeight + 0.5,
                        focused: document.activeElement === field,
                        horizontal:
                            document.documentElement.scrollWidth >
                            document.documentElement.clientWidth + 1,
                    };
                });

                if (!reach.focused || !reach.inside) {
                    notes.push(
                        `composer ${reach.focused ? 'focused' : 'not focused'} ${reach.inside ? 'visible' : 'out of view'}`,
                    );
                }

                if (reach.horizontal) {
                    notes.push('horizontal page scroll');
                }

                await shot(page, 'fechas-zoom400-composer', {
                    fullPage: false,
                });

                return [
                    notes.length === 0,
                    notes.join(' | ') ||
                        `history ${opened}px; «Ir al final» inside the history, clear of ${layout.actions.length} header buttons; composer reachable`,
                ];
            } finally {
                await page.context().close();
            }
        });

        await step('live-message', '1280 light, realtime', async () => {
            const page = await openChat(
                browser,
                storageState,
                { width: 1280, height: 800 },
                'light',
            );
            const notes = [];

            try {
                const text = `Mensaje en vivo de fechas ${Date.now()}`;
                await phpAsync(SEED, 'live', data.wa_id, text);
                await history(page)
                    .getByText(text)
                    .waitFor({ timeout: FALLBACK_MS + 10_000 });
                await page.waitForTimeout(300);
                const report = await inspect(page);
                const firstDays = report.days;
                checkLabels(report, today, notes, firstDays);

                if ((await sectionOf(page, text)) !== today) {
                    notes.push('live message outside today');
                }

                if (
                    report.labels.filter((label) => label === 'Hoy').length !==
                    1
                ) {
                    notes.push(
                        `"Hoy" ×${report.labels.filter((label) => label === 'Hoy').length}`,
                    );
                }

                await shot(page, 'fechas-en-vivo', { fullPage: false });

                return [
                    notes.length === 0,
                    notes.join(' | ') || report.labels.join(' / '),
                ];
            } finally {
                await page.context().close();
            }
        });

        await step('midnight', '390 light, clock +1 day', async () => {
            const page = await openChat(
                browser,
                storageState,
                { width: 390, height: 844 },
                'light',
                { clock: true },
            );
            const notes = [];

            try {
                await loadAll(page);
                const before = await inspect(page);
                checkLabels(before, today, notes);
                await shot(page, 'fechas-medianoche-antes', {
                    fullPage: false,
                });

                await page.clock.fastForward('24:00:00');
                await page.waitForTimeout(500);
                const tomorrow = shiftDay(today, 1);
                const after = await inspect(page);
                checkLabels(after, tomorrow, notes);
                await showBoundary(page, DAYS[4]);
                await page.waitForTimeout(80);
                await shot(page, 'fechas-medianoche-despues', {
                    fullPage: false,
                });

                return [
                    notes.length === 0,
                    notes.join(' | ') ||
                        `${before.labels.join(' / ')} → ${after.labels.join(' / ')}`,
                ];
            } finally {
                await page.context().close();
            }
        });
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
