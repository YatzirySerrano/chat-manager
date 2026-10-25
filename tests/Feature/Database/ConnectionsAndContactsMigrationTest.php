<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migrations that turn WhatsApp accounts into connections and contacts into
 * people with identities, notes and tags, newest first.
 *
 * @return list<string>
 */
function connectionsAndContactsMigrations(): array
{
    return array_reverse(array_map(fn (string $name): string => "database/migrations/{$name}.php", [
        '2026_10_18_012651_rename_whatsapp_accounts_to_connections',
        '2026_10_18_012652_add_configuration_columns_to_connections_table',
        '2026_10_18_012653_create_connection_department_table',
        '2026_10_18_012655_add_profile_columns_to_contacts_table',
        '2026_10_18_012656_create_contact_identities_table',
        '2026_10_18_012657_create_contact_notes_table',
        '2026_10_18_012658_create_connection_contact_table',
        '2026_10_18_012659_create_contact_attributions_table',
        '2026_10_18_012700_create_tags_table',
        '2026_10_18_012702_create_contact_tag_table',
        '2026_10_18_012703_create_conversation_tag_table',
        '2026_10_18_012704_create_quick_replies_table',
        '2026_10_18_012705_create_department_quick_reply_table',
        '2026_10_18_012706_add_operational_columns_to_departments_table',
        // Its unique (channel, external_account_id) index must go before the channel column can be dropped.
        '2026_10_24_185556_add_meta_account_columns_to_connections_table',
    ]));
}

/**
 * @return array<string, list<array<string, mixed>>>
 */
function legacyMessagingSnapshot(): array
{
    return collect(['whatsapp_accounts', 'contacts', 'conversations', 'audit_logs'])
        ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all()])
        ->all();
}

/*
 * Schema changes commit the surrounding test transaction, so the database is
 * rebuilt before the next test.
 */
afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});

test('existing accounts, contacts and notes are carried forward and restored on rollback', function () {
    $this->artisan('migrate:rollback', ['--path' => connectionsAndContactsMigrations(), '--force' => true])->assertSuccessful();

    expect(Schema::hasTable('whatsapp_accounts'))->toBeTrue()
        ->and(Schema::hasColumns('contacts', ['wa_id', 'phone_number', 'display_name', 'notes', 'last_inbound_at']))->toBeTrue();

    $now = now()->startOfSecond();
    $accountId = DB::table('whatsapp_accounts')->insertGetId([
        'name' => 'Ventas', 'display_phone_number' => '+52 55 1111 2222', 'phone_number_id' => '1098765432',
        'business_account_id' => '2098765432', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $contact = fn (array $row): int => DB::table('contacts')->insertGetId([...$row, 'created_at' => $now, 'updated_at' => $now]);
    $ana = $contact(['wa_id' => '5215512345678', 'phone_number' => '+5215512345678', 'profile_name' => 'Ana', 'notes' => 'Cliente frecuente', 'last_inbound_at' => $now]);
    $beto = $contact(['wa_id' => '5215587654321', 'phone_number' => '(55) 8765-4321', 'profile_name' => 'Beto', 'display_name' => 'Beto (Mayoreo)']);
    $carla = $contact(['wa_id' => '447700900123', 'phone_number' => 'n/a', 'display_name' => 'Carla']);
    $closedId = DB::table('conversations')->insertGetId(['whatsapp_account_id' => $accountId, 'contact_id' => $ana, 'status' => 'closed', 'created_at' => $now->subDay(), 'updated_at' => $now]);
    DB::table('conversations')->insert(['whatsapp_account_id' => $accountId, 'contact_id' => $ana, 'status' => 'open', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('audit_logs')->insert(['action' => 'user.updated', 'actor_name' => 'Sistema', 'auditable_type' => 'whatsapp_account', 'auditable_id' => $accountId, 'created_at' => $now]);

    $before = legacyMessagingSnapshot();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect(DB::table('connections')->find($accountId))
        ->name->toBe('Ventas')
        ->phone_number_id->toBe('1098765432')
        ->channel->toBe('whatsapp')
        ->provider->toBe('meta_cloud_api')
        ->and(DB::table('conversations')->where('connection_id', $accountId)->count())->toBe(2)
        ->and(DB::table('audit_logs')->value('auditable_type'))->toBe('connection');

    $contacts = DB::table('contacts')->orderBy('id')->get()->keyBy('id');

    expect($contacts[$ana])->name->toBe('Ana')->phone_e164->toBe('+525512345678')->source->toBe('whatsapp')->custom_fields->toBeNull()
        ->and($contacts[$ana]->first_interaction_at)->toBe($now->subDay()->toDateTimeString())
        ->and($contacts[$beto])->name->toBe('Beto (Mayoreo)')->phone_e164->toBe('+525587654321')
        ->and(json_decode($contacts[$beto]->custom_fields, true))->toBe(['Teléfono original' => '(55) 8765-4321'])
        ->and($contacts[$carla])->name->toBe('Carla')->phone_e164->toBe('+447700900123')
        ->and(DB::table('contact_identities')->orderBy('contact_id')->pluck('external_id', 'contact_id')->all())
        ->toBe([$ana => '5215512345678', $beto => '5215587654321', $carla => '447700900123'])
        ->and(DB::table('contact_notes')->get(['contact_id', 'body', 'author_user_id'])->map(fn (object $note): array => (array) $note)->all())
        ->toBe([['contact_id' => $ana, 'body' => 'Cliente frecuente', 'author_user_id' => null]])
        ->and(DB::table('connection_contact')->where(['connection_id' => $accountId, 'contact_id' => $ana])->exists())->toBeTrue();

    expect(fn () => DB::table('conversations')->insert(['connection_id' => $accountId, 'contact_id' => $ana, 'status' => 'pending']))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => DB::table('conversations')->insert(['connection_id' => 999_999, 'contact_id' => $beto]))
        ->toThrow(QueryException::class, 'foreign key constraint fails');

    expect(DB::table('conversations')->orderBy('id')->pluck('open_lock', 'id')->all())->toBe([$closedId => null, $closedId + 1 => 1]);

    $this->artisan('migrate:rollback', ['--path' => connectionsAndContactsMigrations(), '--force' => true])->assertSuccessful();

    expect(legacyMessagingSnapshot())->toEqual($before);

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect(DB::table('contact_identities')->count())->toBe(3)
        ->and(DB::table('contact_notes')->count())->toBe(1);
});

test('rolling back keeps contacts and connections created after the migration', function () {
    $connectionId = DB::table('connections')->insertGetId(['name' => 'Borrador', 'created_at' => now(), 'updated_at' => now()]);
    $contactId = DB::table('contacts')->insertGetId(['name' => 'Manual', 'phone_e164' => '+525512345678', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('contact_notes')->insert([
        ['contact_id' => $contactId, 'body' => 'Primera', 'created_at' => now()->subMinute(), 'updated_at' => now()],
        ['contact_id' => $contactId, 'body' => 'Segunda', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->artisan('migrate:rollback', ['--path' => connectionsAndContactsMigrations(), '--force' => true])->assertSuccessful();

    expect(DB::table('whatsapp_accounts')->find($connectionId))->phone_number_id->toBe("pending-{$connectionId}")
        ->and(DB::table('contacts')->find($contactId))
        ->wa_id->toBe("manual-{$contactId}")
        ->phone_number->toBe('+525512345678')
        ->display_name->toBe('Manual')
        ->notes->toBe("Primera\n\nSegunda");

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
});
