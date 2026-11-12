<?php

namespace App\Services\WhatsAppWeb;

use App\Actions\Connections\ChangeConnectionStatus;
use App\Enums\AuditAction;
use App\Enums\WhatsAppWebState;
use App\Events\Connections\ConnectionHealthChanged;
use App\Events\Connections\ConnectionStatusChanged;
use App\Models\Connection;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\PhoneNumber;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The WhatsApp Web (QR) session of a connection, as ChatManager knows it.
 *
 * The Node service owns the socket and the credentials; this class keeps
 * what it reports on the connection (state, linked phone, last error), so
 * lists, the composer and the health of the connection reflect the real
 * session, and runs the administrator's actions against the service:
 *
 * - link: generate a QR (only when no phone is linked);
 * - refresh: ask for a new QR after the previous one expired;
 * - disconnect: stop the session and keep the phone linked (or cancel a
 *   link in progress);
 * - reconnect: start it again without a QR;
 * - logout: close the session of the device (a new QR is needed);
 * - forget: remove everything when the connection is deleted.
 *
 * Nothing here invents a state: what is stored is what the service said,
 * and a service that cannot be reached is reported as such. The QR only
 * lives in the cache until it expires; it is never stored in the database,
 * logged or sent to anyone who cannot manage the connection.
 */
class WhatsAppWebSessions
{
    private const QR_CACHE_PREFIX = 'whatsapp-web:qr:';

    private const ALIVE_CACHE_KEY = 'whatsapp-web:alive';

    public function __construct(
        private WhatsAppWebClient $client,
        private Repository $cache,
        private AuditLogger $audit,
    ) {}

    /**
     * Whether the modality is offered in the interface.
     */
    public function isEnabled(): bool
    {
        return (bool) config('whatsapp_web.enabled', false);
    }

    /**
     * Whether the service process answers (cached for a few seconds). It
     * says nothing about any session.
     */
    public function serviceAvailable(bool $fresh = false): bool
    {
        if (! $this->client->isConfigured()) {
            return false;
        }

        if ($fresh) {
            $this->cache->forget(self::ALIVE_CACHE_KEY);
        }

        return (bool) $this->cache->remember(self::ALIVE_CACHE_KEY, max(1, (int) config('whatsapp_web.status_cache_seconds', 10)), fn (): bool => $this->client->isAlive());
    }

    /**
     * Start linking a phone: the service opens the session and reports a QR.
     *
     * @throws ValidationException When the session cannot be linked right now.
     */
    public function link(Connection $connection, User $actor): Connection
    {
        $this->assertManageable($connection);

        if ($connection->isConfigured()) {
            throw ValidationException::withMessages(['session' => 'Ya hay un teléfono vinculado a esta conexión. Cierra la sesión del dispositivo antes de vincular otro.']);
        }

        $payload = $this->call(fn (): array => $this->client->start($connection->id, 'link'));

        $connection->forceFill(['web_session_link_user_id' => $actor->id])->save();
        $this->audit->record(AuditAction::ConnectionWebLinkRequested, $connection, [], $actor);

        return $this->apply($connection, $payload);
    }

    /**
     * A new QR for a link whose code expired without being scanned.
     */
    public function refresh(Connection $connection, User $actor): Connection
    {
        $this->assertManageable($connection);

        if ($connection->isConfigured()) {
            throw ValidationException::withMessages(['session' => 'Esta conexión ya tiene un teléfono vinculado: no necesita un código QR.']);
        }

        $payload = $this->call(fn (): array => $this->client->start($connection->id, 'link'));

        if ($connection->web_session_link_user_id === null) {
            $connection->forceFill(['web_session_link_user_id' => $actor->id])->save();
        }

        return $this->apply($connection, $payload);
    }

    /**
     * Stop the session on purpose. A linked phone stays linked; a link in
     * progress is cancelled.
     */
    public function disconnect(Connection $connection, User $actor): Connection
    {
        $this->assertManageable($connection);

        $wasLinked = $connection->isConfigured();
        $payload = $this->call(fn (): array => $this->client->stop($connection->id));

        $this->audit->record(AuditAction::ConnectionWebDisconnected, $connection, ['cancelled_link' => ! $wasLinked], $actor);

        return $this->apply($connection, $payload, 'requested');
    }

