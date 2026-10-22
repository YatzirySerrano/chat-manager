/*
| Shared helpers of the load scenarios (tests/load/scenarios/*.mjs), on
| Node's built-in fetch, crypto and WebSocket only (no dependencies):
|
| - Session: a cookie jar + CSRF (XSRF-TOKEN) + Inertia headers, logging in
|   through the real login form endpoint.
| - Recorder: latency samples per operation (p50/p95/p99), status codes and
|   errors, throughput over the measured window.
| - signedWebhook(): WhatsApp Cloud API webhook bodies signed with the
|   throwaway secret of a local fixture connection (SIMULACIÓN: nothing is
|   sent to or received from Meta).
| - PusherClient: the pusher protocol (v7) spoken by Laravel Echo against
|   Reverb, with private channel authorization through /broadcasting/auth.
*/

import { createHmac, randomUUID } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';

export const env = (name, fallback) => process.env[name] ?? fallback;
export const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
export const pick = (list) => list[Math.floor(Math.random() * list.length)];
export const between = (min, max) => min + Math.random() * (max - min);

export function loadManifest() {
    return JSON.parse(readFileSync(env('LOAD_MANIFEST'), 'utf8'));
}

export function writeResult(result) {
    writeFileSync(env('LOAD_RESULT'), JSON.stringify(result, null, 2));
}

export class Session {
    constructor(baseUrl, label = '') {
        this.baseUrl = baseUrl;
        this.label = label;
        this.cookies = new Map();
        this.version = null;
    }

    cookieHeader() {
        return [...this.cookies]
            .map(([name, value]) => `${name}=${value}`)
            .join('; ');
    }

    store(response) {
        for (const line of response.headers.getSetCookie()) {
            const [pair, ...attributes] = line.split(';');
            const index = pair.indexOf('=');
            const name = pair.slice(0, index).trim();
            const value = pair.slice(index + 1).trim();
            const expired = attributes.some((attribute) =>
                /max-age=0/i.test(attribute.trim()),
            );

            if (value === '' || expired) {
                this.cookies.delete(name);
            } else {
                this.cookies.set(name, value);
            }
        }
    }

    xsrf() {
        return decodeURIComponent(this.cookies.get('XSRF-TOKEN') ?? '');
    }

    /**
     * One request; never follows redirects (a 302/303 is an answer here).
     * Returns { status, ms, headers, body } where body is text.
     */
    async request(
        method,
        path,
        { headers = {}, body, json, timeoutMs = 30000 } = {},
    ) {
        const finalHeaders = {
            Cookie: this.cookieHeader(),
            'User-Agent': 'chat-manager-load/1.0',
            ...headers,
        };

        if (method !== 'GET' && method !== 'HEAD') {
            finalHeaders['X-XSRF-TOKEN'] = this.xsrf();
        }

        let payload = body;

        if (json !== undefined) {
            payload = JSON.stringify(json);
            finalHeaders['Content-Type'] = 'application/json';
        }

        const started = performance.now();
        const response = await fetch(this.baseUrl + path, {
            method,
            headers: finalHeaders,
            ...(method === 'GET' || method === 'HEAD' ? {} : { body: payload }),
            redirect: 'manual',
            signal: AbortSignal.timeout(timeoutMs),
        });
        const text = await response.text();
        const ms = performance.now() - started;

        this.store(response);

        return {
            status: response.status,
            ms,
            headers: response.headers,
            body: text,
        };
    }

