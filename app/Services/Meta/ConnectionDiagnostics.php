<?php

namespace App\Services\Meta;

use App\Actions\Messaging\ReconcileStuckDeliveries;
use App\Enums\MessageStatus;
use App\Enums\WebhookEventStatus;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Activation checklist of a WhatsApp connection for administrators, built
 * only from recorded evidence. It never includes secrets: only whether each
 * one is saved. It also counts the recent replies whose delivery could not
 * be confirmed ("delivery_unknown") and lists the affected conversations the
 * viewer can see, so someone can check them with the customer.
 */
class ConnectionDiagnostics
{
    public function __construct(private WhatsAppTransport $transport) {}

    /** Days of failed deliveries of unknown outcome shown to administrators. */
    public const UNKNOWN_DELIVERY_DAYS = 7;

    /** Affected conversations listed at most. */
    private const UNKNOWN_DELIVERY_CONVERSATIONS = 10;

    /**
     * @param  User|null  $viewer  Lists the conversations with unconfirmed deliveries this user can see.
     * @return array<string, mixed>
     */
    public function for(Connection $connection, ?User $viewer = null): array
    {
        $since = now()->subDay();
        $events = WebhookEvent::query()
            ->where('connection_id', $connection->id)
            ->where('received_at', '>=', $since)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $lastError = WebhookEvent::query()
            ->where('connection_id', $connection->id)
            ->where('status', WebhookEventStatus::Failed)
            ->latest('id')
            ->first(['id', 'error', 'received_at']);
        $stuck = Message::query()
            ->where('status', MessageStatus::Sending)
            ->whereHas('conversation', fn ($query) => $query->where('connection_id', $connection->id))
            ->count();
        $unknown = $this->unknownDeliveries($connection);

        $url = $connection->webhook_key === null ? null : route('webhooks.whatsapp.verify', ['webhookKey' => $connection->webhook_key]);

        return [
            'transport' => [
                'mode' => $this->transport->name(),
                'label' => match ($this->transport->name()) {
                    'meta' => 'WhatsApp Cloud API (real)',
                    'simulated' => 'Simulación local: ningún mensaje sale a WhatsApp',
                    default => 'Envío deshabilitado en esta instalación (WHATSAPP_TRANSPORT)',
                },
                // The pilot list only restricts the real Meta transport.
                'pilot_recipients' => $this->transport->name() === 'meta' ? count(MetaCloudWhatsAppTransport::pilotRecipients()) : 0,
            ],
            'webhook' => [
                'url' => $url,
                'https' => $url !== null && str_starts_with($url, 'https://'),
                'verify_token_saved' => filled($connection->webhook_verify_token),
                'app_secret_saved' => filled($connection->app_secret),
                'verified_at' => $connection->webhook_verified_at?->toIso8601String(),
                'last_received_at' => $connection->last_webhook_at?->toIso8601String(),
                'fields' => ['messages', 'message_template_status_update', 'message_template_quality_update', 'phone_number_quality_update', 'account_update'],
                'events_24h' => [
                    'processed' => (int) ($events[WebhookEventStatus::Processed->value] ?? 0),
                    'pending' => (int) ($events[WebhookEventStatus::Received->value] ?? 0),
                    'failed' => (int) ($events[WebhookEventStatus::Failed->value] ?? 0),
                ],
                'last_failure' => $lastError === null ? null : [
                    'at' => $lastError->received_at->toIso8601String(),
                    'error' => $lastError->error,
                ],
            ],
            'subscription' => [
                'state' => match ($connection->app_subscribed) {
                    true => 'subscribed',
                    false => 'not_subscribed',
                    default => 'unknown',
                },
                'checked_at' => $connection->subscription_checked_at?->toIso8601String(),
                'meta_app_id_saved' => filled($connection->meta_app_id),
            ],
            'templates' => [
                'approved' => MessageTemplate::query()->where('connection_id', $connection->id)->approved()->count(),
                'last_synced_at' => MessageTemplate::query()->where('connection_id', $connection->id)->max('last_synced_at'),
            ],
            'outbound' => [
                'unconfirmed' => $stuck,
                'unknown' => (clone $unknown)->count(),
                'unknown_days' => self::UNKNOWN_DELIVERY_DAYS,
                'unknown_conversations' => $viewer === null ? [] : $this->unknownConversations($unknown, $viewer),
            ],
            'signals' => collect($connection->provider_signals ?? [])
                ->map(fn (mixed $signal, string $field): array => [
                    'field' => $field,
                    'at' => is_array($signal) ? ($signal['at'] ?? null) : null,
                    'event' => is_array($signal) && is_array($signal['value'] ?? null) && is_string($signal['value']['event'] ?? null) ? $signal['value']['event'] : null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Replies of this connection marked "delivery_unknown" (Meta may or may
     * not have received them) in the recent period.
     *
     * @return Builder<Message>
     */
    private function unknownDeliveries(Connection $connection): Builder
    {
        return Message::query()
            ->where('status', MessageStatus::Failed)
            ->where('error_code', ReconcileStuckDeliveries::CODE)
            ->where('failed_at', '>=', now()->subDays(self::UNKNOWN_DELIVERY_DAYS))
            ->whereHas('conversation', fn (Builder $query) => $query->where('connection_id', $connection->id));
    }

    /**
     * The affected conversations the viewer can see, most recent first.
     *
     * @param  Builder<Message>  $unknown
     * @return list<array{id: int, contact: string|null, messages: int, last_failed_at: string|null}>
     */
    private function unknownConversations(Builder $unknown, User $viewer): array
    {
        $rows = (clone $unknown)
            ->whereIn('conversation_id', Conversation::query()->visibleTo($viewer)->select('id'))
            ->selectRaw('conversation_id, count(*) as total, max(failed_at) as last_failed_at')
            ->groupBy('conversation_id')
            ->orderByDesc('last_failed_at')
            ->limit(self::UNKNOWN_DELIVERY_CONVERSATIONS)
            ->toBase()
            ->get();
        $conversations = Conversation::query()
            ->with('contact:id,name')
            ->whereKey($rows->pluck('conversation_id')->all())
            ->get()
            ->keyBy('id');

        return array_values($rows->map(fn (object $row): array => [
            'id' => (int) $row->conversation_id,
            'contact' => $conversations->get((int) $row->conversation_id)?->contact?->name,
            'messages' => (int) $row->total,
            'last_failed_at' => $row->last_failed_at === null ? null : Carbon::parse((string) $row->last_failed_at)->toIso8601String(),
        ])->all());
    }
}