    /**
     * Start a stopped (or failed) session again with the stored credentials.
     */
    public function reconnect(Connection $connection, User $actor): Connection
    {
        $this->assertManageable($connection);

        if (! $connection->isConfigured()) {
            throw ValidationException::withMessages(['session' => 'No hay un teléfono vinculado que reconectar: genera un código QR y escanéalo.']);
        }

        try {
            $payload = $this->client->start($connection->id, 'restore');
        } catch (WhatsAppWebException $exception) {
            if (in_array($exception->kind, [WhatsAppWebException::NOT_LINKED, WhatsAppWebException::UNKNOWN_SESSION], true)) {
                // The service no longer has the credentials: the phone must be linked again.
                $this->apply($connection, ['state' => WhatsAppWebState::LoggedOut->value], 'session_missing');
            }

            throw ValidationException::withMessages(['session' => $exception->getMessage()]);
        }

        $this->audit->record(AuditAction::ConnectionWebReconnectRequested, $connection, [], $actor);

        return $this->apply($connection, $payload);
    }

    /**
     * Close the session of the linked device. The conversations and their
     * history are kept; a new QR is needed to use the number again.
     */
    public function logout(Connection $connection, User $actor): Connection
    {
        $this->assertManageable($connection);

        try {
            $payload = $this->client->logout($connection->id);
        } catch (WhatsAppWebException $exception) {
            if ($exception->kind !== WhatsAppWebException::UNKNOWN_SESSION) {
                throw ValidationException::withMessages(['session' => $exception->getMessage()]);
            }

            // Nothing to close in the service: the session is gone already.
            $payload = ['state' => WhatsAppWebState::LoggedOut->value];
        }

        $this->audit->record(AuditAction::ConnectionWebLoggedOut, $connection, ['phone' => $connection->web_session_phone], $actor);

        return $this->apply($connection, [...$payload, 'state' => WhatsAppWebState::LoggedOut->value], 'requested');
    }

    /**
     * The connection was deleted: the service closes the session and
     * removes its credentials. Best effort (the scheduled synchronization
     * removes it later when the service cannot be reached now); the
     * conversations and history of the connection are never touched.
     */
    public function forget(Connection $connection): void
    {
        $this->forgetQr($connection->id);

        try {
            $this->client->delete($connection->id);
        } catch (WhatsAppWebException $exception) {
            if ($exception->kind !== WhatsAppWebException::UNKNOWN_SESSION) {
                Log::warning('The WhatsApp Web session of a deleted connection could not be removed now; the synchronization will retry.', [
                    'connection_id' => $connection->id,
                    'kind' => $exception->kind,
                ]);
            }
        }

        Connection::query()->whereKey($connection->id)->update([
            'web_session_state' => WhatsAppWebState::Unconfigured->value,
            'web_session_phone' => null,
            'web_session_push_name' => null,
            'web_session_reason' => 'deleted',
            'web_session_error_code' => null,
            'web_session_error' => null,
            'web_session_linked_at' => null,
            'web_session_connected_at' => null,
            'web_session_link_user_id' => null,
        ]);
    }

    /**
     * Ask the service for the session right now and store what it answers.
     * Returns whether the service answered.
     */
    public function sync(Connection $connection): bool
    {
        if (! $connection->isWhatsAppWeb() || $connection->isDeleted()) {
            return false;
        }

        try {
            $payload = $this->client->session($connection->id);
        } catch (WhatsAppWebException $exception) {
            if ($exception->kind === WhatsAppWebException::UNKNOWN_SESSION) {
                $this->apply($connection, ['state' => $connection->isConfigured() ? WhatsAppWebState::LoggedOut->value : WhatsAppWebState::Unconfigured->value], $connection->isConfigured() ? 'session_missing' : null);

                return true;
            }

            return false;
        }

        $this->apply($connection, $payload);

        return true;
    }

