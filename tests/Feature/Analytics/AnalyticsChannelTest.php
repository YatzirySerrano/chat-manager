<?php

use App\Actions\Analytics\RebuildConversationMetrics;
use App\Models\Connection;
use App\Models\Conversation;
use App\Models\ConversationMetric;
use App\Models\Department;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/*
| Figures per channel: derived from the per connection breakdown, so the
| channels, the connections and the summary always agree.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00', 'America/Mexico_City')->utc());
    $this->sales = Department::factory()->create(['name' => 'Ventas']);
    $this->support = Department::factory()->create(['name' => 'Soporte']);
});

function channelConversations(Connection $connection, Department $department, int $count): void
{
    Conversation::factory()->for($connection, 'channelConnection')->inDepartment($department)->count($count)->create([
        'created_at' => CarbonImmutable::now()->subDay(),
        'updated_at' => CarbonImmutable::now()->subDay(),
    ]);
}

test('channels add up the connections, keep deleted connections and skip empty channels', function () {
    $whatsapp = Connection::factory()->operational()->create();
    $retired = Connection::factory()->operational()->create(['deleted_at' => now(), 'is_active' => false]);
    $web = Connection::factory()->webChat()->create();
    Connection::factory()->messenger()->create();
    channelConversations($whatsapp, $this->sales, 2);
    channelConversations($retired, $this->sales, 1);
    channelConversations($web, $this->support, 4);
    app(RebuildConversationMetrics::class)->handle();
    ConversationMetric::query()->where('connection_id', $web->id)->limit(1)->update(['first_assigned_at' => now()]);

    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('reports.index', ['period' => '7d']))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('analytics.by_channel', [
                ['channel' => 'web_chat', 'label' => 'Chat web', 'received' => 4, 'attended' => 1],
                ['channel' => 'whatsapp', 'label' => 'WhatsApp', 'received' => 3, 'attended' => 0],
            ])
            ->where('analytics.by_connection', fn ($rows) => collect($rows)->sum('received') === 7)
            ->where('analytics.summary.received.value', 7)));
});

test('channels only count the viewer reach', function () {
    $whatsapp = Connection::factory()->operational()->create();
    $web = Connection::factory()->webChat()->create();
    channelConversations($whatsapp, $this->sales, 1);
    channelConversations($web, $this->support, 2);
    app(RebuildConversationMetrics::class)->handle();

    $this->actingAs(User::factory()->supervisor()->inDepartments($this->sales)->create())
        ->get(route('reports.index', ['period' => '7d']))
        ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload
            ->where('analytics.by_channel', [['channel' => 'whatsapp', 'label' => 'WhatsApp', 'received' => 1, 'attended' => 0]])));
});
