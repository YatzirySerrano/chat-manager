<?php

use Tests\Support\WebChatFixtures;
use Tests\Support\WebChatVisitor;

/*
| Found by the load scenario tests/load/scenarios/widget.mjs (phase 4): the
| named throttle ran before webchat.session, so the "per session" limit of
| visitor messages was counted per address and every visitor behind the
| same IP shared it.
*/

test('the per-session message limit is counted per session, not per address', function () {
    config([
        'webchat.limits.messages_per_session_per_minute' => 2,
        'webchat.limits.messages_per_ip_per_minute' => 100,
    ]);
    $connection = WebChatFixtures::connection();
    $first = WebChatVisitor::token($this, $connection);
    $second = WebChatVisitor::token($this, $connection);

    WebChatVisitor::send($this, $first, 'Uno')->assertCreated();
    WebChatVisitor::send($this, $first, 'Dos')->assertCreated();
    WebChatVisitor::send($this, $first, 'Tres')->assertTooManyRequests();

    WebChatVisitor::send($this, $second, 'Hola desde la misma red')->assertCreated();
});
