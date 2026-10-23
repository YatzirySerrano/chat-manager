<?php

namespace App\Console\Commands;

use App\Enums\InboundMessageOutcome;
use App\Models\Connection;
use App\Services\Meta\SimulatedWebhook;
use App\Support\ManualValidation;
use App\Support\ManualValidationSimulator;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Local development tool (SIMULACIÓN): a fictitious customer writes to a
 * demo line, or Meta reports the status of one of its outbound messages.
 * The event, in the documented WhatsApp Cloud API webhook format, goes
 * through the same processing real webhooks use (contact, conversation,
 * message, media, statuses, idempotency). Nothing is sent to or read from
 * Meta, nothing is deleted, and no HTTP endpoint is exposed. The work is
 * done by App\Support\ManualValidationSimulator.
 *
 * - --department=ventas (default) or soporte: the demo line, whose default
 *   department receives new conversations; the support line is created
 *   once with --create-support-line.
 * - --type: text (default), image, document, audio, video, sticker,
 *   location, contacts, interactive, button, reaction or unsupported. Media
 *   ids are fictitious: the attachment shows as unavailable, unless --file
 *   points to a local sample file, validated like a real download.
 * - --status=sent|delivered|read|failed --message=ID: Meta's status for an
 *   outbound message of a demo line; --out-of-order reports "read" before
 *   "delivered"; --duplicate delivers the same status twice; --error-code
 *   chooses the error of "failed" (131047: 24-hour window expired).
 * - --expire-window: moves the customer's last message on the line more
 *   than 24 hours back, closing the customer service window.
 */
class SimulateInboundMessage extends Command
{
    protected $signature = 'chat-manager:simulate-inbound
        {from? : laura, mario, sofia or a fictitious WhatsApp number between 5215500000100 and 5215500000199}
        {text? : Text the customer writes (caption, place or contact name for other types)}
        {--department=ventas : Demo line to write to: ventas or soporte}
        {--create-support-line : Create the demo support line (once) if it does not exist}
        {--type=text : text, image, document, audio, video, sticker, location, contacts, interactive, button, reaction or unsupported}
        {--file= : Local sample file attached to a simulated media message}
        {--reply-to= : Provider id (wamid) of the message the customer replies to}
        {--status= : Report sent, delivered, read or failed for the outbound message given in --message}
        {--message= : Id of an outbound message of a demo line (with --status)}
        {--error-code=131026 : With --status=failed: Meta error code (131026, 131047, 131049, 131050, 131056, 130429, 131000, 131031)}
        {--out-of-order : With --status=delivered or read: report read before delivered}
        {--name= : Profile name of a new fictitious customer}
        {--ad : The message comes from a (fictitious) click-to-WhatsApp ad}
        {--minutes-ago=0 : When the customer sent it, in minutes before now}
        {--duplicate : Deliver again the last message of this customer, with the same id}
        {--expire-window : Move the last message of this customer on the line more than 24 hours back}
        {--confirm : Required: confirms that fictitious data is written to the local database}';

    protected $description = 'LOCAL ONLY (simulación): simulate a WhatsApp message from a fictitious customer, or a status of an outbound message, on a demo line';

    public function isHidden(): bool
    {
        return ! app()->isLocal();
    }

