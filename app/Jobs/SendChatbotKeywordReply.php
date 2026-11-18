<?php

namespace App\Jobs;

use App\Actions\Chatbot\ReplyToChatbotKeyword;
use App\Models\ChatbotKeywordRule;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Matches one customer message against the chatbot's keyword rules and
 * answers it (ReplyToChatbotKeyword), on the messages queue so the inbound
 * receipt never waits for it. ReceiveInboundMessage queues it only for text
 * messages while at least one rule is active.
 */
class SendChatbotKeywordReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(public int $conversationId, public int $messageId)
    {
        $this->onQueue(Queues::name(Queues::MESSAGES));
    }

    /**
     * Whether any rule could answer: ordinary traffic pays nothing while
     * the chatbot has no active keyword.
     */
    public static function anyRule(): bool
    {
        return ChatbotKeywordRule::query()->active()->exists();
    }

    public function handle(ReplyToChatbotKeyword $reply): void
    {
        $reply->handle($this->conversationId, $this->messageId);
    }
}
