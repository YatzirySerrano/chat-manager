<?php

namespace App\Console\Commands;

use App\Actions\Messaging\ProcessWebhookPayload;
use App\Enums\MessageDirection;
use App\Models\Connection;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Conversations\CustomerServiceWindow;
use App\Services\Meta\MediaRejected;
use App\Services\Meta\MediaStorage;
use App\Services\Meta\MetaCloudWhatsAppTransport;
use App\Services\Meta\SimulatedWebhook;
use App\Support\ManualValidation;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local development tool (SIMULACIÓN): a fictitious customer writes to the
 * demo connection, or Meta reports the status of one of its outbound
 * messages. The event, in the documented WhatsApp Cloud API webhook format,
 * goes through the same processing real webhooks use (contact,
 * conversation, message, media, statuses, idempotency). Nothing is sent to
 * or read from Meta, nothing is deleted, and no HTTP endpoint is exposed.
 *
 * - --type: text (default), image, document, audio, video, sticker,
 *   location, contacts, interactive, button, reaction or unsupported. Media
 *   ids are fictitious: the attachment shows as unavailable, unless --file
 *   points to a local sample file, validated like a real download.
 * - --status=sent|delivered|read|failed --message=ID: Meta's status for an
 *   outbound message of the demo connection; --out-of-order reports "read"
 *   before "delivered"; --duplicate delivers the same status twice.
 */
class SimulateInboundMessage extends Command
{
    protected $signature = 'chat-manager:simulate-inbound
        {from? : laura, mario, sofia or a fictitious WhatsApp number between 5215500000100 and 5215500000199}
        {text? : Text the customer writes (caption, place or contact name for other types)}
        {--type=text : text, image, document, audio, video, sticker, location, contacts, interactive, button, reaction or unsupported}
        {--file= : Local sample file attached to a simulated media message}
        {--reply-to= : Provider id (wamid) of the message the customer replies to}
        {--status= : Report sent, delivered, read or failed for the outbound message given in --message}
        {--message= : Id of an outbound message of the demo connection (with --status)}
        {--out-of-order : With --status=delivered or read: report read before delivered}
        {--name= : Profile name of a new fictitious customer}
        {--ad : The message comes from a (fictitious) click-to-WhatsApp ad}
        {--minutes-ago=0 : When the customer sent it, in minutes before now}
        {--duplicate : Deliver again the last message of this customer, with the same id}
        {--confirm : Required: confirms that fictitious data is written to the local database}';

    protected $description = 'LOCAL ONLY (simulación): simulate a WhatsApp message from a fictitious customer, or a status of an outbound message, on the demo connection';

    public function isHidden(): bool
    {
        return ! app()->isLocal();
    }

    public function handle(ProcessWebhookPayload $process, CustomerServiceWindow $window, MediaStorage $storage): int
    {
        try {
            ManualValidation::ensureAllowed();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->error('Add --confirm to write the simulated message to the local database.');

            return self::FAILURE;
        }

        $connection = Connection::query()->where('phone_number_id', ManualValidation::PHONE_NUMBER_ID)->first();

        if ($connection === null) {
            $this->error('The demo connection does not exist yet. Run: php artisan db:seed --class="Database\Seeders\Local\ManualValidationSeeder"');

            return self::FAILURE;
        }

        if ($this->stringOption('status') !== null) {
            return $this->simulateStatus($process, $connection);
        }

        $type = $this->stringOption('type') ?? 'text';

        if (! in_array($type, SimulatedWebhook::TYPES, true)) {
            $this->error('Unknown --type. Use one of: '.implode(', ', SimulatedWebhook::TYPES).'.');

            return self::FAILURE;
        }

        $from = $this->stringArgument('from') ?? '';
        $customer = ManualValidation::CUSTOMERS[Str::lower($from)] ?? null;
        $waId = $customer['wa_id'] ?? $from;

        if (! ManualValidation::isDemoSender($waId)) {
            $this->error('Only fictitious numbers between 5215500000100 and 5215500000199 (or laura, mario, sofia) can be simulated.');

            return self::FAILURE;
        }

        $messageId = $this->option('duplicate') ? $this->lastMessageId($waId) : 'wamid.DEMO-B.sim.'.Str::lower((string) Str::ulid());

        if ($messageId === null) {
            $this->error("No simulated message of {$waId} has been recorded yet to deliver again.");

            return self::FAILURE;
        }

        $name = $customer['name'] ?? ($this->stringOption('name') ?? 'Cliente '.substr($waId, -3));
        $name = str_starts_with($name, ManualValidation::PREFIX) ? $name : ManualValidation::PREFIX.$name;
        $minutesAgo = max(0, (int) ($this->stringOption('minutes-ago') ?? 0));

        $file = $this->stringOption('file');

        if ($file !== null && ! is_file($file)) {
            $this->error("The sample file {$file} does not exist.");

            return self::FAILURE;
        }

        $replyTo = $this->stringOption('reply-to');
        $existed = Message::query()->where('whatsapp_message_id', $messageId)->exists();

        $process->handle($connection, SimulatedWebhook::message(
            ManualValidation::PHONE_NUMBER_ID,
            (string) ($connection->business_account_id ?: 'DEMO-B-WABA'),
            $waId,
            $name,
            $messageId,
            $type,
            $this->stringArgument('text') ?? 'Hola, este es un mensaje de prueba.',
            now()->subMinutes($minutesAgo)->getTimestamp(),
            $this->option('ad') ? ManualValidation::demoAdReferral(substr($waId, -3)) : null,
            $replyTo === null ? null : ['from' => $waId, 'id' => $replyTo],
            $file === null ? null : basename($file),
        ));

        $message = Message::query()->with(['conversation', 'attachments'])->where('whatsapp_message_id', $messageId)->first();

        if ($message === null) {
            $this->error('The simulated event was not received.');

            return self::FAILURE;
        }

        if ($file !== null && ! $existed) {
            $this->attachSample($message->attachments->first(), $file, $storage);
        }

        $conversation = $message->conversation;
        $state = $window->for($conversation);
        $outcome = $existed ? 'duplicate' : 'received';

        $this->record([
            'at' => now()->toIso8601String(),
            'wa_id' => $waId,
            'message_id' => $messageId,
            'outcome' => $outcome,
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
        ]);

        $this->table(['Resultado', 'Tipo', 'Conversación', 'Estado', 'Contacto', 'Ventana 24 h', 'Id del mensaje'], [[
            $outcome,
            $message->type->value,
            "#{$conversation->id}",
            $conversation->status->label(),
            $name,
            $state->toArray()['label'],
            $messageId,
        ]]);
        $this->line('Abre: '.route('conversations.show', $conversation));

        return self::SUCCESS;
    }

