<?php

namespace App\Http\Resources;

use App\Enums\ConversationEventType;
use App\Enums\ConversationOutcome;
use App\Models\ConversationEvent;
use App\Support\OutcomeText;
use App\Support\ProtectedAccounts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ConversationEvent
 */
class ConversationEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $protected = app(ProtectedAccounts::class);

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            // «cerró la conversación»: read after the actor's name.
            'action' => match (true) {
                $this->completedClose() => 'completó la solicitud',
                $this->amountCorrected() => 'corrigió el monto autorizado',
                default => $this->type->action(),
            },
            'label' => match (true) {
                $this->completedClose() => 'Solicitud completada',
                $this->amountCorrected() => 'Monto autorizado corregido',
                default => $this->label(),
            },
            'actor' => $protected->nameForRequest($this->actor, $request),
            'from_user' => $protected->nameForRequest($this->fromUser, $request),
            'to_user' => $protected->nameForRequest($this->toUser, $request),
            'from_department' => $this->fromDepartment?->name,
            'to_department' => $this->toDepartment?->name,
            'note' => $this->note,
            'trigger' => is_string($this->metadata['trigger'] ?? null) ? $this->metadata['trigger'] : null,
            'message_id' => is_int($this->metadata['message_id'] ?? null) ? $this->metadata['message_id'] : null,
            'reason' => is_string($this->metadata['reason'] ?? null) ? $this->metadata['reason'] : null,
            // A justified exception to the queue rules made with this operation.
            'queue_override' => is_array($override = $this->metadata['queue_override'] ?? null) ? [
                'rules' => array_values(array_filter(is_array($override['rules'] ?? null) ? $override['rules'] : [], 'is_string')),
                'reason' => is_string($override['reason'] ?? null) ? $override['reason'] : null,
            ] : null,
            // Category change: names as they were when it happened.
            'category' => array_key_exists('to_category', $this->metadata ?? []) ? [
                'from' => is_string($this->metadata['from_category'] ?? null) ? $this->metadata['from_category'] : null,
                'to' => is_string($this->metadata['to_category'] ?? null) ? $this->metadata['to_category'] : null,
            ] : null,
            // Credit result recorded with a completion or corrected afterwards, in words.
            'outcome' => $this->outcomeText(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * A close made with «Solicitud completada» (with or without farewell)
     * reads as such in the timeline, not as a plain close.
     */
    private function completedClose(): bool
    {
        return $this->type === ConversationEventType::Closed && ($this->metadata['completed'] ?? false) === true;
    }

    /**
     * Only the authorized amount of an approved request changed (a typo):
     * read as «corrigió el monto autorizado: $3,000 → $3,500».
     */
    private function amountCorrected(): bool
    {
        return $this->type === ConversationEventType::OutcomeRecorded && ($this->metadata['amount_corrected'] ?? false) === true;
    }

    /**
     * «Crédito aprobado por $3,000», «Rechazada»; a correction that removed
     * the result reads «sin resultado». Null for every other event.
     */
    private function outcomeText(): ?string
    {
        $metadata = $this->metadata ?? [];
        $outcome = is_string($metadata['outcome'] ?? null) ? ConversationOutcome::tryFrom($metadata['outcome']) : null;
        $amount = is_string($metadata['outcome_amount'] ?? null) || is_numeric($metadata['outcome_amount'] ?? null) ? $metadata['outcome_amount'] : null;

        if ($this->amountCorrected()) {
            $from = is_string($metadata['from_outcome_amount'] ?? null) || is_numeric($metadata['from_outcome_amount'] ?? null) ? OutcomeText::money($metadata['from_outcome_amount']) : 'sin monto';

            return $from.' → '.($amount !== null ? OutcomeText::money($amount) : 'sin monto');
        }

        return match (true) {
            $this->type === ConversationEventType::OutcomeRecorded => OutcomeText::spell($outcome, $amount) ?? 'sin resultado',
            $this->completedClose() => OutcomeText::spell($outcome, $amount),
            default => null,
        };
    }

    /**
     * The chatbot's routing says where it sent the conversation («El
     * chatbot la envió a Mesa de control») or that no option was chosen.
     */
    private function label(): string
    {
        if ($this->type !== ConversationEventType::ChatbotRouted) {
            return $this->type->label();
        }

        if (($this->metadata['outcome'] ?? null) === 'answered') {
            $option = is_string($this->metadata['option'] ?? null) ? $this->metadata['option'] : null;

            return $option !== null ? "El chatbot contestó «{$option}»" : 'El chatbot contestó al cliente';
        }

        if (($this->metadata['outcome'] ?? null) === 'fallback') {
            return ($this->metadata['reason'] ?? null) === 'timeout'
                ? 'El cliente no contestó el menú del chatbot a tiempo'
                : 'El chatbot no recibió una opción válida';
        }

        return $this->toDepartment !== null ? "El chatbot la envió a {$this->toDepartment->name}" : $this->type->label();
    }
}