    /**
     * Store a Session payload of the contract (from an event, an answer of
     * the control API or a synchronization) on the connection.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function apply(Connection $connection, array $payload, ?string $reason = null): Connection
    {
        $state = WhatsAppWebState::tryFrom(is_string($payload['state'] ?? null) ? $payload['state'] : '');

        if ($state === null || ! $connection->isWhatsAppWeb()) {
            return $connection;
        }

        $eventAt = self::moment($payload['last_event_at'] ?? null);
        $reason ??= is_string($payload['reason'] ?? null) ? Str::limit($payload['reason'], 40, '') : null;
        $qr = is_array($payload['qr'] ?? null) ? $payload['qr'] : null;
        $activateFor = null;
        $lost = false;

        $updated = DB::transaction(function () use ($connection, $payload, $state, $eventAt, $reason, &$activateFor, &$lost): ?Connection {
            $current = Connection::query()->whereKey($connection->id)->lockForUpdate()->first();

            if ($current === null || $current->isDeleted()) {
                return null;
            }

            // A report older than the last one stored never moves the session back.
            if ($eventAt !== null && $current->web_session_event_at !== null && $eventAt->lessThan($current->web_session_event_at)) {
                return $current;
            }

            $previous = $current->webSessionState() ?? WhatsAppWebState::Unconfigured;
            $previousHealth = $current->health();
            $wasLinked = $current->isConfigured();

            // "Disconnected" without a linked phone is a cancelled link.
            if ($state === WhatsAppWebState::Disconnected && ! $wasLinked) {
                $state = WhatsAppWebState::Unconfigured;
            }

            $phone = self::digits($payload['phone'] ?? null);
            $error = is_array($payload['last_error'] ?? null) ? $payload['last_error'] : null;
            $errorCode = is_string($error['code'] ?? null) ? Str::limit($error['code'], 60, '') : null;

            $attributes = [
                'web_session_state' => $state,
                'web_session_reason' => $reason,
                'web_session_event_at' => $eventAt ?? now(),
                'web_session_reconnects' => max(0, (int) ($payload['reconnects'] ?? $current->web_session_reconnects)),
            ];

            if ($state === WhatsAppWebState::Connected) {
                $attributes += [
                    'web_session_phone' => $phone ?? $current->web_session_phone,
                    'web_session_push_name' => self::name($payload['push_name'] ?? null) ?? $current->web_session_push_name,
                    'web_session_linked_at' => $current->web_session_linked_at ?? now(),
                    'web_session_connected_at' => self::moment($payload['connected_since'] ?? null) ?? ($previous === WhatsAppWebState::Connected ? $current->web_session_connected_at : now()),
                    'web_session_error_code' => null,
                    'web_session_error' => null,
                ];

                if (($phone ?? $current->web_session_phone) !== null) {
                    $attributes['display_phone_number'] = PhoneNumber::format(PhoneNumber::normalize('+'.($phone ?? $current->web_session_phone)) ?? '+'.($phone ?? $current->web_session_phone));
                }
            } elseif (! $state->isLinked()) {
                // No phone is linked any more (never linked, revoked, QR expired).
                $attributes += [
                    'web_session_phone' => null,
                    'web_session_push_name' => null,
                    'web_session_linked_at' => null,
                    'web_session_connected_at' => null,
                ];
            } else {
                $attributes['web_session_connected_at'] = null;
            }

            if ($state !== WhatsAppWebState::Connected) {
                $message = self::explain($state, $reason, $errorCode, is_string($error['message'] ?? null) ? $error['message'] : null);
                $attributes['web_session_error_code'] = $message === null ? null : ($errorCode ?? $reason);
                $attributes['web_session_error'] = $message;
            }

            // The pending request for a link ends when the link does.
            if ($state === WhatsAppWebState::Connected || in_array($state, [WhatsAppWebState::LoggedOut, WhatsAppWebState::Unconfigured, WhatsAppWebState::Error], true)) {
                if ($state === WhatsAppWebState::Connected && ! $wasLinked && $current->web_session_link_user_id !== null && ! $current->is_active) {
                    $activateFor = $current->web_session_link_user_id;
                }

                $attributes['web_session_link_user_id'] = null;
            }

            $current->forceFill($attributes)->save();

            $nowLinked = $current->isConfigured();
            $lost = $wasLinked && ! $nowLinked && $reason !== 'requested' && $reason !== 'deleted';

            if ($state !== $previous) {
                // Open conversations refresh their composer (it may send or not).
                ConnectionStatusChanged::dispatch($current->id, 'web_session');

                if (! $wasLinked && $nowLinked) {
                    $this->audit->record(AuditAction::ConnectionWebLinked, $current, ['phone' => $current->web_session_phone]);
                }

                if ($lost) {
                    $this->audit->record(AuditAction::ConnectionWebSessionLost, $current, ['reason' => $reason, 'previous_state' => $previous->value]);
                }
            }

            $health = $current->health();

            if ($health !== $previousHealth) {
                ConnectionHealthChanged::dispatch($current->id, $health->value, $previousHealth->value, $current->web_session_error);
            }

            return $current;
        });

        if ($updated === null) {
            return $connection;
        }

        $this->rememberQr($updated, $qr);

        if ($activateFor !== null) {
            $this->activateAfterLink($updated, $activateFor);
        }

        $connection->setRawAttributes($updated->fresh()?->getAttributes() ?? $updated->getAttributes(), true);

        return $connection;
    }

    /**
     * LinkState of the contract (§8) for the interface.
     *
     * @return array<string, mixed>
     */
    public function linkState(Connection $connection, bool $withQr): array
    {
        $state = $connection->webSessionState() ?? WhatsAppWebState::Unconfigured;
        $available = $this->serviceAvailable();
        $linked = $connection->isConfigured();
        $manageable = ! $connection->isDeleted();
        $qr = $withQr && $state === WhatsAppWebState::QrPending ? $this->qr($connection->id) : null;

        return [
            'state' => $state->value,
            'state_label' => $state->label(),
            'state_description' => $state->description(),
            'tone' => $state->tone(),
            // A linked phone only sends while its session is connected, the
            // service answers and the connection is active.
            'can_send' => $state->canSend() && $available && $connection->isOperational(),
            'service_available' => $available,
            'phone' => $connection->web_session_phone === null ? null : PhoneNumber::format(PhoneNumber::normalize('+'.$connection->web_session_phone) ?? '+'.$connection->web_session_phone),
            'push_name' => $connection->web_session_push_name,
            'qr' => $qr,
            'linked_at' => $connection->web_session_linked_at?->toIso8601String(),
            'connected_since' => $connection->web_session_connected_at?->toIso8601String(),
            'last_event_at' => $connection->web_session_event_at?->toIso8601String(),
            'last_inbound_at' => $connection->last_inbound_at?->toIso8601String(),
            'last_outbound_at' => $connection->last_outbound_at?->toIso8601String(),
            'reconnects' => $connection->web_session_reconnects,
            'last_error' => $state === WhatsAppWebState::Connected ? null : $connection->web_session_error,
            'actions' => [
                'link' => $manageable && ! $linked && ! $state->isTransitional(),
                'refresh' => $manageable && ! $linked && in_array($state, [WhatsAppWebState::Starting, WhatsAppWebState::QrPending], true),
                'disconnect' => $manageable && (in_array($state, [WhatsAppWebState::Connected, WhatsAppWebState::Reconnecting], true) || (! $linked && $state->isTransitional())),
                'reconnect' => $manageable && $linked && in_array($state, [WhatsAppWebState::Disconnected, WhatsAppWebState::Error], true),
                'logout' => $manageable && $linked,
            ],
        ];
    }

