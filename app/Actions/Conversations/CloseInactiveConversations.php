<?php

namespace App\Actions\Conversations;

use App\Enums\ConversationEventType;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Services\Conversations\ConversationLifecycle;
use App\Services\PlatformSettings;
use Illuminate\Support\Facades\DB;

/**
 * Applies the platform's "close after inactivity" setting: unclosed
 * conversations without messages for that many hours are closed by the
 * system, one by one and under lock, with an event explaining why. Nothing
 * is sent to the customer.
 */
class CloseInactiveConversations
{
    public function __construct(
        private ConversationLifecycle $lifecycle,
        private PlatformSettings $settings,
    ) {}

    public function handle(): int
    {
        $hours = $this->settings->get('operations.auto_close_after_hours');

        if (! is_int($hours) || $hours < 1) {
            return 0;
        }

        $closed = 0;

        Conversation::query()
            ->notClosed()
            ->where(fn ($query) => $query
                ->where('last_message_at', '<', now()->subHours($hours))
                ->orWhere(fn ($withoutMessages) => $withoutMessages->whereNull('last_message_at')->where('created_at', '<', now()->subHours($hours))))
            ->orderBy('id')
            ->chunkById(100, function ($conversations) use ($hours, &$closed): void {
                foreach ($conversations as $conversation) {
                    $closed += DB::transaction(function () use ($conversation, $hours): int {
                        $conversation = $this->lifecycle->lock($conversation);
                        $lastActivity = $conversation->last_message_at ?? $conversation->created_at;

                        if ($conversation->status === ConversationStatus::Closed || $lastActivity === null || $lastActivity->greaterThanOrEqualTo(now()->subHours($hours))) {
                            return 0;
                        }

                        $this->lifecycle->apply($conversation, ConversationStatus::Closed, [
                            'closed_at' => now(),
                            'closed_by_user_id' => null,
                            'on_hold_at' => null,
                            'on_hold_reason' => null,
                        ]);
                        $this->lifecycle->record($conversation, ConversationEventType::Closed, null, [
                            'from_user_id' => $conversation->assigned_user_id,
                            'from_department_id' => $conversation->department_id,
                            'note' => "Cerrada automáticamente tras {$hours} horas sin mensajes.",
                            'metadata' => ['trigger' => 'inactivity', 'hours' => $hours],
                        ]);

                        return 1;
                    });
                }
            });

        return $closed;
    }
}
