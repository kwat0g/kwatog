<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Listeners;

use App\Common\Services\SettingsService;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Mail\PayslipMail;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Services\PayslipPdfService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailPayslipPdfOnPayrollFinalized implements ShouldQueue
{
    public function __construct(
        private readonly PayslipPdfService $pdf,
        private readonly SettingsService $settings,
    ) {}

    public function handle(PayrollPeriodFinalized $event): void
    {
        if (! (bool) $this->settings->get('payroll.payslip_email.enabled', true)) {
            return;
        }

        $payrolls = Payroll::query()
            ->where('payroll_period_id', $event->period->id)
            ->whereNull('payslip_emailed_at')
            ->with(['employee.department', 'employee.position', 'period', 'deductionDetails'])
            ->get();

        foreach ($payrolls as $payroll) {
            try {
                $email = $payroll->employee?->email;
                if (! $email) {
                    continue;
                }

                $binary   = $this->pdf->generate($payroll);
                $filename = $this->pdf->filename($payroll);

                Mail::to($email)->queue(new PayslipMail($payroll, $binary, $filename));

                $payroll->forceFill(['payslip_emailed_at' => now()])->saveQuietly();
            } catch (\Throwable $e) {
                Log::channel('single')->warning('EmailPayslipPdfOnPayrollFinalized failed for one payroll', [
                    'payroll_id' => $payroll->id,
                    'error'      => $e->getMessage(),
                ]);
                // Swallow — one bad row should not abort the rest of the batch.
            }
        }
    }
}
