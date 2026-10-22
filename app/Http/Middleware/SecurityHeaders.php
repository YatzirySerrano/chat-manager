<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser hardening headers on every response. The Content-Security-Policy
 * is only sent when enabled (default: production) and never while the Vite
 * development server is running, whose scripts come from another origin.
 * Scripts need the per-request nonce (Vite tags and the inline appearance
 * script carry it); inline styles stay allowed because the interface sets
 * style attributes at runtime.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = null;

        if ($this->cspEnabled()) {
            $nonce = Vite::useCspNonce();
        }

        /** @var Response $response */
        $response = $next($request);
        $headers = $response->headers;

        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), geolocation=(), payment=(), usb=(), microphone=(self)',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        foreach ($defaults as $name => $value) {
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        }

        if (app()->isProduction() && $request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age='.max(0, (int) config('operations.http.hsts_max_age', 31536000)).'; includeSubDomains');
        }

        if ($nonce !== null && ! $headers->has('Content-Security-Policy') && str_contains((string) $headers->get('Content-Type', 'text/html'), 'text/html')) {
            $headers->set('Content-Security-Policy', $this->policy($nonce));
        }

        return $response;
    }

    private function cspEnabled(): bool
    {
        $configured = config('operations.http.csp');
        $enabled = $configured === null || $configured === '' ? app()->isProduction() : filter_var($configured, FILTER_VALIDATE_BOOLEAN);

        return $enabled && ! Vite::isRunningHot();
    }

    public function policy(string $nonce): string
    {
        $connect = ["'self'", ...$this->realtimeOrigins()];

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "media-src 'self' blob:",
            'connect-src '.implode(' ', array_unique($connect)),
            "worker-src 'self' blob:",
            "frame-src 'self'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }

    /**
     * WebSocket origins browsers use to reach Reverb (config/broadcasting.php
     * "realtime.client"), when real time is enabled.
     *
     * @return list<string>
     */
    private function realtimeOrigins(): array
    {
        if (config('broadcasting.default') !== 'reverb') {
            return [];
        }

        $host = (string) config('broadcasting.realtime.client.host');

        if ($host === '' || preg_match('/^[A-Za-z0-9.-]+$/', $host) !== 1) {
            return [];
        }

        $secure = config('broadcasting.realtime.client.scheme') === 'https';
        $port = (int) config('broadcasting.realtime.client.port');
        $suffix = $port > 0 && ! in_array($port, [80, 443], true) ? ":{$port}" : '';

        return [($secure ? 'wss://' : 'ws://').$host.$suffix];
    }
}
