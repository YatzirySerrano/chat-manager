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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

/**
 * Production hardening and observability wiring: trusted reverse proxies,
 * https URLs, correlation identifiers on every queued job, and the local
 * `composer run dev` processes (a queue listener for every queue of the
 * application and the scheduler).
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
     * The default dev command only listens to the "default" queue; the
     * application uses one queue per responsibility (App\Support\Queues).
     */
    private function configureDevCommands(): void
    {
        if (! $this->app->runningInConsole() || ! $this->app->isLocal()) {
            return;
        }

        $names = $this->app->make(QueueMonitor::class)->queueNames();
        $exports = $names[Queues::EXPORTS];
        unset($names[Queues::EXPORTS]);
        // Priority order; slow exports last so they never delay messages.
        $queues = implode(',', array_values(array_unique([...array_values($names), $exports])));

        DevCommands::artisan("queue:listen --queue={$queues} --tries=1 --timeout=0", 'queue');
        DevCommands::artisan('schedule:work', 'scheduler');
    }
}
