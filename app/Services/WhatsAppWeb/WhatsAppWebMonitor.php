<?php

namespace App\Services\WhatsAppWeb;

use App\Enums\ConnectionProvider;
use App\Enums\WhatsAppWebState;
use App\Models\Connection;
use App\Services\Operations\HealthCheck;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Technical monitoring of the WhatsApp Web (QR) modality for
 * Administración > Sistema, apart from WhatsApp Cloud API: whether the
 * Node service answers, its version, and the real state of every session
 * (state, time connected, last activity, last inbound and outbound message,
 * reconnections, last error). It never includes message content, the QR or
 * anything of a session's credentials.
 *
 * A session is only reported as able to send when it is connected, the
 * service answers and the connection is active: a running process alone
 * never makes anything green.
 */
class WhatsAppWebMonitor
{
    private const STATUS_CACHE_KEY = 'whatsapp-web:status';

    public function __construct(
        private WhatsAppWebClient $client,
        private WhatsAppWebSessions $sessions,
        private Repository $cache,
    ) {}

    /**
     * @return array{enabled: bool, configured: bool, service: array{available: bool, version: string|null, library: string|null, node: string|null, uptime_seconds: int|null}|null, sessions: list<array<string, mixed>>}
     */
    public function overview(): array
    {
        $connections = $this->connections();
        $service = $this->service($connections);

        return [
            'enabled' => $this->sessions->isEnabled(),
            'configured' => $this->client->isConfigured(),
            'service' => $service,
            'sessions' => array_values($connections->map(function (Connection $connection) use ($service): array {
                $state = $this->sessions->linkState($connection->refresh(), withQr: false);

                return [
                    'connection_id' => $connection->id,
                    'name' => $connection->name,
                    'is_active' => $connection->is_active,
                    'state' => $state['state'],
                    'state_label' => $state['state_label'],
                    'tone' => $state['tone'],
                    'can_send' => $state['can_send'] && ($service['available'] ?? false),
                    'phone' => $state['phone'],
                    'connected_since' => $state['connected_since'],
                    'last_event_at' => $state['last_event_at'],
                    'last_inbound_at' => $state['last_inbound_at'],
                    'last_outbound_at' => $state['last_outbound_at'],
                    'reconnects' => $state['reconnects'],
                    'last_error' => $state['last_error'],
                ];
            })->all()),
        ];
    }

    /**
     * Health check of the modality for the system page and the alerts;
     * null where it is not used (not enabled and no connection of it), so
     * installations without it see nothing new. It is always a warning,
     * never a failure: WhatsApp Web being down does not stop the platform.
     */
    public function healthCheck(): ?HealthCheck
    {
        $connections = $this->connections();

        if (! $this->sessions->isEnabled() && $connections->isEmpty()) {
            return null;
        }

        $label = 'WhatsApp Web (QR)';

        if (! $this->client->isConfigured()) {
            return HealthCheck::warning('whatsapp_web', $label, 'La modalidad está habilitada pero falta el secreto interno (WHATSAPP_WEB_SECRET): no se puede vincular ni enviar.');
        }

        if (! $this->sessions->serviceAvailable()) {
            return HealthCheck::warning('whatsapp_web', $label, 'El servicio de WhatsApp Web no responde en este servidor: las conexiones por QR no reciben ni envían. Pide al equipo técnico que revise el proceso.', ['sessions' => $connections->count()]);
        }

        $active = $connections->where('is_active', true);
        $down = $active->filter(fn (Connection $connection): bool => $connection->webSessionState() !== WhatsAppWebState::Connected);

        if ($down->isNotEmpty()) {
            return HealthCheck::warning('whatsapp_web', $label, 'Hay conexiones activas por QR que no están conectadas: '.$down->pluck('name')->take(5)->implode(', ').'.', [
                'sessions' => $connections->count(),
                'connected' => $active->count() - $down->count(),
            ]);
        }

        return HealthCheck::ok('whatsapp_web', $label, $active->isEmpty() ? 'El servicio responde. No hay conexiones activas por QR.' : 'El servicio responde y las conexiones activas por QR están conectadas.', [
            'sessions' => $connections->count(),
            'connected' => $active->count(),
        ]);
    }

    /**
     * What the service says about itself; null when the modality has no
     * secret (nothing can be asked). The sessions it reports refresh the
     * stored state of their connections.
     *
     * @param  Collection<int, Connection>  $connections
     * @return array{available: bool, version: string|null, library: string|null, node: string|null, uptime_seconds: int|null}|null
     */
    private function service(Collection $connections): ?array
    {
        if (! $this->client->isConfigured()) {
            return null;
        }

        $status = $this->cache->remember(self::STATUS_CACHE_KEY, max(1, (int) config('whatsapp_web.status_cache_seconds', 10)), function (): ?array {
            try {
                return $this->client->status();
            } catch (Throwable) {
                return null;
            }
        });

        if (! is_array($status)) {
            return ['available' => false, 'version' => null, 'library' => null, 'node' => null, 'uptime_seconds' => null];
        }

        foreach ((array) ($status['sessions'] ?? []) as $session) {
            $connection = is_array($session) && is_int($session['connection_id'] ?? null) ? $connections->firstWhere('id', $session['connection_id']) : null;

            if ($connection instanceof Connection) {
                $this->sessions->apply($connection, $session);
            }
        }

        return [
            'available' => true,
            'version' => is_string($status['version'] ?? null) ? $status['version'] : null,
            'library' => is_string($status['library'] ?? null) ? $status['library'] : null,
            'node' => is_string($status['node'] ?? null) ? $status['node'] : null,
            'uptime_seconds' => is_int($status['uptime_seconds'] ?? null) ? $status['uptime_seconds'] : null,
        ];
    }

    /**
     * @return Collection<int, Connection>
     */
    private function connections(): Collection
    {
        return Connection::query()->where('provider', ConnectionProvider::WhatsappWeb)->notDeleted()->orderBy('name')->get();
    }
}
