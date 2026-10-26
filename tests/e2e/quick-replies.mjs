/*
 * Browser scenarios of the quick replies screen (Fase 5.1): switching
 * between «Corporativas», «Mis respuestas» and «De otros usuarios» is
 * smooth (no full reload, scroll kept, the tab bar stays in view when the
 * list gets shorter, only opacity/transform animate, nothing animates with
 * prefers-reduced-motion) and the export menu offers the general report of
 * every authorized reply besides the current view.
 *
 *   php tests/e2e/run.php quick-replies.mjs
 *
 * Data: tests/e2e/quick-replies-seed.php (run by this scenario).
 */
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
    BASE,
    HERE,
    PHP_BINARY,
    ROOT,
    SHOTS,
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

const tab = (page, name) =>
    page
        .getByRole('group', { name: 'Tipo de respuestas' })
        .getByRole('button', { name: new RegExp(`^${name}`) });

/** Shortcuts listed on the page (table view). */
const shortcuts = (page) =>
    page
        .locator('table tbody tr td:first-child span.font-mono')
        .allInnerTexts();

/**
 * Switches tab and reports how long the list took and which properties
 * the transitions of the list touched.
 */
async function switchTo(page, name) {
    await page.evaluate(() => {
        window.__transitions = new Set();
        window.__onTransition = (event) =>
            window.__transitions.add(event.propertyName);
        document.addEventListener('transitionrun', window.__onTransition);
    });
    const started = Date.now();
    await tab(page, name).click();
    await page.waitForFunction(
        (name) =>
            [...document.querySelectorAll('[aria-pressed="true"]')].some(
                (button) => button.textContent.trim().startsWith(name),
            ) && !document.querySelector('[aria-busy="true"]'),
        name,
    );
    const elapsed = Date.now() - started;
    await page.waitForTimeout(300);
    const properties = await page.evaluate(() => {
        document.removeEventListener('transitionrun', window.__onTransition);

        return [...window.__transitions];
    });

    return { elapsed, properties };
}

const run = async () => {
    console.log(php(join(HERE, 'quick-replies-seed.php')).trim());
    const browser = await launchBrowser();

    try {
        const page = await openPage(browser, {
            context: {
                acceptDownloads: true,
                viewport: { width: 1280, height: 720 },
            },
        });
        await login(page, USERS.admin);
        await page.goto(`${BASE}/quick-replies`);
        await page.waitForLoadState('networkidle');

        // A full page load would lose this marker (history pushes do not).
        await page.evaluate(() => {
            window.__sameDocument = true;
        });

        await step('tabs-switch-without-reload', 'click', async () => {
            const mine = await switchTo(page, 'Mis respuestas');
            const mineRows = await shortcuts(page);
            const others = await switchTo(page, 'De otros usuarios');
            const othersRows = await shortcuts(page);
            const sameDocument = await page.evaluate(
                () => window.__sameDocument === true,
            );
            const allowed = [
                'opacity',
                'transform',
                'translate',
                'color',
                'background-color',
                'box-shadow',
                'border-color',
                'text-decoration-color',
                'outline-color',
            ];
            const touched = [
                ...new Set([...mine.properties, ...others.properties]),
            ];

            return [
                sameDocument &&
                    mineRows.length === 2 &&
                    othersRows.length === 14 &&
                    touched.every((property) => allowed.includes(property)) &&
                    new URL(page.url()).searchParams.get('segment') ===
                        'others',
                `mine ${mine.elapsed} ms (${mineRows.length} rows), others ${others.elapsed} ms (${othersRows.length} rows); same document=${sameDocument}; animated properties=${JSON.stringify(touched)}`,
            ];
        });

        await step('tab-bar-stays-in-view', 'scroll + click', async () => {
            await page.mouse.wheel(0, 900);
            await page.waitForTimeout(300);
            await page.evaluate(() =>
                window.scrollTo(0, document.body.scrollHeight),
            );
            const scrolled = await page.evaluate(() => window.scrollY);
            // Bring the tab bar just into view and switch to the short list.
            await tab(page, 'Corporativas').scrollIntoViewIfNeeded();
            await switchTo(page, 'Corporativas');
            await page.waitForTimeout(600);
            const top = await page
                .getByRole('group', { name: 'Tipo de respuestas' })
                .evaluate((element) => element.getBoundingClientRect().top);
            await shot(page, 'quick-replies-corporate', { fullPage: false });

            return [
                top >= 0 && top < 720,
                `scrolled to ${scrolled}px before; tab bar top after switching ${Math.round(top)}px`,
            ];
        });

        await step('reduced-motion', 'emulated', async () => {
            await page.emulateMedia({ reducedMotion: 'reduce' });
            const result = await switchTo(page, 'De otros usuarios');
            const running = await page.evaluate(
                () =>
                    document
                        .getAnimations()
                        .filter(
                            (animation) =>
                                animation.playState === 'running' &&
                                (animation.effect?.getTiming().duration ?? 0) >
                                    50,
                        ).length,
            );
            await page.emulateMedia({ reducedMotion: 'no-preference' });

            return [
                running === 0,
                `switched in ${result.elapsed} ms; long animations running=${running}`,
            ];
        });

        await step('export-general-report', 'menu', async () => {
            await page.getByRole('button', { name: 'Exportar' }).click();
            const menu = page.getByRole('menu');
            const general = await menu.getByText('Reporte general').count();
            const view = await menu.getByText('Solo la vista actual').count();
            const viewHint = await menu.getByText(/De otros usuarios/).count();
            const [download] = await Promise.all([
                page.waitForEvent('download', { timeout: 30_000 }),
                menu
                    .getByRole('menuitem', { name: 'Excel (.xlsx)' })
                    .first()
                    .click(),
            ]);
            const path = join(SHOTS ?? tmpdir(), 'respuestas-generales.xlsx');
            await download.saveAs(path);
            // Data rows start at row 7 (title, filters and header above).
            const rows = Number(
                execFileSync(
                    PHP_BINARY,
                    [
                        '-r',
                        'require "vendor/autoload.php"; $sheet = PhpOffice\\PhpSpreadsheet\\IOFactory::load($argv[1])->getSheetByName("Respuestas rápidas"); $n = 0; for ($row = 7; $sheet->getCell("A{$row}")->getValue() !== null; $row++) { $n++; } echo $n;',
                        path,
                    ],
                    { cwd: ROOT, encoding: 'utf8' },
                ),
            );

            return [
                rows === 17 &&
                    general === 1 &&
                    view === 1 &&
                    viewHint >= 1 &&
                    download.suggestedFilename().endsWith('.xlsx'),
                `general=${general} view=${view} hint=${viewHint}; file ${download.suggestedFilename()} with ${rows} replies (1 corporate + 2 mine + 14 of Ana expected)`,
            ];
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
