<?php

namespace App\Actions\Tags;

use App\Enums\AuditAction;
use App\Enums\ColorSwatch;
use App\Http\Requests\Tags\TagRequest;
use App\Models\Tag;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateTag
{
    public function __construct(
        private AuditLogger $audit,
        private GenerateTagSlug $generateSlug,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, color: ColorSwatch}  $data
     */
    public function handle(Tag $tag, array $data, User $actor): Tag
    {
        return DB::transaction(function () use ($tag, $data, $actor): Tag {
            $tag->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'color' => $data['color'],
            ]);

            if (! $tag->isDirty()) {
                return $tag;
            }

            if ($tag->isDirty('name')) {
                $tag->slug = $this->generateSlug->handle($data['name'], $tag->id);
            }

            $changes = [];

            foreach ($tag->getDirty() as $attribute => $value) {
                if ($attribute === 'slug') {
                    continue;
                }

                $original = $tag->getOriginal($attribute);
                $changes[$attribute] = [
                    'old' => $original instanceof ColorSwatch ? $original->value : $original,
                    'new' => $value instanceof ColorSwatch ? $value->value : $value,
                ];
            }

            try {
                $tag->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['name' => TagRequest::DUPLICATE_NAME_MESSAGE]);
            }

            $this->audit->record(AuditAction::TagUpdated, $tag, ['changes' => $changes], $actor);

            return $tag;
        });
    }
}
