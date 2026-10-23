<?php

namespace App\Support;

use ReflectionClass;

/**
 * Names of the queues, one per responsibility, so a burst of one kind of
 * work (an export, a media download, a wave of notifications) never delays
 * the critical path (webhooks, outbound messages and real-time signals).
 * Each name can be remapped through config/queue.php "names"
 * (QUEUE_NAME_* variables). Constants are declared in priority order.
 */
final class Queues
{
    public const WEBHOOKS = 'webhooks';

    public const MESSAGES = 'messages';

    public const REALTIME = 'realtime';

    public const NOTIFICATIONS = 'notifications';

    public const MEDIA = 'media';

    public const DEFAULT = 'default';

    public const EXPORTS = 'exports';

    /**
     * Every responsibility, in priority order (highest first).
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values(array_filter(
            (new ReflectionClass(self::class))->getConstants(),
            fn (mixed $value): bool => is_string($value),
        ));
    }

    /**
     * Actual queue name for a responsibility. Two groups have an override of
     * their own that wins over QUEUE_NAME_*, so no job of the group lands on
     * a queue no worker listens to:
     *
     * - exports, contact imports and import rollbacks share EXPORTS_QUEUE
     *   (config exports.queue);
     * - real-time signals (listeners and broadcasts) use REALTIME_QUEUE
     *   (config broadcasting.realtime.queue).
     */
    public static function name(string $queue): string
    {
        $name = match ($queue) {
            self::EXPORTS => config('exports.queue'),
            self::REALTIME => config('broadcasting.realtime.queue'),
            default => null,
        };
        $name = is_string($name) && $name !== '' ? $name : config("queue.names.{$queue}");

        return is_string($name) && $name !== '' ? $name : $queue;
    }
}
