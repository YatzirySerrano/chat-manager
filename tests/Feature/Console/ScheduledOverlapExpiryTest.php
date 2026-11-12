<?php

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;

/*
| A task killed in the middle of a run (deploy, OOM, SIGKILL) leaves its
| overlap mutex behind until it expires. Tasks that run several times a day
| expire it within a few of their own periods, never the 24 hour default.
*/

test('tasks that run more than once a day never keep their overlap lock for a whole day', function () {
    $frequent = collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event): bool => $event->withoutOverlapping)
        ->reject(fn (ScheduledEvent $event): bool => preg_match('/^\d+ \d+ \* \* \*$/', $event->expression) === 1);

    expect($frequent)->not->toBeEmpty()
        ->and($frequent->filter(fn (ScheduledEvent $event): bool => $event->expiresAt >= 1440)->map(fn (ScheduledEvent $event): string => (string) $event->command)->values()->all())
        ->toBe([]);
});
