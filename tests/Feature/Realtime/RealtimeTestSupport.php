<?php

namespace Tests\Feature\Realtime;

use Illuminate\Broadcasting\BroadcastManager;

/**
 * The suite runs with BROADCAST_CONNECTION=null. Real-time tests switch to
 * the Reverb broadcaster with throwaway credentials and an address nobody
 * listens on: channel authorization is signed locally (no network), and
 * publishing fails fast, which is exactly the failure mode to cover.
 */
final class RealtimeTestSupport
{
    public const KEY = 'realtime-test-key';

    public const SECRET = 'realtime-test-secret-value';

    public static function useReverb(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => self::KEY,
            'broadcasting.connections.reverb.secret' => self::SECRET,
            'broadcasting.connections.reverb.app_id' => '1001',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 9,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
            'broadcasting.connections.reverb.client_options' => ['timeout' => 1, 'connect_timeout' => 1],
        ]);

        app(BroadcastManager::class)->forgetDrivers();

        // Channels are registered on the broadcaster resolved at boot (null).
        require base_path('routes/channels.php');
    }
}
