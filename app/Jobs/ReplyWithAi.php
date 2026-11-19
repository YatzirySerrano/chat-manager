<?php

namespace App\Jobs;

use App\Services\Ai\AiAssistant;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The assistant of «Inteligencia artificial» answers one customer message
 * (AiAssistant::answer()), queued by the inbound receipt a few seconds
 * later so a burst gets one answer. On the default queue: a provider may
 * take many seconds and must never delay outbound messages or webhooks.
 * One try: the SDK already retries, and a failure leaves the conversation
 * waiting for a person, as without the assistant.
 */
class ReplyWithAi implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public int $conversationId, public int $messageId)
    {
        $this->onQueue(Queues::name(Queues::DEFAULT));
    }

    public function handle(AiAssistant $assistant): void
    {
        $assistant->answer($this->conversationId, $this->messageId);
    }
}
