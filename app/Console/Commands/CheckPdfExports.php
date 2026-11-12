<?php

namespace App\Console\Commands;

use App\Reports\Rendering\ChromeLocator;
use App\Reports\Rendering\PdfRenderingFailed;
use Illuminate\Console\Command;
use Spatie\Browsershot\Browsershot;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Prints a real one-page PDF with the same settings the exports use and
 * explains the cause when it fails. Run it as the account that runs PHP-FPM
 * and the queue workers (e.g. `sudo -u www-data php artisan exports:pdf-check`):
 * a browser installed for another account is the usual reason a PDF export
 * works in a terminal and fails from the website.
 */
class CheckPdfExports extends Command
{
    protected $signature = 'exports:pdf-check
        {--show-candidates : List every place searched for Chrome}
        {--install : Download Chrome (chrome-headless-shell) into storage/app/puppeteer before the test}';

    protected $description = 'Generate a test PDF with Chrome and explain the cause when it cannot be generated';

    public function handle(ChromeLocator $locator): int
    {
        $config = (array) config('exports.pdf');

        if ($this->option('install') && ! $this->installChrome($config)) {
            return self::FAILURE;
        }

        $chrome = $locator->path();

        $this->line('Node: '.$config['node_binary']);
        $this->line('node_modules: '.$config['node_modules_path']);
        $this->line('Chrome: '.($chrome ?? 'no encontrado; se deja la elección a puppeteer'));

        if ($locator->configuredPathIsBroken()) {
            $this->warn('EXPORTS_CHROME_PATH apunta a un archivo que no existe o no es ejecutable: '.$config['chrome_path']);
        }

        if ($this->option('show-candidates')) {
            foreach ($locator->candidates() as $candidate) {
                $this->line('  '.(is_file($candidate) ? '[existe] ' : '[  --  ] ').$candidate);
            }
        }

        $workspace = str_replace('\\', '/', storage_path('app/chrome'));

        foreach (['', '/profile', '/config', '/cache', '/crashes'] as $directory) {
            if (! is_dir($workspace.$directory)) {
                @mkdir($workspace.$directory, 0700, true);
            }
        }

        $target = $workspace.'/check-'.bin2hex(random_bytes(4)).'.pdf';
        $sandboxOff = (bool) ($config['no_sandbox'] ?? false) || (function_exists('posix_geteuid') && posix_geteuid() === 0);

        foreach ($sandboxOff ? [true] : [false, true] as $noSandbox) {
            try {
                $browsershot = Browsershot::html('<html><body><h1>Chat Manager</h1><p>Prueba de PDF. Acentos: á é í ó ú ñ.</p></body></html>')
                    ->setNodeBinary((string) $config['node_binary'])
                    ->setNpmBinary((string) $config['npm_binary'])
                    ->setNodeModulePath((string) $config['node_modules_path'])
                    ->format('A4')
                    ->userDataDir($workspace.'/profile')
                    ->setEnvironmentOptions([
                        'HOME' => $workspace,
                        'XDG_CONFIG_HOME' => $workspace.'/config',
                        'XDG_CACHE_HOME' => $workspace.'/cache',
                    ])
                    ->addChromiumArguments([
                        'disable-dev-shm-usage',
                        'disable-gpu',
                        'no-first-run',
                        'disable-crash-reporter',
                        'crash-dumps-dir' => $workspace.'/crashes',
                    ])
                    ->timeout((int) $config['timeout']);

                if ($chrome !== null) {
                    $browsershot->setChromePath($chrome);
                }

                if ($noSandbox) {
                    $browsershot->noSandbox();
                }

                $browsershot->save($target);

                $bytes = (int) @filesize($target);
                @unlink($target);

                $this->info("PDF generado correctamente ({$bytes} bytes)".($noSandbox && ! $sandboxOff ? ' sin sandbox: las exportaciones lo reintentan solas, o define EXPORTS_CHROME_NO_SANDBOX=true para evitar el primer intento.' : '.'));

                return self::SUCCESS;
            } catch (Throwable $exception) {
                $failure = PdfRenderingFailed::from($exception);

                if ($failure->reason === 'sandbox' && ! $noSandbox) {
                    $this->warn('Chrome no pudo iniciar con sandbox; se reintenta sin él.');

                    continue;
                }

                @unlink($target);

                $this->error("No se pudo generar el PDF ({$failure->reason}).");
                $this->line($failure->hint);
                $this->newLine();
                $this->line('Salida de Chrome/Node:');
                $this->line(mb_substr($this->errorOutput($exception), 0, 1500));

                return self::FAILURE;
            }
        }

        return self::FAILURE;
    }

    /**
     * Download the browser puppeteer expects into storage/app/puppeteer, a
     * directory shared by every release and readable by the web server
     * (ChromeLocator looks there).
     *
     * @param  array<string, mixed>  $config
     */
    private function installChrome(array $config): bool
    {
        $cli = rtrim((string) $config['node_modules_path'], '/\\').'/puppeteer/lib/puppeteer/node/cli.js';

        if (! is_file($cli)) {
            $this->error('Falta puppeteer en node_modules: ejecuta "npm ci" primero.');

            return false;
        }

        $target = storage_path('app/puppeteer');
        $this->line("Descargando chrome-headless-shell en {$target} (unos 100 MB)...");

        $process = new Process([(string) $config['node_binary'], $cli, 'browsers', 'install', 'chrome-headless-shell', '--path', $target]);
        $process->setTimeout(900);
        $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));

        if (! $process->isSuccessful()) {
            $this->error('No se pudo descargar Chrome. Instala el paquete del sistema: apt install chromium.');

            return false;
        }

        return true;
    }

    private function errorOutput(Throwable $exception): string
    {
        $message = $exception->getMessage();
        $marker = strpos($message, 'Error Output:');

        return trim($marker === false ? $message : substr($message, $marker));
    }
}
