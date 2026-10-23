<?php

namespace App\Support;

use App\Actions\Messaging\ProcessWebhookPayload;
use App\Enums\ConversationStatus;
use App\Enums\InboundMessageOutcome;
use App\Enums\MessageDirection;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Conversations\CustomerServiceWindow;
use App\Services\Meta\MediaRejected;
use App\Services\Meta\MediaStorage;
use App\Services\Meta\MetaCloudWhatsAppTransport;
use App\Services\Meta\SimulatedWebhook;
use Carbon\CarbonImmutable;
use Database\Seeders\Local\ManualValidationSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SIMULACIÓN — what chat-manager:simulate-inbound does, once the command has
 * checked that it runs locally on the application database. Every event, in
 * Meta's documented webhook format, goes through the same processing as a
 * real delivery (ProcessWebhookPayload). It only acts on the demo lines
 * (ManualValidation::LINES) and the fictitious customers of the scenario,
 * creates at most one contact per call and never sends anything to Meta.
 * Refusals are RuntimeExceptions with the reason in Spanish.
 */
final class ManualValidationSimulator
{
    /**
     * Department names accepted by --department, by key of ManualValidation::LINES.
     */
    public const DEPARTMENT_ALIASES = ['ventas' => 'sales', 'sales' => 'sales', 'soporte' => 'support', 'support' => 'support'];

    /**
     * How long ago the last customer message is moved to close the 24-hour window.
     */
    public const EXPIRED_WINDOW_MINUTES = 24 * 60 + 5;

    public function __construct(
        private ProcessWebhookPayload $process,
        private CustomerServiceWindow $window,
        private MediaStorage $storage,
    ) {}

    /**
     * The demo line that routes to the department. The support line is only
     * created (idempotently) when it is asked for.
     */
    public function line(string $department, bool $createSupportLine = false): Connection
    {
        $key = self::DEPARTMENT_ALIASES[Str::lower($department)]
            ?? throw new RuntimeException('Departamento desconocido. Usa --department=ventas o --department=soporte.');

        $connection = Connection::query()->where('phone_number_id', ManualValidation::LINES[$key])->first();

        if ($connection !== null) {
            return $connection;
        }

        if ($key === 'support' && $createSupportLine) {
            return (new ManualValidationSeeder)->supportLine();
        }

        throw new RuntimeException($key === 'support'
            ? 'La línea demo de Soporte aún no existe. Agrega --create-support-line para crearla (una sola vez).'
            : 'La conexión demo aún no existe. Ejecuta: php artisan db:seed --class="Database\Seeders\Local\ManualValidationSeeder"');
    }

