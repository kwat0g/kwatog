<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Jobs;

use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollGlPostingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class PostPayrollToGlJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $uniqueFor = 600;

    public function __construct(public PayrollPeriod $period) {}

    public function uniqueId(): string
    {
        return "payroll-gl-post-{$this->period->id}";
    }

    public function handle(PayrollGlPostingService $service): void
    {
        try {
            $service->post($this->period->fresh());
        } catch (Throwable $e) {
            Log::error('PostPayrollToGlJob failed', [
                'period_id' => $this->period->id,
                'message'   => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
