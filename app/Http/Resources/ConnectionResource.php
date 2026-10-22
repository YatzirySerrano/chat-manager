<?php

namespace App\Http\Resources;

use App\Enums\ConnectionHealth;
use App\Models\Connection;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Connection as seen by the interface. Secrets are never included: only
 * whether each one is saved. Every health indicator is derived from recorded
 * evidence; nothing here claims that the integration works.
 *
 * @mixin Connection
 */
class ConnectionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $health = $this->health();
        $configurationHealth = $this->configurationHealth();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'channel' => ['value' => $this->channel->value, 'label' => $this->channel->label()],
            'provider' => ['value' => $this->provider->value, 'label' => $this->provider->label()],
            'display_phone_number' => $this->display_phone_number,
            'phone_number_id' => $this->phone_number_id,
            'business_account_id' => $this->business_account_id,
            'meta_app_id' => $this->meta_app_id,
            'verified_name' => $this->verified_name,
            'is_active' => $this->is_active,
            'is_deleted' => $this->isDeleted(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
            'conversations_count' => $this->whenCounted('conversations'),
            'secrets' => [
                'access_token' => filled($this->access_token),
                'app_secret' => filled($this->app_secret),
                'webhook_verify_token' => filled($this->webhook_verify_token),
            ],
            'has_required_credentials' => $this->hasRequiredCredentials(),
            'default_department_id' => $this->default_department_id,
            'departments' => $this->whenLoaded('departments', fn () => $this->departments->map(fn (Department $department): array => [
                'id' => $department->id,
                'name' => $department->name,
                'color' => $department->color->value,
                'is_active' => $department->is_active,
                'is_default' => $department->id === $this->default_department_id,
            ])->values()->all()),
            'messages' => [
                'welcome' => ['enabled' => $this->welcome_enabled, 'text' => $this->welcome_message],
                'away' => ['enabled' => $this->away_enabled, 'text' => $this->away_message],
                'farewell' => ['enabled' => $this->farewell_enabled, 'text' => $this->farewell_message],
            ],
            'reopen_window_minutes' => $this->reopen_window_minutes,
            'health' => $this->describe($health),
            'configuration_health' => $this->describe($configurationHealth),
            'checks' => [
                'configuration_saved' => $this->hasRequiredCredentials(),
                'credentials' => [
                    'state' => match (true) {
                        ! $this->hasRequiredCredentials() => 'missing',
                        filled($this->credentials_error) => 'invalid',
                        $this->credentials_verified_at !== null => 'verified',
                        default => 'unverified',
                    },
                    'verified_at' => $this->credentials_verified_at?->toIso8601String(),
                    'error' => $this->credentials_error,
                ],
                'webhook_verified_at' => $this->webhook_verified_at?->toIso8601String(),
                'last_inbound_at' => $this->last_inbound_at?->toIso8601String(),
                'last_outbound_at' => $this->last_outbound_at?->toIso8601String(),
                'last_error' => $this->last_error_at === null ? null : [
                    'at' => $this->last_error_at->toIso8601String(),
                    'message' => $this->last_error_message,
                ],
            ],
            'meta' => [
                'phone_status' => $this->phone_status,
                'quality_rating' => $this->quality_rating,
                'code_verification_status' => $this->code_verification_status,
                'platform_type' => $this->platform_type,
            ],
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can' => [
                'view' => $request->user()?->can('view', $this->resource) ?? false,
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'changeStatus' => $request->user()?->can('changeStatus', $this->resource) ?? false,
                'verify' => $request->user()?->can('verify', $this->resource) ?? false,
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
                'restore' => $request->user()?->can('restore', $this->resource) ?? false,
            ],
        ];
    }

    /**
     * @return array{value: string, label: string, description: string, tone: string}
     */
    private function describe(ConnectionHealth $health): array
    {
        return [
            'value' => $health->value,
            'label' => $health->label(),
            'description' => $health->description(),
            'tone' => $health->tone(),
        ];
    }
}
