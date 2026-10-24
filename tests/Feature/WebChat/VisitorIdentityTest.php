<?php

use App\Enums\ConnectionChannel;
use App\Enums\ContactSource;
use App\Events\Messaging\MessageRecorded;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\WebChatSession;
use App\Services\WebChat\VerifiedIdentity;
use App\Services\WebChat\VisitorSessions;
use Illuminate\Support\Facades\Event;
use Tests\Support\WebChatFixtures;
use Tests\Support\WebChatVisitor;

/*
| Identities the host site signs for its signed-in users: HMAC with the
| widget's identity secret, a bounded clock skew and single-use nonces.
| An identified person is one contact per connection, followed across
| devices, and never merged with WhatsApp or another connection.
*/

beforeEach(function () {
    $this->connection = WebChatFixtures::connection(['identity_mode' => 'optional']);
    $this->widget = $this->connection->webChatWidget;
});

test('a signed identity follows the person across devices with a single contact identity', function () {
    $laptop = WebChatVisitor::start($this, $this->connection, ['identity' => WebChatVisitor::identity($this->widget)])
        ->assertCreated()
        ->assertJsonPath('data.identified', true)
        ->assertJsonPath('data.profile.name', 'Ana Torres')
        ->json('token');
    WebChatVisitor::send($this, $laptop, 'Escribo desde la computadora')->assertCreated();

    $phone = WebChatVisitor::token($this, $this->connection, ['identity' => WebChatVisitor::identity($this->widget)]);

    WebChatVisitor::messages($this, $phone)->assertOk()->assertJsonPath('data.0.body', 'Escribo desde la computadora');

    $identity = ContactIdentity::query()->where('channel', ConnectionChannel::WebChat)->sole();
    expect($identity->external_id)->toStartWith('wi:')
        ->and(WebChatSession::query()->pluck('contact_id')->unique()->all())->toBe([$identity->contact_id])
        ->and(Contact::query()->find($identity->contact_id))
        ->email->toBe('ana@tienda.test')
        ->source->toBe(ContactSource::WebChat);
});

test('an invalid, stale or replayed identity is refused with 401', function (Closure $identity) {
    WebChatVisitor::start($this, $this->connection, ['identity' => $identity($this)])
        ->assertUnauthorized()
        ->assertJson(['code' => 'unauthenticated']);

    expect(WebChatSession::query()->count())->toBe(0);
})->with([
    'wrong secret' => [fn ($test) => WebChatVisitor::identity($test->widget, secret: str_repeat('x', 64))],
    'tampered external id' => [fn ($test) => [...WebChatVisitor::identity($test->widget), 'external_id' => 'cliente-43']],
    'tampered email' => [fn ($test) => [...WebChatVisitor::identity($test->widget), 'email' => 'otra@tienda.test']],
    'issued too long ago' => [fn ($test) => WebChatVisitor::identity($test->widget, overrides: ['iat' => now()->subMinutes(6)->getTimestamp()])],
    'issued in the future' => [fn ($test) => WebChatVisitor::identity($test->widget, overrides: ['iat' => now()->addMinutes(6)->getTimestamp()])],
    'replayed nonce' => [function ($test) {
        $identity = WebChatVisitor::identity($test->widget);
        WebChatVisitor::start($test, $test->connection, ['identity' => $identity])->assertCreated();
        WebChatSession::query()->delete();

        return $identity;
    }],
]);

test('a widget that requires identification refuses anonymous visitors', function () {
    $this->widget->forceFill(['identity_mode' => 'required'])->save();

    WebChatVisitor::start($this, $this->connection)->assertUnauthorized();
    WebChatVisitor::start($this, $this->connection, ['identity' => WebChatVisitor::identity($this->widget)])->assertCreated();
});

test('the same external id on another connection is another contact', function () {
    $other = WebChatFixtures::connection(['identity_mode' => 'optional']);

    WebChatVisitor::send($this, WebChatVisitor::token($this, $this->connection, ['identity' => WebChatVisitor::identity($this->widget)]))->assertCreated();
    WebChatVisitor::send($this, WebChatVisitor::token($this, $other, ['identity' => WebChatVisitor::identity($other->webChatWidget)]))->assertCreated();

    expect(ContactIdentity::query()->where('channel', ConnectionChannel::WebChat)->pluck('contact_id')->unique())->toHaveCount(2);
});

