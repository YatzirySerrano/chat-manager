<?php

namespace App\Services\Conversations;

use App\Enums\AuditAction;
use App\Enums\MessageType;
use App\Events\Conversations\ConversationChanged;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\RequestFields\RequestFieldCatalog;
use App\Support\RequestFields\RequestResolution;
use App\Support\RequestOutcomeEvidence;

/**
 * Keeps the resolution of a request in «Datos de la solicitud»
 * (conversations.request_fields) without anyone typing it twice:
 *
 * - fromMessage(): the authorization or rejection an agent sends («Se
 *   autoriza …» with «Monto autorizado», «Desembolso», «Cantidad a
 *   pagar», RequestResolution) stores its amounts (the latest message
 *   wins) and completes the client, product and type when missing;
 * - keepAuthorizedAmount(): the amount confirmed when the request is
 *   completed (or its result corrected) becomes «Monto autorizado», so
 *   the panel, the quick replies and the result always agree;
 * - merge(): any other automatic capture (the request a field manager
 *   writes, RequestSubmissionCapture; the backfill of older requests),
 *   by the system when there is no actor.
 *
 * Callers run it inside their transaction with the conversation row
 * already locked. Like SaveRequestFields it never touches the status, the
 * version or the queue, is recorded in the audit log (mode «auto») and
 * signalled in real time (reason "request_fields") so open panels refresh.
 * A field never pushes the list past RequestFieldCatalog::FIELDS_MAX.
 */
class RequestResolutionCapture
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @return bool Whether anything changed.
     */
    public function fromMessage(Conversation $conversation, Message $message, User $actor): bool
    {
        if ($message->type !== MessageType::Text || (is_array($message->metadata) && ($message->metadata['automatic'] ?? false) === true)) {
            return false;
        }

        $resolution = RequestResolution::read($message->body);

        if ($resolution === null) {
            return false;
        }

        return $this->write($conversation, $resolution['latest'], $resolution['if_missing'], $message->id, $actor, 'outbound_message');
    }

    /**
     * @param  string  $amount  Decimal amount («3000.00»).
     * @return bool Whether anything changed.
     */
    public function keepAuthorizedAmount(Conversation $conversation, string $amount, User $actor, string $source = 'completion'): bool
    {
        return $this->write($conversation, ['monto_autorizado' => RequestOutcomeEvidence::display($amount)], [], null, $actor, $source);
    }

    /**
     * Stores captured values on the (locked) conversation, recorded in the
     * audit log as mode «auto» with $source and $audit (a «note», say).
     *
     * @param  array<string, string>  $latest  Values that replace the stored ones.
     * @param  array<string, string>  $ifMissing  Values kept only when the field has none.
     * @param  User|null  $actor  Null: the system (an inbound message, a command).
     * @param  array<string, mixed>  $audit  Extra properties of the audit entry.
     * @param  bool  $signal  Whether open panels are told in real time (not for a bulk backfill).
     * @return bool Whether anything changed.
     */
    public function merge(Conversation $conversation, array $latest, array $ifMissing, ?int $messageId, ?User $actor, string $source, array $audit = [], bool $signal = true): bool
    {
        return $this->write($conversation, $latest, $ifMissing, $messageId, $actor, $source, $audit, $signal);
    }

    /**
     * @param  array<string, string>  $latest  Values that replace the stored ones.
     * @param  array<string, string>  $ifMissing  Values kept only when the field has none.
     * @param  array<string, mixed>  $audit
     */
    private function write(Conversation $conversation, array $latest, array $ifMissing, ?int $messageId, ?User $actor, string $source, array $audit = [], bool $signal = true): bool
    {
        if ($latest === [] && $ifMissing === []) {
            return false;
        }

        $fields = [];

        foreach (RequestFieldCatalog::fromStored($conversation->request_fields) as $field) {
            $fields[$field['key']] = $field;
        }

        $changes = [];
        $wanted = [];

        foreach ($latest as $key => $value) {
            $wanted[$key] = $value;
        }

        foreach ($ifMissing as $key => $value) {
            if (! isset($fields[$key]) || trim($fields[$key]['value']) === '') {
                $wanted[$key] ??= $value;
            }
        }

        foreach ($wanted as $key => $value) {
            $previous = $fields[$key] ?? null;

            if ($previous !== null && RequestResolution::sameValue($key, $previous['value'], $value)) {
                continue;
            }

            if ($previous === null && count($fields) >= RequestFieldCatalog::FIELDS_MAX) {
                continue;
            }

            $label = RequestFieldCatalog::labelOf($key) ?? $key;
            $fields[$key] = [
                'key' => $key,
                'label' => $label,
                'value' => mb_substr($value, 0, RequestFieldCatalog::VALUE_MAX),
                'message_id' => $messageId,
                'captured_at' => now()->toIso8601String(),
            ];
            $changes[] = ['label' => $label, 'previous' => $previous['value'] ?? null, 'value' => $fields[$key]['value']];
        }

        if ($changes === []) {
            return false;
        }

        $conversation->forceFill(['request_fields' => array_values($fields)])->save();

        $this->audit->record(AuditAction::ConversationRequestFieldsChanged, $conversation, [
            'mode' => 'auto',
            'source' => $source,
            'message_id' => $messageId,
            'changes' => $changes,
            ...$audit,
        ], $actor);

        if ($signal) {
            ConversationChanged::dispatch($conversation->id, 'request_fields', $actor?->id);
        }

        return true;
    }
}
