<?php

namespace App\Actions\Connections;

use App\Enums\ConnectionChannel;
use App\Models\Connection;
use App\Services\Meta\ConnectionMonitor;
use App\Services\Meta\MetaApp;
use App\Services\Meta\MetaGraphException;
use App\Services\Meta\MetaPlatformClient;
use Illuminate\Validation\ValidationException;

/**
 * Automatic check of one active, configured connection against Meta
 * (whatsapp:maintenance check-connections): are the stored credentials still
 * accepted, and is the app still subscribed to the webhooks of the account?
 *
 * - WhatsApp: the same verification an administrator runs by hand
 *   (VerifyConnectionCredentials) and, when it passes, the subscription check
 *   (ManageAppSubscription).
 * - Messenger / Instagram: one call answers both (the subscription of the
 *   page or account to this app).
 *
 * Only an answer from Meta changes what is recorded. A timeout, a 5xx or a
 * throttled request is "could not be checked": the previous evidence, the
 * health and the alerts stay as they were, and only the outcome of the
 * attempt is noted so the interface can say so.
 */
class CheckConnectionWithMeta
{
    public const VERIFIED = 'verified';

    public const REJECTED = 'rejected';

    public const UNREACHABLE = 'unreachable';

    public const RATE_LIMITED = 'rate_limited';

    public const SKIPPED = 'skipped';

    public function __construct(
        private VerifyConnectionCredentials $verify,
        private ManageAppSubscription $subscription,
        private MetaPlatformClient $platform,
        private MetaApp $app,
        private ConnectionMonitor $monitor,
    ) {}

    /**
     * @return 'verified'|'rejected'|'unreachable'|'rate_limited'|'skipped'
     */
    public function handle(Connection $connection): string
    {
        if (! $connection->isOperational() || ! $connection->hasRequiredCredentials()) {
            return self::SKIPPED;
        }

        $outcome = match ($connection->channel) {
            ConnectionChannel::Whatsapp => $this->whatsapp($connection),
            ConnectionChannel::Messenger, ConnectionChannel::Instagram => $this->metaAccount($connection),
            ConnectionChannel::WebChat => self::SKIPPED,
        };

        if ($outcome !== self::SKIPPED) {
            $this->monitor->checked($connection, $outcome);
        }

        return $outcome;
    }

    /**
     * @return 'verified'|'rejected'|'unreachable'|'rate_limited'|'skipped'
     */
    private function whatsapp(Connection $connection): string
    {
        try {
            $verification = $this->verify->handle($connection);
        } catch (ValidationException) {
            // The credentials were replaced while Meta answered: the next run checks the new ones.
            return self::SKIPPED;
        }

        if (! $verification->verified()) {
            return match (true) {
                $verification->result === CredentialVerification::REJECTED => self::REJECTED,
                $verification->failureKind === MetaGraphException::RATE_LIMITED => self::RATE_LIMITED,
                default => self::UNREACHABLE,
            };
        }

        try {
            $this->subscription->check($connection);
        } catch (MetaGraphException $exception) {
            // A rejected token was already recorded by the check itself; any
            // other failure leaves the subscription as it was last known.
            return match (true) {
                $exception->kind === MetaGraphException::TOKEN_INVALID => self::REJECTED,
                $exception->kind === MetaGraphException::RATE_LIMITED => self::RATE_LIMITED,
                default => self::VERIFIED,
            };
        } catch (ValidationException) {
            return self::VERIFIED;
        }

        return self::VERIFIED;
    }

    /**
     * @return 'verified'|'rejected'|'unreachable'|'rate_limited'|'skipped'
     */
    private function metaAccount(Connection $connection): string
    {
        // Without the app of this installation nothing can be asked to Meta.
        if (! $this->app->isConfiguredFor($connection->channel)) {
            return self::SKIPPED;
        }

        try {
            $state = $this->platform->subscription($connection->channel, (string) $connection->external_account_id, (string) $connection->access_token);
        } catch (MetaGraphException $exception) {
            if ($exception->kind === MetaGraphException::RATE_LIMITED) {
                return self::RATE_LIMITED;
            }

            if ($exception->kind === MetaGraphException::TOKEN_INVALID) {
                $this->monitor->credentialsRejected($connection, $exception->getMessage());

                return self::REJECTED;
            }

            // Unreachable, temporary, or a refusal that does not prove the
            // token is wrong: nothing is concluded.
            return self::UNREACHABLE;
        }

        $this->monitor->credentialsConfirmed($connection, $state['subscribed']);

        return self::VERIFIED;
    }
}
