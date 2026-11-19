<?php

namespace App\Actions\Chatbot;

use App\Enums\AuditAction;
use App\Enums\ChatbotOptionAction;
use App\Models\ChatbotMenu;
use App\Models\ChatbotMenuOption;
use App\Models\Connection;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Saves the chatbot menu of a connection with its whole tree of options in
 * their order, in one transaction, and audits what changed on the
 * connection (the menu belongs to it). Nothing is written when the data
 * matches.
 *
 * The options are replaced as a whole. Customers choosing right now keep
 * going: a session whose submenu disappeared continues from the main menu
 * (ChatbotTree::submenu).
 */
class SaveChatbotMenu
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{is_enabled: bool, greeting: string|null, retry_message: string|null, navigation_hint: string|null, max_attempts: int, timeout_minutes: int, options: list<array<string, mixed>>}  $data
     * @return bool Whether anything changed.
     */
    public function handle(Connection $connection, array $data, User $actor): bool
    {
        return DB::transaction(function () use ($connection, $data, $actor): bool {
            $menu = ChatbotMenu::query()->where('connection_id', $connection->id)->lockForUpdate()->first()
                ?? new ChatbotMenu(['is_enabled' => false, 'max_attempts' => 2]);
            $before = $menu->exists ? $this->snapshot($menu) : null;

            $settings = [
                'is_enabled' => $data['is_enabled'],
                'greeting' => $data['greeting'],
                'retry_message' => $data['retry_message'],
                'navigation_hint' => $data['navigation_hint'],
                'max_attempts' => $data['max_attempts'],
                'timeout_minutes' => $data['timeout_minutes'],
            ];

            $menu->forceFill(['connection_id' => $connection->id])->fill($settings);

            $after = [...$settings, 'options' => $this->describe($data['options'])];

            if ($before === $after) {
                return false;
            }

            $menu->forceFill(['updated_by_user_id' => $actor->id])->save();

            // Children go with their parent (cascade).
            $menu->allOptions()->whereNull('parent_id')->delete();
            $menu->allOptions()->delete();
            $this->create($menu, $data['options'], null);

            $changes = [];

            foreach ($after as $field => $value) {
                $old = $before[$field] ?? null;

                if ($old !== $value) {
                    $changes[$field] = ['old' => $old, 'new' => $value];
                }
            }

            $this->audit->record(AuditAction::ChatbotMenuUpdated, $connection, ['changes' => $changes], $actor);

            return true;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $options
     */
    private function create(ChatbotMenu $menu, array $options, ?int $parentId): void
    {
        foreach ($options as $position => $option) {
            /** @var ChatbotOptionAction $action */
            $action = $option['action'];

            $created = $menu->allOptions()->create([
                'parent_id' => $parentId,
                'action' => $action,
                'department_id' => $action === ChatbotOptionAction::Transfer ? $option['department_id'] : null,
                'label' => $option['label'],
                'reply_message' => $option['reply_message'],
                'position' => $position,
            ]);

            if ($action === ChatbotOptionAction::Submenu) {
                $this->create($menu, $option['children'], $created->id);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ChatbotMenu $menu): array
    {
        $all = $menu->allOptions()->get();
        $byParent = $all->groupBy(fn (ChatbotMenuOption $option): int => $option->parent_id ?? 0);

        $tree = function (int $parentId) use (&$tree, $byParent): array {
            return array_values($byParent->get($parentId, collect())->map(fn (ChatbotMenuOption $option): array => [
                'action' => $option->action,
                'department_id' => $option->department_id,
                'label' => $option->label,
                'reply_message' => $option->reply_message,
                'children' => $tree($option->id),
            ])->all());
        };

        return [
            'is_enabled' => $menu->is_enabled,
            'greeting' => $menu->greeting,
            'retry_message' => $menu->retry_message,
            'navigation_hint' => $menu->navigation_hint,
            'max_attempts' => $menu->max_attempts,
            'timeout_minutes' => $menu->timeout_minutes,
            'options' => $this->describe($tree(0)),
        ];
    }

    /**
     * Options as the audit log reads them, one line each with its number
     * path: «1.2. Soy dueño de negocio → Pasa a Solicitudes · con respuesta».
     *
     * @param  list<array<string, mixed>>  $options
     * @return list<string>
     */
    private function describe(array $options): array
    {
        $names = Department::query()->pluck('name', 'id');
        $lines = [];

        $walk = function (array $level, string $prefix) use (&$walk, &$lines, $names): void {
            foreach ($level as $index => $option) {
                $number = $prefix.($index + 1);
                /** @var ChatbotOptionAction $action */
                $action = $option['action'];
                $target = $action === ChatbotOptionAction::Transfer
                    ? 'Pasa a '.($names[$option['department_id']] ?? "Departamento #{$option['department_id']}")
                    : $action->label();

                $lines[] = "{$number}. ".($option['label'] ?? '(sin texto)')." → {$target}"
                    .($option['reply_message'] !== null ? ' · con respuesta: '.$option['reply_message'] : '');

                $walk($option['children'] ?? [], $number.'.');
            }
        };

        $walk($options, '');

        return $lines;
    }
}
