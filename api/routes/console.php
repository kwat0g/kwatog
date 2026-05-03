<?php

use App\Modules\Maintenance\Jobs\GeneratePreventiveMaintenanceJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Sprint 8 — Task 69. Daily cron — materialise preventive maintenance WOs
 * (time-based schedules due now, mold-shot schedules at 100% threshold).
 */
Schedule::job(new GeneratePreventiveMaintenanceJob)
    ->dailyAt('02:00')
    ->name('maintenance:generate-preventive')
    ->withoutOverlapping();
