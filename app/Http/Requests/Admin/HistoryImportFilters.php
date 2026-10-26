<?php

namespace App\Http\Requests\Admin;

use App\Enums\HistoryImportDecision;
use App\Enums\HistoryImportRowState;
use App\Models\HistoryImportConversation;
use App\Services\Migration\History\HistoryInventory;
use Illuminate\Validation\Rule;

/**
 * Validation and normalization of the inventory filters of a history
 * import batch, shared by the inventory list, bulk decisions and rules
 * (a rule's criteria are these same filters).
 *
 * @phpstan-import-type InventoryFilters from HistoryInventory
 */
class HistoryImportFilters
{
    /**
     * Keys of yes/no filters.
     *
     * @var list<string>
     */
    public const FLAGS = ['duplicates', 'empty', 'with_attachments', 'missing_attachments', 'group', 'has_issues'];

    /**
     * Keys of text filters.
     *
     * @var list<string>
     */
    public const TEXTS = ['search', 'agent', 'department', 'connection', 'status', 'tag'];

    /**
     * @return array<string, array<mixed>>
     */
    public static function rules(string $prefix = ''): array
    {
        $rules = [
            $prefix.'date_from' => ['nullable', 'date_format:Y-m-d'],
            $prefix.'date_to' => ['nullable', 'date_format:Y-m-d'],
            $prefix.'inactive_since' => ['nullable', 'date_format:Y-m-d'],
            $prefix.'decision' => ['nullable', 'string', Rule::in([...HistoryImportDecision::values(), HistoryInventory::UNDECIDED])],
            $prefix.'kind' => ['nullable', 'string', Rule::in([HistoryImportConversation::KIND_TICKET, HistoryImportConversation::KIND_CONTACT, HistoryImportConversation::KIND_CHAT_TEXT])],
            $prefix.'state' => ['nullable', 'string', Rule::in(array_column(HistoryImportRowState::cases(), 'value'))],
        ];

        foreach (self::TEXTS as $key) {
            $rules[$prefix.$key] = ['nullable', 'string', 'max:160'];
        }

        foreach (self::FLAGS as $key) {
            $rules[$prefix.$key] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return InventoryFilters
     */
    public static function normalize(array $input): array
    {
        $filters = [];

        foreach ([...self::TEXTS, 'date_from', 'date_to', 'inactive_since', 'decision', 'kind', 'state'] as $key) {
            $value = $input[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $filters[$key] = trim($value);
            }
        }

        foreach (self::FLAGS as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== null) {
                $value = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

                if ($value === true) {
                    $filters[$key] = true;
                }
            }
        }

        return $filters;
    }
}
