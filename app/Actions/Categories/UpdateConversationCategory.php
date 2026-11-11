<?php

namespace App\Actions\Categories;

use App\Enums\AuditAction;
use App\Enums\ColorSwatch;
use App\Http\Requests\Categories\ConversationCategoryRequest;
use App\Models\ConversationCategory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\AppColor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateConversationCategory
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Name, description and color only; the rules of the category are saved
     * by UpdateConversationCategoryRules. Nothing is written (no save, no
     * audit entry) when the data matches.
     *
     * @param  array{name: string, description?: string|null, color: AppColor|ColorSwatch|string}  $data
     * @return bool Whether anything changed.
     */
    public function handle(ConversationCategory $category, array $data, User $actor): bool
    {
        return DB::transaction(function () use ($category, $data, $actor): bool {
            $category->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'color' => $data['color'],
            ]);

            if (! $category->isDirty()) {
                return false;
            }

            $changes = [];

            foreach ($category->getDirty() as $attribute => $value) {
                $original = $category->getOriginal($attribute);
                $changes[$attribute] = [
                    'old' => $original instanceof AppColor ? $original->value : $original,
                    'new' => $value instanceof AppColor ? $value->value : $value,
                ];
            }

            try {
                $category->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['name' => ConversationCategoryRequest::DUPLICATE_NAME_MESSAGE]);
            }

            $this->audit->record(AuditAction::ConversationCategoryUpdated, $category, ['changes' => $changes], $actor);

            return true;
        });
    }
}
