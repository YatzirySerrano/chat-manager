<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\User;
use App\Services\Operations\FailedJobs;
use App\Services\Operations\HealthCheck;
use App\Services\Operations\QueueMonitor;
use App\Services\Operations\RecentErrors;
use App\Services\Operations\SchedulerHeartbeat;
use App\Services\Operations\SensitiveText;
use App\Services\Operations\SystemHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración > Sistema: technical state of the platform for the people
 * holding system.view (organization-wide scope). Everything shown is
 * sanitized: counts, ages, job classes and the first line of errors with
 * secrets and personal data masked; never payloads or credentials.
 */
class SystemController extends Controller
{
    public function index(
        Request $request,
        SystemHealth $health,
        QueueMonitor $queues,
        FailedJobs $failedJobs,
        RecentErrors $recentErrors,
        SchedulerHeartbeat $heartbeat,
    ): Response {
        Gate::authorize('view-system');

        $failedQueues = $failedJobs->isAvailable() ? $failedJobs->queues() : [];
        $filters = $request->validate([
            'queue' => ['nullable', 'string', Rule::in($failedQueues)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        /** @var User $viewer */
        $viewer = $request->user();
        $checks = $health->checks();

        return Inertia::render('admin/system/Index', [
            'overall' => $health->overall($checks)->value,
            'checks' => array_map(fn (HealthCheck $check): array => $check->toArray(), $checks),
            'queueConnection' => $queues->connectionName(),
            'queuesAsync' => $queues->isAsynchronous(),
            'queues' => $queues->stats(),
            'failedJobs' => $this->failedJobsPage($failedJobs, $filters['queue'] ?? null),
            'failedQueues' => $failedQueues,
            'filters' => ['queue' => $filters['queue'] ?? null],
            'scheduler' => [
                'last_beat_at' => $heartbeat->lastBeatAt()?->toIso8601String(),
                'age_seconds' => $heartbeat->ageInSeconds(),
                'stale_after_seconds' => $heartbeat->staleAfterSeconds(),
            ],
            'recentErrors' => $recentErrors->latest(),
            'connections' => $this->connections($viewer),
            'environment' => [
                'app_env' => (string) config('app.env'),
                'debug' => (bool) config('app.debug'),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'cache' => (string) config('cache.default'),
                'broadcasting' => (string) config('broadcasting.default'),
                'whatsapp_transport' => (string) config('whatsapp.transport', 'unavailable'),
            ],
            'canManage' => Gate::allows('manage-system'),
            'checkedAt' => now()->toIso8601String(),
        ]);
    }

    /**
     * Detailed health as JSON for administrators (the public readiness
     * endpoint only answers ok/fail).
     */
    public function health(SystemHealth $health): JsonResponse
    {
        Gate::authorize('view-system');

        $checks = $health->checks();

        return response()->json([
            'status' => $health->overall($checks)->value,
            'checked_at' => now()->toIso8601String(),
            'checks' => array_map(fn (HealthCheck $check): array => $check->toArray(), $checks),
        ]);
    }

    /**
     * Failed jobs page in the {data, meta} shape the interface paginates.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}|null
     */
    private function failedJobsPage(FailedJobs $failedJobs, ?string $queue): ?array
    {
        if (! $failedJobs->isAvailable()) {
            return null;
        }

        $page = $failedJobs->paginate((int) config('operations.failed_jobs.page_size', 25), $queue)->withQueryString()->toArray();
        $data = array_values($page['data']);
        unset($page['data']);

        return ['data' => $data, 'meta' => $page];
    }

    /**
     * Stored health of the WhatsApp connections (no live calls to Meta),
     * only for viewers who may see connections.
     *
     * @return list<array{id: int, name: string, phone: string|null, is_active: bool, health: array{value: string, label: string, description: string, tone: string}, last_webhook_at: string|null, last_inbound_at: string|null, last_outbound_at: string|null, last_error_at: string|null, last_error: string|null, can_verify: bool}>|null
     */
    private function connections(User $viewer): ?array
    {
        if (! $viewer->holdsPermission(Permission::ViewConnections) && ! $viewer->holdsPermission(Permission::ManageConnections)) {
            return null;
        }

        return array_values(Connection::query()
            ->notDeleted()
            ->with('webChatWidget')
            ->orderBy('name')
            ->limit(100)
            ->get()
            ->map(function (Connection $connection) use ($viewer): array {
                $health = $connection->health();

                return [
                    'id' => $connection->id,
                    'name' => $connection->name,
                    'phone' => $connection->display_phone_number,
                    'is_active' => $connection->is_active,
                    'health' => ['value' => $health->value, 'label' => $health->label(), 'description' => $health->description(), 'tone' => $health->tone()],
                    'last_webhook_at' => $connection->last_webhook_at?->toIso8601String(),
                    'last_inbound_at' => $connection->last_inbound_at?->toIso8601String(),
                    'last_outbound_at' => $connection->last_outbound_at?->toIso8601String(),
                    'last_error_at' => $connection->last_error_at?->toIso8601String(),
                    'last_error' => $connection->last_error_message === null ? null : SensitiveText::firstLine($connection->last_error_message, 200),
                    'can_verify' => $viewer->can('verify', $connection),
                ];
            })
            ->all());
    }
}
