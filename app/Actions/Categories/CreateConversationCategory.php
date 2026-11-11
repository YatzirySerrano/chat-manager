<?php

namespace App\Actions\Categories;

use App\Enums\AuditAction;
use App\Enums\ColorSwatch;
use App\Http\Requests\Categories\ConversationCategoryRequest;
use App\Models\ConversationCategory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ServiceRules;
use App\Support\AppColor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateConversationCategory
{
    public function __construct(
        private AuditLogger $audit,
        private ServiceRules $rules,
    ) {}

    /**
     * A new category starts without rules of its own: it inherits them all.
     *
     * @param  array{name: string, description?: string|null, color: AppColor|ColorSwatch|string}  $data
     */
    public function handle(array $data, User $actor): ConversationCategory
    {
        return DB::transaction(function () use ($data, $actor): ConversationCategory {
            $category = new ConversationCategory([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'color' => $data['color'],
            ]);

            try {
                $category->save();
            } catch (UniqueConstraintViolationException) {
                // Another request created a category with the same name after validation passed.
                throw ValidationException::withMessages(['name' => ConversationCategoryRequest::DUPLICATE_NAME_MESSAGE]);
            }

            $this->audit->record(AuditAction::ConversationCategoryCreated, $category, [
                'color' => $category->color->value,
            ], $actor);

            $this->rules->flush();

            return $category;
        });
    }
}
