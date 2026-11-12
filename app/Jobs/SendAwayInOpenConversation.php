<?php

namespace App\Jobs;

use App\Actions\Automation\QueueAutomaticMessage;
use App\Listeners\Automation\SendAutomaticMessages;
use App\Models\Conversation;
use App\Models\Department;
use App\Models\Message;
use App\Services\Automation\AutomaticMessageKind;
use App\Services\Automation\AutomaticMessages;
use App\Services\BusinessHours;
use App\Support\Queues;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The away message for a customer who writes outside business hours in a
 * conversation that is already open or waiting in a queue, when the
 * connection's administrator turned on «También en conversaciones
 * abiertas». ReceiveInboundMessage queues it only for messages appended to
 * an unclosed conversation of such a connection (new and reopened
 * conversations keep their greeting, SendAutomaticMessages), so ordinary
 * traffic pays nothing for it.
 *
 * Sent once per period outside hours (QueueAutomaticMessage), so several
 * consecutive messages, retried webhooks or a repeated job never send it
 * twice; never when the schedule says the business is open (attention is
 * continuous without a schedule), never for a message processed more than
 * SendAutomaticMessages::MAX_GREETING_DELAY_MINUTES late, and only inside
 * the customer service window (just reopened by the message itself).
 */
class SendAwayInOpenConversation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(public int $conversationId, public int $messageId)
    {
        $this->onQueue(Queues::name(Queues::MESSAGES));
    }

    public function handle(AutomaticMessages $messages, QueueAutomaticMessage $queueMessage, BusinessHours $hours): void
    {
        $conversation = Conversation::query()->with(['channelConnection', 'department'])->find($this->conversationId);
        $connection = $conversation?->channelConnection;
        $message = Message::query()->whereKey($this->messageId)->where('conversation_id', $this->conversationId)->first(['id', 'sent_at', 'created_at']);

        if ($conversation === null || $connection === null || $message === null || ! $connection->away_in_open_conversations || $connection->isWhatsAppWeb() || $conversation->isImported()) {
            return;
        }

        $writtenAt = $message->sent_at ?? $message->created_at;

        if ($writtenAt === null || $writtenAt->lessThan(now()->subMinutes(SendAutomaticMessages::MAX_GREETING_DELAY_MINUTES))) {
            $this->skipped($conversation, 'processed_late');

            return;
        }

        $now = now();
        $department = $this->operatingDepartment($conversation->department);

        // Within business hours an open conversation gets nothing (its
        // welcome was the greeting of the cycle).
        if ($hours->isOpenAt($department, $now)) {
            return;
        }

        $plan = $messages->greeting($connection, $department, $now);

        if ($plan === null || $plan->kind !== AutomaticMessageKind::Away) {
            $this->skipped($conversation, 'away_not_configured');

            return;
        }

        $queueMessage->handleAwayInOpenConversation($conversation, $plan, $queueMessage->periodKey($conversation, $now), $message->id);
    }

    private function operatingDepartment(?Department $department): ?Department
    {
        return $department !== null && $department->is_active && ! $department->isDeleted() ? $department : null;
    }

    private function skipped(Conversation $conversation, string $reason): void
    {
        Log::info('Automatic message not sent.', [
            'conversation_id' => $conversation->id,
            'connection_id' => $conversation->connection_id,
            'slot' => 'away_in_open_conversation',
            'reason' => $reason,
        ]);
    }
}
