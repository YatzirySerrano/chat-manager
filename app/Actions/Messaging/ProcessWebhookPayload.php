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
 * Changes of another business account than the connection's are ignored.
 */
class ProcessWebhookPayload
{
    public function __construct(
        private ProcessInboundMessages $inbound,
        private RecordMessageStatus $statuses,
        private RecordTemplateUpdate $templates,
        private ConnectionMonitor $monitor,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array{messages: int, statuses: int, templates: int, signals: int, ignored: int}
     */
    public function handle(Connection $connection, array $payload): array
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
            $code = $error['code'];
            $this->monitor->failure($connection, Str::limit((MetaErrorCatalog::messageFor($code) ?? $error['title'] ?? 'Meta reportó un error en el webhook.').($code !== null ? " (código {$code})" : ''), 500, ''));
        }

        foreach (MetaInboundPayload::changes($payload) as ['field' => $field, 'value' => $value]) {
            match ($field) {
                'messages' => null,
                'message_template_status_update' => $summary['templates'] += $this->templates->status($connection, $value),
                'message_template_quality_update' => $summary['templates'] += $this->templates->quality($connection, $value),
                default => $summary['signals'] += $this->signal($connection, $field, $value),
            };
        }

        return $summary;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function signal(Connection $connection, string $field, array $value): int
    {
        $this->monitor->signal($connection, Str::limit($field, 60, ''), $value);

        $event = is_string($value['event'] ?? null) ? strtoupper($value['event']) : '';

        if ($field === 'account_update' && in_array($event, ['DISABLED_UPDATE', 'ACCOUNT_VIOLATION', 'ACCOUNT_RESTRICTION', 'ACCOUNT_DELETED'], true)) {
            $this->monitor->failure($connection, "Meta informó un cambio en la cuenta de WhatsApp Business: {$event}. Revisa WhatsApp Manager.");
        }

        if ($field === 'phone_number_quality_update' && $event === 'FLAGGED') {
            $this->monitor->failure($connection, 'Meta marcó la calidad del número como baja (FLAGGED); los límites de envío podrían reducirse.');
        }

        return 1;
    }
}
