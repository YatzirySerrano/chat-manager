<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Exceptions\ConversationConflict;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Services\Conversations\ConversationAccess;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a conversation to another agent and/or department. Everything that
 * belongs to it (contact, messages, media, notes, tags, origin) stays
 * attached because only the responsible and the queue change; the event
 * keeps who transferred it, from where, to where, when and why.
 *
 * Without a receiving agent it waits in the destination queue (and is
 * distributed automatically when that department does so). No message is
 * ever sent to the customer.
 */
class TransferConversation
{
    public function __construct(
        private ConversationAccess $access,
        private ConversationLifecycle $lifecycle,
        private AssignAutomatically $assignAutomatically,
        private PlatformSettings $settings,
    ) {}

    /**
     * @param  Department|null  $department  Destination department; null keeps the current one.
     * @param  User|null  $agent  Receiving agent; null leaves it pending in the destination queue.
     *
     * @throws ValidationException
     */
    public function handle(Conversation $conversation, ?Department $department, ?User $agent, User $actor, ?string $reason = null, ?int $expectedVersion = null): Conversation
    {
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

        if ($reason === null && $this->settings->get('operations.transfer_reason_required') === true) {
            throw ValidationException::withMessages(['reason' => 'Indica el motivo de la transferencia.']);
        }

        return DB::transaction(function () use ($conversation, $department, $agent, $actor, $reason, $expectedVersion): Conversation {
            $conversation = $this->lifecycle->lock($conversation, $expectedVersion);
            $actor = $this->lifecycle->authorize($actor, 'transfer', $conversation);

            $destination = $department === null
                ? $conversation->department()->first()
                : Department::query()->lockForUpdate()->findOrFail($department->id);

            if ($department !== null) {
                $this->ensureDepartmentReceives($conversation, $destination);
            }

            if ($agent !== null) {
                $agent = $this->lifecycle->freshUser($agent->id);
                $refusal = $this->access->attendRefusal($agent, $destination);

                if ($refusal !== null) {
                    throw new ConversationConflict($refusal);
                }
            }

            $fromDepartment = $conversation->department_id;
            $fromUser = $conversation->assigned_user_id;
            $sameDepartment = $destination?->id === $fromDepartment;

            if ($sameDepartment && ($agent?->id === $fromUser || ($agent === null && $fromUser === null))) {
                throw new ConversationConflict('Elige un destino distinto al actual.');
            }

            $this->lifecycle->apply(
                $conversation,
                $agent === null ? ConversationStatus::Pending : ConversationStatus::Open,
                [
                    ...($agent === null ? $this->lifecycle->queued() : $this->lifecycle->attendedBy($agent)),
                    'department_id' => $destination?->id,
                ],
            );

            if ($agent !== null) {
                $this->lifecycle->touchAssignment($agent);
            }

            $this->lifecycle->record($conversation, ConversationEventType::Transferred, $actor, [
                'from_user_id' => $fromUser,
                'to_user_id' => $agent?->id,
                'from_department_id' => $fromDepartment,
                'to_department_id' => $destination?->id,
                'note' => $reason,
            ]);

            if ($agent === null) {
                $this->assignAutomatically->handle($conversation, $fromUser === null ? [] : [$fromUser]);
            }

            return $conversation;
        });
    }

    /**
     * The destination must be active and authorized to receive the
     * conversations of the connection (when the connection restricts them).
     */
    private function ensureDepartmentReceives(Conversation $conversation, ?Department $department): void
    {
        if ($department === null || ! $department->is_active) {
            throw new ConversationConflict('El departamento de destino está inactivo.');
        }

        $connection = $conversation->channelConnection()->firstOrFail();
        $authorized = $connection->departments()->pluck('departments.id')->all();

        if ($authorized !== [] && ! in_array($department->id, array_map('intval', $authorized), true)) {
            throw new ConversationConflict("El departamento {$department->name} no está autorizado para recibir conversaciones de la conexión {$connection->name}.");
        }
    }
}
