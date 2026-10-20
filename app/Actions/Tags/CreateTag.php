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

class CreateTag
{
    public function __construct(
        private AuditLogger $audit,
        private GenerateTagSlug $generateSlug,
    ) {}

    /**
     * @param  array{name: string, description?: string|null, color: ColorSwatch}  $data
     */
    public function handle(array $data, User $actor): Tag
    {
        return DB::transaction(function () use ($data, $actor): Tag {
            $tag = new Tag([
                'name' => $data['name'],
                'slug' => $this->generateSlug->handle($data['name']),
                'description' => $data['description'] ?? null,
                'color' => $data['color'],
            ]);
            $tag->created_by_user_id = $actor->id;

            try {
                $tag->save();
            } catch (UniqueConstraintViolationException) {
                // Another request created a tag with the same name after validation passed.
                throw ValidationException::withMessages(['name' => TagRequest::DUPLICATE_NAME_MESSAGE]);
            }

            $this->audit->record(AuditAction::TagCreated, $tag, [
                'color' => $tag->color->value,
            ], $actor);

            return $tag;
        });
    }
}
