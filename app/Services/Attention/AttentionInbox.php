<?php

namespace App\Services\Attention;

use App\Broadcasting\RealtimeSignals;
use App\Enums\AttentionAlertPriority;
use App\Models\AttentionAlert;
use App\Models\AttentionAlertRecipient;
use App\Models\User;
use App\Support\ProtectedAccounts;
use Illuminate\Support\Collection;

/**
 * The recipient's side of attention alerts. Every query is bound to the
 * person's own rows, so ids of someone else's alerts match nothing.
 *
 * Alerts stay pending until the person acknowledges them, whatever the
 * real-time connection did: the application asks for them when it loads,
 * after every reconnection and periodically without real-time, so an alert
 * sent while someone was offline is waiting when they come back.
 *
 * @phpstan-type PresentedAlert array{
 *     id: int,
 *     alert_id: int,
 *     title: string,
 *     body: string,
 *     priority: string,
 *     priority_label: string,
 *     color: string,
 *     sound: string,
 *     action: string|null,
 *     sender: string|null,
 *     sent_at: string|null,
 *     delivered_at: string|null,
 *     acknowledged_at: string|null,
 * }
 */
class AttentionInbox
{
    /** Pending alerts returned at most (the oldest still waiting first). */
    public const PENDING_LIMIT = 20;

    public function __construct(
        private RealtimeSignals $signals,
        private ProtectedAccounts $protectedAccounts,
    ) {}

    /**
     * @return list<PresentedAlert>
     */
    public function pending(User $user): array
    {
        $rows = AttentionAlertRecipient::query()
            ->where('user_id', $user->id)
            ->whereNull('acknowledged_at')
            ->with(['alert.sender:id,name'])
            ->orderBy('id')
            ->limit(self::PENDING_LIMIT)
            ->get();

        return $this->present($rows, $user);
    }

    public function pendingCount(User $user): int
    {
        return AttentionAlertRecipient::query()->where('user_id', $user->id)->whereNull('acknowledged_at')->count();
    }

    /**
     * The person's application showed these alerts: the first time is
     * recorded (never moved later, never confused with an acknowledgement).
     *
     * @param  list<int>  $recipientIds
     */
    public function markDelivered(User $user, array $recipientIds): int
    {
        if ($recipientIds === []) {
            return 0;
        }

        $alertIds = array_values(AttentionAlertRecipient::query()
            ->where('user_id', $user->id)
            ->whereKey($recipientIds)
            ->whereNull('delivered_at')
            ->pluck('attention_alert_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());

        $updated = AttentionAlertRecipient::query()
            ->where('user_id', $user->id)
            ->whereKey($recipientIds)
            ->whereNull('delivered_at')
            ->update(['delivered_at' => now(), 'updated_at' => now()]);

        $this->tellSenders($alertIds);

        return $updated;
    }

    /**
     * «Entendido»: the alert leaves every tab of the person.
     */
    public function acknowledge(User $user, int $recipientId): bool
    {
        $row = AttentionAlertRecipient::query()->where('user_id', $user->id)->whereKey($recipientId)->first();

        if ($row === null) {
            return false;
        }

        if ($row->acknowledged_at === null) {
            $now = now();
            $row->forceFill(['acknowledged_at' => $now, 'delivered_at' => $row->delivered_at ?? $now])->save();
            $this->tellSenders([$row->attention_alert_id]);
        }

        $this->signals->toUsers([$user->id], 'attention.alert', ['alert_id' => $row->attention_alert_id, 'acknowledged' => true]);

        return true;
    }

    /**
     * The senders' history refreshes the delivery and acknowledgement counts.
     *
     * @param  list<int>  $alertIds
     */
    private function tellSenders(array $alertIds): void
    {
        if ($alertIds === []) {
            return;
        }

        AttentionAlert::query()->whereKey(array_values(array_unique($alertIds)))->whereNotNull('sender_id')->get(['id', 'sender_id'])
            ->groupBy('sender_id')
            ->each(fn (Collection $alerts, int|string $senderId) => $this->signals->toUsers([(int) $senderId], 'attention.alert_status', [
                'alert_id' => (int) $alerts->first()?->id,
            ]));
    }

    /**
     * @param  Collection<int, AttentionAlertRecipient>  $rows
     * @return list<PresentedAlert>
     */
    private function present(Collection $rows, User $viewer): array
    {
        return array_values($rows->map(function (AttentionAlertRecipient $row) use ($viewer): array {
            /** @var AttentionAlert $alert */
            $alert = $row->alert;
            /** @var AttentionAlertPriority $priority */
            $priority = $alert->priority;

            return [
                'id' => $row->id,
                'alert_id' => $alert->id,
                'title' => $alert->title,
                'body' => $alert->body,
                'priority' => $priority->value,
                'priority_label' => $priority->label(),
                'color' => $alert->color->value,
                'sound' => $alert->sound->resolve($priority)->value,
                'action' => $alert->action,
                'sender' => $this->protectedAccounts->nameFor($alert->sender, $viewer) ?? 'Administración',
                'sent_at' => $alert->created_at?->toIso8601String(),
                'delivered_at' => $row->delivered_at?->toIso8601String(),
                'acknowledged_at' => $row->acknowledged_at?->toIso8601String(),
            ];
        })->all());
    }
}