    public function handle(ManualValidationSimulator $simulator): int
    {
        try {
            ManualValidation::ensureAllowed();

            if (! $this->option('confirm')) {
                throw new RuntimeException('Agrega --confirm para escribir la simulación en la base de datos local.');
            }

            if ($this->stringOption('status') !== null) {
                return $this->status($simulator);
            }

            $connection = $simulator->line($this->stringOption('department') ?? 'ventas', (bool) $this->option('create-support-line'));

            return $this->option('expire-window')
                ? $this->expireWindow($simulator, $connection)
                : $this->inbound($simulator, $connection);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function inbound(ManualValidationSimulator $simulator, Connection $connection): int
    {
        $result = $simulator->inbound($connection, $this->stringArgument('from') ?? '', $this->stringArgument('text'), [
            'type' => $this->stringOption('type') ?? 'text',
            'file' => $this->stringOption('file'),
            'reply_to' => $this->stringOption('reply-to'),
            'name' => $this->stringOption('name'),
            'ad' => (bool) $this->option('ad'),
            'minutes_ago' => (int) ($this->stringOption('minutes-ago') ?? 0),
            'duplicate' => (bool) $this->option('duplicate'),
        ]);
        $message = $result['message'];
        $conversation = $result['conversation'];

        if ($result['warning'] !== null) {
            $this->warn($result['warning']);
        } elseif ($this->stringOption('file') !== null && $result['outcome'] !== InboundMessageOutcome::Duplicate) {
            $this->info('Archivo de muestra adjuntado (simulación).');
        }

        file_put_contents(ManualValidation::inboundLogPath(), json_encode([
            'at' => now()->toIso8601String(),
            'wa_id' => $message->conversation->contact?->phone_e164,
            'message_id' => $message->whatsapp_message_id,
            'outcome' => $result['outcome']->value,
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
        ], JSON_UNESCAPED_UNICODE).PHP_EOL, FILE_APPEND | LOCK_EX);

        $this->table(['Resultado', 'Contacto', 'Tipo', 'Conversación', 'Estado', 'Departamento', 'Ventana 24 h', 'Id del mensaje'], [[
            $this->outcomeLabel($result['outcome']),
            $result['name'].($result['new_contact'] ? ' (nuevo)' : ' (existente)'),
            $message->type->value,
            "#{$conversation->id}",
            $conversation->status->label(),
            $conversation->department->name ?? 'Sin departamento',
            $result['window'],
            $message->whatsapp_message_id,
        ]]);
        $this->line('Abre: '.route('conversations.show', $conversation));

        return self::SUCCESS;
    }

    private function status(ManualValidationSimulator $simulator): int
    {
        $code = $this->stringOption('error-code') ?? (string) SimulatedWebhook::DEFAULT_ERROR_CODE;

        if (! ctype_digit($code)) {
            throw new RuntimeException('--error-code debe ser un número.');
        }

        ['message' => $message, 'sequence' => $sequence] = $simulator->status(
            (int) $this->stringOption('message'),
            (string) $this->stringOption('status'),
            (bool) $this->option('out-of-order'),
            (bool) $this->option('duplicate'),
            (int) $code,
        );

        $this->table(['Mensaje', 'Estados simulados', 'Estado actual', 'Error', 'Enviado', 'Entregado', 'Leído'], [[
            "#{$message->id}",
            implode(' → ', $sequence),
            $message->status->label(),
            $message->error_message ?? '—',
            $message->sent_at?->toDateTimeString() ?? '—',
            $message->delivered_at?->toDateTimeString() ?? '—',
            $message->read_at?->toDateTimeString() ?? '—',
        ]]);

        return self::SUCCESS;
    }

    private function expireWindow(ManualValidationSimulator $simulator, Connection $connection): int
    {
        $minutes = (int) ($this->stringOption('minutes-ago') ?? 0);
        $result = $simulator->expireWindow($connection, $this->stringArgument('from') ?? '', max($minutes, ManualValidationSimulator::EXPIRED_WINDOW_MINUTES));

        $this->table(['Conversación', 'Último mensaje del cliente', 'Ventana 24 h'], [[
            "#{$result['conversation']->id}",
            $result['last_inbound_at']->toDateTimeString(),
            $result['window'],
        ]]);
        $this->line('Recarga la conversación para ver la ventana cerrada: '.route('conversations.show', $result['conversation']));

        return self::SUCCESS;
    }

    private function outcomeLabel(InboundMessageOutcome $outcome): string
    {
        return match ($outcome) {
            InboundMessageOutcome::Created => 'Conversación nueva',
            InboundMessageOutcome::Appended => 'Agregado a la conversación abierta',
            InboundMessageOutcome::Reopened => 'Conversación reabierta',
            InboundMessageOutcome::LateArrival => 'Llegó tarde: se agregó al historial sin reabrir',
            InboundMessageOutcome::Duplicate => 'Duplicado: no se guardó otra vez',
        };
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
}
