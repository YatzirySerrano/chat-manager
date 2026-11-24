<?php

namespace App\Actions\Conversations;

use App\Enums\ConnectionChannel;
use App\Enums\ContactSource;
use App\Enums\ContactStatus;
use App\Enums\ConversationEventType;
use App\Enums\ConversationReadiness;
use App\Enums\ConversationStatus;
use App\Enums\DataScope;
use App\Enums\DistributionMode;
use App\Enums\Permission;
use App\Exceptions\ConversationConflict;
use App\Models\Connection;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\User;
use App\Services\Conversations\ConversationLifecycle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * «Iniciar chat»: an agent writes first to a customer through a WhatsApp
 * connection (a phone linked by QR or WhatsApp Cloud API), to a contact of
 * the directory, one who wrote recently or a number typed by hand. In order:
 *  1. the contact already has an unclosed conversation on that connection:
 *     the actor's own (or one waiting in a queue they may accept) is opened;
 *     one attended by somebody else is refused, naming who attends it;
 *  2. otherwise a new conversation starts in the chosen department and the
 *     actor accepts it when the queue rules allow it; if not, it waits in
 *     its queue like any other.
 *
 * A typed number that matches no contact registers a provisional contact
 * (as an unknown sender who wrote would be). Sending stays with the usual
 * composer: free text through WhatsApp Web, and through Cloud API an
 * approved template while the customer has not written in 24 hours.
 */
class StartConversation
{
    public function __construct(
        private ConversationLifecycle $lifecycle,
        private AcceptConversation $accept,
    ) {}

    /**
     * WhatsApp connections the actor may start a chat from: active, not
     * deleted, and reachable through one of their departments (any
     * connection for a global scope or one without department restriction).
     *
     * @return Collection<int, Connection>
     */
    public function connections(User $actor): Collection
    {
        $query = Connection::query()
            ->where('channel', ConnectionChannel::Whatsapp)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->with('departments:id')
            ->orderBy('name');

        $connections = $query->get();

        if ($actor->dataScope() === DataScope::Global) {
            return $connections;
        }

        $own = $actor->departmentIds();

        return $connections->filter(fn (Connection $connection): bool => $connection->departments->isEmpty()
            || $connection->departments->pluck('id')->map(fn ($id): int => (int) $id)->intersect($own)->isNotEmpty())->values();
    }

    /**
     * Active departments of the connection the actor works in.
     *
     * @return Collection<int, Department>
     */
    public function departments(User $actor, Connection $connection): Collection
    {
        $authorized = array_map('intval', $connection->departments()->pluck('departments.id')->all());

        return Department::query()
            ->active()
            ->when($authorized !== [], fn (Builder $query) => $query->whereKey($authorized))
            ->when($actor->dataScope() !== DataScope::Global, fn (Builder $query) => $query->whereKey($actor->departmentIds()))
            ->orderBy('name')
            ->get(['id', 'name', 'color']);
    }

    /**
     * @return array{conversation: Conversation, outcome: 'existing'|'created'|'queued'}
     *
     * @throws AuthorizationException
     * @throws ConversationConflict
     * @throws ValidationException
     */
    public function handle(User $actor, Connection $connection, ?Contact $contact, ?string $phoneE164, ?string $name, ?int $departmentId): array
    {
        if (! $actor->holdsPermission(Permission::ReplyConversations)) {
            throw new AuthorizationException('No tienes permiso para responder conversaciones.');
        }

        if (! $this->connections($actor)->contains('id', $connection->id)) {
            throw ValidationException::withMessages(['connection_id' => 'Elige una conexión de WhatsApp activa de tus departamentos.']);
        }

        $refusal = $connection->sendingBlockReason();

        if ($refusal !== null) {
            throw ValidationException::withMessages(['connection_id' => $refusal]);
        }

        $department = $departmentId === null ? null : $this->departments($actor, $connection)->firstWhere('id', $departmentId);

        if ($department === null) {
            throw ValidationException::withMessages(['department_id' => 'Elige un departamento de esta conexión en el que trabajes.']);
        }

        $contact ??= $this->contactFor($actor, (string) $phoneE164, $name);

        if ($contact->isDeleted()) {
            throw ValidationException::withMessages(['contact_id' => 'Este contacto está eliminado: restáuralo antes de escribirle.']);
        }

        if ($contact->phone_e164 === null && ! $contact->identities()->where('channel', ConnectionChannel::Whatsapp)->exists()) {
            throw ValidationException::withMessages(['contact_id' => 'Este contacto no tiene un número de WhatsApp al cual escribir.']);
        }

        $unclosed = Conversation::query()
            ->where('connection_id', $connection->id)
            ->where('contact_id', $contact->id)
            ->live()
            ->notClosed()
            ->with('assignee:id,name')
            ->orderByDesc('id')
            ->first();

        if ($unclosed !== null) {
            return $this->continueUnclosed($unclosed, $actor);
        }

        try {
            $conversation = DB::transaction(fn (): Conversation => $this->start($connection, $contact, $department, $actor));
        } catch (UniqueConstraintViolationException) {
            // The customer (or a colleague) started it at the same moment.
            throw ConversationConflict::anotherActive();
        }

        return ['conversation' => $conversation, 'outcome' => $this->acceptOrQueue($conversation, $actor)];
    }

