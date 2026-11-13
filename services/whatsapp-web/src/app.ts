import { createRequire } from 'node:module';
import type { Server } from 'node:http';
import { join } from 'node:path';
import type { Config } from './config.js';
import { createEventDelivery } from './laravel.js';
import { acquireLock } from './lock.js';
import type { Logger } from './logger.js';
import { SessionManager } from './manager.js';
import { createMediaFetcher, type MediaFetcher } from './media-fetch.js';
import { Outbox, type Deliver } from './outbox.js';
import { SentStore } from './sent-store.js';
import { createControlServer } from './server.js';
import type { SessionOptions } from './session.js';
import { ensurePrivateDir, Vault } from './vault.js';
import type { MediaDownloader, WaFactory } from './wa.js';

/**
 * Wires the service: instance lock, encrypted stores, event outbox,
 * sessions and the control API. The WhatsApp side (factory and media
 * download) is injected, so the tests run the whole service with a double.
 */
export type ServiceDependencies = {
    config: Config;
    logger: Logger;
    factory: WaFactory;
    downloadMedia: MediaDownloader;
    /** Overrides for tests. */
    deliver?: Deliver;
    fetchMedia?: MediaFetcher;
    session?: Partial<Pick<SessionOptions, 'now' | 'random' | 'schedule'>>;
    outbox?: {
        retryDelay?: (attempt: number) => number;
        sleep?: (ms: number) => Promise<void>;
    };
};

export type Service = {
    server: Server;
    manager: SessionManager;
    outbox: Outbox;
    sent: SentStore;
    /** Binds the control API; resolves with the port in use. */
    listen: () => Promise<number>;
    /** Orderly shutdown: closes sockets without unlinking any device. */
    close: () => Promise<void>;
};

const packageVersion = (name: string): string => {
    try {
        const require = createRequire(import.meta.url);

        return (require(`${name}/package.json`) as { version: string }).version;
    } catch {
        return 'unknown';
    }
};

export async function createService(
    dependencies: ServiceDependencies,
): Promise<Service> {
    const { config, logger } = dependencies;
    const startedAt = Date.now();

    await ensurePrivateDir(config.dataDir);

    const releaseLock = acquireLock(join(config.dataDir, 'service.lock'));
    const vault = new Vault(config.sessionKey);
    const sent = new SentStore(
        join(config.dataDir, 'sent'),
        vault,
        config.sessionKey,
    );

    await sent.load();

    let manager: SessionManager | null = null;

    const outbox = new Outbox({
        directory: join(config.dataDir, 'outbox'),
        vault,
        deliver:
            dependencies.deliver ??
            createEventDelivery({
                laravelUrl: config.laravelUrl,
                secret: config.secret,
                timeoutMs: config.eventTimeoutMs,
            }),
        onGone: (connectionId) => manager?.stopBecauseGone(connectionId),
        logger,
        ...dependencies.outbox,
    });

    manager = new SessionManager({
        sessionsDirectory: join(config.dataDir, 'sessions'),
        vault,
        factory: dependencies.factory,
        outbox,
        sent,
        fetchMedia:
            dependencies.fetchMedia ??
            createMediaFetcher({
                laravelUrl: config.laravelUrl,
                temporaryDirectory: join(config.dataDir, 'tmp'),
                maxBytes: config.maxMediaBytes,
            }),
        logger,
        limits: {
            qrMaxRefresh: config.qrMaxRefresh,
            reconnectMaxAttempts: config.reconnectMaxAttempts,
            sendTimeoutMs: config.sendTimeoutMs,
            syncHistory: config.syncHistory,
            historyMaxDays: config.historyMaxDays,
            historyBatchSize: config.historyBatchSize,
        },
        session: dependencies.session,
    });

    const server = createControlServer({
        secret: config.secret,
        manager,
        downloadMedia: dependencies.downloadMedia,
        maxMediaBytes: config.maxMediaBytes,
        logger,
        status: () => ({
            service: 'chatmanager-whatsapp-web',
            version: packageVersion('..'),
            library: `baileys ${packageVersion('baileys')}`,
            node: process.version,
            uptime_seconds: Math.floor((Date.now() - startedAt) / 1000),
            events: outbox.stats(),
        }),
    });

    const pruning = setInterval(
        () => void sent.prune().catch(() => undefined),
        3600 * 1000,
    );

    pruning.unref();

    const activeManager = manager;

    return {
        server,
        manager: activeManager,
        outbox,
        sent,
        listen: async () => {
            await outbox.load();

            const restored = await activeManager.restore();

            await new Promise<void>((resolve, reject) => {
                server.once('error', reject);
                server.listen(config.port, config.host, () => {
                    server.off('error', reject);
                    resolve();
                });
            });

            const address = server.address();
            const port =
                typeof address === 'object' && address !== null
                    ? address.port
                    : config.port;

            logger.info(
                { host: config.host, port, restored },
                'WhatsApp Web service ready',
            );

            return port;
        },
        close: async () => {
            clearInterval(pruning);
            await new Promise<void>((resolve) => {
                server.close(() => resolve());
                server.closeIdleConnections?.();
            });
            await activeManager.shutdown();
            await outbox.stop();
            releaseLock();
        },
    };
}
