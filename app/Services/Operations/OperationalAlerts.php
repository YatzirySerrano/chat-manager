<?php

namespace App\Services\Operations;

use App\Enums\Permission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Critical operational conditions and their delivery. Deliberately small:
 * each alert is written to the "ops" log channel and mailed to the
 * configured addresses (and, optionally, to the users who can view the
 * system page). The same alert is not repeated within the throttle window.
 * Alerts carry counts and ages only, never customer data.
 *
 * A total outage (server, PHP-FPM or database down) cannot alert from
 * inside the application: an external monitor must poll /health/ready.
 *
 * @phpstan-type OperationalAlert array{key: string, title: string, message: string}
 */
class OperationalAlerts
{
    public function __construct(
        private SystemHealth $health,
        private FailedJobs $failedJobs,
        private Cache $cache,
    ) {}

    /**
     * Conditions that deserve an alert right now.
     *
     * @return list<OperationalAlert>
     */
    public function evaluate(): array
    {
        $alerts = [];

        $spikeMinutes = max(1, (int) config('operations.failed_jobs.spike_minutes', 15));
        $spike = max(1, (int) config('operations.failed_jobs.spike', 10));
        $recentFailures = $this->failedJobs->isAvailable() ? $this->failedJobs->count(CarbonImmutable::now()->subMinutes($spikeMinutes)) : 0;

        if ($recentFailures >= $spike) {
            $alerts[] = [
                'key' => 'failed-jobs-spike',
                'title' => 'Aumento de tareas con error',
                'message' => "{$recentFailures} tareas fallaron en los últimos {$spikeMinutes} minutos (umbral {$spike}). Revisa Administración › Sistema.",
            ];
        }

        foreach ([$this->health->scheduler(), $this->health->webhookBacklog(), $this->health->queueBacklog(), $this->health->database()] as $check) {
            if ($check->status === HealthStatus::Fail) {
                $alerts[] = ['key' => $check->key, 'title' => $check->label, 'message' => $check->summary];
            }
        }

        return $alerts;
    }

    /**
     * Deliver the alerts not sent within the throttle window.
     *
     * @param  list<OperationalAlert>  $alerts
     * @return list<OperationalAlert> The alerts actually delivered.
     */
    public function deliver(array $alerts): array
    {
        $throttle = max(1, (int) config('operations.alerts.throttle_minutes', 60));
        $delivered = [];

        foreach ($alerts as $alert) {
            try {
                $fresh = $this->cache->add("operations:alert:{$alert['key']}", CarbonImmutable::now()->getTimestamp(), $throttle * 60);
            } catch (Throwable) {
                // Without a cache the alert is still worth sending.
                $fresh = true;
            }

            if (! $fresh) {
                continue;
            }

            Log::channel('ops')->critical("[Alerta] {$alert['title']}: {$alert['message']}", ['alert' => $alert['key']]);
            $this->mail($alert);
            $delivered[] = $alert;
        }

        return $delivered;
    }

    /**
     * @return list<string>
     */
    public function recipients(): array
    {
        $emails = array_map('strval', (array) config('operations.alerts.emails', []));

        if ((bool) config('operations.alerts.notify_admins', false)) {
            $admins = User::query()
                ->active()
                ->withPermission(Permission::ViewSystem)
                ->with('roles.permissions')
                ->get()
                ->filter(fn (User $user): bool => $user->holdsPermission(Permission::ViewSystem))
                ->pluck('email')
                ->map(fn (mixed $email): string => (string) $email)
                ->all();

            $emails = [...$emails, ...$admins];
        }

        return array_values(array_unique(array_filter($emails, fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));
    }

    /**
     * @param  OperationalAlert  $alert
     */
    private function mail(array $alert): void
    {
        $recipients = $this->recipients();

        if ($recipients === []) {
            return;
        }

        $app = (string) config('app.name');
        $url = rtrim((string) config('app.url'), '/').'/admin/system';
        $body = "{$alert['message']}\n\nAplicación: {$app}\nDetectado: ".CarbonImmutable::now()->format('Y-m-d H:i:s T')."\nEstado del sistema: {$url}\n\nEste aviso se repite como máximo una vez cada ".config('operations.alerts.throttle_minutes', 60).' minutos mientras la condición persista.';

        try {
            Mail::raw($body, function ($message) use ($recipients, $alert, $app): void {
                $message->to($recipients)->subject("[{$app}] Alerta operativa: {$alert['title']}");
            });
        } catch (Throwable $exception) {
            Log::channel('ops')->error('No se pudo enviar el correo de alerta operativa.', ['alert' => $alert['key'], 'exception' => class_basename($exception)]);
        }
    }
}
