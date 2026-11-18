<?php

namespace App\Actions\Chatbot;

use App\Enums\AuditAction;
use App\Models\ChatbotKeywordRule;
use App\Models\Connection;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a keyword rule of the chatbot with its connection and
 * department scopes, in one transaction, and audits it. A new rule goes
 * last (the first matching rule answers a message). An update that changes
 * nothing writes nothing.
 */
class SaveChatbotKeywordRule
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{name: string, keywords: list<string>, reply_message: string, is_active: bool, throttle_minutes: int, connection_ids: list<int>, department_ids: list<int>}  $data
     * @return array{rule: ChatbotKeywordRule, changed: bool}
     */
    public function handle(?ChatbotKeywordRule $rule, array $data, User $actor): array
    {
        return DB::transaction(function () use ($rule, $data, $actor): array {
            $creating = $rule === null;
            $rule ??= new ChatbotKeywordRule;
            $before = $creating ? null : $this->snapshot($rule);

            $rule->fill([
                'name' => $data['name'],
                'keywords' => $data['keywords'],
                'reply_message' => $data['reply_message'],
                'is_active' => $data['is_active'],
                'throttle_minutes' => $data['throttle_minutes'],
            ]);

            $after = [
                'name' => $data['name'],
                'keywords' => $data['keywords'],
                'reply_message' => $data['reply_message'],
                'is_active' => $data['is_active'],
                'throttle_minutes' => $data['throttle_minutes'],
                'connections' => $this->names(Connection::class, $data['connection_ids']),
                'departments' => $this->names(Department::class, $data['department_ids']),
            ];

            if ($before === $after) {
                return ['rule' => $rule, 'changed' => false];
            }

            if ($creating) {
                $rule->forceFill([
                    'position' => ((int) ChatbotKeywordRule::query()->max('position')) + 1,
                    'created_by_user_id' => $actor->id,
                ]);
            }

            $rule->forceFill(['updated_by_user_id' => $actor->id])->save();
            $rule->connections()->sync($data['connection_ids']);
            $rule->departments()->sync($data['department_ids']);

            if ($creating) {
                $this->audit->record(AuditAction::ChatbotRuleCreated, $rule, $after, $actor);
            } else {
                $changes = [];

                foreach ($after as $field => $value) {
                    if (($before[$field] ?? null) !== $value) {
                        $changes[$field] = ['old' => $before[$field] ?? null, 'new' => $value];
                    }
                }

                $this->audit->record(AuditAction::ChatbotRuleUpdated, $rule, ['changes' => $changes], $actor);
            }

            return ['rule' => $rule, 'changed' => true];
        });
    }

    /**
     * @return array{name: string, keywords: list<string>, reply_message: string, is_active: bool, throttle_minutes: int, connections: list<string>, departments: list<string>}
     */
    private function snapshot(ChatbotKeywordRule $rule): array
    {
        return [
            'name' => $rule->name,
            'keywords' => $rule->keywords,
            'reply_message' => $rule->reply_message,
            'is_active' => $rule->is_active,
            'throttle_minutes' => $rule->throttle_minutes,
            'connections' => $this->names(Connection::class, $rule->connections()->pluck('connections.id')->all()),
            'departments' => $this->names(Department::class, $rule->departments()->pluck('departments.id')->all()),
        ];
    }

    /**
     * Names in id order, for the audit log («Todas» when empty is shown by the screen).
     *
     * @param  class-string<Connection|Department>  $model
     * @param  array<int, mixed>  $ids
     * @return list<string>
     */
    private function names(string $model, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return array_values($model::query()->whereKey($ids)->orderBy('id')->pluck('name')->map(fn ($name): string => (string) $name)->all());
    }
}
