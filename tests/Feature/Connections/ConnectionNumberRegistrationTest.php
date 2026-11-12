<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| Registration of a WhatsApp number with the Cloud API
| (POST /{phone-number-id}/register) from the connection page.
*/

const REGISTRATION_TOKEN = 'EAAGregistrationTestToken000000000000000000000000000000';

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.meta.graph_version' => 'v26.0']);
    $this->connection = Connection::factory()->verified()->create([
        'phone_number_id' => '106540352242922',
        'access_token' => REGISTRATION_TOKEN,
    ]);
    $this->admin = User::factory()->superAdministrator()->create();
});

test('registering the number sends the PIN to Meta and records only the fact', function () {
    Http::fake([
        'graph.facebook.com/v26.0/106540352242922/register' => Http::response(['success' => true]),
        'graph.facebook.com/v26.0/106540352242922*' => Http::response(['id' => '106540352242922', 'status' => 'CONNECTED', 'verified_name' => 'Empresa de prueba']),
    ]);

    $this->actingAs($this->admin)
        ->post(route('connections.register', $this->connection), ['pin' => '482913'])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.type', 'success');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/106540352242922/register')
        && $request->method() === 'POST'
        && $request['messaging_product'] === 'whatsapp'
        && $request['pin'] === '482913'
        && $request->hasHeader('Authorization', 'Bearer '.REGISTRATION_TOKEN));

    $entry = AuditLog::query()->where('action', AuditAction::ConnectionNumberRegistered)->sole();

    expect(json_encode($entry->getAttributes()))->not->toContain('482913')
        ->and($this->connection->refresh()->phone_status)->toBe('CONNECTED');
});

test('a PIN that is not six digits never reaches Meta', function (mixed $pin) {
    Http::fake();

    $this->actingAs($this->admin)
        ->post(route('connections.register', $this->connection), ['pin' => $pin])
        ->assertSessionHasErrors('pin');

    Http::assertNothingSent();
    expect(session()->getOldInput('pin'))->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionNumberRegistered)->exists())->toBeFalse();
})->with(['12345', '1234567', '12a456', '', null]);

test('a refusal from Meta is explained next to the PIN without keeping it', function () {
    Http::fake(['graph.facebook.com/v26.0/106540352242922/register' => Http::response([
        'error' => ['message' => 'Two step verification PIN mismatch', 'code' => 133005],
    ], 400)]);

    $this->actingAs($this->admin)
        ->post(route('connections.register', $this->connection), ['pin' => '000000'])
        ->assertSessionHasErrors(['pin' => 'El PIN no coincide con el PIN de verificación en dos pasos de este número. Escribe el PIN correcto o restablécelo en WhatsApp Manager (Números de teléfono → Verificación en dos pasos).']);

    expect(session()->getOldInput('pin'))->toBeNull()
        ->and(AuditLog::query()->where('action', AuditAction::ConnectionNumberRegistered)->exists())->toBeFalse();
});

test('only people who manage connections can register a number', function () {
    Http::fake();

    $this->actingAs(User::factory()->create())
        ->post(route('connections.register', $this->connection), ['pin' => '482913'])
        ->assertForbidden();

    Http::assertNothingSent();
});
