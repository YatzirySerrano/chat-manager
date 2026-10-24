<?php

namespace App\Services\WebChat;

use App\Models\WebChatSession;

/**
 * What the widget frame needs to open its real-time connection: the same
 * Reverb application as the agents (its key is public by design) and the
 * single private channel of the visitor's session, authorized only by
 * POST /api/v1/widget/broadcasting/auth with the visitor's bearer token.
 * Disabled when broadcasting is off; the widget then relies on polling.
 */
class VisitorRealtimeConfig
{
    /**
     * @return array{enabled: bool, key: string|null, host: string|null, port: int|null, scheme: string|null, path: string|null, channel: string|null, auth_endpoint: string|null}
     */
    public function for(WebChatSession $session): array
    {
        $key = config('broadcasting.connections.reverb.key');

        if (config('broadcasting.default') !== 'reverb' || ! is_string($key) || $key === '') {
            return [
                'enabled' => false,
                'key' => null,
                'host' => null,
                'port' => null,
                'scheme' => null,
                'path' => null,
                'channel' => null,
                'auth_endpoint' => null,
            ];
        }

        $client = (array) config('broadcasting.realtime.client');
        $scheme = ($client['scheme'] ?? 'https') === 'http' ? 'http' : 'https';
        $host = is_string($client['host'] ?? null) && $client['host'] !== '' ? $client['host'] : null;
        $path = is_string($client['path'] ?? null) ? trim($client['path'], '/') : '';

        return [
            'enabled' => true,
            'key' => $key,
            'host' => $host,
            'port' => is_numeric($client['port'] ?? null) ? (int) $client['port'] : ($scheme === 'https' ? 443 : 80),
            'scheme' => $scheme,
            'path' => $path === '' ? null : '/'.$path,
            'channel' => 'private-'.$session->channelName(),
            'auth_endpoint' => route('api.v1.widget.broadcasting.auth'),
        ];
    }
}
