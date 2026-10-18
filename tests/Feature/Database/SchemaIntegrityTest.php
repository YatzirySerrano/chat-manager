<?php

use App\Enums\ConversationStatus;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Department;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappAccount;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('every migration is applied and can be rolled back and applied again', function () {
    $migrator = app('migrator');
    $migrationNames = array_keys($migrator->getMigrationFiles(database_path('migrations')));

    expect(app('migration.repository')->getRan())->toEqualCanonicalizing($migrationNames);

    $this->artisan('migrate:reset', ['--force' => true])->assertSuccessful();
    expect(Schema::hasTable('users'))->toBeFalse()
        ->and(Schema::hasTable('conversations'))->toBeFalse();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    expect(app('migration.repository')->getRan())->toEqualCanonicalizing($migrationNames);
});

test('json columns reject invalid documents', function (string $table, array $row, string $column) {
    expect(fn () => DB::table($table)->insert([...$row, $column => '{not json', 'created_at' => now()]))
        ->toThrow(QueryException::class);
})->with([
    'settings' => ['settings', ['key' => 'general'], 'value'],
    'audit logs' => ['audit_logs', ['action' => 'user.created', 'actor_name' => 'Sistema'], 'properties'],
]);

test('foreign keys reject rows that point to missing records', function (string $table, Closure $row) {
    expect(fn () => DB::table($table)->insert($row()))->toThrow(QueryException::class, 'foreign key constraint fails');
})->with([
    'membership without department' => ['department_user', fn () => ['department_id' => 999_999, 'user_id' => User::factory()->create()->id]],
    'message without conversation' => ['messages', fn () => ['conversation_id' => 999_999, 'direction' => 'inbound', 'type' => 'text', 'status' => 'received']],
    'conversation without contact' => ['conversations', fn () => ['whatsapp_account_id' => WhatsappAccount::factory()->create()->id, 'contact_id' => 999_999]],
]);

test('conversation history cannot be deleted in cascade', function () {
    $message = Message::factory()->create();
    $conversation = $message->conversation;

    expect(fn () => $conversation->delete())->toThrow(QueryException::class, 'foreign key constraint fails');

    $event = ConversationEvent::query()->create([
        'conversation_id' => Conversation::factory()->create()->id,
        'type' => 'unassigned',
        'created_at' => now(),
    ]);

    expect(fn () => $event->conversation->delete())->toThrow(QueryException::class, 'foreign key constraint fails');

    $this->assertModelExists($message);
    $this->assertModelExists($event);
});

test('contacts and WhatsApp accounts with conversations cannot be deleted', function () {
    $conversation = Conversation::factory()->create();

    expect(fn () => $conversation->contact->delete())->toThrow(QueryException::class)
        ->and(fn () => $conversation->whatsappAccount->delete())->toThrow(QueryException::class);
});

test('removing a user keeps the audit trail and drops only their memberships', function () {
    $department = Department::factory()->create();
    $user = User::factory()->agent()->inDepartments($department)->create(['name' => 'Ana Pérez']);
    $log = AuditLog::query()->create(['user_id' => $user->id, 'actor_name' => $user->name, 'action' => 'user.updated', 'created_at' => now()]);

    $user->delete();

    expect($log->refresh())
        ->user_id->toBeNull()
        ->actor_name->toBe('Ana Pérez')
        ->and(DB::table('department_user')->where('user_id', $user->id)->exists())->toBeFalse();
    $this->assertModelExists($department);
});

test('the database derives the open conversation guard from the status', function () {
    $conversation = Conversation::factory()->create();
    expect($conversation->refresh()->open_lock)->toBeTrue();

    $conversation->forceFill(['status' => ConversationStatus::Closed])->save();
    expect($conversation->refresh()->open_lock)->toBeNull();

    expect(fn () => DB::table('conversations')->whereKey($conversation->id)->update(['open_lock' => true]))
        ->toThrow(QueryException::class);
});

test('a closed conversation cannot be reopened while another one is open', function () {
    $account = WhatsappAccount::factory()->create();
    $contact = Contact::factory()->create();
    $closed = Conversation::factory()->for($account)->for($contact)->closed()->create();
    Conversation::factory()->for($account)->for($contact)->create();

    expect(fn () => $closed->forceFill(['status' => ConversationStatus::Open])->save())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('duplicates are rejected by unique indexes', function (Closure $insertDuplicate) {
    expect($insertDuplicate)->toThrow(UniqueConstraintViolationException::class);
})->with([
    'outbound message submitted twice' => [function () {
        $message = Message::factory()->create(['client_reference' => (string) str()->uuid()]);
        Message::factory()->create(['client_reference' => $message->client_reference]);
    }],
    'contact with the same WhatsApp id' => [function () {
        $contact = Contact::factory()->create();
        Contact::factory()->create(['wa_id' => $contact->wa_id]);
    }],
    'WhatsApp number registered twice' => [function () {
        $account = WhatsappAccount::factory()->create();
        WhatsappAccount::factory()->create(['phone_number_id' => $account->phone_number_id]);
    }],
    'department membership stored twice' => [function () {
        $department = Department::factory()->create();
        $agent = User::factory()->agent()->inDepartments($department)->create();
        DB::table('department_user')->insert(['department_id' => $department->id, 'user_id' => $agent->id]);
    }],
    'department with the same slug' => [function () {
        $department = Department::factory()->create();
        Department::factory()->create(['slug' => $department->slug]);
    }],
]);
