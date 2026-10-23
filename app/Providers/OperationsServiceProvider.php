<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use App\Services\Operations\QueueMonitor;
use App\Support\LogContext;
use App\Support\Queues;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

/**
 * Production hardening and observability wiring: trusted reverse proxies,
 * https URLs, correlation identifiers on every queued job, and the local
 * `composer run dev` processes (queue workers for every queue of the
 * application, the scheduler and a multi-worker web server).
 */
class OperationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureAuthorization();
        $this->configureProxies();
        $this->configureHttps();
        $this->configureJobLogContext();
        $this->configureDevCommands();
    }

    /**
     * Administración > Sistema. Both permissions are organization-wide
     * (Permission::requiresGlobalScope), enforced by holdsPermission.
     */
    private function configureAuthorization(): void
    {
        Gate::define('view-system', fn (User $user): bool => $user->holdsPermission(Permission::ViewSystem));
        Gate::define('manage-system', fn (User $user): bool => $user->holdsPermission(Permission::ViewSystem) && $user->holdsPermission(Permission::ManageSystem));
    }

    /**
     * TRUSTED_PROXIES: "*" or a comma separated list of addresses/CIDRs of
     * the reverse proxies (Nginx on the same host is 127.0.0.1). Without it
     * no X-Forwarded-* header is trusted.
     */
    private function configureProxies(): void
    {
        $proxies = trim((string) config('operations.http.trusted_proxies'));

        if ($proxies === '') {
            return;
        }

        TrustProxies::at($proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
    }

    private function configureHttps(): void
    {
        if ($this->app->isProduction()
            && (bool) config('operations.http.force_https', true)
            && str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceHttps();
        }
    }

    /**
     * Runs after Laravel hydrates the context carried by the job payload.
     */
    private function configureJobLogContext(): void
    {
        Event::listen(function (JobProcessing $event): void {
            LogContext::add(LogContext::fromJobPayload($event->job->payload(), $event->job->getQueue()));
        });
    }

    /**
     * `composer run dev` processes (the framework defaults only listen to the
     * "default" queue with one `queue:listen` and serve with one PHP worker).
     *
     * - "queue" and "webhooks": two long-lived `queue:work` processes for the
     *   critical queues (App\Support\Queues), one preferring outbound
     *   messages and the other the reception of webhooks, both draining the
     *   real-time signals, polling every second. `queue:listen` boots a new
     *   PHP process for every job and sleeps 3 s when the queue is empty,
     *   which put 1-2 s between queued and sending and 3-6 s until accepted.
     *   A worker keeps the code it booted with: after editing PHP run
     *   `php artisan queue:restart`; the shell loop starts it again at once
     *   (multiplex only restarts commands that exit with an error, and a
     *   restarted worker exits with 0).
     * - "queue-bg": `queue:listen` (reloads the code by itself) for the
     *   remaining queues, exports last.
     * - "server": `artisan serve --no-reload` with PHP_CLI_SERVER_WORKERS
     *   (default 4; the value of .env or of the shell wins), so webhooks of
     *   the tunnel, the send request and its refetches are served in
     *   parallel. Without --no-reload the variable is ignored; in exchange
     *   a change to .env needs `composer run dev` restarted.
     *
     * On Windows the dev command runs through `concurrently` and cmd.exe (no
     * shell loop, no inline variables, no built-in server workers): there a
     * single listener polls every second.
     */
    private function configureDevCommands(): void
    {
        if (! $this->app->runningInConsole() || ! $this->app->isLocal()) {
            return;
        }

        $names = $this->app->make(QueueMonitor::class)->queueNames();
        $sendFirst = $this->queueList([$names[Queues::MESSAGES], $names[Queues::REALTIME], $names[Queues::WEBHOOKS]]);
        $receiveFirst = $this->queueList([$names[Queues::WEBHOOKS], $names[Queues::REALTIME], $names[Queues::MESSAGES]]);
        $exports = $names[Queues::EXPORTS];
        unset($names[Queues::WEBHOOKS], $names[Queues::MESSAGES], $names[Queues::REALTIME], $names[Queues::EXPORTS]);
        // Priority order; slow exports last so they never delay the rest.
        $background = array_values(array_diff(array_unique([...array_values($names), $exports]), $sendFirst));

        DevCommands::artisan('schedule:work', 'scheduler');

        if (PHP_OS_FAMILY === 'Windows') {
            DevCommands::artisan('queue:listen --queue='.implode(',', [...$sendFirst, ...$background]).' --sleep=1 --tries=1 --timeout=0', 'queue');

            return;
        }

        DevCommands::register($this->restartingWorker($sendFirst), 'queue');
        DevCommands::register($this->restartingWorker($receiveFirst), 'webhooks');

        if ($background !== []) {
            DevCommands::artisan('queue:listen --queue='.escapeshellarg(implode(',', $background)).' --sleep=3 --tries=1 --timeout=0', 'queue-bg');
        }

        // Same variable ServeCommand reads; only used by the local dev command.
        $serverWorkers = max(1, (int) (Env::get('PHP_CLI_SERVER_WORKERS') ?: 4));
        DevCommands::register("PHP_CLI_SERVER_WORKERS={$serverWorkers} php artisan serve --no-reload", 'server');
    }

    /**
     * @param  list<string>  $queues
     * @return list<string>
     */
    private function queueList(array $queues): array
    {
        return array_values(array_unique($queues));
    }

    /**
     * A long-lived worker started again whenever it stops (queue:restart,
     * memory limit, a crash), one second later.
     *
     * @param  list<string>  $queues
     */
    private function restartingWorker(array $queues): string
    {
        $queue = escapeshellarg(implode(',', $queues));

        return "while :; do php artisan queue:work --queue={$queue} --sleep=1 --tries=1 --timeout=0; sleep 1; done";
    }
}
