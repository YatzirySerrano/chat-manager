<?php

namespace App\Actions\Conversations;

use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationOutcome;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\User;
use App\Notifications\ConversationCompletedNotification;
use App\Notifications\NotificationRecipients;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\RequestResolutionCapture;
use App\Services\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Ends the attention. The agent who attended it is kept for history, and
 * nothing is sent to the customer. Once committed, the agent has room for
 * one more conversation, so the queues of their automatic departments are
 * offered again.
 */
class CloseConversation
{
    public function __construct(
        private ConversationLifecycle $lifecycle,
        private AssignAutomatically $assignAutomatically,
        private NotificationRecipients $recipients,
        private PlatformSettings $settings,
        private AuditLogger $audit,
        private RequestResolutionCapture $resolution,
    ) {}

    /**
     * `$completed` («Solicitud completada»): the request was resolved, so the
     * customer's next message never reopens this conversation, even inside
     * the connection's reopening window; it starts a new request in the
     * waiting list (ReceiveInboundMessage).
     *
     * `$outcome` (optional, completion only): the result of the credit
     * request, «Crédito aprobado» with its authorized amount or «Rechazada».
     * Without it the request is completed without a result, which can be
     * recorded afterwards (RecordConversationOutcome). Any close resets the
     * previous result: a plain close has none, a completion has the new one.
     * The authorized amount is also kept as «Monto autorizado» in the
     * request data (RequestResolutionCapture), so both always agree.
     */
    public function handle(Conversation $conversation, User $actor, ?string $reason = null, ?int $expectedVersion = null, bool $completed = false, bool $farewell = true, ?ConversationOutcome $outcome = null, ?string $outcomeAmount = null): Conversation
    {
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
        $outcome = $completed ? $outcome : null;
        // Only an approval carries an amount.
        $outcomeAmount = $outcome === ConversationOutcome::Approved && $outcomeAmount !== null ? number_format((float) $outcomeAmount, 2, '.', '') : null;

        return DB::transaction(function () use ($conversation, $actor, $reason, $expectedVersion, $completed, $farewell, $outcome, $outcomeAmount): Conversation {
            $conversation = $this->lifecycle->lock($conversation);
            // Lock order step 2, before the event's foreign keys lock the user rows.
            $this->lifecycle->lockDepartment($conversation->department_id);
            $actor = $this->lifecycle->authorize($actor, 'close', $conversation, $expectedVersion);

            $this->lifecycle->apply($conversation, ConversationStatus::Closed, [
                'closed_at' => now(),
                'closed_by_user_id' => $actor->id,
                'completed_at' => $completed ? now() : null,
                'on_hold_at' => null,
                'on_hold_reason' => null,
                'outcome' => $outcome,
                'outcome_amount' => $outcomeAmount,
                'outcome_at' => $outcome === null ? null : now(),
                'outcome_by_user_id' => $outcome === null ? null : $actor->id,
            ]);
            $this->lifecycle->record($conversation, ConversationEventType::Closed, $actor, [
                'from_user_id' => $conversation->assigned_user_id,
                'from_department_id' => $conversation->department_id,
                'note' => $reason,
                // «Sin despedida»: the connection's farewell is not sent this time.
                ...($completed || ! $farewell ? ['metadata' => array_filter([
                    'completed' => $completed ?: null,
                    'farewell' => $farewell ? null : false,
                    // The result recorded with the completion, as it was then.
                    'outcome' => $outcome?->value,
                    'outcome_amount' => $outcomeAmount,
                ], fn (mixed $value): bool => $value !== null)] : []),
            ]);

            if ($outcome !== null) {
                $this->audit->record(AuditAction::ConversationOutcomeRecorded, $conversation, [
                    'outcome' => $outcome->value,
                    'outcome_label' => $outcome->label(),
                    'outcome_amount' => $outcomeAmount,
                    'on_completion' => true,
                ], $actor);
            }

            if ($outcomeAmount !== null) {
                $this->resolution->keepAuthorizedAmount($conversation, $outcomeAmount, $actor);
            }

            $this->assignAutomatically->distributeForAgentAfterCommit($conversation->assigned_user_id);

            if ($completed) {
                $this->alertCompletedAfterCommit($conversation, $actor);
            }

            return $conversation;
        });
    }

    /**
     * «Alerta para todos»: once the completion is committed, tell everyone who
     * can see the conversation (except the agent who completed it). Configured
     * in Seguimiento de atención → «Alertas automáticas» (time_alerts.completed_*);
     * off, nothing is sent. The whole alert is best-effort: a failure here
     * never undoes the close (the recipients are resolved after commit).
     */
    private function alertCompletedAfterCommit(Conversation $conversation, User $actor): void
    {
        if (! (bool) $this->settings->get('time_alerts.completed_enabled', true)) {
            return;
        }

        $priority = (string) $this->settings->get('time_alerts.completed_priority', 'informative');
        $message = $this->settings->get('time_alerts.completed_message');
        $message = is_string($message) && trim($message) !== '' ? trim($message) : null;

        DB::afterCommit(function () use ($conversation, $actor, $priority, $message): void {
            $people = $this->recipients->forCompletedConversation($conversation, $actor->id);

            if ($people->isEmpty()) {
                return;
            }

            Notification::send($people, new ConversationCompletedNotification(
                $conversation->id,
                $conversation->department_id,
                $actor->id,
                (string) ($conversation->completed_at?->getTimestamp() ?? now()->getTimestamp()),
                $priority,
                $message,
            ));
        });
    }
}
