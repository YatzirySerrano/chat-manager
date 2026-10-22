<?php

namespace App\Actions\Messaging;

use App\Enums\WebhookEventStatus;
use App\Models\WebhookEvent;

/**
 * Removes the raw webhook log after its retention: processed deliveries
 * after whatsapp.webhooks.retention_days, failed ones after
 * failed_retention_days. Messages, statuses and templates already applied
 * are business records stored elsewhere and are not affected.
 */
class PruneWebhookEvents
{
    public function handle(): int
    {
        $processed = now()->subDays(max(1, (int) config('whatsapp.webhooks.retention_days', 30)));
        $failed = now()->subDays(max(1, (int) config('whatsapp.webhooks.failed_retention_days', 90)));
        $deleted = 0;

        do {
            $batch = WebhookEvent::query()
                ->where(fn ($query) => $query
                    ->where(fn ($done) => $done->where('status', WebhookEventStatus::Processed)->where('received_at', '<', $processed))
                    ->orWhere(fn ($old) => $old->where('status', '!=', WebhookEventStatus::Processed)->where('received_at', '<', $failed)))
                ->limit(1000)
                ->delete();
            $deleted += $batch;
        } while ($batch === 1000);

        return $deleted;
    }
}
