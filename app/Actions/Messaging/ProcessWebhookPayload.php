<?php

namespace App\Actions\Messaging;

use App\Actions\Conversations\ProcessInboundMessages;
use App\Actions\Templates\RecordTemplateUpdate;
use App\Models\Connection;
use App\Services\Meta\ConnectionMonitor;
use App\Services\Meta\MetaErrorCatalog;
use App\Services\Meta\MetaInboundPayload;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Handles every change of an authenticated webhook body received through a
 * connection's URL, idempotently (a body processed twice changes nothing):
 *
 * - messages: customer messages (ProcessInboundMessages) and statuses of
 *   outbound messages (RecordMessageStatus), only for the connection's own
 *   phone number id; change-level errors are recorded on the connection;
 * - message_template_status_update / message_template_quality_update:
 *   templates of the connection's business account;
 * - phone_number_quality_update / account_update and other fields: kept as
 *   provider signals; account bans or restrictions become a connection error.
 *
 * Changes about another number or business account than the connection's
 * are never attached to it. With several numbers under the same Meta app
 * they are handed to the connection they belong to, when it provably shares
 * the app (RouteWebhookChanges, ADR-META-12), and processed there with these
 * same rules; the rest is refused and left as a visible warning on the
 * receiving connection (no secrets, no message content).
 * A body delivered again (a Meta retry, or a replay after the webhook log
 * was pruned) changes nothing: messages are unique by wamid, statuses and
 * template updates never go back in time, and connection errors or signals
 * older than whatsapp.webhooks.max_age_days are not applied again.
 *
 * Each connection of a re-routed body is isolated from the others: a
 * failure applying the changes of one (the receiving connection or a
 * sibling) never skips the remaining ones nor the bookkeeping of what was
 * refused. The first failure is thrown once everything else was applied,
 * so the job is retried; the others are reported. The retry applies the
 * whole body again, which is safe for the reasons above, and a connection
 * error already recorded from the same entry is not recorded again
 * (ConnectionMonitor::failure() with the entry time as reference). The
 * "last notice" of a signal is simply rewritten with the same value.
 *
 * A field name can never address the notes the platform itself keeps in
 * `provider_signals` (isReserved()): such a change is ignored.
 */
