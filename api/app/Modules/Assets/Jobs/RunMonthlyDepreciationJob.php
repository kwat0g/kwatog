<?php

declare(strict_types=1);

namespace App\Modules\Assets\Jobs;

use App\Modules\Assets\Services\DepreciationService;
use App\Modules\Auth\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 8 — Task 70. Runs depreciation for the previous calendar month.
 *
 * Scheduled monthly on the 1st at 03:00. Idempotent: re-running for an
 * already-processed period is a no-op.
 */
class RunMonthlyDepreciationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(DepreciationService $depreciation): void
    {
        $systemUser = User::query()->orderBy('id')->first();
        if (! $systemUser) return;

        $previousMonth = CarbonImmutable::now()->subMonthNoOverflow();
        $depreciation->runForMonth(
            (int) $previousMonth->year,
            (int) $previousMonth->month,
            $systemUser,
        );
    }
}
