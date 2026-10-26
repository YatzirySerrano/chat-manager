<?php

use App\Enums\HistoryImportRowState;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\HistoryImportConversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/HistoryImportSupport.php';

/*
| Classification of the inventory: per conversation, in bulk (selection or
| filter), with reviewable rules; everything can change until the explicit
| confirmation, which requires every conversation decided, the connections
| mapped and the summary the admin reviewed.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->superAdministrator()->create();
    $this->batch = historyStandardBatch($this->admin);
});

test('a decision can change any number of times before the confirmation, never after', function () {
    $row = historyRow($this->batch, 'ticket:1002');
    $update = fn (?string $decision) => $this->actingAs($this->admin)->patchJson(route('admin.history-import.inventory.update', [$this->batch, $row]), ['decision' => $decision]);

    expect($update('import')->assertOk()->json('conversation.decision'))->toBe('import')
        ->and($update('archive')->assertOk()->json('conversation.decision'))->toBe('archive')
        ->and($update(null)->assertOk()->json('conversation.decision'))->toBeNull()
        ->and($update('exclude')->assertOk()->json('conversation.decision_source'))->toBe('manual');

    historyMapPlaceholders($this->admin, $this->batch);
    historyDecide($this->admin, $this->batch, 'exclude');
    historyConfirm($this->admin, $this->batch);

    $update('import')->assertStatus(409)->assertJson(['code' => 'batch_locked']);
    expect($row->refresh()->decision->value)->toBe('exclude');
});

test('a destination a conversation does not allow is refused, and bulk actions skip it', function () {
    // 1003 has no messages (only "No importar"); 1005 is a group chat (no operational import).
    $this->actingAs($this->admin)->patchJson(route('admin.history-import.inventory.update', [$this->batch, historyRow($this->batch, 'ticket:1003')]), ['decision' => 'archive'])
        ->assertUnprocessable()->assertJsonValidationErrors('decision');
    $this->actingAs($this->admin)->patchJson(route('admin.history-import.inventory.update', [$this->batch, historyRow($this->batch, 'ticket:1005')]), ['decision' => 'import'])
        ->assertUnprocessable();

    $result = historyDecide($this->admin, $this->batch, 'import');

    expect($result)->toBe(['matched' => 5, 'updated' => 3, 'not_allowed' => 2])
        ->and(historyRow($this->batch, 'ticket:1003')->decision)->toBeNull()
        ->and(historyRow($this->batch, 'ticket:1005')->decision)->toBeNull();
});

test('bulk classification works on a selection and on a filter', function () {
    $ids = [historyRow($this->batch, 'ticket:1001')->id, historyRow($this->batch, 'ticket:1002')->id];

    $selection = $this->actingAs($this->admin)->postJson(route('admin.history-import.inventory.bulk', $this->batch), ['decision' => 'archive', 'ids' => $ids])->assertOk();
    expect($selection->json('result.updated'))->toBe(2);

    $byDepartment = historyDecide($this->admin, $this->batch, 'import', ['department' => 'Soporte']);
    expect($byDepartment['matched'])->toBe(2)
        ->and(historyRow($this->batch, 'ticket:1002')->decision->value)->toBe('import')
        ->and(historyRow($this->batch, 'ticket:1004')->decision->value)->toBe('import')
        ->and(historyRow($this->batch, 'ticket:1001')->decision->value)->toBe('archive');

    $undecided = $this->actingAs($this->admin)->postJson(route('admin.history-import.inventory.bulk', $this->batch), ['decision' => 'undecided', 'ids' => $ids])->assertOk();
    expect($undecided->json('result.updated'))->toBe(2)
        ->and(historyRow($this->batch, 'ticket:1001')->decision)->toBeNull();

    // A selection never reaches rows of another batch.
    $other = historyBatch($this->admin);
    $this->actingAs($this->admin)->postJson(route('admin.history-import.inventory.bulk', $other), ['decision' => 'exclude', 'ids' => $ids])
        ->assertOk()->assertJsonPath('result.matched', 0);
});

test('rules are previewed with counts, applied without overwriting manual decisions and removable', function () {
    $this->actingAs($this->admin)->patchJson(route('admin.history-import.inventory.update', [$this->batch, historyRow($this->batch, 'ticket:1001')]), ['decision' => 'import'])->assertOk();

    $rule = ['name' => 'Archivar antes de marzo', 'criteria' => ['date_to' => '2026-02-28'], 'decision' => 'archive'];
    $preview = $this->actingAs($this->admin)->postJson(route('admin.history-import.rules.preview', $this->batch), $rule)->assertOk()->json('preview');

    // 1003 (January, empty: not allowed), 1004 (February), 1005 (February, group).
    expect($preview)->toBe(['matched' => 3, 'would_change' => 2, 'already_decided' => 0, 'not_allowed' => 1])
        ->and(HistoryImportConversation::query()->where('data_transfer_id', $this->batch->id)->whereNotNull('decision')->count())->toBe(1);

    $applied = $this->actingAs($this->admin)->postJson(route('admin.history-import.rules.store', $this->batch), $rule)->assertCreated();
    expect($applied->json('rule.applied'))->toBe(2)
        ->and(historyRow($this->batch, 'ticket:1004')->decision->value)->toBe('archive')
        ->and(historyRow($this->batch, 'ticket:1004')->decision_source)->toBe('rule');

    $excludeAll = ['name' => 'El resto no se importa', 'criteria' => (object) [], 'decision' => 'exclude'];
    $this->actingAs($this->admin)->postJson(route('admin.history-import.rules.store', $this->batch), $excludeAll)->assertCreated()->assertJsonPath('rule.applied', 2);
    expect(historyRow($this->batch, 'ticket:1001')->decision->value)->toBe('import');

    $ruleId = $applied->json('rule.id');
    $this->actingAs($this->admin)->deleteJson(route('admin.history-import.rules.destroy', [$this->batch, $ruleId]))->assertOk()->assertJsonPath('reverted', 2);
    expect(historyRow($this->batch, 'ticket:1004')->decision)->toBeNull();
});

test('the execution needs every conversation decided, connections mapped and the reviewed summary', function () {
    $summary = fn () => $this->actingAs($this->admin)->getJson(route('admin.history-import.summary', $this->batch))->assertOk()->json('summary');

    expect(collect($summary()['blockers'])->pluck('code')->all())->toBe(['undecided']);

    historyDecide($this->admin, $this->batch, 'exclude');
    expect(collect($summary()['blockers'])->pluck('code')->all())->toBe([]);

    historyDecide($this->admin, $this->batch, 'archive', ['search' => '5500000002']);
    $current = $summary();
    expect($current['ready'])->toBeFalse()
        ->and($current['unmapped_connections'])->toBe(['Linea Prueba']);

    $this->actingAs($this->admin)->postJson(route('admin.history-import.execute', $this->batch), ['confirmation_token' => $current['confirmation_token'], 'confirm' => true])
        ->assertUnprocessable();

    historyMapPlaceholders($this->admin, $this->batch);
    $stale = $current['confirmation_token'];

    $this->actingAs($this->admin)->postJson(route('admin.history-import.execute', $this->batch), ['confirmation_token' => $stale, 'confirm' => true])
        ->assertStatus(409)->assertJson(['code' => 'summary_changed']);

    $this->actingAs($this->admin)->postJson(route('admin.history-import.execute', $this->batch), ['confirmation_token' => $summary()['confirmation_token']])
        ->assertUnprocessable()->assertJsonValidationErrors('confirm');

    expect(Conversation::query()->imported()->count())->toBe(0);
});

test('a batch can mix the three destinations and the numbers before and after agree', function () {
    historyMapPlaceholders($this->admin, $this->batch);
    $decide = fn (string $key, string $decision) => $this->actingAs($this->admin)
        ->patchJson(route('admin.history-import.inventory.update', [$this->batch, historyRow($this->batch, $key)]), ['decision' => $decision])->assertOk();

    $decide('ticket:1001', 'import');
    $decide('ticket:1002', 'archive');
    $decide('ticket:1004', 'import');
    $decide('ticket:1005', 'archive');
    $decide('ticket:1003', 'exclude');

    $contactsBefore = Contact::query()->count();
    $summary = historyConfirm($this->admin, $this->batch);

    expect($summary['totals'])->toBe(['conversations' => 5, 'import' => 2, 'archive' => 2, 'exclude' => 1, 'undecided' => 0])
        ->and($summary['messages'])->toBe(['import' => 7, 'archive' => 5, 'total' => 12])
        ->and($summary['attachments'])->toBe(['recoverable' => 1, 'missing' => 1, 'estimated_bytes' => strlen(historyPng())])
        ->and($summary['groups'])->toBe(1);

    $result = $this->batch->refresh()->summary;
    $imported = Conversation::query()->where('imported_by_transfer_id', $this->batch->id);

    expect($result['imported'])->toBe($summary['totals']['import'])
        ->and($result['archived'])->toBe($summary['totals']['archive'])
        ->and($result['excluded'])->toBe($summary['totals']['exclude'])
        ->and($result['messages'])->toBe($summary['messages']['total'])
        ->and(Message::query()->whereIn('conversation_id', (clone $imported)->select('id'))->count())->toBe(12)
        ->and($result['attachments_available'])->toBe(1)
        ->and($result['attachments_missing'])->toBe(1)
        ->and((clone $imported)->whereNotNull('history_only_at')->count())->toBe(2)
        ->and(Contact::query()->count() - $contactsBefore)->toBe($summary['contacts']['new'])
        ->and(HistoryImportConversation::query()->where('data_transfer_id', $this->batch->id)->where('state', HistoryImportRowState::Done)->count())->toBe(4);
});

test('a Chat.txt needs the admin to say which participant is the company', function () {
    $batch = historyBatch($this->admin);
    historyAddFile($this->admin, $batch, 'media', historyZipUpload([
        'Chat de WhatsApp con Cliente Ficticio Cinco.txt' => (string) file_get_contents(historyFixture('chat-android.txt')),
        'IMG-20260401-WA0001.jpg' => historyPng(),
    ], 'WhatsApp Chat - Cliente Ficticio Cinco.zip'));

    $row = HistoryImportConversation::query()->where('data_transfer_id', $batch->id)->sole();
    expect($row->source_kind)->toBe('chat_txt')
        ->and($row->participants)->toEqualCanonicalizing(['Cliente Ficticio Cinco', 'Empresa Ficticia'])
        ->and($row->message_count)->toBe(5)
        ->and($row->attachments_total)->toBe(2)
        ->and($row->attachments_available)->toBe(1)
        ->and(collect($row->issues)->pluck('code')->all())->toContain('participants_unidentified');

    $updated = $this->actingAs($this->admin)->patchJson(route('admin.history-import.inventory.update', [$batch, $row]), [
        'business_participants' => ['Empresa Ficticia'],
        'decision' => 'import',
    ])->assertOk()->json('conversation');

    expect($updated['messages'])->toBe(['total' => 5, 'inbound' => 3, 'outbound' => 2])
        ->and($updated['contact']['name'])->toBe('Cliente Ficticio Cinco')
        ->and(collect($updated['issues'])->pluck('code')->all())->not->toContain('participants_unidentified');

    $this->actingAs($this->admin)->patchJson(route('admin.history-import.inventory.update', [$batch, $row]), ['business_participants' => ['Alguien que no participa']])
        ->assertUnprocessable();
});
