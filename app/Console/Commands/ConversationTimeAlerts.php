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
 * «Alertas de tiempo» (Seguimiento de atención → «Alertas automáticas»),
 * every minute:
 *
 * - General: a request ready for attention («Solicitud completa / Lista
 *   para atención») waiting in «Pendientes» longer than each configured
 *   step alerts whoever can take it and the administrators who see it.
 * - Individual: a conversation «En atención» with the same agent longer
 *   than each configured step without being resolved alerts that agent.
 *
 * Each step («escalón»: 5 min, then 10, then 15…) carries its own urgency
 * and optional message, and alerts ONCE per conversation and wait/attention
 * start (sourceKey includes the step's minutes). A conversation that crossed
 * a step more than an hour ago is no longer looked at for that step, so a
 * long outage of the scheduler does not flood anyone afterwards.
 */
class ConversationTimeAlerts extends Command
{
    protected $signature = 'conversations:time-alerts';

    protected $description = 'Alert about requests waiting in Pendientes or in attention longer than the configured steps';

    private const LOOKBACK_MINUTES = 60;

    private const BATCH = 200;

    public function handle(PlatformSettings $settings, NotificationRecipients $recipients): int
    {
        $sent = 0;

        if ((bool) $settings->get('time_alerts.queue_enabled', true)) {
            foreach ($this->steps($settings, 'queue') as $step) {
                $sent += $this->queue($step, $recipients);
            }
        }

        if ((bool) $settings->get('time_alerts.attention_enabled', true)) {
            foreach ($this->steps($settings, 'attention') as $step) {
                $sent += $this->attention($step, $recipients);
            }
        }

        $this->info("Alertas de tiempo revisadas: {$sent} avisos enviados.");

        return self::SUCCESS;
    }

    /**
     * The configured steps of a kind, normalized and sorted by minutes. Falls
     * back to a single step at `{kind}_minutes` when none are stored, so the
     * old single-threshold configuration keeps working.
     *
     * @return list<array{minutes: int, priority: string, message: ?string}>
     */
    private function steps(PlatformSettings $settings, string $kind): array
    {
        $raw = $settings->get("time_alerts.{$kind}_steps");
        $fallback = max(1, (int) $settings->get("time_alerts.{$kind}_minutes", $kind === 'queue' ? 5 : 15));

        if (! is_array($raw) || $raw === []) {
            $raw = [['minutes' => $fallback]];
        }

        $steps = [];

        foreach ($raw as $step) {
            if (! is_array($step)) {
                continue;
            }

            $minutes = max(1, (int) ($step['minutes'] ?? 0));
            $priority = ($step['priority'] ?? null) === 'informative' ? 'informative' : 'urgent';
            $message = is_string($step['message'] ?? null) && trim($step['message']) !== '' ? trim($step['message']) : null;
            // A step repeated at the same minute would alert twice: keep the first.
            $steps[$minutes] ??= ['minutes' => $minutes, 'priority' => $priority, 'message' => $message];
        }

        ksort($steps);

        return array_values($steps);
    }

    /**
     * @param  array{minutes: int, priority: string, message: ?string}  $step
     */
    private function queue(array $step, NotificationRecipients $recipients): int
    {
        $limit = now()->subMinutes($step['minutes']);
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
                Notification::send($people, new ConversationOverdueNotification(ConversationOverdueNotification::QUEUE, $conversation->id, $conversation->department_id, $step['minutes'], (string) $since->getTimestamp(), $step['priority'], $step['message']));
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * @param  array{minutes: int, priority: string, message: ?string}  $step
     */
    private function attention(array $step, NotificationRecipients $recipients): int
    {
        $limit = now()->subMinutes($step['minutes']);
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

            $agent->notify(new ConversationOverdueNotification(ConversationOverdueNotification::ATTENTION, $conversation->id, $conversation->department_id, $step['minutes'], $conversation->assigned_at->getTimestamp().':'.$conversation->assigned_user_id, $step['priority'], $step['message']));
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
