<?php

namespace App\Actions\Templates;

use App\Enums\MessageTemplateCategory;
use App\Enums\MessageTemplateStatus;
use App\Enums\RecordState;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Filters of the templates screen and its export: text, connection, status,
 * category, language and record state, always within the connections the
 * actor can see.
 */
class MessageTemplateListQuery
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'connection' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(MessageTemplateStatus::class)],
            'category' => ['nullable', Rule::enum(MessageTemplateCategory::class)],
            'language' => ['nullable', 'string', 'max:15'],
            'record' => ['nullable', Rule::enum(RecordState::class)],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{search: string|null, connection: int|null, status: string|null, category: string|null, language: string|null, record: RecordState}
     */
    public function filters(User $actor, array $input): array
    {
        $text = fn (string $key): ?string => is_string($input[$key] ?? null) && trim($input[$key]) !== '' ? trim($input[$key]) : null;

        return [
            'search' => $text('search'),
            'connection' => is_numeric($input['connection'] ?? null) ? (int) $input['connection'] : null,
            'status' => $text('status'),
            'category' => $text('category'),
            'language' => $text('language'),
            'record' => RecordState::resolveFor($actor, $input['record'] ?? null),
        ];
    }

    /**
     * @param  array{search: string|null, connection: int|null, status: string|null, category: string|null, language: string|null, record: RecordState}  $filters
     * @return Builder<MessageTemplate>
     */
    public function handle(User $actor, array $filters): Builder
    {
        return MessageTemplate::query()
            ->visibleTo($actor)
            ->inRecordState($filters['record'])
            ->when($filters['search'], fn (Builder $query, string $search) => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%'))
            ->when($filters['connection'], fn (Builder $query, int $id) => $query->where('connection_id', $id))
            ->when($filters['status'], fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['category'], fn (Builder $query, string $category) => $query->where('category', $category))
            ->when($filters['language'], fn (Builder $query, string $language) => $query->where('language', $language))
            ->orderBy('name')
            ->orderBy('language')
            ->orderBy('id');
    }
}
