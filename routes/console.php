<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Monitors: the command decides which are actually due from each monitor's
// own interval, so checking every minute costs one cheap query. Requires a
// server cron entry running `php artisan schedule:run` every minute.
Schedule::command('monitors:run')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Dead-man's-switch check for webhook endpoints with an expectation set.
Schedule::command('webhooks:check')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