    async login(email, password) {
        await this.request('GET', '/login', {
            headers: { Accept: 'text/html' },
        });
        const response = await this.request('POST', '/login', {
            json: { email, password },
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (response.status >= 400) {
            throw new Error(
                `Login of ${email} failed with HTTP ${response.status}`,
            );
        }

        const page = await this.request('GET', '/dashboard', {
            headers: { Accept: 'text/html' },
        });
        this.version = inertiaVersion(page.body);

        return response;
    }

    /**
     * An Inertia visit (JSON page object), optionally a partial reload of
     * some props, retrying once after an asset version change (409).
     */
    async inertia(path, { component, only } = {}) {
        const headers = {
            Accept: 'text/html, application/xhtml+xml',
            'X-Inertia': 'true',
            'X-Requested-With': 'XMLHttpRequest',
        };

        if (this.version !== null) {
            headers['X-Inertia-Version'] = this.version;
        }

        if (component && only) {
            headers['X-Inertia-Partial-Component'] = component;
            headers['X-Inertia-Partial-Data'] = only.join(',');
        }

        let response = await this.request('GET', path, { headers });

        if (response.status === 409) {
            const page = await this.request('GET', '/dashboard', {
                headers: { Accept: 'text/html' },
            });
            this.version = inertiaVersion(page.body);
            response = await this.request('GET', path, {
                headers: {
                    ...headers,
                    'X-Inertia-Version': this.version ?? '',
                },
            });
        }

        return response;
    }

    async json(method, path, json) {
        return this.request(method, path, {
            json,
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
    }
}

export function inertiaVersion(html) {
    const match =
        html.match(/"version":(null|"([^"]*)")/) ??
        html.match(/&quot;version&quot;:(null|&quot;(.*?)&quot;)/);

    return match ? (match[2] ?? null) : null;
}

export function percentile(sorted, p) {
    if (sorted.length === 0) {
        return null;
    }

    const index = Math.min(
        sorted.length - 1,
        Math.max(0, Math.ceil((p / 100) * sorted.length) - 1),
    );

    return sorted[index];
}

export function summarize(values) {
    const sorted = [...values].sort((a, b) => a - b);
    const round = (value) =>
        value === null ? null : Math.round(value * 10) / 10;

    return {
        count: sorted.length,
        min: round(sorted[0] ?? null),
        p50: round(percentile(sorted, 50)),
        p95: round(percentile(sorted, 95)),
        p99: round(percentile(sorted, 99)),
        max: round(sorted[sorted.length - 1] ?? null),
        mean: round(
            sorted.length === 0
                ? null
                : sorted.reduce((sum, value) => sum + value, 0) / sorted.length,
        ),
    };
}

/**
 * Latency samples and outcomes per operation.
 * An outcome is an error when the status is not in `ok` (default 2xx/3xx)
 * or the request threw (timeout, connection reset...).
 */
export class Recorder {
    constructor() {
        this.operations = new Map();
        this.startedAt = null;
        this.endedAt = null;
    }

    start() {
        this.startedAt = performance.now();
    }

    stop() {
        this.endedAt = performance.now();
    }

    entry(name) {
        if (!this.operations.has(name)) {
            this.operations.set(name, {
                latencies: [],
                statuses: {},
                errors: 0,
                failures: [],
            });
        }

        return this.operations.get(name);
    }

    record(name, response, ok = (status) => status >= 200 && status < 400) {
        const entry = this.entry(name);
        entry.latencies.push(response.ms);
        entry.statuses[response.status] =
            (entry.statuses[response.status] ?? 0) + 1;

        if (!ok(response.status)) {
            entry.errors++;

            if (entry.failures.length < 5) {
                entry.failures.push({
                    status: response.status,
                    body: String(response.body ?? '').slice(0, 200),
                });
            }
        }

        return response;
    }

    fail(name, error, ms = null) {
        const entry = this.entry(name);
        entry.errors++;
        entry.statuses.exception = (entry.statuses.exception ?? 0) + 1;

        if (ms !== null) {
            entry.latencies.push(ms);
        }

        if (entry.failures.length < 5) {
            entry.failures.push({
                error: String(error?.cause?.code ?? error?.name ?? error).slice(
                    0,
                    200,
                ),
            });
        }
    }

    /** Runs `call`, recording its latency or its failure. */
    async measure(name, call, ok) {
        const started = performance.now();

        try {
            return this.record(name, await call(), ok);
        } catch (error) {
            this.fail(name, error, performance.now() - started);

            return null;
        }
    }

    report() {
        const seconds =
            ((this.endedAt ?? performance.now()) -
                (this.startedAt ?? performance.now())) /
            1000;
        const operations = {};
        let total = 0;
        let errors = 0;

        for (const [name, entry] of this.operations) {
            const count = entry.latencies.length || entry.errors;
            total += count;
            errors += entry.errors;
            operations[name] = {
                ...summarize(entry.latencies),
                requests: count,
                errors: entry.errors,
                error_rate:
                    count === 0
                        ? 0
                        : Math.round((entry.errors / count) * 10000) / 100,
                throughput_per_s:
                    seconds > 0
                        ? Math.round((count / seconds) * 100) / 100
                        : null,
                statuses: entry.statuses,
                failures: entry.failures,
            };
        }

        return {
            seconds: Math.round(seconds * 100) / 100,
            requests: total,
            errors,
            error_rate:
                total === 0 ? 0 : Math.round((errors / total) * 10000) / 100,
            throughput_per_s:
                seconds > 0 ? Math.round((total / seconds) * 100) / 100 : null,
            operations,
        };
    }
}

/**
 * Runs `worker(index)` on `concurrency` parallel lanes until `items` is empty.
 */
export async function pool(items, concurrency, worker) {
    let next = 0;
    const lanes = Array.from(
        { length: Math.min(concurrency, items.length) },
        async () => {
            while (next < items.length) {
                const index = next++;
                await worker(items[index], index);
            }
        },
    );

    await Promise.all(lanes);
}

/* ---------------------------------------------------------------- webhooks */

export function inboundBody(
    connection,
    { waId, name, wamid, text, timestamp },
) {
    return {
        object: 'whatsapp_business_account',
        entry: [
            {
                id: connection.business_account_id,
                changes: [
                    {
                        field: 'messages',
                        value: {
                            messaging_product: 'whatsapp',
                            metadata: {
                                display_phone_number: '5215500000100',
                                phone_number_id: connection.phone_number_id,
                            },
                            contacts: [{ profile: { name }, wa_id: waId }],
                            messages: [
                                {
                                    from: waId,
                                    id: wamid,
                                    timestamp: String(timestamp),
                                    type: 'text',
                                    text: { body: text },
                                },
                            ],
                        },
                    },
                ],
            },
        ],
    };
}

export function statusBody(connection, { wamid, status, timestamp }) {
    return {
        object: 'whatsapp_business_account',
        entry: [
            {
                id: connection.business_account_id,
                changes: [
                    {
                        field: 'messages',
                        value: {
                            messaging_product: 'whatsapp',
                            metadata: {
                                display_phone_number: '5215500000100',
                                phone_number_id: connection.phone_number_id,
                            },
                            statuses: [
                                {
                                    id: wamid,
                                    status,
                                    timestamp: String(timestamp),
                                    recipient_id: '5215500000100',
                                },
                            ],
                        },
                    },
                ],
            },
        ],
    };
}

/** POSTs a webhook body signed like Meta does (X-Hub-Signature-256). */
export async function postWebhook(
    baseUrl,
    connection,
    secret,
    payload,
    timeoutMs = 30000,
) {
    const raw = typeof payload === 'string' ? payload : JSON.stringify(payload);
    const signature =
        'sha256=' + createHmac('sha256', secret).update(raw).digest('hex');
    const started = performance.now();
    const response = await fetch(
        `${baseUrl}/webhooks/whatsapp/${connection.webhook_key}`,
        {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Hub-Signature-256': signature,
                'User-Agent': 'chat-manager-load/1.0 (simulación)',
            },
            body: raw,
            signal: AbortSignal.timeout(timeoutMs),
        },
    );
    await response.arrayBuffer();

    return {
        status: response.status,
        ms: performance.now() - started,
        body: '',
    };
}

export const uuid = () => randomUUID();

/* ---------------------------------------------------------------- realtime */

/**
 * Minimal pusher-protocol client (what laravel-echo + pusher-js do), with
 * the Origin header of the application so Reverb's origin check applies.
 */
export class PusherClient {
    constructor({ host, port, key, origin, session }) {
        this.url = `ws://${host}:${port}/app/${key}?protocol=7&client=js&version=8.4.0&flash=false`;
        this.origin = origin;
        this.session = session;
        this.socket = null;
        this.socketId = null;
        this.handlers = new Set();
        this.subscribed = new Set();
    }

    connect(timeoutMs = 15000) {
        return new Promise((resolve, reject) => {
            const started = performance.now();
            const socket = new WebSocket(this.url, {
                headers: { Origin: this.origin },
            });
            const timer = setTimeout(() => {
                reject(new Error('connect timeout'));
                socket.close();
            }, timeoutMs);

            this.socket = socket;
            socket.addEventListener('message', (event) => {
                const frame = JSON.parse(String(event.data));
                const data =
                    typeof frame.data === 'string'
                        ? safeJson(frame.data)
                        : frame.data;

                if (frame.event === 'pusher:connection_established') {
                    this.socketId = data.socket_id;
                    clearTimeout(timer);
                    resolve(performance.now() - started);
                } else if (frame.event === 'pusher:ping') {
                    socket.send(
                        JSON.stringify({ event: 'pusher:pong', data: {} }),
                    );
                }

                for (const handler of this.handlers) {
                    handler(frame.event, frame.channel, data);
                }
            });
            socket.addEventListener('error', (error) => {
                clearTimeout(timer);
                reject(new Error(error?.message ?? 'websocket error'));
            });
            socket.addEventListener('close', () => {
                this.subscribed.clear();
            });
        });
    }

    on(handler) {
        this.handlers.add(handler);

        return () => this.handlers.delete(handler);
    }

    /**
     * Authorizes (POST /broadcasting/auth) and subscribes a private channel.
     * Resolves { authMs, totalMs } or rejects with the HTTP status / error.
     */
    async subscribe(channel, timeoutMs = 15000) {
        const name = `private-${channel}`;
        const started = performance.now();
        const auth = await this.session.request('POST', '/broadcasting/auth', {
            json: { socket_id: this.socketId, channel_name: name },
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const authMs = auth.ms;

        if (auth.status !== 200) {
            const error = new Error(`auth ${auth.status}`);
            error.status = auth.status;
            error.authMs = authMs;
            throw error;
        }

        const signature = JSON.parse(auth.body).auth;

        await new Promise((resolve, reject) => {
            const timer = setTimeout(() => {
                off();
                reject(new Error('subscribe timeout'));
            }, timeoutMs);
            const off = this.on((event, frameChannel) => {
                if (frameChannel !== name) {
                    return;
                }

                if (event === 'pusher_internal:subscription_succeeded') {
                    clearTimeout(timer);
                    off();
                    resolve();
                } else if (event === 'pusher:subscription_error') {
                    clearTimeout(timer);
                    off();
                    reject(new Error('subscription_error'));
                }
            });

            this.socket.send(
                JSON.stringify({
                    event: 'pusher:subscribe',
                    data: { channel: name, auth: signature },
                }),
            );
        });

        this.subscribed.add(name);

        return { authMs, totalMs: performance.now() - started };
    }

    close() {
        try {
            this.socket?.close();
        } catch {
            // already closed
        }
    }
}

function safeJson(text) {
    try {
        return JSON.parse(text);
    } catch {
        return text;
    }
}
