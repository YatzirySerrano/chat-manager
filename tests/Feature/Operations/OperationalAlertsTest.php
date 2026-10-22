<?php

use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\User;
use App\Services\Operations\SchedulerHeartbeat;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

function sentAlertMessages(): array
{
    /** @var ArrayTransport $transport */
    $transport = Mail::mailer('array')->getSymfonyTransport();

    return iterator_to_array($transport->messages());
}

beforeEach(function () {
    config(['mail.default' => 'array', 'operations.alerts.emails' => ['ops@example.test']]);
});

test('a stopped scheduler is alerted once per throttle window', function () {
    Cache::forever(config('operations.scheduler.heartbeat_key'), now()->subHour()->getTimestamp());

    Artisan::call('ops:check-alerts');
    Artisan::call('ops:check-alerts');

    $messages = sentAlertMessages();

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->getOriginalMessage()->getSubject())->toContain('Alerta operativa: Tareas programadas')
        ->and($messages[0]->getEnvelope()->getRecipients()[0]->getAddress())->toBe('ops@example.test');
});

test('a spike of failed jobs raises an alert', function () {
    app(SchedulerHeartbeat::class)->beat();
    config(['operations.failed_jobs.spike' => 3]);

    foreach (range(1, 3) as $i) {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'RuntimeException: fallo',
            'failed_at' => now(),
        ]);
    }

    Artisan::call('ops:check-alerts');

    expect(sentAlertMessages())->toHaveCount(1)
        ->and(sentAlertMessages()[0]->getOriginalMessage()->getSubject())->toContain('Aumento de trabajos fallidos');
});

test('nothing is sent when the system is healthy', function () {
    app(SchedulerHeartbeat::class)->beat();

    Artisan::call('ops:check-alerts');

    expect(sentAlertMessages())->toBe([])
        ->and(Artisan::output())->toContain('Sin condiciones críticas');
});

test('administrators who can view the system receive alerts when enabled', function () {
    config(['operations.alerts.emails' => [], 'operations.alerts.notify_admins' => true]);
    $watcher = conversationUser([Permission::ViewSystem], DataScope::Global);
    conversationUser([Permission::ViewSystem], DataScope::Departments);
    Cache::forever(config('operations.scheduler.heartbeat_key'), now()->subHour()->getTimestamp());

    Artisan::call('ops:check-alerts');

    $recipients = collect(sentAlertMessages()[0]->getEnvelope()->getRecipients())->map->getAddress()->all();

    expect($recipients)->toContain($watcher->email)->toHaveCount(
        User::query()->with('roles.permissions')->get()->filter(fn ($user) => $user->holdsPermission(Permission::ViewSystem))->count(),
    );
});

test('the scheduler heartbeat command records the beat', function () {
    Artisan::call('ops:heartbeat');

    expect(app(SchedulerHeartbeat::class)->ageInSeconds())->toBeLessThan(5);
});
