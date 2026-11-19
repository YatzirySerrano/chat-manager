<?php

namespace App\Actions\Ai;

use App\Enums\AiVendor;
use App\Enums\AuditAction;
use App\Models\Ai\AiConnectionSetting;
use App\Models\Ai\AiSetting;
use App\Models\Connection;
use App\Models\User;
use App\Services\Ai\AiAssistant;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Saves the configuration of «Inteligencia artificial» and, separately,
 * whether the assistant answers on a connection. Both are audited by what
 * changed; the API key only as «replaced» or «removed», never its value.
 * Nothing is written when the data matches.
 */
class SaveAiSettings
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  array{provider: AiVendor, model: string|null, api_key: string|null, remove_api_key: bool, business_info: string|null, catalog: list<array{question: string, answer: string}>, instructions: string|null, assistant_name: string|null, handoff_message: string|null, max_replies: int}  $data
     * @return bool Whether anything changed.
     */
    public function handle(array $data, User $actor): bool
    {
        return DB::transaction(function () use ($data, $actor): bool {
            $settings = AiSetting::query()->orderBy('id')->lockForUpdate()->first() ?? new AiSetting;
            $before = $settings->exists ? $this->snapshot($settings) : null;
            $providerChanged = $settings->exists && $settings->provider !== $data['provider'];

            $settings->forceFill([
                'provider' => $data['provider'],
                'model' => $data['model'],
                'business_info' => $data['business_info'],
                'catalog' => $data['catalog'],
                'instructions' => $data['instructions'],
                'assistant_name' => $data['assistant_name'],
                'handoff_message' => $data['handoff_message'],
                'max_replies' => $data['max_replies'],
            ]);

            $key = 'kept';

            if ($data['api_key'] !== null) {
                $key = $settings->api_key === $data['api_key'] ? 'kept' : 'replaced';
                $settings->api_key = $data['api_key'];
            } elseif ($data['remove_api_key'] || $providerChanged) {
                // A key belongs to its company: another provider needs its own.
                $key = $settings->hasApiKey() ? 'removed' : 'kept';
                $settings->api_key = null;
            }

            $after = $this->snapshot($settings);
            $changes = [];

            foreach ($after as $field => $value) {
                $old = $before[$field] ?? null;

                if ($old !== $value && $field !== 'catalog_hash') {
                    $changes[$field] = ['old' => $old, 'new' => $value];
                }
            }

            // The catalog is audited by its size; an edit that keeps the
            // size still shows up.
            if (($before['catalog_hash'] ?? null) !== $after['catalog_hash']) {
                $changes['catalog'] = ['old' => $before['catalog'] ?? null, 'new' => $after['catalog']];
            }

            if ($key !== 'kept') {
                $changes['credential'] = ['old' => null, 'new' => $key === 'replaced' ? 'Clave reemplazada' : 'Clave eliminada'];
            }

            if ($changes === [] && $settings->exists) {
                return false;
            }

            if ($key !== 'kept') {
                $settings->forceFill(['last_error_type' => null, 'last_error_at' => null]);
            }

            $settings->forceFill(['updated_by_user_id' => $actor->id])->save();
            $this->audit->record(AuditAction::AiSettingsUpdated, null, ['changes' => $changes], $actor);

            return true;
        });
    }

    /**
     * @param  array{is_enabled: bool, handoff_department_id: int|null, only_outside_hours: bool}  $data
     * @return bool Whether anything changed.
     */
    public function connection(Connection $connection, array $data, User $actor): bool
    {
        // Meta's channels are not offered yet: they can never be turned on.
        $data['is_enabled'] = $data['is_enabled'] && AiAssistant::supports($connection);

        return DB::transaction(function () use ($connection, $data, $actor): bool {
            $setting = AiConnectionSetting::query()->where('connection_id', $connection->id)->lockForUpdate()->first()
                ?? new AiConnectionSetting;
            $before = $setting->exists ? $setting->only(['is_enabled', 'handoff_department_id', 'only_outside_hours']) : ['is_enabled' => false, 'handoff_department_id' => null, 'only_outside_hours' => false];

            $setting->forceFill(['connection_id' => $connection->id, ...$data]);

            if ($before === $setting->only(['is_enabled', 'handoff_department_id', 'only_outside_hours'])) {
                return false;
            }

            $setting->save();

            $changes = [];

            foreach ($data as $field => $value) {
                if ($before[$field] !== $value) {
                    $changes["ai_{$field}"] = ['old' => $before[$field], 'new' => $value];
                }
            }

            $this->audit->record(AuditAction::AiSettingsUpdated, $connection, ['changes' => $changes], $actor);

            return true;
        });
    }

    /**
     * What the audit compares (never the key).
     *
     * @return array<string, mixed>
     */
    private function snapshot(AiSetting $settings): array
    {
        return [
            'provider' => $settings->provider->value,
            'model' => $settings->model,
            'business_info' => $settings->business_info,
            'catalog' => count($settings->catalog ?? []).' respuestas',
            // Normalized: the JSON column does not keep the keys' order.
            'catalog_hash' => md5((string) json_encode(array_map(
                fn (array $entry): array => [$entry['question'], $entry['answer']],
                $settings->catalog ?? [],
            ))),
            'instructions' => $settings->instructions,
            'assistant_name' => $settings->assistant_name,
            'handoff_message' => $settings->handoff_message,
            'max_replies' => $settings->max_replies,
        ];
    }
}
