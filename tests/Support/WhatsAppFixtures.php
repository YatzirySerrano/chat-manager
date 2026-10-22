<?php

namespace Tests\Support;

use App\Models\Connection;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Meta\SendResult;
use App\Services\Meta\WhatsAppTransport;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Builders of WhatsApp Cloud API webhook bodies, signed requests and
 * transport doubles for the WhatsApp module tests. Formats follow Meta's
 * documented webhook payloads.
 */
final class WhatsAppFixtures
{
    /**
     * A "messages" change carrying statuses of outbound messages.
     *
     * @param  list<array<string, mixed>>  $statuses
     * @return array<string, mixed>
     */
    public static function statusWebhook(Connection $connection, array $statuses): array
    {
        return self::envelope($connection, 'messages', [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '15550783881', 'phone_number_id' => $connection->phone_number_id],
            'statuses' => $statuses,
        ]);
    }

    /**
     * One status entry.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function status(string $wamid, string $status, CarbonImmutable $at, array $extra = []): array
    {
        return [
            'id' => $wamid,
            'status' => $status,
            'timestamp' => (string) $at->getTimestamp(),
            'recipient_id' => '5215512345678',
            ...$extra,
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    public static function envelope(Connection $connection, string $field, array $value): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => (string) $connection->business_account_id,
                'changes' => [['field' => $field, 'value' => $value]],
            ]],
        ];
    }

    public static function signature(string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    /**
     * POST a body to the connection's webhook URL, signed with its app secret
     * unless another signature is given.
     *
     * @param  array<string, mixed>|string  $payload
     */
    public static function post(mixed $test, Connection $connection, array|string $payload, ?string $signature = null): TestResponse
    {
        $body = is_string($payload) ? $payload : (string) json_encode($payload);

        return $test->call(
            'POST',
            route('webhooks.whatsapp.receive', ['webhookKey' => $connection->webhook_key]),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature ?? self::signature($body, (string) $connection->app_secret),
            ],
            content: $body,
        );
    }

    /**
     * Transport double: records the messages it "sends" and answers with
     * the given closure (a SendResult or an exception).
     *
     * @param  (Closure(Message): SendResult)|null  $answer
     */
    public static function transport(?Closure $answer = null): object
    {
        $transport = new class($answer) implements WhatsAppTransport
        {
            /** @var list<int> */
            public array $sent = [];

            public function __construct(private ?Closure $answer) {}

            public function send(Message $message): SendResult
            {
                $this->sent[] = $message->id;

                return $this->answer === null ? new SendResult('wamid.out.'.$message->id) : ($this->answer)($message);
            }

            public function name(): string
            {
                return 'fake';
            }
        };

        app()->instance(WhatsAppTransport::class, $transport);

        return $transport;
    }

    /**
     * An outbound message of an attended conversation, in the given state.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function outbound(Conversation $conversation, array $attributes = []): Message
    {
        return Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'type' => 'text',
            'status' => 'queued',
            'body' => 'Hola, le escribe el equipo de soporte',
            'client_reference' => (string) Str::uuid(),
            'whatsapp_message_id' => null,
            ...$attributes,
        ]);
    }
}