    /**
     * @return array{conversation: Conversation, outcome: 'existing'|'queued'}
     */
    private function continueUnclosed(Conversation $conversation, User $actor): array
    {
        $assignee = $conversation->assignee;

        if ($assignee !== null && $assignee->id !== $actor->id && $conversation->status !== ConversationStatus::Pending) {
            throw new ConversationConflict("Este contacto ya está siendo atendido por el usuario {$assignee->name}.");
        }

        if ($assignee?->id === $actor->id) {
            return ['conversation' => $conversation, 'outcome' => 'existing'];
        }

        $outcome = $this->acceptOrQueue($conversation, $actor);

        if ($outcome === 'queued' && ! $actor->can('view', $conversation)) {
            throw new ConversationConflict('Este contacto ya tiene una conversación esperando en la cola de otro departamento.');
        }

        return ['conversation' => $conversation->refresh(), 'outcome' => $outcome === 'created' ? 'existing' : 'queued'];
    }

    /**
     * @return 'created'|'queued'
     */
    private function acceptOrQueue(Conversation $conversation, User $actor): string
    {
        try {
            $this->accept->handle($conversation, $actor);

            return 'created';
        } catch (AuthorizationException|ValidationException|ConversationConflict|HttpExceptionInterface) {
            // The queue rules (capacity, «Necesita información», reach) keep
            // it in its queue for whoever may attend it.
            return 'queued';
        } catch (Throwable $e) {
            report($e);

            return 'queued';
        }
    }

    private function start(Connection $connection, Contact $contact, Department $department, User $actor): Conversation
    {
        // Same lock order as an inbound arrival (ReceiveInboundMessage).
        if ($department->distribution_mode === DistributionMode::Automatic) {
            Department::query()->whereKey($department->id)->lockForUpdate()->value('id');
        }

        $conversation = new Conversation([
            'connection_id' => $connection->id,
            'contact_id' => $contact->id,
            'department_id' => $department->id,
        ]);
        // Written by an agent, the request is ready to be attended: it never
        // starts as «Necesita información».
        $conversation->forceFill(['readiness' => ConversationReadiness::Ready, 'queued_at' => now()])->save();

        $this->lifecycle->record($conversation, ConversationEventType::Created, $actor, [
            'to_department_id' => $department->id,
            'metadata' => ['source' => 'started_by_agent'],
        ]);

        return $conversation;
    }

    /**
     * The contact of a typed number: the one already registered with that
     * number (or that WhatsApp identity), else a new provisional contact.
     */
    private function contactFor(User $actor, string $phoneE164, ?string $name): Contact
    {
        if ($phoneE164 === '') {
            throw ValidationException::withMessages(['phone' => 'Escribe un número de WhatsApp válido o elige un contacto.']);
        }

        $digits = ltrim($phoneE164, '+');

        $existing = Contact::query()
            ->where('status', ContactStatus::Active)
            ->where(fn (Builder $match) => $match
                ->where('phone_e164', $phoneE164)
                ->orWhereHas('identities', fn (Builder $identities) => $identities
                    ->where('channel', ConnectionChannel::Whatsapp)
                    ->where('external_id', $digits)))
            ->orderByDesc('last_interaction_at')
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $name = $name === null ? '' : trim($name);

        $contact = new Contact([
            'name' => $name !== '' ? mb_substr($name, 0, 255) : $phoneE164,
            'phone_e164' => $phoneE164,
        ]);
        $contact->forceFill([
            'status' => ContactStatus::Active,
            'source' => ContactSource::Manual,
            'created_by_user_id' => $actor->id,
        ])->save();

        return $contact;
    }
}
