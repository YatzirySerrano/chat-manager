<?php

use App\Support\Queues;

/*
| Every job must land on a queue some worker consumes. The production
| templates (Supervisor and systemd) are read as text so a renamed queue or a
| forgotten worker group fails here instead of silently piling up jobs.
*/

/**
 * Queue names consumed by the `--queue=` lists of a deployment template.
 *
 * @return list<string>
 */
function consumedQueues(string $template): array
{
    preg_match_all('/--queue=([a-z,_-]+)/', (string) file_get_contents(base_path($template)), $matches);

    return array_values(array_unique(array_merge(...array_map(fn (string $list): array => explode(',', $list), $matches[1]))));
}

test('every queue responsibility has a worker in both production templates', function (string $template) {
    $responsibilities = [Queues::WEBHOOKS, Queues::MESSAGES, Queues::MEDIA, Queues::NOTIFICATIONS, Queues::EXPORTS, Queues::DEFAULT];

    expect(array_diff(array_map(fn (string $queue): string => Queues::name($queue), $responsibilities), consumedQueues($template)))->toBe([]);
})->with(['deploy/supervisor/chat-manager.conf', 'deploy/systemd/chat-manager-worker@.service']);

test('exports, imports and rollbacks share the queue chosen by EXPORTS_QUEUE', function () {
    expect(Queues::name(Queues::EXPORTS))->toBe('exports');

    config(['exports.queue' => 'default']);

    expect(Queues::name(Queues::EXPORTS))->toBe('default');
});
