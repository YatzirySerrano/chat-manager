<?php

namespace App\Actions\Tags;

use App\Enums\AuditAction;
use App\Enums\ColorSwatch;
use App\Http\Requests\Tags\TagRequest;
use App\Models\Tag;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\AppColor;
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
     * Nothing is written (no save, no audit entry) when the data matches.
     *
     * @param  array{name: string, description?: string|null, color: AppColor|ColorSwatch|string}  $data
     * @return bool Whether anything changed.
     */
    public function handle(Tag $tag, array $data, User $actor): bool
    {
        return DB::transaction(function () use ($tag, $data, $actor): bool {
            $tag->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'color' => $data['color'],
            ]);

            if (! $tag->isDirty()) {
                return false;
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
                    'old' => $original instanceof AppColor ? $original->value : $original,
                    'new' => $value instanceof AppColor ? $value->value : $value,
                ];
            }

            try {
                $tag->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['name' => TagRequest::DUPLICATE_NAME_MESSAGE]);
            }

            $this->audit->record(AuditAction::TagUpdated, $tag, ['changes' => $changes], $actor);

            return true;
        });
    }
}
