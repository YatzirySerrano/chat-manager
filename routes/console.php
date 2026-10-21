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
