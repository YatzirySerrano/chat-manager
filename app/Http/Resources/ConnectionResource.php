<?php

namespace App\Http\Resources;

use App\Actions\WebChat\UpdateWidgetSettings;
use App\Enums\ConnectionHealth;
use App\Http\Requests\Admin\WebChatWidgetRequest;
use App\Models\Connection;
use App\Models\Department;
use App\Models\WebChatWidget;
use App\Services\Meta\MetaApp;
use App\Support\ProtectedAccounts;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
            'has_required_credentials' => $this->isConfigured(),
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
            'web_chat_widget' => $this->when(
                $this->isWebChat() && $this->relationLoaded('webChatWidget') && $this->webChatWidget !== null,
                fn (): array => $this->widgetSettings(),
            ),
            'meta_account' => $this->when($this->usesMetaAuthorization(), fn (): array => $this->metaAccount()),
            'health' => $this->describe($health),
            'configuration_health' => $this->describe($configurationHealth),
            'checks' => [
                'configuration_saved' => $this->isConfigured(),
                'credentials' => [
                    'state' => match (true) {
                        ! $this->isConfigured() => 'missing',
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
     * Account authorized with Meta (Messenger page or Instagram professional
     * account): never the token, only whether it is saved and until when.
     *
     * @return array<string, mixed>
     */
    private function metaAccount(): array
    {
        $expiresAt = $this->token_expires_at;

        return [
            'id' => $this->external_account_id,
            'name' => $this->external_account_name,
            'picture' => is_string($this->external_account_picture) && str_starts_with($this->external_account_picture, 'https://') ? $this->external_account_picture : null,
            'authorized' => filled($this->access_token),
            'state' => match (true) {
                blank($this->access_token) => 'disconnected',
                $expiresAt !== null && $expiresAt->isPast() => 'expired',
                filled($this->credentials_error) => 'invalid',
                default => 'authorized',
            },
            'granted_scopes' => array_map(fn (string $scope): array => ['value' => $scope, 'label' => MetaApp::scopeLabel($scope)], array_values(array_filter($this->granted_scopes ?? [], 'is_string'))),
            'authorized_at' => $this->authorized_at?->toIso8601String(),
            'authorized_by' => $this->whenLoaded('authorizedBy', fn (): ?string => app(ProtectedAccounts::class)->nameForRequest($this->authorizedBy, request())),
            'token_expires_at' => $expiresAt?->toIso8601String(),
            'subscribed' => $this->app_subscribed,
            'subscription_checked_at' => $this->subscription_checked_at?->toIso8601String(),
            'subscribed_fields' => MetaApp::SUBSCRIBED_FIELDS[$this->channel->value] ?? [],
        ];
    }

    /**
     * Embed snippet that loads the widget with the given public key.
     */
    public static function snippet(string $publicKey): string
    {
        $script = url('/widget/v1/chat-manager.js');

        return "<script>(function(w,d,s,u){w.ChatManager=w.ChatManager||function(){(w.ChatManager.q=w.ChatManager.q||[]).push(arguments)};var j=d.createElement(s);j.async=true;j.src=u;d.head.appendChild(j)})(window,document,'script','{$script}');ChatManager('init',{key:'{$publicKey}'});</script>";
    }

    /**
     * Widget settings of a web chat connection. The identity secret is never
     * included, only whether one is set; the avatar travels inlined because
     * it lives in the private disk.
     *
     * @return array<string, mixed>
     */
    private function widgetSettings(): array
    {
        /** @var WebChatWidget $widget */
        $widget = $this->webChatWidget;
        $prechat = $widget->prechat ?? [];

        return [
            'public_key' => $widget->public_key,
            'allowed_origins' => $widget->allowed_origins,
            'title' => $widget->title,
            'subtitle' => $widget->subtitle,
            'welcome_text' => $widget->welcome_text,
            'offline_text' => $widget->offline_text,
            'position' => $widget->position->value,
            'accent_color' => $widget->accent_color,
            'launcher_enabled' => $widget->launcher_enabled,
            'launcher_label' => $widget->launcher_label,
            'avatar_url' => $this->avatarDataUri($widget),
            'identity_mode' => $widget->identity_mode->value,
            'prechat' => [
                'fields' => $prechat['fields'] ?? [],
                'required' => (bool) ($prechat['required'] ?? false),
                'privacy_notice' => $prechat['privacy_notice'] ?? null,
            ],
            'availability_mode' => $widget->availability_mode->value,
            'session_idle_days' => $widget->session_idle_days,
            'snippet' => self::snippet($widget->public_key),
            'frame_url' => url('/widget/v1/frame/'.$widget->public_key),
            'identity_secret_set' => filled($widget->identity_secret),
            'key_rotated_at' => $widget->key_rotated_at?->toIso8601String(),
        ];
    }

    private function avatarDataUri(WebChatWidget $widget): ?string
    {
        if ($widget->avatar_path === null) {
            return null;
        }

        $disk = Storage::disk(UpdateWidgetSettings::disk());

        if (! $disk->exists($widget->avatar_path)) {
            return null;
        }

        $bytes = (string) $disk->get($widget->avatar_path);
        $type = WebChatWidgetRequest::imageTypeOf(substr($bytes, 0, 16));

        return $type === null ? null : 'data:'.$type.';base64,'.base64_encode($bytes);
    }

    /**
     * @return array{value: string, label: string, description: string, tone: string}
     */
    private function describe(ConnectionHealth $health): array
    {
        $webChat = $this->isWebChat() ? self::webChatHealth($health) : null;

        return [
            'value' => $health->value,
            'label' => $webChat['label'] ?? $health->label(),
            'description' => $webChat['description'] ?? $health->description(),
            'tone' => $health->tone(),
        ];
    }

    /**
     * Wording of the health of a web chat connection, which has no Meta
     * credentials or webhook to talk about.
     *
     * @return array{label: string, description: string}|null
     */
    private static function webChatHealth(ConnectionHealth $health): ?array
    {
        return match ($health) {
            ConnectionHealth::Inactive => ['label' => 'Inactiva', 'description' => 'El chat web está desactivado: el widget se muestra fuera de línea y no acepta mensajes nuevos.'],
            ConnectionHealth::NotConfigured => ['label' => 'Sin configurar', 'description' => 'Autoriza al menos un dominio donde se insertará el chat y guarda la configuración del widget.'],
            ConnectionHealth::Configured => ['label' => 'Configurada', 'description' => 'El widget tiene dominios autorizados; aún no se ha recibido ningún mensaje de un visitante.'],
            ConnectionHealth::Receiving => ['label' => 'Recibiendo', 'description' => 'Ya se recibió al menos un mensaje de un visitante; aún no hay respuestas entregadas.'],
            ConnectionHealth::Operational => ['label' => 'Operativa', 'description' => 'Se confirmó la recepción de mensajes de visitantes y el envío de respuestas.'],
            ConnectionHealth::Error => ['label' => 'Error', 'description' => 'El evento más reciente fue un error posterior al último éxito.'],
            default => null,
        };
    }
}
