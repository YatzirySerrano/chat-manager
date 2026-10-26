/*
 * Browser scenarios of signing out (Fase 5.1): the confirmation must give
 * feedback, end the session on the server, land on the login page by
 * itself, close the dialog, keep the back button away from protected pages
 * and never load forever, also with a large HTTP cache (the Fase 5
 * regression: Clear-Site-Data "cache" made Chrome hold the logout response
 * while it walked the whole cache), with several sessions, with the service
 * worker installed, when the network fails or the server errs (message and
 * retry) and when the session had already expired.
 *
 * Real Chromium with a persistent profile (so it has a disk cache like a
 * normal browser). Run it through the runner:
 *
 *   php tests/e2e/run.php logout.mjs
 *
 * Environment (set by the runner): E2E_BASE_URL, PLAYWRIGHT_MODULE,
 * optional E2E_SCREENSHOTS. E2E_LOGOUT_CACHE_ENTRIES (default 15000) sets
 * how many responses fill the HTTP cache before the large-cache check.
 */
import { mkdtempSync, rmSync } from 'node:fs';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
    BASE,
    USERS,
    chromium,
    exitWithSummary,
    login,
    printProblems,
    problems,
    recordPageProblems,
    shot,
    step,
    watchProblems,
} from './lib.mjs';

const CACHE_ENTRIES = Number(process.env.E2E_LOGOUT_CACHE_ENTRIES ?? 15_000);
/** A sign out must finish well within this, whatever the cache size. */
const LOGOUT_BUDGET_MS = 4_000;
const USER_MENU = /E2E Administración/;

/** Every login counts against the login rate limit (per email), so the
 * later checks sign in as other seeded users. */
const MENUS = {
    [USERS.admin]: USER_MENU,
    [USERS.ana]: /E2E Ana/,
    [USERS.beto]: /E2E Beto/,
    [USERS.carla]: /E2E Carla/,
};
let currentMenu = USER_MENU;

async function signIn(page, email) {
    currentMenu = MENUS[email];
    await login(page, email);
}

async function openSession(context) {
    const page = await context.newPage();
    page.setDefaultTimeout(15_000);
    watchProblems(page, /status of (403|404|409|419|422|429|500)|ERR_FAILED/);

    return page;
}

async function openLogoutDialog(page) {
    await page.getByRole('button', { name: currentMenu }).first().click();
    await page.getByRole('menuitem', { name: 'Cerrar sesión' }).click();
    const dialog = page.getByRole('alertdialog').or(page.getByRole('dialog'));
    await dialog.getByText('¿Cerrar sesión?').waitFor();

    return dialog;
}

/**
 * Confirms and measures: time to the feedback, to the /logout answer and
 * to the login page with the dialog gone.
 */
async function confirmLogout(page) {
    const dialog = await openLogoutDialog(page);
    const answered = page.waitForResponse(
        (response) =>
            new URL(response.url()).pathname === '/logout' &&
            response.request().method() === 'POST',
        { timeout: 60_000 },
    );
    const started = Date.now();
    await dialog.getByRole('button', { name: 'Cerrar sesión' }).click();
    const feedback = await dialog
        .getByRole('button', { name: 'Cerrando sesión…' })
        .waitFor({ timeout: 2_000 })
        .then(() => Date.now() - started)
        .catch(() => null);
    const response = await answered;
    const responseMs = Date.now() - started;
    await page.waitForURL((url) => url.pathname === '/login', {
        timeout: 60_000,
    });
    await page
        .getByRole('dialog')
        .or(page.getByRole('alertdialog'))
        .waitFor({ state: 'detached', timeout: 5_000 })
        .catch(() => {});
    const loginMs = Date.now() - started;
    const dialogs = await page
        .getByRole('dialog')
        .or(page.getByRole('alertdialog'))
        .count();

    return {
        feedback,
        responseMs,
        loginMs,
        dialogs,
        headers: await response.allHeaders(),
    };
}

