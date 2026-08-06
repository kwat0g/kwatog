<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
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

    public function __construct(private readonly SettingsService $settings)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $year = (int) ($this->argument('year') ?: Carbon::now()->year);

        /** @var User|null $systemUser */
        $systemEmail = $this->settings->requiredString('leave.year_end.automation_user_email');
        $systemUser = User::query()->where('email', $systemEmail)->first();

        if (! $systemUser) {
            $this->warn("No automation user found ({$systemEmail}). Falling back to a configured automation actor.");

            $roles = array_values(array_filter((array) $this->settings->get('system.automation.actor_roles', []), static fn ($role): bool => is_string($role) && $role !== ''));
            $systemUser = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                ->first();
        }

        if (! $systemUser) {
            $this->error('Cannot dispatch — no eligible automation user found.');

            return self::FAILURE;
        }

        $job = new ProcessYearEndLeave($systemUser, $year);
        dispatch($job);

        $this->info("Dispatched ProcessYearEndLeave job for year {$year}.");

        return self::SUCCESS;
    }
}
