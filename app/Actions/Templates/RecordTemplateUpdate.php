<?php

namespace App\Actions\Templates;

use App\Enums\MessageTemplateStatus;
use App\Jobs\SyncConnectionTemplates;
use App\Models\Connection;
use App\Models\MessageTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Applies the template webhooks of a business account
 * (message_template_status_update, message_template_quality_update) to the
 * synchronized copies of every connection sharing that account. A template
 * not synchronized yet triggers a synchronization of the connection instead
 * of being invented from the notice.
 *
 * Status updates never go back in time: one whose entry time is older than
 * the status already recorded (a late or replayed delivery) is ignored, so
 * a replayed body changes nothing.
 */
class RecordTemplateUpdate
{
    /**
     * @param  array<array-key, mixed>  $value
     * @param  int|null  $entryTime  When Meta says the change happened (Unix seconds).
     */
    public function status(Connection $connection, array $value, ?int $entryTime = null): int
    {
        $templateId = isset($value['message_template_id']) && is_scalar($value['message_template_id']) ? (string) $value['message_template_id'] : null;
        $event = is_string($value['event'] ?? null) ? strtoupper($value['event']) : null;

        if ($templateId === null || $event === null) {
            return 0;
        }

        $templates = $this->templates($connection, $templateId);

        if ($templates === []) {
            SyncConnectionTemplates::dispatch($connection->id);

            return 0;
        }

        $reason = is_string($value['reason'] ?? null) && ! in_array($value['reason'], ['NONE', ''], true) ? Str::limit($value['reason'], 255, '') : null;
        // Never in the future, so a skewed clock cannot freeze later updates.
        $occurredAt = $entryTime === null ? null : Carbon::createFromTimestamp($entryTime, (string) config('app.timezone'))->min(now());

        foreach ($templates as $template) {
            if ($occurredAt !== null && $template->status_updated_at !== null && $occurredAt->lt($template->status_updated_at)) {
                Log::info('Template status update older than the recorded status ignored.', ['template_id' => $template->id]);

                continue;
            }

            if ($event === 'FLAGGED') {
                // Quality warning: Meta keeps the template approved until it pauses
                // it; the score itself arrives as message_template_quality_update.
                Log::info('Template flagged by Meta for low quality.', ['template_id' => $template->id]);

                continue;
            }

            $status = MessageTemplateStatus::fromMeta($event);

            if ($status === MessageTemplateStatus::Unknown) {
                Log::info('Template status update with an unknown event kept without changes.', ['template_id' => $template->id]);

                continue;
            }

            $template->forceFill([
                'status' => $status,
                'rejected_reason' => $status === MessageTemplateStatus::Rejected ? $reason : $template->rejected_reason,
                'status_updated_at' => $occurredAt ?? now(),
                // Like the synchronization: a template Meta reports again is no longer deleted.
                'deleted_at' => $status === MessageTemplateStatus::Deleted ? ($template->deleted_at ?? $occurredAt ?? now()) : null,
            ])->save();
        }

        return count($templates);
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    public function quality(Connection $connection, array $value): int
    {
        $templateId = isset($value['message_template_id']) && is_scalar($value['message_template_id']) ? (string) $value['message_template_id'] : null;
        $score = is_string($value['new_quality_score'] ?? null) ? Str::limit(strtoupper($value['new_quality_score']), 20, '') : null;

        if ($templateId === null || $score === null) {
            return 0;
        }

        $templates = $this->templates($connection, $templateId);

        foreach ($templates as $template) {
            $template->forceFill(['quality_score' => $score])->save();
        }

        return count($templates);
    }

    /**
     * @return list<MessageTemplate>
     */
    private function templates(Connection $connection, string $templateId): array
    {
        return array_values(MessageTemplate::query()
            ->where('business_account_id', (string) $connection->business_account_id)
            ->where('meta_template_id', $templateId)
            ->get()
            ->all());
    }
}
