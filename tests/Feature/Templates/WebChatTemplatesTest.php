<?php

use App\Jobs\SyncConnectionTemplates;
use App\Models\Connection;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\WebChatFixtures;

/*
| The web chat has no provider templates: they are never synchronized for it,
| neither by hand nor by the scheduled task.
*/

beforeEach(function () {
    Http::preventStrayRequests();
});

test('templates of a web chat connection cannot be synchronized', function () {
    Queue::fake();
    $webChat = WebChatFixtures::connection();
    $admin = User::factory()->superAdministrator()->create();

    expect($admin->can('sync', [MessageTemplate::class, $webChat]))->toBeFalse()
        ->and($admin->can('sync', [MessageTemplate::class, Connection::factory()->verified()->create()]))->toBeTrue();

    $this->actingAs($admin)->post(route('templates.sync', $webChat))->assertForbidden();

    Queue::assertNotPushed(SyncConnectionTemplates::class);
});

test('the scheduled template sync skips web chat connections', function () {
    Queue::fake();
    $whatsapp = Connection::factory()->verified()->create();
    WebChatFixtures::connection();

    $this->artisan('whatsapp:maintenance', ['task' => 'sync-templates'])->assertSuccessful();

    Queue::assertPushed(SyncConnectionTemplates::class, 1);
    Queue::assertPushed(SyncConnectionTemplates::class, fn (SyncConnectionTemplates $job): bool => $job->connectionId === $whatsapp->id);
});
