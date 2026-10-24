<?php

use App\Models\User;
use Tests\Support\WebChatFixtures;

/*
| Found by tests/e2e/widget.mjs (phase 4): the dashboard answered 500 as soon
| as an active web chat connection existed, because the "WhatsApp connected"
| check read each connection's widget lazily (preventLazyLoading).
*/

test('the dashboard opens with an active web chat connection', function () {
    WebChatFixtures::connection();

    $this->actingAs(User::factory()->superAdministrator()->create())
        ->get(route('dashboard'))
        ->assertOk();
});
