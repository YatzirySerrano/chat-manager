<?php

use App\Models\Conversation;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/HistoryImportSupport.php';

/*
| Archived history: read-only, only in its own admin screen, searchable by
| contact, number, dates, department, agent and tag, with its recoverable
| files, clearly marked as coming from Whaticket.
*/

beforeEach(function () {
    // The Vue pages are built by W5b from CONTRACT-history-import.md.
    config(['inertia.testing.ensure_pages_exist' => false]);
    Storage::fake('local');
    $this->admin = User::factory()->superAdministrator()->create();
    $this->batch = historyStandardBatch($this->admin);
    historyMapPlaceholders($this->admin, $this->batch);
    historyDecide($this->admin, $this->batch, 'exclude');
    $this->actingAs($this->admin)->patchJson(route('admin.history-import.inventory.update', [$this->batch, historyRow($this->batch, 'ticket:1001')]), ['decision' => 'archive'])->assertOk();
    historyConfirm($this->admin, $this->batch);
    $this->archived = Conversation::query()->imported()->sole();
});

test('archiving one conversation keeps it read-only and only in the archived-history screen', function () {
    expect($this->archived->isHistoryOnly())->toBeTrue()
        ->and($this->archived->isImported())->toBeTrue();

    $this->actingAs($this->admin)->get(route('admin.history.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/history/Index')
            ->where('conversations.meta.total', 1)
            ->where('conversations.data.0.id', $this->archived->id)
            ->where('conversations.data.0.origin_label', 'Importada de Whaticket')
            ->where('conversations.data.0.destination', 'archive')
            ->where('conversations.data.0.read_only', true)
            ->where('conversations.data.0.source.ticket_id', '1001'));

    $page = $this->actingAs($this->admin)->get(route('admin.history.conversations.show', $this->archived))->assertOk();
    $page->assertInertia(fn (Assert $page) => $page
        ->component('admin/history/Show')
        ->where('conversation.contact.phone', '+525500000001')
        ->has('timeline.messages', 5)
        ->where('timeline.messages.0.imported.source', 'whaticket')
        ->where('timeline.messages.1.imported.from_me', true)
        ->where('timeline.messages.0.direction', 'inbound'));
});

test('the archive is searchable by number, name, dates, department and agent', function () {
    $search = fn (array $filters) => $this->actingAs($this->admin)->getJson(route('admin.history.conversations.index', $filters))->assertOk()->json('meta.total');

    expect($search(['search' => '55 0000 0001']))->toBe(1)
        ->and($search(['search' => '5599']))->toBe(0)
        ->and($search(['date_from' => '2026-03-01', 'date_to' => '2026-03-01']))->toBe(1)
        ->and($search(['date_from' => '2026-04-01']))->toBe(0)
        ->and($search(['destination' => 'import']))->toBe(0)
        ->and($search(['destination' => 'all', 'batch_id' => $this->batch->id]))->toBe(1)
        ->and($search(['contact_id' => $this->archived->contact_id]))->toBe(1);

    $this->actingAs($this->admin)->getJson(route('admin.history.contacts.show', $this->archived->contact_id))->assertOk()->assertJsonPath('meta.total', 1);
});

test('recoverable files of archived history can be opened from the archive', function () {
    $attachment = MessageAttachment::query()->whereIn('message_id', $this->archived->messages()->select('id'))->where('status', 'available')->sole();

    $response = $this->actingAs($this->admin)->get(route('admin.history.conversations.attachments.show', [$this->archived, $attachment]))->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox');

    $missing = MessageAttachment::query()->whereIn('message_id', $this->archived->messages()->select('id'))->where('status', 'unavailable')->sole();
    $this->actingAs($this->admin)->get(route('admin.history.conversations.attachments.show', [$this->archived, $missing]))->assertNotFound();

    $other = Conversation::factory()->create();
    $this->actingAs($this->admin)->get(route('admin.history.conversations.attachments.show', [$other, $attachment]))->assertNotFound();
});
