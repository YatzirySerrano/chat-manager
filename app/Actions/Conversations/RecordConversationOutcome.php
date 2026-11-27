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
 * conversation event (previous and new result) and in the audit log.
 */
class RecordConversationOutcome
{
    public const NOT_COMPLETED_MESSAGE = 'Esta solicitud ya no está completada: el resultado se registra al completarla.';

    public function __construct(
        private ConversationLifecycle $lifecycle,
        private AuditLogger $audit,
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

            $conversation->forceFill([
                'outcome' => $outcome,
                'outcome_amount' => $amount,
                'outcome_at' => $outcome === null ? null : now(),
                'outcome_by_user_id' => $outcome === null ? null : $actor->id,
            ])->save();

            $this->lifecycle->record($conversation, ConversationEventType::OutcomeRecorded, $actor, [
                'metadata' => [
                    'outcome' => $outcome?->value,
                    'outcome_amount' => $amount,
                    'from_outcome' => $previous?->value,
                    'from_outcome_amount' => $previousAmount,
                ],
            ]);

            $this->audit->record(AuditAction::ConversationOutcomeRecorded, $conversation, [
                'outcome' => $outcome?->value,
                'outcome_label' => $outcome?->label() ?? 'Sin resultado',
                'outcome_amount' => $amount,
                'previous_outcome' => $previous?->value,
                'previous_outcome_amount' => $previousAmount,
                'on_completion' => false,
            ], $actor);

            return true;
        });
    }
}
