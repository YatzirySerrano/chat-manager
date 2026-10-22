<?php

use App\Actions\Messaging\ProcessWebhookPayload;
use App\Enums\AuditAction;
use App\Enums\DataScope;
use App\Enums\MessageTemplateStatus;
use App\Enums\Permission;
use App\Jobs\SyncConnectionTemplates;
use App\Models\AuditLog;
use App\Models\Connection;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Templates\TemplateFixtures;
use Tests\Support\WhatsAppFixtures;

/*
| Templates are copied from Meta (every page), never invented: statuses,
| categories and components are exactly what Meta reports.
*/

const TEMPLATES_TOKEN = 'EAAGtemplatesTestToken00000000000000000000000000000000';

beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.meta.graph_version' => 'v26.0']);
    $this->connection = Connection::factory()->verified()->create(['business_account_id' => '102290129340398', 'access_token' => TEMPLATES_TOKEN]);
    $this->admin = User::factory()->superAdministrator()->create();
});

test('a manual sync copies every page of templates from Meta and is audited', function () {
    Http::fake([
        'graph.facebook.com/v26.0/102290129340398/message_templates*' => Http::sequence()
            ->push(['data' => [TemplateFixtures::orderUpdate('1', 'APPROVED')], 'paging' => ['cursors' => ['after' => 'CURSOR1'], 'next' => 'https://graph.facebook.com/next']])
            ->push(['data' => [['id' => '2', 'name' => 'promo', 'language' => 'es', 'status' => 'REJECTED', 'category' => 'MARKETING', 'rejected_reason' => 'PROMOTIONAL', 'components' => [['type' => 'BODY', 'text' => 'Oferta']]]], 'paging' => ['cursors' => ['after' => 'CURSOR2']]]),
    ]);

    $this->actingAs($this->admin)
        ->post(route('templates.sync', $this->connection))
        ->assertRedirect()
        ->assertInertiaFlash('toast.type', 'success');

    expect(MessageTemplate::query()->orderBy('meta_template_id')->get()->map->only(['meta_template_id', 'name', 'status', 'category', 'quality_score', 'rejected_reason'])->all())->toBe([
        ['meta_template_id' => '1', 'name' => 'order_update', 'status' => MessageTemplateStatus::Approved, 'category' => 'UTILITY', 'quality_score' => 'GREEN', 'rejected_reason' => null],
        ['meta_template_id' => '2', 'name' => 'promo', 'status' => MessageTemplateStatus::Rejected, 'category' => 'MARKETING', 'quality_score' => null, 'rejected_reason' => 'PROMOTIONAL'],
    ]);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'after=CURSOR1')
        && $request->hasHeader('Authorization', 'Bearer '.TEMPLATES_TOKEN)
        && ! str_contains($request->url(), TEMPLATES_TOKEN));
    expect(AuditLog::query()->where('action', AuditAction::TemplatesSynchronized)->sole()->properties)->toMatchArray(['created' => 2, 'total' => 2]);
});

test('templates Meta no longer returns are logically deleted, and restored if they come back', function () {
    $kept = TemplateFixtures::stored($this->connection, ['meta_template_id' => '1']);
    $removed = TemplateFixtures::stored($this->connection, ['meta_template_id' => '9', 'name' => 'old']);
    Http::fake(['graph.facebook.com/*' => Http::sequence()
        ->push(['data' => [TemplateFixtures::orderUpdate('1', 'PAUSED')]])
        ->push(['data' => [TemplateFixtures::orderUpdate('1'), ['id' => '9', 'name' => 'old', 'language' => 'es', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Hola']]]]])]);

    $this->actingAs($this->admin)->post(route('templates.sync', $this->connection));

    expect($kept->refresh())->status->toBe(MessageTemplateStatus::Paused)->deleted_at->toBeNull()
        ->and($removed->refresh()->deleted_at)->not->toBeNull();

    $this->actingAs($this->admin)->post(route('templates.sync', $this->connection));

    expect($removed->refresh()->deleted_at)->toBeNull()
        ->and($kept->refresh()->status)->toBe(MessageTemplateStatus::Approved);
});

test('a listing cut by the page limit retires nothing', function () {
    config(['whatsapp.templates.max_pages' => 1]);
    $unseen = TemplateFixtures::stored($this->connection, ['meta_template_id' => '9', 'name' => 'second_page']);
    Http::fake(['graph.facebook.com/*' => Http::response([
        'data' => [TemplateFixtures::orderUpdate('1')],
        'paging' => ['cursors' => ['after' => 'CURSOR1'], 'next' => 'https://graph.facebook.com/next'],
    ])]);

    $this->actingAs($this->admin)->post(route('templates.sync', $this->connection))->assertRedirect();

    expect($unseen->refresh()->deleted_at)->toBeNull()
        ->and(MessageTemplate::query()->where('meta_template_id', '1')->exists())->toBeTrue();
    Http::assertSentCount(1);
});

test('a failed sync changes nothing and explains the Meta error', function () {
    $template = TemplateFixtures::stored($this->connection);
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.', 'code' => 190]], 401)]);

    $this->actingAs($this->admin)
        ->post(route('templates.sync', $this->connection))
        ->assertInertiaFlash('toast.type', 'error');

    expect($template->refresh()->deleted_at)->toBeNull()
        ->and($this->connection->refresh()->credentials_error)->toContain('(código 190)');
});

test('only template managers can synchronize', function () {
    Http::fake();
    $viewer = conversationUser([Permission::ViewTemplates], DataScope::Global);

    $this->actingAs($viewer)->post(route('templates.sync', $this->connection))->assertForbidden();
    Http::assertNothingSent();
});

test('status webhooks update the synchronized copy; unknown templates trigger a sync instead of being invented', function () {
    Queue::fake();
    $template = TemplateFixtures::stored($this->connection, ['meta_template_id' => '777']);
    $process = fn (array $value) => app(ProcessWebhookPayload::class)->handle($this->connection, WhatsAppFixtures::envelope($this->connection, 'message_template_status_update', $value));

    $process(['event' => 'REJECTED', 'message_template_id' => 777, 'message_template_name' => 'order_update', 'message_template_language' => 'es_MX', 'reason' => 'INCORRECT_CATEGORY']);

    expect($template->refresh())->status->toBe(MessageTemplateStatus::Rejected)->rejected_reason->toBe('INCORRECT_CATEGORY');

    $process(['event' => 'REINSTATED', 'message_template_id' => 777, 'reason' => 'NONE']);
    expect($template->refresh()->status)->toBe(MessageTemplateStatus::Approved);

    $process(['event' => 'APPROVED', 'message_template_id' => 123456, 'message_template_name' => 'new_one']);

    expect(MessageTemplate::query()->count())->toBe(1);
    Queue::assertPushed(SyncConnectionTemplates::class, fn (SyncConnectionTemplates $job): bool => $job->connectionId === $this->connection->id);
});

test('quality updates are applied as reported', function () {
    $template = TemplateFixtures::stored($this->connection, ['meta_template_id' => '778', 'quality_score' => 'GREEN']);

    app(ProcessWebhookPayload::class)->handle($this->connection, WhatsAppFixtures::envelope($this->connection, 'message_template_quality_update', [
        'previous_quality_score' => 'GREEN', 'new_quality_score' => 'YELLOW', 'message_template_id' => 778,
    ]));

    expect($template->refresh()->quality_score)->toBe('YELLOW');
});