class ProcessWebhookPayload
{
    public function __construct(
        private ProcessInboundMessages $inbound,
        private RecordMessageStatus $statuses,
        private RecordTemplateUpdate $templates,
        private ConnectionMonitor $monitor,
        private RouteWebhookChanges $router,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array{messages: int, statuses: int, templates: int, signals: int, ignored: int, rerouted?: int, refused?: int}
     */
    public function handle(Connection $connection, array $payload): array
    {
        $routes = $this->router->handle($connection, $payload);
        $summary = ['messages' => 0, 'statuses' => 0, 'templates' => 0, 'signals' => 0, 'ignored' => 0];
        $failure = null;

        try {
            $summary = $this->apply($connection, $routes['own']);
        } catch (Throwable $exception) {
            // Without siblings nor refusals there is nothing left to protect.
            if ($routes['siblings'] === [] && $routes['refused'] === []) {
                throw $exception;
            }

            $failure = $this->isolate($failure, $exception, $connection, $connection);
        }

        foreach ($routes['siblings'] as ['connection' => $sibling, 'payload' => $changes]) {
            try {
                // The evidence belongs to the connection that owns the number.
                $this->monitor->webhookReceived($sibling);
                $handled = $this->apply($sibling, $changes);
            } catch (Throwable $exception) {
                $failure = $this->isolate($failure, $exception, $connection, $sibling);

                continue;
            }

            $summary['rerouted'] = ($summary['rerouted'] ?? 0) + $handled['messages'] + $handled['statuses'] + $handled['templates'] + $handled['signals'];

            Log::info('Webhook changes were handed to the connection of their number (same Meta app).', [
                'connection_id' => $connection->id,
                'owner_connection_id' => $sibling->id,
            ]);
        }

        foreach ($routes['refused'] as $refusal) {
            $summary['refused'] = ($summary['refused'] ?? 0) + 1;

            Log::warning('Webhook changes of a number or account that cannot be attributed were refused.', [
                'connection_id' => $connection->id,
                'kind' => $refusal['kind'],
                'reason' => $refusal['reason'],
            ]);

            // A replay of an old delivery does not bring the warning back.
            if (! $this->isStale($refusal['entry_time'])) {
                $this->monitor->unattributed($connection, $refusal['kind'], $refusal['identifier'], $refusal['reason']);
            }
        }

        // Everything else was applied: now the job may fail and be retried.
        if ($failure !== null) {
            throw $failure;
        }

        return $summary;
    }

    /**
     * Keep the first failure to throw it at the end (the queue reports that
     * one) and report the following ones here, so none is lost and none is
     * reported twice. No payload is logged.
     */
    private function isolate(?Throwable $first, Throwable $exception, Connection $receiving, Connection $owner): Throwable
    {
        Log::error('Applying the webhook changes of a connection failed; the other connections of the delivery are still processed.', [
            'connection_id' => $receiving->id,
            'owner_connection_id' => $owner->id,
            'exception' => $exception::class,
        ]);

        if ($first === null) {
            return $exception;
        }

        report($exception);

        return $first;
    }

    /**
     * Every change of the body, for the connection they all belong to.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array{messages: int, statuses: int, templates: int, signals: int, ignored: int}
     */
    private function apply(Connection $connection, array $payload): array
    {
        $summary = ['messages' => 0, 'statuses' => 0, 'templates' => 0, 'signals' => 0, 'ignored' => 0];
        $accounts = array_values(array_unique(array_filter(array_column(MetaInboundPayload::changes($payload), 'entry_id'))));

        if ($accounts !== [] && filled($connection->business_account_id) && array_diff($accounts, [(string) $connection->business_account_id]) !== []) {
            Log::warning('Webhook changes of another business account were ignored.', ['connection_id' => $connection->id]);
            $payload['entry'] = array_values(array_filter(
                is_array($payload['entry'] ?? null) ? $payload['entry'] : [],
                fn (mixed $entry): bool => is_array($entry) && (string) ($entry['id'] ?? '') === (string) $connection->business_account_id,
            ));
        }

        $summary['messages'] = count($this->inbound->handle($payload, $connection));

        foreach (MetaInboundPayload::statuses($payload) as $status) {
            if ($status->phoneNumberId !== $connection->phone_number_id) {
                $summary['ignored']++;

                continue;
            }

            $this->statuses->handle($connection, $status);
            $summary['statuses']++;
        }

        foreach (MetaInboundPayload::changeErrors($payload) as $error) {
            if ($this->isStale($error['entry_time'])) {
                $summary['ignored']++;

                continue;
            }

            // The plain explanation only: Meta's code and English title stay in the log.
            $code = $error['code'];
            Log::warning('Meta reported a webhook-level error.', ['connection_id' => $connection->id, 'meta_code' => $code, 'title' => $error['title']]);
            $this->monitor->failure($connection, Str::limit(MetaErrorCatalog::explain($code, 'Meta reportó un error en el webhook.'), 500, ''), ConnectionMonitor::ERROR_PROVIDER, $error['entry_time']);
        }

        foreach (MetaInboundPayload::changes($payload) as ['field' => $field, 'entry_time' => $entryTime, 'value' => $value]) {
            match (true) {
                $field === 'messages' => null,
                $field === 'message_template_status_update' => $summary['templates'] += $this->templates->status($connection, $value, $entryTime),
                $field === 'message_template_quality_update' => $summary['templates'] += $this->templates->quality($connection, $value),
                $this->isStale($entryTime), $this->isReserved($field) => $summary['ignored']++,
                default => $summary['signals'] += $this->signal($connection, $field, $value, $entryTime),
            };
        }

        return $summary;
    }

    /**
     * Whether the entry is older than any delivery Meta still retries, so it
     * can only be a replay of something already applied.
     */
    private function isStale(?int $entryTime): bool
    {
        return $entryTime !== null
            && $entryTime < now()->subDays(max(1, (int) config('whatsapp.webhooks.max_age_days', 7)))->getTimestamp();
    }

    /**
     * Whether the field name of a change would address a note the
     * application itself keeps in `provider_signals` (the `platform.`
     * prefix, the authorization of a Meta account, the token renewal). A
     * provider payload must never be able to write or replace those.
     */
    private function isReserved(string $field): bool
    {
        $name = strtolower(trim($field));

        if (! str_starts_with($name, ConnectionMonitor::PLATFORM_SIGNAL_PREFIX) && ! in_array($name, ConnectionMonitor::RESERVED_SIGNALS, true)) {
            return false;
        }

        Log::warning('A webhook change named like an internal note of the platform was ignored.', ['field' => Str::limit($name, 60, '')]);

        return true;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function signal(Connection $connection, string $field, array $value, ?int $entryTime): int
    {
        $this->monitor->signal($connection, Str::limit($field, 60, ''), $value);

        $event = is_string($value['event'] ?? null) ? strtoupper($value['event']) : '';

        // DISABLED_UPDATE also announces that a disabled account is back (ban_info.waba_ban_state REINSTATE).
        $banState = $value['ban_info']['waba_ban_state'] ?? null;
        $reinstated = $field === 'account_update' && $event === 'DISABLED_UPDATE' && is_string($banState) && strtoupper($banState) === 'REINSTATE';

        if ($reinstated) {
            $this->monitor->restrictionLifted($connection, ConnectionMonitor::ERROR_ACCOUNT);
        } elseif ($field === 'account_update' && in_array($event, ['DISABLED_UPDATE', 'ACCOUNT_VIOLATION', 'ACCOUNT_RESTRICTION', 'ACCOUNT_DELETED'], true)) {
            $this->monitor->failure($connection, "Meta informó un cambio en la cuenta de WhatsApp Business: {$event}. Revisa WhatsApp Manager.", ConnectionMonitor::ERROR_ACCOUNT, $entryTime);
        }

        if ($field === 'phone_number_quality_update' && $event === 'FLAGGED') {
            $this->monitor->failure($connection, 'Meta marcó la calidad del número como baja (FLAGGED); los límites de envío podrían reducirse.', ConnectionMonitor::ERROR_QUALITY, $entryTime);
        }

        // Meta's own word that the restriction is over is evidence; a
        // verified token is not.
        if ($field === 'phone_number_quality_update' && $event === 'UNFLAGGED') {
            $this->monitor->restrictionLifted($connection, ConnectionMonitor::ERROR_QUALITY);
        }

        return 1;
    }
}
