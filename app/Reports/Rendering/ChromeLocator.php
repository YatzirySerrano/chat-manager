<?php

namespace App\Reports\Rendering;

/**
 * Finds the Chrome/Chromium binary used to print PDFs.
 *
 * EXPORTS_CHROME_PATH wins when it points at an executable. Otherwise the
 * usual system packages are tried, then every browser downloaded by
 * puppeteer (`npx puppeteer browsers install chrome-headless-shell`), also
 * when it was installed by another account than the one running PHP: the
 * web server and the queue workers rarely share HOME with the deploy user,
 * which is the most common reason for "Could not find Chrome".
 */
class ChromeLocator
{
    /**
     * @var list<string>
     */
    private const SYSTEM_PATHS = [
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/google-chrome',
        '/usr/bin/chrome',
        '/usr/local/bin/chromium',
        '/usr/local/bin/chrome',
        '/snap/bin/chromium',
        '/opt/google/chrome/chrome',
        '/usr/lib/chromium/chromium',
        '/usr/lib/chromium-browser/chromium-browser',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    ];

    /**
     * The configured binary, or the first one found; null leaves the choice
     * to puppeteer (its own cache of the running account).
     */
    public function path(): ?string
    {
        $configured = trim((string) config('exports.pdf.chrome_path'));

        if ($configured !== '' && $this->usable($configured)) {
            return $configured;
        }

        foreach ($this->candidates() as $candidate) {
            if ($this->usable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Whether the configured path was given but cannot be executed.
     */
    public function configuredPathIsBroken(): bool
    {
        $configured = trim((string) config('exports.pdf.chrome_path'));

        return $configured !== '' && ! $this->usable($configured);
    }

    /**
     * Every place searched, in order (also shown by exports:pdf-check).
     *
     * @return list<string>
     */
    public function candidates(): array
    {
        $candidates = self::SYSTEM_PATHS;

        foreach ($this->cacheDirectories() as $directory) {
            foreach (['chrome-headless-shell/*/*/chrome-headless-shell', 'chrome/*/*/chrome'] as $pattern) {
                $found = [
                    ...(glob($directory.'/'.$pattern) ?: []),
                    ...(glob($directory.'/'.$pattern.'.exe') ?: []),
                ];

                // Newest downloaded build first.
                rsort($found, SORT_NATURAL);

                $candidates = [...$candidates, ...$found];
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Puppeteer cache directories that may hold a downloaded browser.
     *
     * @return list<string>
     */
    private function cacheDirectories(): array
    {
        $directories = [];

        $explicit = getenv('PUPPETEER_CACHE_DIR');

        if (is_string($explicit) && $explicit !== '') {
            $directories[] = $explicit;
        }

        $directories[] = base_path('.cache/puppeteer');
        $directories[] = storage_path('app/puppeteer');

        foreach ([getenv('HOME'), getenv('USERPROFILE')] as $home) {
            if (is_string($home) && $home !== '') {
                $directories[] = rtrim($home, '/\\').'/.cache/puppeteer';
            }
        }

        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $account = posix_getpwuid(posix_geteuid());

            if (is_array($account) && $account['dir'] !== '') {
                $directories[] = rtrim($account['dir'], '/').'/.cache/puppeteer';
            }
        }

        $directories[] = '/var/www/.cache/puppeteer';
        $directories[] = '/root/.cache/puppeteer';

        foreach (glob('/home/*/.cache/puppeteer') ?: [] as $shared) {
            $directories[] = $shared;
        }

        return array_values(array_unique(array_map(
            fn (string $directory): string => str_replace('\\', '/', $directory),
            $directories,
        )));
    }

    private function usable(string $path): bool
    {
        return @is_file($path) && @is_executable($path);
    }
}