    /**
     * A message of a fictitious customer through the demo line.
     *
     * @param  array{type?: string, file?: string|null, reply_to?: string|null, name?: string|null, ad?: bool, minutes_ago?: int, duplicate?: bool}  $options
     * @return array{outcome: InboundMessageOutcome, new_contact: bool, message: Message, conversation: Conversation, name: string, window: string, warning: string|null}
     */
    public function inbound(Connection $connection, string $from, ?string $text, array $options = []): array
    {
        $this->ensureDemoLine($connection);

        $type = $options['type'] ?? 'text';

        if (! in_array($type, SimulatedWebhook::TYPES, true)) {
            throw new RuntimeException('Tipo desconocido. Usa uno de: '.implode(', ', SimulatedWebhook::TYPES).'.');
        }

        [$waId, $customerName] = $this->customer($from);
        $file = $options['file'] ?? null;

        if ($file !== null && ! is_file($file)) {
            throw new RuntimeException("El archivo de muestra {$file} no existe.");
        }

        $messageId = ($options['duplicate'] ?? false)
            ? $this->lastMessageId($waId) ?? throw new RuntimeException("Todavía no hay un mensaje simulado de {$waId} que entregar de nuevo.")
            : 'wamid.DEMO-B.sim.'.Str::lower((string) Str::ulid());

        $name = $customerName ?? ($options['name'] ?? 'Cliente '.substr($waId, -3));
        $name = str_starts_with($name, ManualValidation::PREFIX) ? $name : ManualValidation::PREFIX.$name;

        $existed = Message::query()->where('whatsapp_message_id', $messageId)->exists();
        $contactExisted = $this->contactIds($waId) !== [];
        $before = Conversation::query()
            ->where('connection_id', $connection->id)
            ->whereIn('contact_id', $this->contactIds($waId))
            ->pluck('status', 'id')
            ->all();
        $replyTo = $options['reply_to'] ?? null;

        $this->process->handle($connection, SimulatedWebhook::message(
            (string) $connection->phone_number_id,
            (string) ($connection->business_account_id ?: 'DEMO-B-WABA'),
            $waId,
            $name,
            $messageId,
            $type,
            $text ?? 'Hola, este es un mensaje de prueba.',
            now()->subMinutes(max(0, $options['minutes_ago'] ?? 0))->getTimestamp(),
            ($options['ad'] ?? false) ? ManualValidation::demoAdReferral(substr($waId, -3)) : null,
            $replyTo === null ? null : ['from' => $waId, 'id' => $replyTo],
            $file === null ? null : basename($file),
        ));

        $message = Message::query()->with(['conversation', 'attachments'])->where('whatsapp_message_id', $messageId)->first()
            ?? throw new RuntimeException('El evento simulado no se recibió.');
        $conversation = $message->conversation;
        $warning = $file !== null && ! $existed ? $this->attachSample($message, $file) : null;

        $previous = $before[$conversation->id] ?? null;
        $wasClosed = ($previous instanceof ConversationStatus ? $previous : ConversationStatus::tryFrom((string) $previous)) === ConversationStatus::Closed;

        return [
            'outcome' => match (true) {
                $existed => InboundMessageOutcome::Duplicate,
                $previous === null => InboundMessageOutcome::Created,
                $wasClosed && $conversation->status !== ConversationStatus::Closed => InboundMessageOutcome::Reopened,
                $wasClosed => InboundMessageOutcome::LateArrival,
                default => InboundMessageOutcome::Appended,
            },
            'new_contact' => ! $contactExisted,
            'message' => $message,
            'conversation' => $conversation,
            'name' => $name,
            'window' => $this->window->for($conversation)->toArray()['label'],
            'warning' => $warning,
        ];
    }

    /**
     * Meta's status for an outbound message of a demo line; --out-of-order
     * reports read before delivered and --duplicate repeats the last one.
     *
     * @return array{message: Message, sequence: list<string>}
     */
    public function status(int $messageId, string $status, bool $outOfOrder = false, bool $duplicate = false, int $errorCode = SimulatedWebhook::DEFAULT_ERROR_CODE): array
    {
        if (! in_array($status, ['sent', 'delivered', 'read', 'failed'], true)) {
            throw new RuntimeException('Usa --status=sent|delivered|read|failed con --message=<id de un mensaje saliente de una línea demo>.');
        }

        if (! array_key_exists($errorCode, SimulatedWebhook::ERROR_CODES)) {
            throw new RuntimeException('Código de error no simulable. Usa uno de: '.implode(', ', array_keys(SimulatedWebhook::ERROR_CODES)).'.');
        }

        $message = Message::query()
            ->with('conversation.channelConnection')
            ->whereKey($messageId)
            ->where('direction', MessageDirection::Outbound)
            ->whereHas('conversation.channelConnection', fn ($query) => $query->whereIn('phone_number_id', ManualValidation::LINES))
            ->first()
            ?? throw new RuntimeException("El mensaje #{$messageId} no es un mensaje saliente de una línea demo.");

        $connection = $message->conversation->channelConnection;
        $wamid = $message->whatsapp_message_id ?? 'wamid.DEMO-B.sim.'.Str::lower((string) Str::ulid());
        $sequence = $outOfOrder && in_array($status, ['delivered', 'read'], true) ? ['read', 'delivered'] : [$status];

        if ($duplicate) {
            $sequence[] = $sequence[array_key_last($sequence)];
        }

        foreach ($sequence as $offset => $reported) {
            $this->process->handle($connection, SimulatedWebhook::status(
                (string) $connection->phone_number_id,
                (string) ($connection->business_account_id ?: 'DEMO-B-WABA'),
                $wamid,
                $reported,
                now()->addSeconds($offset)->getTimestamp(),
                MetaCloudWhatsAppTransport::callbackData($message),
                $errorCode,
            ));
        }

        return ['message' => $message->refresh(), 'sequence' => $sequence];
    }

