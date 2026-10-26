<?php

namespace App\Actions\Conversations;

use App\Enums\AuditAction;
use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationAccess;
use App\Services\Conversations\ConversationLifecycle;
use Illuminate\Support\Facades\DB;

/**
 * Moves one legacy "en espera" (on_hold) conversation out of the retired
 * state (DESIGN decision 1, `conversations:retire-on-hold`):
 *
 * - its responsible agent can still attend it (active account and role,
 *   permission, department membership; ConversationAccess::canAttend()):
 *   back to Open with the same agent (event Resumed);
 * - otherwise: back to its queue as Pending (event Unassigned), offered to
 *   the automatic distribution like any released conversation; the freed
 *   agent is then offered their other automatic queues.
 *
 * The on_hold_* columns are cleared; the reason is kept in the event
 * metadata and, when there was one, as an internal note without author.
 * Each conversation is migrated in its own transaction with the engine's
 * lock order (conversation, department, agent) and audited.
 */
class RetireOnHoldConversation
{
    public const TARGET_OPEN = 'open';

    public const TARGET_PENDING = 'pending';

    public function __construct(
        private ConversationAccess $access,
        private ConversationLifecycle $lifecycle,
        private AssignAutomatically $assignAutomatically,
        private AuditLogger $audit,
    ) {}

    /**
     * What would happen to the conversation, without writing anything.
     *
     * @return array{target: 'open'|'pending', reason: string}
     */
    public function plan(Conversation $conversation): array
    {
        $agent = $conversation->assigned_user_id === null
            ? null
            : User::query()->with(['roles', 'departments'])->find($conversation->assigned_user_id);

        return $this->decide($conversation, $agent);
    }

    /**
     * Migrates the conversation, or returns null when it is no longer on hold.
     *
     * @return array{target: 'open'|'pending', reason: string}|null
     */
    public function handle(Conversation $conversation): ?array
    {
        return DB::transaction(function () use ($conversation): ?array {
            $conversation = $this->lifecycle->lock($conversation);

            if ($conversation->status !== ConversationStatus::OnHold) {
                return null;
            }

            $department = $this->lifecycle->lockDepartment($conversation->department_id, exclusive: true);
            $conversation->setRelation('department', $department);
            $agent = $conversation->assigned_user_id === null ? null : $this->lifecycle->freshUser($conversation->assigned_user_id);
            $plan = $this->decide($conversation, $agent);
            $heldSince = $conversation->on_hold_at?->toIso8601ZuluString();
            $holdReason = $conversation->on_hold_reason;
            $previous = $conversation->assigned_user_id;
            $metadata = array_filter([
                'reason' => 'on_hold_retired',
                'held_since' => $heldSince,
                'on_hold_reason' => $holdReason,
            ], fn (?string $value): bool => $value !== null);

            if ($plan['target'] === self::TARGET_OPEN) {
                $this->lifecycle->apply($conversation, ConversationStatus::Open, [
                    'on_hold_at' => null,
                    'on_hold_reason' => null,
                ]);
                $this->lifecycle->record($conversation, ConversationEventType::Resumed, null, [
                    'to_user_id' => $previous,
                    'metadata' => $metadata,
                ]);
            } else {
                $this->lifecycle->apply($conversation, ConversationStatus::Pending, $this->lifecycle->queued());
                $this->lifecycle->record($conversation, ConversationEventType::Unassigned, null, [
                    'from_user_id' => $previous,
                    'from_department_id' => $conversation->department_id,
                    'note' => 'El estado «En espera» ya no existe; la conversación pasó a «Pendiente».',
                    'metadata' => $metadata,
                ]);
            }

            if ($holdReason !== null && trim($holdReason) !== '') {
                $note = new ConversationNote(['body' => "Motivo de espera registrado antes de retirar el estado «En espera»: {$holdReason}"]);
                $note->forceFill(['conversation_id' => $conversation->id, 'user_id' => null])->save();
            }

            $this->audit->record(AuditAction::ConversationOnHoldRetired, $conversation, [
                'previous_status' => ConversationStatus::OnHold->value,
                'new_status' => $plan['target'],
                'assigned_user_id' => $previous,
            ]);

            if ($plan['target'] === self::TARGET_PENDING) {
                $this->assignAutomatically->handle($conversation, $previous === null ? [] : [$previous]);

                // The previous responsible no longer carries this load: offer
                // them the rest of their automatic queues (where they can
                // still attend), as ReleaseConversation does.
                if ($previous !== null && $conversation->assigned_user_id !== $previous) {
                    $this->assignAutomatically->distributeForAgentAfterCommit($previous, [$conversation->id]);
                }
            }

            return $plan;
        });
    }

    /**
     * @return array{target: 'open'|'pending', reason: string}
     */
    private function decide(Conversation $conversation, ?User $agent): array
    {
        if ($agent === null) {
            return ['target' => self::TARGET_PENDING, 'reason' => 'No tiene agente responsable.'];
        }

        $department = $conversation->relationLoaded('department') ? $conversation->department : $conversation->department()->first();
        $refusal = $this->access->attendRefusal($agent, $department);

        return $refusal === null
            ? ['target' => self::TARGET_OPEN, 'reason' => "{$agent->name} puede seguir atendiéndola."]
            : ['target' => self::TARGET_PENDING, 'reason' => $refusal];
    }
}