test('an identified visitor is never linked to a WhatsApp contact with the same email or phone', function () {
    $whatsapp = Contact::factory()->create(['email' => 'ana@tienda.test', 'phone_e164' => '+525512345678']);
    ContactIdentity::factory()->create(['contact_id' => $whatsapp->id, 'external_id' => '5215512345678']);

    $token = WebChatVisitor::token($this, $this->connection, ['identity' => WebChatVisitor::identity($this->widget, '5215512345678')]);
    WebChatVisitor::send($this, $token)->assertCreated();

    $visitor = ContactIdentity::query()->where('channel', ConnectionChannel::WebChat)->sole();
    expect($visitor->contact_id)->not->toBe($whatsapp->id)
        ->and($visitor->contact->phone_e164)->toBeNull()
        ->and($whatsapp->identities()->count())->toBe(1);
});

test('identifying a session that has not written yet keeps the session', function () {
    $token = WebChatVisitor::token($this, $this->connection);

    $this->withToken($token)->postJson(route('api.v1.widget.session.identity.store'), WebChatVisitor::identity($this->widget))
        ->assertOk()
        ->assertJsonPath('data.identified', true)
        ->assertJsonMissingPath('token');

    expect(WebChatSession::query()->sole())->identified->toBeTrue()->visitor_id->toStartWith('wi:');
});

test('identifying a session that already wrote replaces it without attaching the anonymous history', function () {
    $anonymous = WebChatVisitor::token($this, $this->connection);
    WebChatVisitor::send($this, $anonymous, 'Mensaje anónimo')->assertCreated();

    $response = $this->withToken($anonymous)->postJson(route('api.v1.widget.session.identity.store'), WebChatVisitor::identity($this->widget))
        ->assertCreated()
        ->assertJsonPath('data.identified', true);
    $identified = $response->json('token');

    expect($identified)->not->toBe($anonymous)
        ->and(WebChatSession::query()->whereNotNull('revoked_at')->sole()->revoke_reason)->toBe('identified');

    $this->withToken($anonymous)->getJson(route('api.v1.widget.session.show'))->assertUnauthorized();
    WebChatVisitor::messages($this, $identified)->assertOk()->assertJsonCount(0, 'data');
});

test('identify decides with the session as it is now, not the copy read before it wrote', function () {
    ['session' => $session, 'token' => $token] = WebChatFixtures::session($this->connection);
    $stale = WebChatSession::query()->with('connection.webChatWidget')->findOrFail($session->id);
    WebChatVisitor::send($this, $token, 'Mensaje anónimo')->assertCreated();

    ['session' => $identified, 'token' => $replacementToken] = app(VisitorSessions::class)
        ->identify($stale, new VerifiedIdentity('cliente-42', 'wi:cliente-42', 'Ana Torres'));

    expect($replacementToken)->not->toBeNull()
        ->and($identified->id)->not->toBe($session->id)
        ->and($identified->contact_id)->toBeNull()
        ->and($session->refresh()->revoke_reason)->toBe('identified');
});

test('a message racing with identify never attaches the anonymous contact to the identified session', function () {
    ['session' => $session, 'token' => $token] = WebChatFixtures::session($this->connection);

    // identify() commits while the message goes through the pipeline.
    Event::listen(MessageRecorded::class, fn () => WebChatSession::query()->whereKey($session->id)->update([
        'visitor_id' => 'wi:cliente-42',
        'identified' => true,
        'contact_id' => null,
    ]));

    WebChatVisitor::send($this, $token, 'Mensaje anónimo')
        ->assertStatus(409)
        ->assertJson(['code' => 'conflict']);

    expect($session->refresh())
        ->identified->toBeTrue()
        ->contact_id->toBeNull();
    WebChatVisitor::messages($this, $token)->assertOk()->assertJsonCount(0, 'data');
});

test('the other devices of an identified person read the history once their contact exists', function () {
    $laptop = WebChatVisitor::token($this, $this->connection, ['identity' => WebChatVisitor::identity($this->widget)]);
    $phone = WebChatVisitor::token($this, $this->connection, ['identity' => WebChatVisitor::identity($this->widget)]);

    WebChatVisitor::messages($this, $phone)->assertOk()->assertJsonCount(0, 'data');
    WebChatVisitor::send($this, $laptop, 'Escribo desde la computadora')->assertCreated();

    WebChatVisitor::messages($this, $phone)->assertOk()->assertJsonPath('data.0.body', 'Escribo desde la computadora');
    expect(WebChatSession::query()->pluck('contact_id')->unique()->all())
        ->toBe([ContactIdentity::query()->where('channel', ConnectionChannel::WebChat)->sole()->contact_id]);
});

test('a widget for anonymous visitors does not accept identities', function () {
    $this->widget->forceFill(['identity_mode' => 'anonymous'])->save();
    $token = WebChatVisitor::token($this, $this->connection);

    $this->withToken($token)->postJson(route('api.v1.widget.session.identity.store'), WebChatVisitor::identity($this->widget))
        ->assertStatus(409)
        ->assertJson(['code' => 'conflict']);
});
