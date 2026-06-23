<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Leave\Jobs\ProcessYearEndLeave;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * OGAMI-104 — Dispatch the year-end leave forfeiture/conversion job.
 *
 * Usage:
 *   php artisan leave:process-year-end           # defaults to current year
 *   php artisan leave:process-year-end 2025      # process 2025
 */
class ProcessYearEndLeaveCommand extends Command
{
    protected $signature   = 'leave:process-year-end {year? : Target year (default: current)}';
    protected $description = 'Dispatch year-end leave forfeiture/conversion for the given year';

    public function handle(): int
    {
        $year = (int) ($this->argument('year') ?: Carbon::now()->year);

        /** @var User|null $systemUser */
        $systemUser = User::query()->where('email', 'system@ogami.local')->first();

        if (! $systemUser) {
            $this->warn('No system user found (system@ogami.local). Falling back to first admin user.');

            $systemUser = User::query()
                ->whereHas('role', fn ($q) => $q->where('slug', 'system_admin'))
                ->first();
        }

        if (! $systemUser) {
            $this->error('Cannot dispatch — no eligible system/admin user found.');

            return self::FAILURE;
        }

        $job = new ProcessYearEndLeave($systemUser, $year);
        dispatch($job);

        $this->info("Dispatched ProcessYearEndLeave job for year {$year}.");

        return self::SUCCESS;
    }
}
