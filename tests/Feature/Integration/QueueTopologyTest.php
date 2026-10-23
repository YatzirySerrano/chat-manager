<?php

use App\Providers\OperationsServiceProvider;
use App\Services\Operations\QueueMonitor;
use App\Support\Queues;
use Illuminate\Foundation\DevCommands;

/*
| Every job must land on a queue some worker consumes. The production
| templates (Supervisor and systemd) are read as text so a renamed queue or a
| forgotten worker group fails here instead of silently piling up jobs. The
| list of queues comes from App\Support\Queues, never from this file.
*/

const QUEUE_TEMPLATES = ['deploy/supervisor/chat-manager.conf', 'deploy/systemd/chat-manager-worker@.service'];

/**
 * The `queue:work` commands of a deployment template.
 *
 * @return list<string>
 */
function workerCommands(string $template): array
{
    preg_match_all('/artisan queue:work[^\n;]*/', (string) file_get_contents(base_path($template)), $matches);

    return $matches[0];
}

/**
 * The `--queue=` list of each worker command of a template.
 *
 * @return list<list<string>>
 */
function workerQueueLists(string $template): array
{
    return array_map(function (string $command): array {
        preg_match('/--queue=([a-z,_-]+)/', $command, $match);

        return explode(',', $match[1] ?? '');
    }, workerCommands($template));
}

/**
 * The `composer run dev` commands registered by OperationsServiceProvider in
 * the local environment, by name.
 *
 * @return array<string, string>
 */
function localDevCommands(): array
{
    $environment = app()->environment();
    app()->detectEnvironment(fn (): string => 'local');

    try {
        (new OperationsServiceProvider(app()))->boot();
    } finally {
        app()->detectEnvironment(fn (): string => $environment);
    }

    return array_column(DevCommands::commands(), 'command', 'name');
}

/**
 * @return list<string>
 */
function devCommandQueues(string $command): array
{
    preg_match("/--queue='?([a-z,_-]+)'?/", $command, $match);

    return explode(',', $match[1] ?? '');
}

test('every queue responsibility has a worker in both production templates', function (string $template) {
    $consumed = array_merge(...workerQueueLists($template));

    expect(Queues::all())->toContain(Queues::REALTIME)
        ->and(array_diff(array_map(Queues::name(...), Queues::all()), $consumed))->toBe([]);
})->with(QUEUE_TEMPLATES);

test('critical queues never share a worker with notifications, media, metrics or exports', function (string $template) {
    $critical = [Queues::WEBHOOKS, Queues::MESSAGES, Queues::REALTIME];

    foreach (workerQueueLists($template) as $queues) {
        if (array_intersect($queues, $critical) !== []) {
            expect(array_diff($queues, $critical))->toBe([]);
        }
    }

    expect(workerQueueLists($template))->toContain([Queues::REALTIME]);
})->with(QUEUE_TEMPLATES);

test('critical workers take outbound messages before webhooks in both production templates', function (string $template) {
    $critical = array_values(array_filter(workerQueueLists($template), fn (array $queues): bool => in_array(Queues::WEBHOOKS, $queues, true)));

    expect($critical)->toBe([[Queues::MESSAGES, Queues::WEBHOOKS]]);
})->with(QUEUE_TEMPLATES);

test('workers use the configured queue connection and stop before retry_after', function (string $template) {
    $retryAfter = (int) config('queue.connections.database.retry_after');

    expect(workerCommands($template))->not->toBeEmpty()->each(function ($command) use ($retryAfter): void {
        // `queue:work database` would ignore QUEUE_CONNECTION=redis.
        expect($command->value)->toMatch('/artisan queue:work --/');

        preg_match('/--timeout=(\d+)/', $command->value, $timeout);
        expect((int) ($timeout[1] ?? 0))->toBeGreaterThan(0)->toBeLessThan($retryAfter);
    });
})->with(QUEUE_TEMPLATES);

test('supervisor keeps retrying the workers while the database starts', function () {
    $template = (string) file_get_contents(base_path('deploy/supervisor/chat-manager.conf'));
    preg_match_all('/\[program:chatmanager-queue-[a-z]+\][^\[]*/', $template, $programs);

    expect($programs[0])->toHaveCount(count(workerCommands('deploy/supervisor/chat-manager.conf')))
        ->each->toMatch('/startretries=([1-9]\d+)/');
});

test('exports, imports and rollbacks share the queue chosen by EXPORTS_QUEUE', function () {
    expect(Queues::name(Queues::EXPORTS))->toBe('exports');

    config(['exports.queue' => 'default']);

    expect(Queues::name(Queues::EXPORTS))->toBe('default');
});

test('the system page monitors every queue, including the REALTIME_QUEUE override', function () {
    expect(array_keys(app(QueueMonitor::class)->queueNames()))->toBe(Queues::all())
        ->and(app(QueueMonitor::class)->queueNames()[Queues::REALTIME])->toBe('realtime');

    config(['broadcasting.realtime.queue' => 'fast']);

    expect(app(QueueMonitor::class)->queueNames()[Queues::REALTIME])->toBe('fast');
});

test('composer run dev keeps two polling workers on the critical queues, one preferring sends and one webhooks', function () {
    config(['exports.queue' => 'exports']);

    $commands = localDevCommands();

    expect(devCommandQueues($commands['queue']))->toBe([Queues::MESSAGES, Queues::REALTIME, Queues::WEBHOOKS])
        ->and(devCommandQueues($commands['webhooks']))->toBe([Queues::WEBHOOKS, Queues::REALTIME, Queues::MESSAGES]);

    foreach (['queue', 'webhooks'] as $name) {
        // A long-lived worker polling every second, started again after `queue:restart`.
        expect($commands[$name])->toContain('queue:work')->toContain('--sleep=1')->toStartWith('while :; do ');
    }
});

test('composer run dev consumes every other queue in a reloading listener with exports last', function () {
    config(['exports.queue' => 'exports']);

    $commands = localDevCommands();
    $background = devCommandQueues($commands['queue-bg']);

    expect($commands['queue-bg'])->toContain('queue:listen')
        ->and($background)->toBe([Queues::NOTIFICATIONS, Queues::MEDIA, Queues::DEFAULT, Queues::EXPORTS])
        ->and(array_diff(array_values(app(QueueMonitor::class)->queueNames()), [...devCommandQueues($commands['queue']), ...$background]))->toBe([]);
});

test('composer run dev never lists a remapped queue twice', function () {
    config(['exports.queue' => 'default', 'broadcasting.realtime.queue' => 'messages']);

    $commands = localDevCommands();

    expect(devCommandQueues($commands['queue']))->toBe([Queues::MESSAGES, Queues::WEBHOOKS])
        ->and(devCommandQueues($commands['queue-bg']))->toBe([Queues::NOTIFICATIONS, Queues::MEDIA, Queues::DEFAULT]);
});

test('composer run dev serves with several PHP workers', function () {
    // PHP_CLI_SERVER_WORKERS is ignored by `artisan serve` without --no-reload.
    expect(localDevCommands()['server'])->toMatch('/^PHP_CLI_SERVER_WORKERS=\\d+ php artisan serve --no-reload$/');
});
