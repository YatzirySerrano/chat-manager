<?php

namespace App\Console\Commands;

use App\Actions\Conversations\ProcessInboundMessages;
use App\Models\Connection;
use App\Services\Conversations\CustomerServiceWindow;
use App\Support\ManualValidation;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local development tool: a fictitious customer writes to the demo
 * connection. The event, in the documented WhatsApp Cloud API webhook
 * format, goes through the same pipeline a real webhook will use (contact,
 * conversation, message, idempotency). Nothing is sent to or read from Meta,
 * nothing is deleted, and no HTTP endpoint is exposed.
 */
class SimulateInboundMessage extends Command
{
    protected $signature = 'chat-manager:simulate-inbound
        {from : laura, mario, sofia or a fictitious WhatsApp number between 5215500000100 and 5215500000199}
        {text? : Text the customer writes}
        {--name= : Profile name of a new fictitious customer}
        {--ad : The message comes from a (fictitious) click-to-WhatsApp ad}
        {--minutes-ago=0 : When the customer sent it, in minutes before now}
        {--duplicate : Deliver again the last message of this customer, with the same id}
        {--confirm : Required: confirms that fictitious data is written to the local database}';

    protected $description = 'LOCAL ONLY: simulate a WhatsApp message from a fictitious customer of the Demo B scenario';

    public function isHidden(): bool
    {
        return ! app()->isLocal();
    }

    public function handle(ProcessInboundMessages $process, CustomerServiceWindow $window): int
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

        $from = $this->stringArgument('from') ?? '';
        $customer = ManualValidation::CUSTOMERS[Str::lower($from)] ?? null;
        $waId = $customer['wa_id'] ?? $from;

        if (! ManualValidation::isDemoSender($waId)) {
            $this->error('Only fictitious numbers between 5215500000100 and 5215500000199 (or laura, mario, sofia) can be simulated.');

            return self::FAILURE;
        }

        if (! Connection::query()->where('phone_number_id', ManualValidation::PHONE_NUMBER_ID)->exists()) {
            $this->error('The demo connection does not exist yet. Run: php artisan db:seed --class="Database\Seeders\Local\ManualValidationSeeder"');

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

        $receipts = $process->handle(ManualValidation::inboundPayload(
            $waId,
            $name,
            $messageId,
            $this->stringArgument('text') ?? 'Hola, este es un mensaje de prueba.',
            now()->subMinutes($minutesAgo)->getTimestamp(),
            $this->option('ad') ? ManualValidation::demoAdReferral(substr($waId, -3)) : null,
        ));

        $receipt = $receipts[0] ?? null;

        if ($receipt === null) {
            $this->error('The simulated event was not received.');

            return self::FAILURE;
        }

        $conversation = $receipt->conversation;
        $state = $window->for($conversation);

        $this->record([
            'at' => now()->toIso8601String(),
            'wa_id' => $waId,
            'message_id' => $messageId,
            'outcome' => $receipt->outcome->value,
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
        ]);

        $this->table(['Resultado', 'Conversación', 'Estado', 'Contacto', 'Ventana 24 h', 'Id del mensaje'], [[
            $receipt->outcome->value,
            "#{$conversation->id}",
            $conversation->status->label(),
            $name,
            $state->toArray()['label'],
            $messageId,
        ]]);
        $this->line('Abre: '.route('conversations.show', $conversation));

        return self::SUCCESS;
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
