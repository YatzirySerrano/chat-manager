<?php

namespace App\Actions\Messaging;

use App\Actions\Conversations\ReceiveInboundMessage;
use App\Enums\InboundMessageOutcome;
use App\Models\Connection;
use App\Services\Meta\ConnectionMonitor;
use App\Services\Telegram\TelegramUpdate;
use Illuminate\Support\Facades\Log;

/**
 * Handles one authenticated Telegram update already stored for its bot
 * connection (TelegramWebhookController → StoreWebhookDelivery), on the
 * webhooks queue, idempotently:
 *
 * - a customer message in a private chat enters the same pipeline as every
 *   channel (ReceiveInboundMessage: contact + identity by Telegram user id,
 *   conversation, routing, chatbot, AI, notifications, media download);
 *   the same update delivered again is stored once (its stored message id
 *   is unique, and its body hash too);
 * - an edited message updates the stored one (RecordMessageEdit);
 * - groups, channels, bots and button presses are ignored with a log line.
 */
class ProcessTelegramUpdate
{
    public function __construct(
        private ReceiveInboundMessage $receive,
        private RecordMessageEdit $edits,
        private ConnectionMonitor $monitor,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array{messages: int, duplicates: int, edits: int, ignored: int}
     */
    public function handle(Connection $connection, array $payload): array
    {
        $summary = ['messages' => 0, 'duplicates' => 0, 'edits' => 0, 'ignored' => 0];
        $update = TelegramUpdate::parse($connection, $payload);

        if ($update->kind === TelegramUpdate::MESSAGE && $update->message !== null) {
            $receipt = $this->receive->handle($connection, $update->message);

            if ($receipt->outcome === InboundMessageOutcome::Duplicate) {
                $summary['duplicates']++;
            } else {
                $summary['messages']++;
                $this->monitor->inboundReceived($connection, $update->message->sender->occurredAt);
            }

            return $summary;
        }

        if ($update->kind === TelegramUpdate::EDIT && $update->editedMessageId !== null && $update->editedAt !== null) {
            $edited = $this->edits->handle($connection, $update->editedMessageId, $update->editedText, $update->editedAt);
            $summary[$edited === null ? 'ignored' : 'edits']++;

            return $summary;
        }

        Log::info('Telegram update ignored.', ['connection_id' => $connection->id, 'update_id' => $update->updateId, 'reason' => $update->ignoredReason]);
        $summary['ignored']++;

        return $summary;
    }
}
