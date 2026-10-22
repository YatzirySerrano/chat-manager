<?php

use App\Jobs\ProcessWhatsAppWebhook;
use App\Support\LogContext;
use Illuminate\Support\Facades\Context;

test('job payloads yield their correlation identifiers', function () {
    $payload = [
        'uuid' => 'job-uuid-1',
        'displayName' => ProcessWhatsAppWebhook::class,
        'data' => ['command' => serialize(new ProcessWhatsAppWebhook(42))],
    ];

    expect(LogContext::fromJobPayload($payload, 'webhooks'))->toBe([
        'job' => ProcessWhatsAppWebhook::class,
        'job_id' => 'job-uuid-1',
        'queue' => 'webhooks',
        'webhook_event_id' => 42,
    ]);
});

test('only identifier keys and scalar values enter the log context', function () {
    LogContext::add(['message_id' => 5, 'body' => 'Hola, mi teléfono es 5512345678', 'conversation_id' => null]);

    expect(Context::get('message_id'))->toBe(5)
        ->and(Context::has('body'))->toBeFalse()
        ->and(Context::has('conversation_id'))->toBeFalse();
});
