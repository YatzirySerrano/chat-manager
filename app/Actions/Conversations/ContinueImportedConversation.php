<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Enums\DistributionMode;
use App\Exceptions\ConversationConflict;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\Conversations\ConversationRouter;
use App\Services\Conversations\QueueRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * «Reabrir» on a conversation imported from Whaticket. The imported history
 * stays read-only for good; what reopens is the relationship with the
 * customer on the same connection (they have the number and the WhatsApp
 * line), in this order:
 *  1. the contact already has an unclosed live conversation there → that one;
 *  2. it has a closed live one → it is reopened (ReopenConversation, with its
 *     own rules: back to its agent or to its queue);
 *  3. otherwise a new live conversation starts, in the imported one's
 *     department (or the connection's routing), and the actor accepts it
 *     when the queue rules allow it; if not, it waits in its queue.
 */
class ContinueImportedConversation
{
    public function __construct(
        private ConversationLifecycle $lifecycle,
        private ConversationRouter $router,
        private ReopenConversation $reopen,
        private AcceptConversation $accept,
    ) {}

    /**
     * @return array{conversation: Conversation, outcome: 'existing'|'reopened'|'created'|'queued'}
     *
     * @throws AuthorizationException
     */
    public function handle(Conversation $imported, User $actor): array
    {
        if (! $actor->can('continueImported', $imported)) {
            throw new AuthorizationException('No puedes reabrir esta conversación: necesitas el permiso «Reabrir conversaciones cerradas» y que su conexión siga existiendo.');
        }

        /** @var Connection $connection */
        $connection = $imported->channelConnection;

        $live = fn () => Conversation::query()
            ->where('connection_id', $connection->id)
            ->where('contact_id', $imported->contact_id)
            ->live();

        $unclosed = $live()->notClosed()->orderByDesc('id')->first();

        if ($unclosed !== null) {
            return ['conversation' => $unclosed, 'outcome' => 'existing'];
        }

        $lastClosed = $live()->where('status', ConversationStatus::Closed)->orderByDesc('closed_at')->orderByDesc('id')->first();

        if ($lastClosed !== null) {
            return ['conversation' => $this->reopen->handle($lastClosed, $actor), 'outcome' => 'reopened'];
        }

        try {
            $conversation = DB::transaction(fn (): Conversation => $this->start($imported, $connection, $actor));
        } catch (UniqueConstraintViolationException) {
            // Someone (or the customer) started it at the same moment.
            throw ConversationConflict::anotherActive();
        }

        try {
            $this->accept->handle($conversation, $actor);

            return ['conversation' => $conversation->refresh(), 'outcome' => 'created'];
        } catch (AuthorizationException|ValidationException|ConversationConflict|HttpExceptionInterface) {
            // The queue rules (capacity, «Necesita información», reach) keep it
            // in its queue: it is still there for whoever may attend it.
            return ['conversation' => $conversation->refresh(), 'outcome' => 'queued'];
        } catch (Throwable $e) {
            report($e);

            return ['conversation' => $conversation->refresh(), 'outcome' => 'queued'];
        }
    }

    private function start(Conversation $imported, Connection $connection, User $actor): Conversation
    {
        $department = $this->departmentFor($imported, $connection);

        // Same lock order as an inbound arrival (ReceiveInboundMessage).
        if ($department?->distribution_mode === DistributionMode::Automatic) {
            Department::query()->whereKey($department->id)->lockForUpdate()->value('id');
        }

        $conversation = new Conversation([
            'connection_id' => $connection->id,
            'contact_id' => $imported->contact_id,
            'department_id' => $department?->id,
        ]);
        $conversation->forceFill(app(QueueRules::class)->born($department?->id))->save();

        $this->lifecycle->record($conversation, ConversationEventType::Created, $actor, [
            'to_department_id' => $department?->id,
            'metadata' => ['source' => 'continued_import', 'continued_from_conversation_id' => $imported->id],
        ]);

        return $conversation;
    }

    /**
     * The imported conversation's department while it is active and the
     * actor could attend it; otherwise the connection's usual routing.
     */
    private function departmentFor(Conversation $imported, Connection $connection): ?Department
    {
        $previous = $imported->department_id === null ? null : Department::query()->active()->find($imported->department_id);

        if ($previous !== null) {
            $authorized = array_map('intval', $connection->departments()->pluck('departments.id')->all());

            if ($authorized === [] || in_array($previous->id, $authorized, true)) {
                return $previous;
            }
        }

        return $this->router->route($connection)['department'];
    }
}
