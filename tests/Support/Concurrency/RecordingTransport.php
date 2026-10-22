<?php

namespace Tests\Support\Concurrency;

use App\Models\Message;
use App\Services\Meta\SendResult;
use App\Services\Meta\WhatsAppTransport;

/**
 * Test double of the WhatsApp transport for worker processes (never Meta):
 * every "send" is appended to a log file shared by all processes of a test
 * (with an exclusive lock), and takes a little time like a network call, so
 * a duplicate send by another process would be visible.
 */
final class RecordingTransport implements WhatsAppTransport
{
    public function __construct(private string $log, private int $latencyMilliseconds = 150) {}

    public function send(Message $message): SendResult
    {
        file_put_contents($this->log, $message->id.' '.getmypid()."\n", FILE_APPEND | LOCK_EX);
        usleep($this->latencyMilliseconds * 1000);

        return new SendResult('wamid.concurrency.'.$message->id);
    }

    public function name(): string
    {
        return 'recording';
    }

    /**
     * Message ids sent, one entry per call (duplicates included).
     *
     * @return list<int>
     */
    public static function sentIds(string $log): array
    {
        if (! is_file($log)) {
            return [];
        }

        $lines = array_filter(explode("\n", (string) file_get_contents($log)));

        return array_values(array_map(fn (string $line): int => (int) explode(' ', $line)[0], $lines));
    }
}
