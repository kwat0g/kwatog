<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Jobs;

use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollPeriodService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Backward-compatible adapter for deployments that still have this job in a
 * queue payload. New code stages PayrollGlPostingRequested through the outbox.
 * Keeping this adapter durable prevents an old/manual dispatch from bypassing
 * the handoff ledger and writing directly to the General Ledger.
 */
class PostPayrollToGlJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public PayrollPeriod $period) {}

    public function uniqueId(): string
    {
        return "payroll-gl-post-{$this->period->id}";
    }

    public function handle(PayrollPeriodService $periods): void
    {
        try {
            $periods->retryGlPosting($this->period->fresh());
        } catch (Throwable $e) {
            Log::error('PostPayrollToGlJob compatibility handoff failed', [
                'period_id' => $this->period->id,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
