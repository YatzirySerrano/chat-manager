<?php

namespace App\Actions\Tags;

use App\Enums\AuditAction;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SyncContactTags
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Replace the contact's tags with the given ones. Only active tags can be
     * newly attached; inactive tags the contact already carries may remain.
     *
     * @param  list<int>  $tagIds
     * @return array{added: list<string>, removed: list<string>}
     */
    public function handle(Contact $contact, array $tagIds, User $actor): array
    {
        return DB::transaction(function () use ($contact, $tagIds, $actor): array {
            Contact::query()->whereKey($contact->id)->lockForUpdate()->first();

            $currentIds = $contact->tags()->pluck('tags.id')->map(fn (mixed $id): int => (int) $id)->all();
            $requestedIds = array_values(array_unique($tagIds));

            $addedIds = array_values(array_diff($requestedIds, $currentIds));
            $removedIds = array_values(array_diff($currentIds, $requestedIds));

            $added = Tag::query()->whereKey($addedIds)->orderBy('name')->get(['id', 'name', 'is_active']);
            $inactive = $added->reject(fn (Tag $tag): bool => $tag->is_active);

            if ($inactive->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'tag_ids' => $inactive->count() === 1
                        ? "La etiqueta «{$inactive->first()->name}» está inactiva y no puede asignarse."
                        : 'Las etiquetas «'.$inactive->pluck('name')->join('», «', '» y «').'» están inactivas y no pueden asignarse.',
                ]);
            }

            $removed = Tag::query()->whereKey($removedIds)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Tag $tag): string => $tag->name)
                ->all();

            if ($addedIds === [] && $removedIds === []) {
                return ['added' => [], 'removed' => []];
            }

            if ($removedIds !== []) {
                $contact->tags()->detach($removedIds);
            }

            if ($addedIds !== []) {
                $now = now();
                $contact->tags()->attach(array_fill_keys($addedIds, [
                    'tagged_by_user_id' => $actor->id,
                    'created_at' => $now,
                ]));
            }

            $changes = [
                'added' => array_values($added->map(fn (Tag $tag): string => $tag->name)->all()),
                'removed' => array_values($removed),
            ];

            $this->audit->record(AuditAction::ContactTagsChanged, $contact, $changes, $actor);

            return $changes;
        });
    }
}