/** Fills the HTTP cache of the profile with many cacheable responses. */
async function fillHttpCache(page, entries) {
    const body = Buffer.alloc(2048, 'x');
    const server = createServer((request, response) => {
        response.writeHead(200, {
            'Content-Type': 'text/plain',
            'Cache-Control': 'public, max-age=31536000',
            'Access-Control-Allow-Origin': '*',
        });
        response.end(body);
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${server.address().port}`;
    const started = Date.now();

    await page.evaluate(
        async ({ origin, entries }) => {
            for (let index = 0; index < entries; index += 100) {
                await Promise.all(
                    Array.from({ length: 100 }, (_, offset) =>
                        fetch(`${origin}/entry/${index + offset}`).then(
                            (response) => response.arrayBuffer(),
                        ),
                    ),
                );
            }
        },
        { origin, entries },
    );
    server.close();

    return Date.now() - started;
}

const run = async () => {
    const profile = mkdtempSync(join(tmpdir(), 'cm-logout-'));
    const context = await chromium.launchPersistentContext(profile, {
        viewport: { width: 1366, height: 900 },
        locale: 'es-MX',
    });

    try {
        const page = await openSession(context);
        await signIn(page, USERS.admin);

        await step('logout-normal', 'click', async () => {
            const result = await confirmLogout(page);

            return [
                result.feedback !== null &&
                    result.loginMs < LOGOUT_BUDGET_MS &&
                    result.dialogs === 0 &&
                    result.headers['clear-site-data'] === undefined,
                `feedback ${result.feedback} ms, answer ${result.responseMs} ms, login page ${result.loginMs} ms, dialogs left ${result.dialogs}, Clear-Site-Data=${result.headers['clear-site-data']}`,
            ];
        });

        await step('back-button-after-logout', 'history', async () => {
            await page.goBack().catch(() => {});
            // Inertia cannot decrypt the entry of the closed session, so it
            // asks the server again, which answers with the login page.
            await page
                .waitForURL((url) => url.pathname === '/login', {
                    timeout: 8_000,
                })
                .catch(() => {});
            await shot(page, 'logout-back-button', { fullPage: false });
            const path = new URL(page.url()).pathname;
            const protectedShown = await page
                .getByRole('button', { name: USER_MENU })
                .count();
            await page.goto(`${BASE}/dashboard`);

            return [
                path === '/login' &&
                    protectedShown === 0 &&
                    new URL(page.url()).pathname === '/login',
                `back -> ${path}, user menu visible=${protectedShown}; /dashboard -> ${new URL(page.url()).pathname}`,
            ];
        });

        await step('logout-with-large-http-cache', 'click', async () => {
            await signIn(page, USERS.admin);
            const fillMs = await fillHttpCache(page, CACHE_ENTRIES);

            const result = await confirmLogout(page);

            return [
                result.loginMs < LOGOUT_BUDGET_MS && result.dialogs === 0,
                `${CACHE_ENTRIES} cache entries (filled in ${fillMs} ms); sign out: answer ${result.responseMs} ms, login page ${result.loginMs} ms`,
            ];
        });

        await step('logout-with-service-worker', 'pwa', async () => {
            await signIn(page, USERS.admin);
            await page.goto(`${BASE}/dashboard`);
            const scope = await page.evaluate(async () => {
                const registration = await Promise.race([
                    navigator.serviceWorker.ready,
                    new Promise((resolve) =>
                        setTimeout(() => resolve(null), 8_000),
                    ),
                ]);

                return registration?.scope ?? null;
            });
            const result = await confirmLogout(page);
            const caches = await page.evaluate(async () =>
                (await globalThis.caches.keys()).filter((name) =>
                    name.startsWith('chat-manager-static-'),
                ),
            );
            // Back navigation with the service worker in control must not
            // bring the closed session's screen back (nor from its cache).
            await page.goBack().catch(() => {});
            await page
                .waitForURL((url) => url.pathname === '/login', {
                    timeout: 8_000,
                })
                .catch(() => {});
            await page.waitForLoadState('networkidle').catch(() => {});
            const backPath = new URL(page.url()).pathname;
            const protectedShown = await page
                .getByRole('button', { name: USER_MENU })
                .count();
            await shot(page, 'logout-pwa-back-button', { fullPage: false });

            return [
                scope !== null &&
                    result.loginMs < LOGOUT_BUDGET_MS &&
                    result.dialogs === 0 &&
                    backPath === '/login' &&
                    protectedShown === 0,
                `worker scope=${scope}; login page ${result.loginMs} ms; caches after (worker may re-create the public one) ${JSON.stringify(caches)}; back -> ${backPath}, user menu visible=${protectedShown}`,
            ];
        });

        await step('several-sessions', 'two tabs, two profiles', async () => {
            const other = await chromium.launch();
            const otherContext = await other.newContext({ locale: 'es-MX' });
            const second = await otherContext.newPage();
            await signIn(page, USERS.ana);
            await login(second, USERS.ana);
            const result = await confirmLogout(page);
            await second.goto(`${BASE}/dashboard`);
            const secondStillIn =
                new URL(second.url()).pathname === '/dashboard';
            await otherContext.close();
            await other.close();

            return [
                result.loginMs < LOGOUT_BUDGET_MS && secondStillIn,
                `this device signed out in ${result.loginMs} ms; the other device keeps its session=${secondStillIn}`,
            ];
        });

        await step('network-failure-shows-retry', 'offline', async () => {
            await signIn(page, USERS.beto);
            await page.route('**/logout', (route) => route.abort('failed'));
            const dialog = await openLogoutDialog(page);
            await dialog.getByRole('button', { name: 'Cerrar sesión' }).click();
            const message = await dialog
                .getByRole('alert')
                .innerText({ timeout: 8_000 })
                .catch(() => null);
            await shot(page, 'logout-network-error', { fullPage: false });
            const stillIn = new URL(page.url()).pathname !== '/login';
            await page.unroute('**/logout');
            await dialog
                .getByRole('button', { name: 'Intentar de nuevo' })
                .click();
            await page.waitForURL((url) => url.pathname === '/login');

            return [
                message !== null && /conexión/i.test(message) && stillIn,
                `message="${message?.replace(/\s+/g, ' ')}"; retry -> ${new URL(page.url()).pathname}`,
            ];
        });

        await step('server-error-shows-retry', '500', async () => {
            await signIn(page, USERS.carla);
            await page.route('**/logout', (route) =>
                route.fulfill({
                    status: 500,
                    contentType: 'text/html',
                    body: '<h1>Error</h1>',
                }),
            );
            const dialog = await openLogoutDialog(page);
            await dialog.getByRole('button', { name: 'Cerrar sesión' }).click();
            const message = await dialog
                .getByRole('alert')
                .innerText({ timeout: 8_000 })
                .catch(() => null);
            const errorModal = await page.locator('iframe').count();
            // The 500 above is the simulated one, not a server problem.
            problems.server = problems.server.filter(
                (line) => !line.endsWith('/logout'),
            );
            await page.unroute('**/logout');
            await dialog
                .getByRole('button', { name: 'Intentar de nuevo' })
                .click();
            await page.waitForURL((url) => url.pathname === '/login');

            return [
                message !== null && errorModal === 0,
                `message="${message?.replace(/\s+/g, ' ')}"; Inertia error modal frames=${errorModal}`,
            ];
        });

        await step('stale-token-open-session-explains', '419', async () => {
            await signIn(page, USERS.carla);
            // The token no longer matches although the session is still open
            // (e.g. it was opened again in another tab).
            await page.route(
                '**/logout',
                (route) =>
                    route.fulfill({
                        status: 419,
                        contentType: 'text/html',
                        body: '<h1>Page Expired</h1>',
                    }),
                { times: 1 },
            );
            const dialog = await openLogoutDialog(page);
            await dialog.getByRole('button', { name: 'Cerrar sesión' }).click();
            const message = await dialog
                .getByRole('alert')
                .innerText({ timeout: 8_000 })
                .catch(() => null);
            await shot(page, 'logout-stale-token', { fullPage: false });
            const stillIn = new URL(page.url()).pathname !== '/login';
            await dialog
                .getByRole('button', { name: 'Intentar de nuevo' })
                .click();
            await page.waitForURL((url) => url.pathname === '/login');
            await page.goto(`${BASE}/dashboard`);
            const signedOut = new URL(page.url()).pathname === '/login';

            return [
                message !== null &&
                    /otra pestaña/i.test(message) &&
                    stillIn &&
                    signedOut,
                `message="${message?.replace(/\s+/g, ' ')}"; stayed signed in until the retry=${stillIn}; signed out after it=${signedOut}`,
            ];
        });

        await step('expired-session-goes-to-login', '419', async () => {
            await signIn(page, USERS.carla);
            const dialog = await openLogoutDialog(page);
            // The session ends elsewhere (expiry, another tab): only the
            // session cookie is dropped, the page still has its token.
            const cookies = await context.cookies(BASE);
            await context.clearCookies();
            await context.addCookies(
                cookies.filter((cookie) => !/session/i.test(cookie.name)),
            );
            const started = Date.now();
            await dialog.getByRole('button', { name: 'Cerrar sesión' }).click();
            await page.waitForURL((url) => url.pathname === '/login', {
                timeout: 10_000,
            });

            return [true, `landed on /login in ${Date.now() - started} ms`];
        });
    } finally {
        await context.close();
        rmSync(profile, { recursive: true, force: true });
    }

    recordPageProblems(800);
    printProblems();
    exitWithSummary();
};

run().catch((error) => {
    console.error(error);
    process.exit(1);
});
