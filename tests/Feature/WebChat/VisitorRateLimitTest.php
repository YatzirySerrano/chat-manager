<?php

use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\WebChatFixtures;
use Tests\Support\WebChatVisitor;

/*
| Abuse limits of the anonymous visitor surface: sessions per IP (an IPv6
| /64 counts as one address) and per widget (only sessions with a valid
| ticket count), and messages per session, answered as JSON 429 with
| Retry-After.
*/

beforeEach(function () {
    RateLimiter::clear('webchat-sessions');
    $this->connection = WebChatFixtures::connection();
});

test('starting too many sessions from one address is limited', function () {
    config(['webchat.limits.sessions_per_ip_per_minute' => 2]);

    WebChatVisitor::start($this, $this->connection)->assertCreated();
    WebChatVisitor::start($this, $this->connection)->assertCreated();

    WebChatVisitor::start($this, $this->connection)
        ->assertStatus(429)
        ->assertJson(['code' => 'rate_limited'])
        ->assertHeader('Retry-After');
});

test('a widget limits the sessions of all addresses together', function () {
    config(['webchat.limits.sessions_per_widget_per_minute' => 1]);

    WebChatVisitor::start($this, $this->connection)->assertCreated();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

    WebChatVisitor::start($this, $this->connection)->assertStatus(429);
});

test('junk session requests never exhaust the widget limit of real visitors', function () {
    config(['webchat.limits.sessions_per_widget_per_minute' => 3]);
    $key = $this->connection->webChatWidget->public_key;

    foreach (range(1, 4) as $address) {
        $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$address}"]);

        foreach (range(1, 2) as $attempt) {
            $this->postJson(route('api.v1.widget.sessions.store', $key), ['ticket' => 'basura'])->assertForbidden();
        }
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77']);

    WebChatVisitor::start($this, $this->connection)->assertCreated();
});

test('every address of one IPv6 /64 network shares the per-address limit', function () {
    config(['webchat.limits.sessions_per_ip_per_minute' => 2]);

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2::a']);
    WebChatVisitor::start($this, $this->connection)->assertCreated();
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2:ffff::b']);
    WebChatVisitor::start($this, $this->connection)->assertCreated();

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2:1234:5678:9abc:def0']);
    WebChatVisitor::start($this, $this->connection)->assertStatus(429);

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:3::a']);
    WebChatVisitor::start($this, $this->connection)->assertCreated();
});

test('the shared per-connection message ceiling is high by default and can be disabled', function () {
    expect(config('webchat.limits.messages_per_connection_per_minute'))->toBeGreaterThanOrEqual(6000);

    config(['webchat.limits.messages_per_connection_per_minute' => 2]);
    $tokens = collect(range(1, 3))->map(function (int $visitor): string {
        $this->withServerVariables(['REMOTE_ADDR' => "198.51.100.{$visitor}"]);

        return WebChatVisitor::token($this, $this->connection);
    });

    WebChatVisitor::send($this, $tokens[0])->assertCreated();
    WebChatVisitor::send($this, $tokens[1])->assertCreated();
    WebChatVisitor::send($this, $tokens[2])->assertStatus(429);

    config(['webchat.limits.messages_per_connection_per_minute' => 0]);

    WebChatVisitor::send($this, $tokens[2])->assertCreated();
});

test('a session writing too fast is limited', function () {
    config(['webchat.limits.messages_per_session_per_minute' => 2]);
    $token = WebChatVisitor::token($this, $this->connection);

    WebChatVisitor::send($this, $token)->assertCreated();
    WebChatVisitor::send($this, $token)->assertCreated();

    WebChatVisitor::send($this, $token)
        ->assertStatus(429)
        ->assertJson(['code' => 'rate_limited'])
        ->assertHeader('Retry-After');

    $this->travel(61)->seconds();
    WebChatVisitor::send($this, $token)->assertCreated();
});
