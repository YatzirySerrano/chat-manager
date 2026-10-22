<?php

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationNote;
use App\Models\Department;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

/*
| Defects fixed by the security audit (docs/seguridad-y-auditoria.md) and the
| invariants they protect.
*/

beforeEach(function () {
    $this->department = Department::factory()->create();
    $this->owner = User::factory()->agent()->inDepartments($this->department)->create();
    $this->outsider = User::factory()->agent()->inDepartments(Department::factory()->create())->create();
    $this->conversation = Conversation::factory()->inDepartment($this->department)->assignedTo($this->owner)->create();
});

test('an out of reach conversation answers 404 before its input is validated', function (string $method, Closure $uri) {
    $hidden = $this->actingAs($this->outsider)->json($method, $uri($this->conversation), []);
    $missing = $this->actingAs($this->outsider)->json($method, $uri(Conversation::factory()->make(['id' => 999999])), []);

    $hidden->assertNotFound();
    expect($hidden->json())->toBe($missing->json());
})->with([
    'hold' => ['POST', fn (Conversation $c) => "/conversations/{$c->id}/hold"],
    'resume' => ['POST', fn (Conversation $c) => "/conversations/{$c->id}/resume"],
    'close' => ['POST', fn (Conversation $c) => "/conversations/{$c->id}/close"],
    'reopen' => ['POST', fn (Conversation $c) => "/conversations/{$c->id}/reopen"],
    'release' => ['POST', fn (Conversation $c) => "/conversations/{$c->id}/release"],
    'assign' => ['POST', fn (Conversation $c) => "/conversations/{$c->id}/assign"],
    'transfer' => ['POST', fn (Conversation $c) => "/conversations/{$c->id}/transfer"],
    'new note' => ['POST', fn (Conversation $c) => "/conversations/{$c->id}/notes"],
    'reply' => ['POST', fn (Conversation $c) => "/conversations/{$c->id}/messages"],
]);

test('editing a note of an out of reach conversation answers 404 before validation', function () {
    $note = ConversationNote::factory()->create(['conversation_id' => $this->conversation->id, 'user_id' => $this->owner->id]);

    $this->actingAs($this->outsider)
        ->putJson(route('conversations.notes.update', [$this->conversation, $note]), [])
        ->assertNotFound();
});

test('the people who can see the conversation still get validation errors', function () {
    $this->actingAs($this->owner)
        ->postJson(route('conversations.hold', $this->conversation), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('version');
});

test('changing the status of a connection without permission is refused before validation', function () {
    $connection = Connection::factory()->create();

    $this->actingAs($this->outsider)
        ->patchJson(route('connections.status.update', $connection), [])
        ->assertForbidden();

    $this->actingAs(User::factory()->administrator()->create())
        ->patchJson(route('connections.status.update', $connection), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('is_active');
});

test('the appearance cookie is never echoed into the inline script unless it is a known value', function (string $cookie, string $expected) {
    $html = $this->actingAs($this->owner)
        ->withUnencryptedCookie('appearance', $cookie)
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain("const appearance = '{$expected}';")
        ->not->toContain('alert(1)');
})->with([
    'dark' => ['dark', 'dark'],
    'light' => ['light', 'light'],
    'script attempt' => ["\\';alert(1);//", 'system'],
    'markup attempt' => ['</script><script>alert(1)</script>', 'system'],
]);

test('private disks are never served through a signed storage route', function () {
    expect(Route::has('storage.local'))->toBeFalse()
        ->and(Route::has('storage.local.upload'))->toBeFalse()
        ->and(config('filesystems.disks.local.serve'))->toBeFalse();
});

test('connection secrets never reach any page, shared prop or JSON answer', function () {
    $root = User::factory()->superAdministrator()->create();
    $connection = Connection::factory()->operational()->create();
    $secrets = [(string) $connection->access_token, (string) $connection->app_secret, (string) $connection->webhook_verify_token];

    $pages = [
        $this->actingAs($root)->get(route('connections.index')),
        $this->actingAs($root)->get(route('connections.show', $connection)),
        $this->actingAs($root)->get(route('templates.index')),
        $this->actingAs($root)->get(route('admin.system.index')),
        $this->actingAs($root)->getJson(route('admin.system.health')),
        $this->actingAs($root)->get(route('dashboard')),
    ];

    foreach ($pages as $page) {
        $page->assertOk();

        foreach ($secrets as $secret) {
            expect($page->getContent())->not->toContain($secret);
        }
    }
});

test('changing one\'s own password is audited without storing it', function () {
    $this->actingAs($this->owner)
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasNoErrors();

    $entry = AuditLog::query()->where('action', AuditAction::AuthPasswordChanged)->sole();

    expect($entry->user_id)->toBe($this->owner->id)
        ->and($entry->auditable_id)->toBe($this->owner->id)
        ->and(json_encode($entry->properties))->not->toContain('new-password');
});

test('account security events of Fortify are audited', function (Closure $event, AuditAction $action) {
    event($event($this->owner));

    $entry = AuditLog::query()->where('action', $action)->sole();

    expect($entry->user_id)->toBe($this->owner->id)
        ->and($entry->auditable_id)->toBe($this->owner->id)
        ->and($entry->properties)->toBeNull();
})->with([
    'password reset by link' => [fn (User $user) => new PasswordReset($user), AuditAction::AuthPasswordResetByLink],
    'two factor confirmed' => [fn (User $user) => new TwoFactorAuthenticationConfirmed($user), AuditAction::AuthTwoFactorEnabled],
    'two factor disabled' => [fn (User $user) => new TwoFactorAuthenticationDisabled($user), AuditAction::AuthTwoFactorDisabled],
    'recovery codes regenerated' => [fn (User $user) => new RecoveryCodesGenerated($user), AuditAction::AuthRecoveryCodesRegenerated],
]);

test('a sign-in lockout is audited once per e-mail and minute, without the password', function () {
    foreach (range(1, 8) as $attempt) {
        $this->post(route('login.store'), ['email' => $this->owner->email, 'password' => 'wrong-password-'.$attempt]);
    }

    $entry = AuditLog::query()->where('action', AuditAction::AuthLockout)->sole();

    expect($entry->auditable_id)->toBe($this->owner->id)
        ->and($entry->properties)->toBe(['email' => $this->owner->email])
        ->and($entry->user_id)->toBeNull();
});

test('the forgot password form does not reveal which e-mails have an account', function () {
    Notification::fake();

    $known = $this->from(route('password.request'))->post(route('password.email'), ['email' => $this->owner->email]);
    $again = $this->from(route('password.request'))->post(route('password.email'), ['email' => $this->owner->email]);
    $unknown = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nadie@example.com']);

    foreach ([$known, $again, $unknown] as $response) {
        $response->assertSessionHasNoErrors()
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', __('passwords.sent'));
    }

    Notification::assertSentToTimes($this->owner, ResetPassword::class, 1);
});
