<?php

namespace App\Services\Conversations;

use App\Enums\ConversationOutcome;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\RequestOutcomeEvidence;
use Illuminate\Support\Str;

/**
 * The result to pre-select when a request is completed (or its result is
 * corrected), from the evidence in the conversation:
 *
 * 1. the «Monto autorizado» kept in the request data (`request_fields`,
 *    key `monto_autorizado`): typed by the analyst in «Resolución», taken
 *    from the authorization sent (RequestResolutionCapture) or confirmed
 *    on a previous completion. When present it is the amount of the
 *    approval, and an approval by itself when no reply announces a result;
 * 2. the most recent of the latest text replies written by a person that
 *    announces a result («Se autoriza …» with its «Monto autorizado», or
 *    «Solicitud rechazada»): the result, and the amount when the request
 *    data has none. Automatic messages (the farewell, greetings) are
 *    skipped, so a correction made after the farewell still finds it.
 *
 * Only a suggestion: nothing is recorded until the person confirms it.
 *
 * @phpstan-type Suggestion array{outcome: string, label: string, amount: string|null, source: 'message'|'request_fields', excerpt: string|null}
 */
class RequestOutcomeSuggestions
{
    /**
     * Latest outbound replies read, newest first.
     */
    public const MESSAGES = 10;

    /**
     * @return Suggestion|null
     */
    public function for(Conversation $conversation): ?array
    {
        $captured = $this->capturedAmount($conversation);

        foreach ($this->latestReplies($conversation) as $message) {
            $evidence = RequestOutcomeEvidence::fromText($message->body);

            if ($evidence === null) {
                continue;
            }

            $approved = $evidence['outcome'] === ConversationOutcome::Approved;

            return [
                'outcome' => $evidence['outcome']->value,
                'label' => $evidence['outcome']->label(),
                'amount' => $approved ? ($captured ?? $evidence['amount']) : null,
                'source' => 'message',
                'excerpt' => Str::limit(trim(str_replace(['*', '_', '~'], '', (string) strtok((string) $message->body, "\n"))), 80),
            ];
        }

        if ($captured !== null) {
            return [
                'outcome' => ConversationOutcome::Approved->value,
                'label' => ConversationOutcome::Approved->label(),
                'amount' => $captured,
                'source' => 'request_fields',
                'excerpt' => null,
            ];
        }

        return null;
    }

    /**
     * Text replies written by a person (not automatic, not failed, not
     * deleted for everyone), newest first.
     *
     * @return iterable<Message>
     */
    private function latestReplies(Conversation $conversation): iterable
    {
        $messages = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Outbound->value)
            ->where('type', MessageType::Text->value)
            ->where('status', '!=', MessageStatus::Failed->value)
            ->whereNull('revoked_at')
            ->whereNotNull('body')
            ->orderByDesc('id')
            ->limit(self::MESSAGES)
            ->get(['id', 'body', 'metadata']);

        return $messages->reject(fn (Message $message): bool => is_array($message->metadata) && ($message->metadata['automatic'] ?? false) === true);
    }

    /**
     * «Monto autorizado» of the request data, as a decimal string.
     */
    private function capturedAmount(Conversation $conversation): ?string
    {
        // Written by the «Datos de la solicitud» capture; read defensively (a list of {key, label, value, ...}).
        $fields = $conversation->getAttribute('request_fields');

        if (! is_array($fields)) {
            return null;
        }

        foreach ($fields as $field) {
            if (is_array($field) && ($field['key'] ?? null) === 'monto_autorizado' && is_scalar($field['value'] ?? null)) {
                return RequestOutcomeEvidence::amount((string) $field['value']);
            }
        }

        return null;
    }
}
