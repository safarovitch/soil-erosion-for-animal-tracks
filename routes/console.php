<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule Pulse data aggregation (required for Pulse to work)
Schedule::command('pulse:check')->everyFiveMinutes();

// Schedule automatic precomputation of latest year erosion maps
// Runs twice per year: June 1st and December 1st at 2:00 AM
Schedule::command('erosion:precompute-latest-year --type=all')
    ->cron('0 2 1 6,12 *') // At 02:00 on day 1 of June and December
    ->timezone('UTC')
    ->withoutOverlapping()
    ->runInBackground()
    ->onSuccess(function () {
        \Log::info('Automated erosion precomputation completed successfully');
    })
    ->onFailure(function () {
        \Log::error('Automated erosion precomputation failed');
    });
