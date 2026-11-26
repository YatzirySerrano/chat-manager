<?php

namespace App\Actions\Conversations;

use App\Actions\Contacts\SaveContactToDirectory;
use App\Enums\AuditAction;
use App\Events\Conversations\ConversationChanged;
use App\Models\Conversation;
use App\Models\Tag;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationLifecycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Replaces the tags of a conversation, which are its CONTACT's tags (see
 * Conversation::tags()): they follow the person to every conversation.
 * Only active tags can be newly
 * attached; deleted tags it already carries may stay as history. The
 * conversation row is locked so two people editing at once never lose each
 * other's changes silently, and the actor is authorized again under lock.
 * Tags never change the status, the version or the customer window.
 */
class SyncConversationTags
{
    public function __construct(
        private ConversationLifecycle $lifecycle,
        private AuditLogger $audit,
        private SaveContactToDirectory $saveContact,
    ) {}

    /**
     * @param  list<int>  $tagIds
     * @return array{added: list<string>, removed: list<string>}
     */
    public function handle(Conversation $conversation, array $tagIds, User $actor): array
    {
        return DB::transaction(function () use ($conversation, $tagIds, $actor): array {
            $conversation = $this->lifecycle->lock($conversation);
            $actor = $this->lifecycle->authorize($actor, 'tag', $conversation);

            $currentIds = $conversation->tags()->pluck('tags.id')->map(fn (mixed $id): int => (int) $id)->all();
            $requestedIds = array_values(array_unique(array_map('intval', $tagIds)));

            $addedIds = array_values(array_diff($requestedIds, $currentIds));
            $removedIds = array_values(array_diff($currentIds, $requestedIds));

            if ($addedIds === [] && $removedIds === []) {
                return ['added' => [], 'removed' => []];
            }

            $added = Tag::query()->whereKey($addedIds)->orderBy('name')->get(['id', 'name', 'is_active']);

            if ($added->count() !== count($addedIds)) {
                throw ValidationException::withMessages(['tag_ids' => 'Alguna de las etiquetas elegidas no existe.']);
            }

            $deleted = $added->filter(fn (Tag $tag): bool => $tag->isDeleted());

            if ($deleted->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'tag_ids' => $deleted->count() === 1
                        ? "La etiqueta «{$deleted->first()->name}» está eliminada y no puede asignarse."
                        : 'Las etiquetas «'.$deleted->pluck('name')->join('», «', '» y «').'» están eliminadas y no pueden asignarse.',
                ]);
            }

            $removed = Tag::query()->whereKey($removedIds)->orderBy('name')->pluck('name')->all();

            if ($removedIds !== []) {
                $conversation->tags()->detach($removedIds);
            }

            if ($addedIds !== []) {
                $conversation->tags()->attach(array_fill_keys($addedIds, [
                    'tagged_by_user_id' => $actor->id,
                    'created_at' => now(),
                ]));
            }

            $changes = [
                'added' => array_values($added->map(fn (Tag $tag): string => $tag->name)->all()),
                'removed' => array_values(array_map('strval', $removed)),
            ];

            $this->audit->record(AuditAction::ConversationTagsChanged, $conversation, $changes, $actor);
            // The tags live on the contact: its history shows the change too.
            if ($conversation->contact !== null) {
                $this->audit->record(AuditAction::ContactTagsChanged, $conversation->contact, $changes, $actor);

                // Tagging somebody means the team is working with them: an
                // unknown sender becomes a saved contact listed in Contactos.
                $contact = $conversation->contact;
                if ($addedIds !== [] && ! $contact->isSaved() && $actor->can('saveToDirectory', $contact)) {
                    $this->saveContact->promote($contact, $actor, 'tags');
                }
            }

            ConversationChanged::dispatch($conversation->id, 'tags', $actor->id);

            return $changes;
        });
    }
}