    /**
     * The QR in force as an SVG, or null when none is pending or it expired.
     *
     * @return array{svg: string, expires_at: string, seconds_left: int}|null
     */
    public function qr(int $connectionId): ?array
    {
        $stored = $this->cache->get(self::QR_CACHE_PREFIX.$connectionId);

        if (! is_array($stored) || ! is_string($stored['value'] ?? null) || ! is_int($stored['expires_at'] ?? null)) {
            return null;
        }

        $left = $stored['expires_at'] - now()->getTimestamp();

        if ($left <= 0) {
            return null;
        }

        return [
            'svg' => self::svg($stored['value']),
            'expires_at' => CarbonImmutable::createFromTimestampUTC($stored['expires_at'])->toIso8601String(),
            'seconds_left' => $left,
        ];
    }

    public function forgetQr(int $connectionId): void
    {
        $this->cache->forget(self::QR_CACHE_PREFIX.$connectionId);
    }

    /**
     * @param  array<array-key, mixed>|null  $qr
     */
    private function rememberQr(Connection $connection, ?array $qr): void
    {
        if ($connection->webSessionState() !== WhatsAppWebState::QrPending) {
            $this->forgetQr($connection->id);

            return;
        }

        $value = $qr['value'] ?? null;
        $expiresAt = $qr['expires_at'] ?? null;

        if (! is_string($value) || $value === '' || strlen($value) > 2048 || ! is_int($expiresAt)) {
            return;
        }

        $seconds = $expiresAt - now()->getTimestamp();

        if ($seconds <= 0) {
            $this->forgetQr($connection->id);

            return;
        }

        $this->cache->put(self::QR_CACHE_PREFIX.$connection->id, ['value' => $value, 'expires_at' => $expiresAt], min($seconds, 300));
    }

