<?php

namespace App\Broadcasting;

use App\Broadcasting\TeamChat\TeamPresenceChannel;
use App\Models\User;

/**
 * What the browser needs to open the real-time connection, shared with
 * every Inertia page as the "realtime" prop. Only public values: the Reverb
 * application key is public by design (it identifies the application, the
 * secret signs the channel authorizations and never leaves the server).
 *
 * Reading the values from the server instead of VITE_* variables means the
 * same build works in every environment, real-time can be switched off
 * without rebuilding, and the channels of the user follow their current
 * permissions on every page load.
 */
class RealtimeClientConfig
{
    public function __construct(
        private RealtimeAudience $audience,
        private TeamPresenceChannel $teamPresence,
    ) {}

    public function isEnabled(): bool
    {
        return config('broadcasting.default') === 'reverb'
            && filled(config('broadcasting.connections.reverb.key'));
    }

    /**
     * @return array{enabled: bool, key: string|null, host: string|null, port: int|null, scheme: string|null, path: string|null, user_channel: string|null, inbox_channels: list<string>, presence_channels: list<string>}
     */
    public function for(?User $user): array
    {
        if ($user === null || ! $this->isEnabled()) {
            return [
                'enabled' => false,
                'key' => null,
                'host' => null,
                'port' => null,
                'scheme' => null,
                'path' => null,
                'user_channel' => null,
                'inbox_channels' => [],
                'presence_channels' => [],
            ];
        }

        $client = (array) config('broadcasting.realtime.client');
        $scheme = ($client['scheme'] ?? 'https') === 'http' ? 'http' : 'https';
        $host = is_string($client['host'] ?? null) && $client['host'] !== '' ? $client['host'] : null;
        $path = is_string($client['path'] ?? null) ? trim($client['path'], '/') : '';

        return [
            'enabled' => true,
            'key' => (string) config('broadcasting.connections.reverb.key'),
            'host' => $host,
            'port' => is_numeric($client['port'] ?? null) ? (int) $client['port'] : ($scheme === 'https' ? 443 : 80),
            'scheme' => $scheme,
            'path' => $path === '' ? null : '/'.$path,
            'user_channel' => RealtimeChannels::user($user->id),
            'inbox_channels' => $this->audience->inboxChannels($user),
            'presence_channels' => $this->teamPresence->channelsFor($user),
        ];
    }
}
