<?php

namespace App\Reports\Rendering;

use RuntimeException;
use Throwable;

/**
 * Chrome could not print a report. It carries a message that is safe to show
 * to the person exporting and a reason code for the administrator; the
 * original error (with paths and the full command) stays in the log.
 */
class PdfRenderingFailed extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $userMessage,
        public readonly string $hint,
        ?Throwable $previous = null,
    ) {
        parent::__construct("PDF rendering failed ({$reason}): {$hint}", 0, $previous);
    }

    public static function from(Throwable $exception): self
    {
        // Only what Chrome/Node printed: the command line itself contains
        // flags such as --no-sandbox that would be mistaken for the cause.
        $message = $exception->getMessage();
        $marker = strpos($message, 'Error Output:');
        $output = $marker === false ? $message : substr($message, $marker);

        [$reason, $hint] = match (true) {
            self::mentions($output, ['Could not find Chrome', 'Could not find chrome', 'Could not find browser', 'Browser was not found', 'executablePath', 'ENOENT']) => ['chrome_missing', 'No hay Chrome/Chromium disponible para la cuenta que ejecuta PHP. Instala Chromium (apt install chromium) o ejecuta "npx puppeteer browsers install chrome-headless-shell" y, si hace falta, define EXPORTS_CHROME_PATH.'],
            self::mentions($output, ['No usable sandbox', 'setuid sandbox', 'sandbox_host', 'zygote_host', 'Running as root without --no-sandbox']) => ['sandbox', 'Chrome no pudo iniciar su sandbox. Define EXPORTS_CHROME_NO_SANDBOX=true.'],
            self::mentions($output, ['error while loading shared libraries', 'cannot open shared object', 'libnss', 'libatk', 'libgbm', 'libasound']) => ['libraries', 'A Chrome le faltan bibliotecas del sistema. Instala el paquete chromium de la distribución (trae sus dependencias) o las bibliotecas que indica el registro.'],
            self::mentions($output, ['Cannot find module', "puppeteer'", 'MODULE_NOT_FOUND']) => ['puppeteer_missing', 'Falta puppeteer en node_modules. Ejecuta "npm ci" en la versión desplegada.'],
            self::mentions($output, ['is not recognized', 'command not found', 'No such file or directory', 'not found']) => ['node_missing', 'No se encontró Node. Define EXPORTS_NODE_BINARY y EXPORTS_NPM_BINARY con rutas absolutas.'],
            self::mentions($output, ['timed out', 'Timeout', 'exceeded the timeout']) => ['timeout', 'Chrome tardó más de lo permitido (EXPORTS_PDF_TIMEOUT). Reduce los filtros del reporte.'],
            default => ['unknown', 'Revisa el registro de la aplicación para ver el error completo de Chrome.'],
        };

        return new self(
            $reason,
            'No se pudo generar el PDF en el servidor. Descarga el reporte en Excel mientras el administrador lo revisa.',
            $hint,
            $exception,
        );
    }

    /**
     * @param  list<string>  $needles
     */
    private static function mentions(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (stripos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
