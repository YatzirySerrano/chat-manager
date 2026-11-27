<?php

namespace App\Listeners\Analytics;

use App\Enums\ConversationEventType;
use App\Events\Conversations\ConversationChanged;
use App\Events\Messaging\MessageRecorded;
use App\Events\Messaging\MessageStatusChanged;
use App\Jobs\Analytics\RefreshConversationMetrics;

/**
 * Keeps the per-conversation metrics up to date: every change that can
 * alter a figure queues a (deduplicated) recomputation of its conversation.
 * The listener itself only dispatches, so it never slows down the request
 * or the webhook that produced the change.
 */
class RefreshMetricsOnActivity
{
    /**
     * Conversation changes that never alter a metric.
     *
     * @var list<string>
     */
    private const IGNORED_REASONS = [
        ConversationEventType::NoteAdded->value,
        ConversationEventType::NoteEdited->value,
        'read',
        'tags',
        'contact',
    ];

    public function handleConversationChanged(ConversationChanged $event): void
    {
        if (! in_array($event->reason, self::IGNORED_REASONS, true)) {
            RefreshConversationMetrics::dispatch($event->conversationId);
        }
    }

    public function handleMessageRecorded(MessageRecorded $event): void
    {
        RefreshConversationMetrics::dispatch($event->conversationId);
    }

    public function handleMessageStatusChanged(MessageStatusChanged $event): void
    {
        RefreshConversationMetrics::dispatch($event->conversationId);
    }
}
