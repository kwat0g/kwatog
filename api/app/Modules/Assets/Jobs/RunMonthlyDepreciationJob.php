<?php

declare(strict_types=1);

namespace App\Modules\Assets\Jobs;

use App\Common\Services\SystemActorService;
use App\Modules\Assets\Services\DepreciationService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sprint 8 — Task 70. Execution primitive for the durable monthly request.
 * Idempotent: re-running an already-processed period is a no-op.
 */
class RunMonthlyDepreciationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const TIMEOUT_SECONDS = 120;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public int $timeout = self::TIMEOUT_SECONDS;

    public function __construct(
        public readonly ?int $year = null,
        public readonly ?int $month = null,
    ) {}

    public function handle(DepreciationService $depreciation, SystemActorService $actors): void
    {
        $systemUser = $actors->resolve();
        if (! $systemUser) {
            throw new \RuntimeException('Monthly depreciation cannot run without an automation actor.');
        }

        $previousMonth = $this->targetMonth();
        $depreciation->runForMonth(
            (int) $previousMonth->year,
            (int) $previousMonth->month,
            $systemUser,
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::error('RunMonthlyDepreciationJob failed permanently.', [
            'year' => $this->year,
            'month' => $this->month,
            'error' => $exception->getMessage(),
        ]);
    }

    private function targetMonth(): CarbonImmutable
    {
        return ($this->year !== null && $this->month !== null)
            ? CarbonImmutable::create($this->year, $this->month, 1)
            : CarbonImmutable::now()->subMonthNoOverflow();
    }
}