    /**
     * The phone finished linking: the connection is activated in the name
     * of the administrator who asked for the link (audited like any other
     * activation). A failure here never undoes the link.
     */
    private function activateAfterLink(Connection $connection, int $userId): void
    {
        $actor = User::query()->find($userId);

        if ($actor === null || ! $actor->can('changeStatus', $connection)) {
            return;
        }

        try {
            app(ChangeConnectionStatus::class)->handle($connection, true, $actor);
        } catch (Throwable $exception) {
            Log::warning('The WhatsApp Web connection could not be activated after linking.', [
                'connection_id' => $connection->id,
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * @param  callable(): array<array-key, mixed>  $call
     * @return array<array-key, mixed>
     *
     * @throws ValidationException With the reason in Spanish.
     */
    private function call(callable $call): array
    {
        try {
            return $call();
        } catch (WhatsAppWebException $exception) {
            throw ValidationException::withMessages(['session' => $exception->getMessage()]);
        }
    }

    private function assertManageable(Connection $connection): void
    {
        if (! $connection->isWhatsAppWeb()) {
            abort(404);
        }

        if ($connection->isDeleted()) {
            throw ValidationException::withMessages(['session' => 'La conexión está eliminada. Restáurala antes de vincular un teléfono.']);
        }
    }

    /**
     * Readable reason of a session that is not connected; null when there
     * is nothing to explain (never linked, stopped on purpose, linking).
     */
    private static function explain(WhatsAppWebState $state, ?string $reason, ?string $code, ?string $message): ?string
    {
        $known = match ($reason ?? $code) {
            'qr_timeout' => 'El código QR caducó sin que nadie lo escaneara. Genera uno nuevo.',
            'logged_out_from_phone' => 'La sesión se cerró desde el teléfono (WhatsApp → Dispositivos vinculados). Hay que vincularlo de nuevo.',
            'replaced' => 'Otra sesión de WhatsApp Web reemplazó a esta. Vincula el teléfono de nuevo.',
            'banned' => 'WhatsApp bloqueó o suspendió este número. Revisa la cuenta en el teléfono antes de volver a vincularlo.',
            'bad_session' => 'La sesión guardada de este teléfono quedó inservible. Hay que vincularlo de nuevo.',
            'network' => 'No hay conexión con los servidores de WhatsApp.',
            'session_missing' => 'El servicio ya no tiene la sesión de este teléfono. Hay que vincularlo de nuevo.',
            'service_unreachable' => 'El servicio de WhatsApp Web no responde en este servidor.',
            default => null,
        };

        if ($known !== null) {
            return $known;
        }

        if (in_array($state, [WhatsAppWebState::Error, WhatsAppWebState::Reconnecting, WhatsAppWebState::LoggedOut], true) && $reason !== 'requested') {
            $detail = $message === null ? '' : Str::limit(Str::squish(strip_tags($message)), 160, '…');

            return match ($state) {
                WhatsAppWebState::LoggedOut => 'WhatsApp cerró la sesión de este dispositivo. Hay que vincular el teléfono de nuevo.',
                WhatsAppWebState::Reconnecting => 'Se perdió la conexión con WhatsApp; se está reintentando.',
                default => trim('La sesión de WhatsApp Web no pudo recuperarse. '.($detail === '' ? '' : "Detalle del servicio: {$detail}")),
            };
        }

        return null;
    }

    private static function svg(string $value): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(280, 2), new SvgImageBackEnd)))->writeString($value);

        return trim((string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg));
    }

    private static function digits(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

        return $digits === '' || strlen($digits) > 20 ? null : $digits;
    }

    private static function name(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $name = Str::squish(preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', strip_tags($value)) ?? '');

        return $name === '' ? null : Str::limit($name, 120, '');
    }

    private static function moment(mixed $value): ?CarbonImmutable
    {
        return is_int($value) && $value > 0 ? CarbonImmutable::createFromTimestamp($value) : null;
    }
}
