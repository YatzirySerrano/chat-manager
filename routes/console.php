<?php

use App\Actions\Conversations\CloseInactiveConversations;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('conversations:close-inactive', function (CloseInactiveConversations $closeInactive) {
    $this->info("Conversaciones cerradas por inactividad: {$closeInactive->handle()}");
})->purpose('Close conversations without messages for the configured number of hours');

Schedule::command('conversations:close-inactive')->hourly()->withoutOverlapping();

Schedule::command('exports:prune')->hourly()->withoutOverlapping();

// Reconciles the report metrics of recent conversations (covers refreshes lost by a worker outage).
Schedule::command('analytics:rebuild --days=2')->dailyAt('03:30')->withoutOverlapping();

// Old read notifications and expired deduplication receipts (automation module).
Schedule::command('notifications:prune')->dailyAt('04:10')->withoutOverlapping();

// WhatsApp Cloud API (meta module): stuck deliveries, raw webhook log retention, template synchronization.
Schedule::command('whatsapp:maintenance reconcile-deliveries')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('whatsapp:maintenance recover-webhooks')->everyTenMinutes()->withoutOverlapping();
Schedule::command('whatsapp:maintenance prune-webhooks')->dailyAt('03:10')->withoutOverlapping();
Schedule::command('whatsapp:maintenance sync-templates')->everySixHours()->withoutOverlapping();

// Web chat: visitor sessions expired or revoked more than webchat.prune_after_days ago.
Schedule::command('webchat:prune-sessions')->dailyAt('04:20')->withoutOverlapping();

// Operations (ops module): scheduler heartbeat (system health) and critical failure alerts.
Schedule::command('ops:heartbeat')->everyMinute();
Schedule::command('ops:check-alerts')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=2160')->dailyAt('04:30');
Schedule::command('queue:prune-batches --hours=168')->dailyAt('04:40');
