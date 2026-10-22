<?php

namespace App\Support;

/**
 * Names of the queues, one per responsibility, so a burst of one kind of
 * work (an export, a media download) never delays the critical path
 * (webhooks and outbound messages). Each name can be remapped through
 * config/queue.php "names" (QUEUE_NAME_* variables).
 */
final class Queues
{
    public const WEBHOOKS = 'webhooks';

    public const MESSAGES = 'messages';

    public const MEDIA = 'media';

    public const NOTIFICATIONS = 'notifications';

    public const EXPORTS = 'exports';

    public const DEFAULT = 'default';

    /**
     * Actual queue name for a responsibility. Exports, contact imports and
     * import rollbacks share one queue: EXPORTS_QUEUE (config exports.queue)
     * wins over QUEUE_NAME_EXPORTS, so no job of that group lands on a queue
     * no worker listens to.
     */
    public static function name(string $queue): string
    {
        $name = $queue === self::EXPORTS ? config('exports.queue') : null;
        $name = is_string($name) && $name !== '' ? $name : config("queue.names.{$queue}");

        return is_string($name) && $name !== '' ? $name : $queue;
    }
}
