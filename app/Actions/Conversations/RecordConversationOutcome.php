<?php

namespace App\Actions\Conversations;

use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationOutcome;
use App\Exceptions\ConversationConflict;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\RequestResolutionCapture;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Records or corrects the credit result of a request already completed
 * («Crédito aprobado» with its authorized amount, «Rechazada», or none).
 * «Solicitud completada» only says the request was attended; analysts
 * commission on approved credits, so the result may be fixed afterwards by
 * whoever completed it or by whoever manages the conversation
 * (ConversationPolicy::permitsOutcome()).
 *
 * The conversation row is locked and the actor authorized again with fresh
 * data. It does not change the status nor the version (it is not an
 * operational decision), and keeps who and when (`outcome_by_user_id`,
 * `outcome_at`): the date of the result in the reports. Recorded as a
 * conversation event (previous and new result) and in the audit log. The
 * authorized amount is kept as «Monto autorizado» in the request data too.
 */
class RecordConversationOutcome
{
    public const NOT_COMPLETED_MESSAGE = 'Esta solicitud ya no está completada: el resultado se registra al completarla.';

    public function __construct(
        private ConversationLifecycle $lifecycle,
        private AuditLogger $audit,
        private RequestResolutionCapture $resolution,
    ) {}

    /**
     * @return bool Whether the result changed.
     *
     * @throws AuthorizationException
     * @throws ConversationConflict
     */
    public function handle(Conversation $conversation, User $actor, ?ConversationOutcome $outcome, ?string $amount = null): bool
    {
        $amount = $outcome === ConversationOutcome::Approved && $amount !== null ? number_format((float) $amount, 2, '.', '') : null;

        return DB::transaction(function () use ($conversation, $actor, $outcome, $amount): bool {
            $conversation = $this->lifecycle->lock($conversation);
            $actor = $this->lifecycle->freshUser($actor->id, lock: false);
            $gate = Gate::forUser($actor);

            if ($gate->denies('permitsOutcome', $conversation)) {
                throw ConversationLifecycle::forbidden($conversation);
            }

            if ($gate->denies('recordOutcome', $conversation)) {
                throw new ConversationConflict(self::NOT_COMPLETED_MESSAGE);
            }

            $previous = $conversation->outcome;
            $previousAmount = $conversation->outcome_amount;

            if ($previous === $outcome && $previousAmount === $amount) {
                return false;
            }

            $this->apply($conversation, $actor, $outcome, $amount);

            if ($amount !== null) {
                $this->resolution->keepAuthorizedAmount($conversation, $amount, $actor, 'outcome');
            }

            return true;
        });
    }

    /**
     * «Monto autorizado» corrected in the request data of an approved
     * request: the result follows it, so the reports read the same amount.
     * Runs inside the caller's transaction with the row already locked and
     * the actor authorized; it does not write the request data back.
     *
     * @param  string|null  $amount  Decimal amount («3500.00»), null when removed.
     * @return bool Whether the result changed.
     */
    public function followAuthorizedAmount(Conversation $conversation, User $actor, ?string $amount): bool
    {
        if ($conversation->outcome !== ConversationOutcome::Approved) {
            return false;
        }

        $amount = $amount !== null ? number_format((float) $amount, 2, '.', '') : null;

        if ($conversation->outcome_amount === $amount) {
            return false;
        }

        $this->apply($conversation, $actor, ConversationOutcome::Approved, $amount, 'request_fields');

        return true;
    }

    /**
     * Stores the result, with its event and audit entry. Correcting only the
     * amount keeps when and by whom the result was recorded: a typo does not
     * move the credit to another period of the reports.
     */
    private function apply(Conversation $conversation, User $actor, ?ConversationOutcome $outcome, ?string $amount, string $source = 'outcome'): void
    {
        $previous = $conversation->outcome;
        $previousAmount = $conversation->outcome_amount;
        $amountOnly = $outcome !== null && $previous === $outcome;

        $conversation->forceFill([
            'outcome' => $outcome,
            'outcome_amount' => $amount,
            'outcome_at' => $outcome === null ? null : ($amountOnly && $conversation->outcome_at !== null ? $conversation->outcome_at : now()),
            'outcome_by_user_id' => $outcome === null ? null : ($amountOnly && $conversation->outcome_by_user_id !== null ? $conversation->outcome_by_user_id : $actor->id),
        ])->save();

        $this->lifecycle->record($conversation, ConversationEventType::OutcomeRecorded, $actor, [
            'metadata' => [
                'outcome' => $outcome?->value,
                'outcome_amount' => $amount,
                'from_outcome' => $previous?->value,
                'from_outcome_amount' => $previousAmount,
                // «corrigió el monto autorizado: $3,000 → $3,500».
                ...($amountOnly && $outcome === ConversationOutcome::Approved ? ['amount_corrected' => true] : []),
                ...($source !== 'outcome' ? ['source' => $source] : []),
            ],
        ]);

        $this->audit->record(AuditAction::ConversationOutcomeRecorded, $conversation, [
            'outcome' => $outcome?->value,
            'outcome_label' => $outcome?->label() ?? 'Sin resultado',
            'outcome_amount' => $amount,
            'previous_outcome' => $previous?->value,
            'previous_outcome_amount' => $previousAmount,
            'on_completion' => false,
            'amount_corrected' => $amountOnly && $outcome === ConversationOutcome::Approved,
            'source' => $source,
        ], $actor);
    }
}
