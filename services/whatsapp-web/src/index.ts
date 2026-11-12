import { resolve } from 'node:path';
import { createService } from './app.js';
import { createBaileysFactory, downloadMedia } from './baileys.js';
import { ConfigError, loadConfig, loadEnvFile } from './config.js';
import { LockError } from './lock.js';
import { createLogger, safeErrorMessage } from './logger.js';

/**
 * Entry point: `node dist/index.js`. Variables come from the environment
 * of the process manager; a `.env` next to the working directory (or the
 * file named by WAWEB_ENV_FILE) fills in what is missing.
 */
async function main(): Promise<void> {
    loadEnvFile(resolve(process.env.WAWEB_ENV_FILE ?? '.env'));

    let config;

    try {
        config = loadConfig();
    } catch (error) {
        if (error instanceof ConfigError) {
            process.stderr.write(`Configuration error: ${error.message}\n`);
            process.exit(78);
        }

        throw error;
    }

    const logger = createLogger(config.logLevel);

    let service;

    try {
        service = await createService({
            config,
            logger,
            factory: createBaileysFactory({
                logger,
                libraryLogLevel: config.libraryLogLevel,
            }),
            downloadMedia,
        });
        await service.listen();
    } catch (error) {
        logger.fatal(
            {
                err:
                    error instanceof LockError
                        ? error.message
                        : safeErrorMessage(error),
            },
            'The service could not start',
        );
        process.exit(error instanceof LockError ? 75 : 1);
    }

    let closing = false;

    const shutdown = (signal: string): void => {
        if (closing) {
            return;
        }

        closing = true;
        logger.info({ signal }, 'Shutting down');

        // Sockets are closed without logging out: sessions come back on the next start.
        const forced = setTimeout(() => process.exit(1), 15_000);

        forced.unref();
        void service.close().finally(() => process.exit(0));
    };

    process.on('SIGTERM', () => shutdown('SIGTERM'));
    process.on('SIGINT', () => shutdown('SIGINT'));

    // The library rejects promises of sockets that were already replaced;
    // one failed session must never take the others down.
    process.on('unhandledRejection', (reason) => {
        logger.error({ err: safeErrorMessage(reason) }, 'Unhandled rejection');
    });
    process.on('uncaughtException', (error) => {
        logger.error({ err: safeErrorMessage(error) }, 'Uncaught exception');
    });
}

void main();