    /**
     * Close the 24-hour window of the customer's latest demo conversation on
     * the line by moving its last customer message (and last_inbound_at)
     * back in time. Refused when it would reorder the conversation's history.
     *
     * @return array{conversation: Conversation, last_inbound_at: CarbonImmutable, window: string}
     */
    public function expireWindow(Connection $connection, string $from, int $minutesAgo = self::EXPIRED_WINDOW_MINUTES): array
    {
        $this->ensureDemoLine($connection);
        [$waId] = $this->customer($from);

        $conversation = Conversation::query()
            ->where('connection_id', $connection->id)
            ->whereIn('contact_id', $this->contactIds($waId))
            ->orderByRaw('status = ? asc', [ConversationStatus::Closed->value])
            ->orderByDesc('id')
            ->first()
            ?? throw new RuntimeException("{$waId} todavía no tiene conversaciones en esta línea demo.");

        $last = $conversation->messages()->where('direction', MessageDirection::Inbound)->orderByDesc('sent_at')->orderByDesc('id')->first()
            ?? throw new RuntimeException("La conversación #{$conversation->id} no tiene mensajes del cliente.");

        $target = CarbonImmutable::now()->subMinutes(max(self::EXPIRED_WINDOW_MINUTES, $minutesAgo));

        if ($last->sent_at !== null && $last->sent_at->lessThanOrEqualTo($target)) {
            return ['conversation' => $conversation, 'last_inbound_at' => CarbonImmutable::instance($last->sent_at), 'window' => $this->window->for($conversation)->toArray()['label']];
        }

        $reorders = $conversation->messages()
            ->whereKeyNot($last->id)
            ->where('sent_at', '<=', $last->sent_at ?? now())
            ->where('sent_at', '>', $target)
            ->exists();

        if ($reorders) {
            throw new RuntimeException("La conversación #{$conversation->id} tiene mensajes anteriores de las últimas 24 horas; moverlo cambiaría el orden del historial. Simula la ventana vencida con un número ficticio sin conversación: php artisan chat-manager:simulate-inbound 5215500000160 \"Hola\" --minutes-ago=1500 --confirm.");
        }

        DB::transaction(function () use ($conversation, $last, $target): void {
            $last->forceFill(['sent_at' => $target])->save();
            $conversation->forceFill(['last_inbound_at' => $target])->save();
        });

        $conversation->refresh();

        return ['conversation' => $conversation, 'last_inbound_at' => $target, 'window' => $this->window->for($conversation)->toArray()['label']];
    }

    private function ensureDemoLine(Connection $connection): void
    {
        if (! in_array($connection->phone_number_id, ManualValidation::LINES, true)) {
            throw new RuntimeException('El simulador solo actúa sobre las líneas demo.');
        }
    }

    /**
     * @return array{string, string|null} The wa_id and, for a customer of the scenario, its name.
     */
    private function customer(string $from): array
    {
        $customer = ManualValidation::CUSTOMERS[Str::lower($from)] ?? null;
        $waId = $customer['wa_id'] ?? $from;

        if (! ManualValidation::isDemoSender($waId)) {
            throw new RuntimeException('Solo se pueden simular números ficticios entre 5215500000100 y 5215500000199 (o laura, mario, sofia).');
        }

        return [$waId, $customer['name'] ?? null];
    }

    /**
     * @return list<int>
     */
    private function contactIds(string $waId): array
    {
        return array_values(array_map('intval', DB::table('contact_identities')
            ->where('channel', 'whatsapp')
            ->where('external_id', $waId)
            ->pluck('contact_id')
            ->all()));
    }

    private function attachSample(Message $message, string $file): ?string
    {
        $attachment = $message->attachments->first();

        if ($attachment === null) {
            return 'Este tipo de mensaje no lleva archivo; se ignoró --file.';
        }

        try {
            SimulatedWebhook::attachLocalFile($attachment->load('message'), $file, $this->storage);
        } catch (MediaRejected $exception) {
            return 'El archivo de muestra fue rechazado: '.$exception->getMessage();
        }

        return null;
    }

    /**
     * The customer's latest message on any demo line, to deliver it again.
     */
    private function lastMessageId(string $waId): ?string
    {
        $id = Message::query()
            ->where('direction', MessageDirection::Inbound)
            ->whereHas('conversation', fn ($query) => $query
                ->whereIn('contact_id', $this->contactIds($waId))
                ->whereHas('channelConnection', fn ($line) => $line->whereIn('phone_number_id', ManualValidation::LINES)))
            ->orderByDesc('id')
            ->value('whatsapp_message_id');

        return is_string($id) ? $id : null;
    }
}
