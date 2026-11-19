<?php

namespace App\Console\Commands;

use App\Enums\ConversationReadiness;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Notifications\ConversationOverdueNotification;
use App\Notifications\NotificationRecipients;
use App\Services\PlatformSettings;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * «Alertas de tiempo» (Administración → Configuración), every minute:
 *
 * - General: a request ready for attention («Solicitud completa / Lista
 *   para atención») waiting in «Pendientes» longer than
 *   time_alerts.queue_minutes (5 by default) alerts whoever can take it
 *   and the administrators who see it.
 * - Individual: a conversation «En atención» with the same agent longer
 *   than time_alerts.attention_minutes (15 by default) without being
 *   resolved alerts that agent.
 *
 * Each alert goes once per wait (or attention) and person; a conversation
 * that crossed its limit more than an hour ago is no longer looked at, so
 * a long outage of the scheduler does not flood anyone afterwards.
 */
class ConversationTimeAlerts extends Command
{
    protected $signature = 'conversations:time-alerts';

    protected $description = 'Alert about requests waiting in Pendientes or in attention longer than the configured time';

    private const LOOKBACK_MINUTES = 60;

    private const BATCH = 200;

    public function handle(PlatformSettings $settings, NotificationRecipients $recipients): int
    {
        $sent = 0;

        if ((bool) $settings->get('time_alerts.queue_enabled', true)) {
            $sent += $this->queue(max(1, (int) $settings->get('time_alerts.queue_minutes', 5)), $recipients);
        }

        if ((bool) $settings->get('time_alerts.attention_enabled', true)) {
            $sent += $this->attention(max(1, (int) $settings->get('time_alerts.attention_minutes', 15)), $recipients);
        }

        $this->info("Alertas de tiempo revisadas: {$sent} conversaciones avisadas.");

        return self::SUCCESS;
    }

    private function queue(int $minutes, NotificationRecipients $recipients): int
    {
        $limit = now()->subMinutes($minutes);
        $oldest = $limit->copy()->subMinutes(self::LOOKBACK_MINUTES);
        $sent = 0;

        $candidates = Conversation::query()
            ->live()
            ->notHistoryOnly()
            ->where('status', ConversationStatus::Pending)
            ->whereNull('assigned_user_id')
            ->where('readiness', ConversationReadiness::Ready)
            ->where(fn ($query) => $query
                ->whereBetween('queued_at', [$oldest, $limit])
                ->orWhere(fn ($query) => $query->whereNull('queued_at')->whereBetween('created_at', [$oldest, $limit]))
                ->orWhereBetween('readiness_changed_at', [$oldest, $limit]))
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        foreach ($candidates as $conversation) {
            // Waiting since it entered the queue or, when it was confirmed
            // complete later, since that confirmation.
            $since = $this->latest($conversation->queued_at ?? $conversation->created_at, $conversation->readiness_changed_at);

            if ($since === null || $since->greaterThan($limit) || $since->lessThan($oldest)) {
                continue;
            }

            $people = $recipients->forUnattendedConversation($conversation);

            if ($people->isNotEmpty()) {
                Notification::send($people, new ConversationOverdueNotification(ConversationOverdueNotification::QUEUE, $conversation->id, $conversation->department_id, $minutes, (string) $since->getTimestamp()));
                $sent++;
            }
        }

        return $sent;
    }

    private function attention(int $minutes, NotificationRecipients $recipients): int
    {
        $limit = now()->subMinutes($minutes);
        $oldest = $limit->copy()->subMinutes(self::LOOKBACK_MINUTES);
        $sent = 0;

        $candidates = Conversation::query()
            ->live()
            ->notHistoryOnly()
            ->where('status', ConversationStatus::Open)
            ->whereNotNull('assigned_user_id')
            ->whereBetween('assigned_at', [$oldest, $limit])
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        foreach ($candidates as $conversation) {
            $agent = $recipients->ifCanView($conversation->assigned_user_id, $conversation);

            if ($agent === null || $conversation->assigned_at === null) {
                continue;
            }

            $agent->notify(new ConversationOverdueNotification(ConversationOverdueNotification::ATTENTION, $conversation->id, $conversation->department_id, $minutes, $conversation->assigned_at->getTimestamp().':'.$conversation->assigned_user_id));
            $sent++;
        }

        return $sent;
    }

    private function latest(?CarbonInterface $first, ?CarbonInterface $second): ?CarbonInterface
    {
        if ($first === null || $second === null) {
            return $first ?? $second;
        }

        return $first->greaterThan($second) ? $first : $second;
    }
}
