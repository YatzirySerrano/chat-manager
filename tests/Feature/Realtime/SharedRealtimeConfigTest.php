<?php

use App\Models\Department;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Realtime\RealtimeTestSupport;

test('real-time is reported as disabled when broadcasting is not reverb', function () {
    $this->actingAs(User::factory()->administrator()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('realtime.enabled', false)
            ->where('realtime.key', null)
            ->where('realtime.inbox_channels', []));
});

test('the client configuration carries public values and the channels of the user scope', function () {
    RealtimeTestSupport::useReverb();
    config(['broadcasting.realtime.client' => ['host' => 'chat.example.test', 'port' => 443, 'scheme' => 'https', 'path' => '']]);
    $department = Department::factory()->create();
    $agent = User::factory()->agent()->inDepartments($department)->create();

    $response = $this->actingAs($agent)->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('realtime.enabled', true)
        ->where('realtime.key', RealtimeTestSupport::KEY)
        ->where('realtime.host', 'chat.example.test')
        ->where('realtime.port', 443)
        ->where('realtime.scheme', 'https')
        ->where('realtime.user_channel', "users.{$agent->id}")
        ->where('realtime.inbox_channels', ["inbox.queues.{$department->id}"]));

    expect($response->getContent())->not->toContain(RealtimeTestSupport::SECRET);
});

test('each scope listens to the inbox channels its inbox shows', function () {
    RealtimeTestSupport::useReverb();
    $department = Department::factory()->create();

    $this->actingAs(User::factory()->administrator()->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('realtime.inbox_channels', ['inbox.global']));

    $this->actingAs(User::factory()->supervisor()->inDepartments($department)->create())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('realtime.inbox_channels', ["inbox.departments.{$department->id}", "inbox.supervision.{$department->id}"]));
});