    /**
     * Meta's status for an outbound message of the demo connection, through
     * the same processing as a status webhook.
     */
    private function simulateStatus(ProcessWebhookPayload $process, Connection $connection): int
    {
        $status = (string) $this->stringOption('status');
        $message = Message::query()
            ->whereKey((int) $this->stringOption('message'))
            ->where('direction', MessageDirection::Outbound)
            ->whereHas('conversation', fn ($query) => $query->where('connection_id', $connection->id))
            ->first();

        if (! in_array($status, ['sent', 'delivered', 'read', 'failed'], true) || $message === null) {
            $this->error('Use --status=sent|delivered|read|failed with --message=<id of an outbound message of the demo connection>.');

            return self::FAILURE;
        }

        $wamid = $message->whatsapp_message_id ?? 'wamid.DEMO-B.sim.'.Str::lower((string) Str::ulid());
        $sequence = $this->option('out-of-order') && in_array($status, ['delivered', 'read'], true) ? ['read', 'delivered'] : [$status];

        if ($this->option('duplicate')) {
            $sequence[] = end($sequence);
        }

        foreach ($sequence as $offset => $reported) {
            $process->handle($connection, SimulatedWebhook::status(
                ManualValidation::PHONE_NUMBER_ID,
                (string) ($connection->business_account_id ?: 'DEMO-B-WABA'),
                $wamid,
                $reported,
                now()->addSeconds($offset)->getTimestamp(),
                MetaCloudWhatsAppTransport::callbackData($message),
            ));
        }

        $message->refresh();
        $this->table(['Mensaje', 'Estados simulados', 'Estado actual', 'Enviado', 'Entregado', 'Leído'], [[
            "#{$message->id}",
            implode(' → ', $sequence),
            $message->status->label(),
            $message->sent_at?->toDateTimeString() ?? '—',
            $message->delivered_at?->toDateTimeString() ?? '—',
            $message->read_at?->toDateTimeString() ?? '—',
        ]]);

        return self::SUCCESS;
    }

    private function attachSample(?MessageAttachment $attachment, string $file, MediaStorage $storage): void
    {
        if ($attachment === null) {
            $this->warn('This message type carries no file; --file was ignored.');

            return;
        }

        try {
            SimulatedWebhook::attachLocalFile($attachment->load('message'), $file, $storage);
            $this->info('Sample file attached (simulación).');
        } catch (MediaRejected $exception) {
            $this->warn('The sample file was refused: '.$exception->getMessage());
        }
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function lastMessageId(string $waId): ?string
    {
        $path = ManualValidation::inboundLogPath();
        $lines = is_file($path) ? array_reverse(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];

        foreach ($lines as $line) {
            $entry = json_decode($line, true);

            if (is_array($entry) && ($entry['wa_id'] ?? null) === $waId && is_string($entry['message_id'] ?? null)) {
                return $entry['message_id'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function record(array $entry): void
    {
        file_put_contents(ManualValidation::inboundLogPath(), json_encode($entry, JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
