<?php

namespace Tests\Support\Concurrency;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A separate PHP process running one scenario of worker.php against the
 * testing database, to reproduce real concurrency: its own PHP runtime, its
 * own container and its own database connection, like a second queue
 * worker or web request.
 *
 * Protocol (one line per message): the worker prints READY once booted and
 * waits for GO; scenarios may print intermediate markers (e.g. HOLDING) and
 * wait for a word from the test (e.g. COMMIT); the last line is
 * "RESULT {json}". Only the testing database is ever used: the environment
 * forces it and the worker refuses to run otherwise.
 */
final class ConcurrentProcess
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    private string $stdout = '';

    private string $stderr = '';

    /** @var list<string> Every line printed so far, for failure messages. */
    private array $transcript = [];

    /** @var array<string, mixed>|null */
    private ?array $result = null;

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, string>  $environment  Extra variables (e.g. CACHE_STORE).
     */
    public static function start(string $scenario, array $arguments = [], array $environment = []): self
    {
        return new self($scenario, $arguments, $environment);
    }

    /**
     * Start every process, wait until all of them booted, then release them
     * at the same instant and collect their results.
     *
     * @param  list<self>  $processes
     * @return list<array<string, mixed>>
     */
    public static function race(array $processes, float $timeout = 60): array
    {
        foreach ($processes as $process) {
            $process->waitFor('READY', $timeout);
        }

        foreach ($processes as $process) {
            $process->tell('GO');
        }

        return array_map(fn (self $process): array => $process->result($timeout), $processes);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, string>  $environment
     */
    private function __construct(private string $scenario, array $arguments, array $environment)
    {
        $database = (string) config('database.testing_database');

        if ($database === '' || config('database.connections.mariadb.database') !== $database) {
            throw new RuntimeException('Concurrent processes only run against the testing database.');
        }

        $env = [
            ...array_filter(getenv(), fn (mixed $value): bool => is_string($value)),
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mariadb',
            'DB_DATABASE' => $database,
            'DB_URL' => '',
            'QUEUE_CONNECTION' => 'sync',
            'BROADCAST_CONNECTION' => 'null',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
            'LOG_CHANNEL' => 'null',
            'WHATSAPP_TRANSPORT' => 'unavailable',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
            ...$environment,
        ];

        $command = [PHP_BINARY, __DIR__.'/worker.php', $scenario, base64_encode((string) json_encode($arguments))];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);

        if (! is_resource($process)) {
            throw new RuntimeException("The process for [{$scenario}] could not be started.");
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->process = $process;
        $this->pipes = $pipes;
    }

    /**
     * Wait for a line starting with the marker and return it.
     */
    public function waitFor(string $marker, float $timeout = 30): string
    {
        $deadline = microtime(true) + $timeout;

        while (true) {
            $line = $this->takeLine($marker);

            if ($line !== null) {
                return $line;
            }

            if (microtime(true) > $deadline) {
                $statements = self::runningStatements();
                $this->interrupt();
                $this->stop();

                throw new RuntimeException("[{$this->scenario}] did not print {$marker} within {$timeout}s. Lines: ".implode(' | ', $this->transcript).' '.trim($this->stdout).' Errors: '.mb_substr(trim($this->stderr), 0, 2000).' Statements running: '.$statements);
            }

            if (! $this->read(0.05) && ! $this->isRunning()) {
                $this->read(0);

                if (($line = $this->takeLine($marker)) !== null) {
                    return $line;
                }

                throw new RuntimeException("[{$this->scenario}] ended before printing {$marker}. Lines: ".implode(' | ', $this->transcript).' '.trim($this->stdout).' Errors: '.mb_substr(trim($this->stderr), 0, 2000));
            }
        }
    }

    /**
     * Whether the scenario already printed its result (without consuming it).
     */
    public function hasFinished(): bool
    {
        $this->read(0);

        return str_contains("\n".$this->stdout, "\nRESULT ");
    }

    public function tell(string $word): void
    {
        fwrite($this->pipes[0], $word."\n");
        fflush($this->pipes[0]);
    }

    /**
     * The decoded result of the scenario: {ok: true, value} or {ok: false, error, message}.
     *
     * @return array<string, mixed>
     */
    public function result(float $timeout = 60): array
    {
        if ($this->result !== null) {
            return $this->result;
        }

        $line = $this->waitFor('RESULT ', $timeout);
        $decoded = json_decode(substr($line, strlen('RESULT ')), true);

        $this->stop();

        if (! is_array($decoded)) {
            throw new RuntimeException("[{$this->scenario}] printed an invalid result: {$line}");
        }

        return $this->result = $decoded;
    }

    /**
     * Ends the process (it is always stopped, even when a test fails).
     */
    public function stop(): void
    {
        if (! is_resource($this->process)) {
            return;
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if ($this->isRunning()) {
            proc_terminate($this->process);
        }

        proc_close($this->process);
    }

    public function __destruct()
    {
        $this->stop();
    }

    /**
     * Asks a hung worker to print where it is (worker.php prints its stack
     * on SIGUSR1) and collects it.
     */
    private function interrupt(): void
    {
        $pid = proc_get_status($this->process)['pid'] ?? null;

        if (! is_int($pid) || ! $this->isRunning() || ! function_exists('posix_kill') || ! defined('SIGUSR1')) {
            return;
        }

        posix_kill($pid, SIGUSR1);
        $deadline = microtime(true) + 2;

        while (microtime(true) < $deadline && ! str_contains($this->stderr, 'END OF STACK')) {
            $this->read(0.1);
        }
    }

    /**
     * Statements of this database user still running (to explain a hang).
     */
    private static function runningStatements(): string
    {
        $threads = array_filter(DB::select('SHOW FULL PROCESSLIST'), fn (object $thread): bool => $thread->db !== null && ! str_starts_with((string) $thread->Info, 'SHOW'));

        return (string) json_encode(array_values(array_map(fn (object $thread): array => ['command' => $thread->Command, 'time' => $thread->Time, 'state' => $thread->State, 'sql' => mb_substr((string) $thread->Info, 0, 300)], $threads)));
    }

    private function isRunning(): bool
    {
        return is_resource($this->process) && (proc_get_status($this->process)['running'] ?? false);
    }

    /**
     * Read what is available; whether anything was read.
     */
    private function read(float $wait): bool
    {
        $streams = array_values(array_filter([$this->pipes[1], $this->pipes[2]], 'is_resource'));

        if ($streams === []) {
            return false;
        }

        $write = $except = null;
        $ready = @stream_select($streams, $write, $except, 0, (int) ($wait * 1_000_000));

        if ($ready === false || $ready === 0) {
            return false;
        }

        $read = false;

        foreach ($streams as $stream) {
            $chunk = (string) fread($stream, 65536);
            $read = $read || $chunk !== '';

            if ($stream === $this->pipes[1]) {
                $this->stdout .= $chunk;
            } else {
                $this->stderr .= $chunk;
            }
        }

        return $read;
    }

    /**
     * Removes and returns the first complete line starting with the marker
     * (earlier lines are discarded).
     */
    private function takeLine(string $marker): ?string
    {
        while (($end = strpos($this->stdout, "\n")) !== false) {
            $line = substr($this->stdout, 0, $end);
            $this->stdout = substr($this->stdout, $end + 1);
            $this->transcript[] = mb_substr($line, 0, 200);

            if (str_starts_with($line, $marker)) {
                return $line;
            }
        }

        return null;
    }
}
