<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Listeners;

use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Services\BankFileService;
use Illuminate\Support\Facades\Log;

/**
 * On finalize, generate the disbursement CSV automatically. Best-effort:
 * errors are logged and never rethrown — the period is already finalized
 * whether or not the file lands.
 *
 * `BankFileService::generate()` needs a User attribution row on
 * BankFileRecord.generated_by. The Period itself does not record who
 * finalized it, so we resolve a system_admin as the system actor. If no
 * system_admin exists we skip and log — the manual /periods/{id}/bank-file
 * endpoint still works as a fallback.
 *
 * NOT implementing ShouldQueue. The CSV generation is sub-second and the
 * sync queue's serialize/deserialize round-trip would re-fetch the
 * PayrollPeriod from the DB — fragile if a co-listener's failed query has
 * already aborted the surrounding Postgres transaction. Running inline
 * keeps the try/catch in actual control of the failure path.
 */
class GenerateBankFileOnPayrollFinalized
{
    public function __construct(private readonly BankFileService $bankFiles) {}

    public function handle(PayrollPeriodFinalized $event): void
    {
        try {
            $generator = User::whereHas('role', fn ($q) => $q->where('slug', 'system_admin'))
                ->where('is_active', true)
                ->orderBy('id')
                ->first();

            if (! $generator) {
                Log::channel('stack')->warning('GenerateBankFileOnPayrollFinalized: no system_admin user found; skipping auto-generation', [
                    'period_id' => $event->period->id,
                ]);
                return;
            }

            $record = $this->bankFiles->generate($event->period, $generator);

            Log::info('GenerateBankFileOnPayrollFinalized: bank file generated', [
                'period_id'  => $event->period->id,
                'record_id'  => $record->id,
                'file_path'  => $record->file_path,
                'rows'       => $record->record_count,
                'total'      => (string) $record->total_amount,
            ]);
        } catch (\Throwable $e) {
            Log::error('GenerateBankFileOnPayrollFinalized: generation failed', [
                'period_id' => $event->period->id ?? null,
                'message'   => $e->getMessage(),
            ]);
        }
    }
}
