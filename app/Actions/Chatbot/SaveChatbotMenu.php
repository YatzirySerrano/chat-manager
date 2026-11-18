<?php

namespace App\Actions\Chatbot;

use App\Enums\AuditAction;
use App\Models\ChatbotMenu;
use App\Models\Connection;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Saves the welcome menu of a connection with its options in their order,
 * in one transaction, and audits what changed on the connection (the menu
 * belongs to it). Nothing is written when the data matches.
 *
 * Changing the menu never touches conversations already choosing: they
 * answer against the options in force when they write
 * (ChatbotConversations).
 */
class SaveChatbotMenu
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{is_enabled: bool, greeting: string|null, retry_message: string|null, max_attempts: int, options: list<array{department_id: int, label: string|null, reply_message: string|null}>}  $data
     * @return bool Whether anything changed.
     */
    public function handle(Connection $connection, array $data, User $actor): bool
    {
        return DB::transaction(function () use ($connection, $data, $actor): bool {
            $menu = ChatbotMenu::query()->where('connection_id', $connection->id)->lockForUpdate()->first()
                ?? new ChatbotMenu(['is_enabled' => false, 'max_attempts' => 2]);
            $before = $menu->exists ? $this->snapshot($menu) : null;

            $menu->forceFill(['connection_id' => $connection->id])->fill([
                'is_enabled' => $data['is_enabled'],
                'greeting' => $data['greeting'],
                'retry_message' => $data['retry_message'],
                'max_attempts' => $data['max_attempts'],
            ]);

            $after = [
                'is_enabled' => $data['is_enabled'],
                'greeting' => $data['greeting'],
                'retry_message' => $data['retry_message'],
                'max_attempts' => $data['max_attempts'],
                'options' => $this->describeOptions($data['options']),
            ];

            if ($before === $after) {
                return false;
            }

            $menu->forceFill(['updated_by_user_id' => $actor->id])->save();

            // Options are replaced as a whole: their identity is the
            // department, and the order is their position.
            $menu->options()->delete();

            foreach ($data['options'] as $position => $option) {
                $menu->options()->create([...$option, 'position' => $position]);
            }

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
     * @return array{is_enabled: bool, greeting: string|null, retry_message: string|null, max_attempts: int, options: list<string>}
     */
    private function snapshot(ChatbotMenu $menu): array
    {
        $options = $menu->options()->get(['department_id', 'label', 'reply_message'])
            ->map(fn ($option): array => [
                'department_id' => $option->department_id,
                'label' => $option->label,
                'reply_message' => $option->reply_message,
            ])
            ->all();

        return [
            'is_enabled' => $menu->is_enabled,
            'greeting' => $menu->greeting,
            'retry_message' => $menu->retry_message,
            'max_attempts' => $menu->max_attempts,
            'options' => $this->describeOptions(array_values($options)),
        ];
    }

    /**
     * Options as the audit log reads them: «1. Mesa de control (Mesa) ·
     * con respuesta».
     *
     * @param  list<array{department_id: int, label: string|null, reply_message: string|null}>  $options
     * @return list<string>
     */
    private function describeOptions(array $options): array
    {
        $names = Department::query()->whereKey(array_column($options, 'department_id'))->pluck('name', 'id');

        return array_map(fn (array $option, int $index): string => ($index + 1).'. '.($names[$option['department_id']] ?? "Departamento #{$option['department_id']}")
            .($option['label'] !== null ? " («{$option['label']}»)" : '')
            .($option['reply_message'] !== null ? ' · con respuesta: '.$option['reply_message'] : ''), $options, array_keys($options));
    }
}
