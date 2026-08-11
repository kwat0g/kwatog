<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Listeners;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\ChainListenerRunService;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Enums\BankFileGenerationStatus;
use App\Modules\Payroll\Events\PayrollPeriodFinalized;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\BankFileService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * On finalize, generate the disbursement CSV automatically. The payroll is
 * already finalized whether or not the file lands, so every outcome is
 * persisted on the period for operator recovery.
 *
 * `BankFileService::generate()` needs a User attribution row on
 * BankFileRecord.generated_by. The Period itself does not record who
 * finalized it, so we resolve a system_admin as the system actor. If no
 * system_admin exists we skip and log — the manual /periods/{id}/bank-file
 * endpoint still works as a fallback.
 *
 * The finalized event is published after the owning transaction commits, so
 * this listener can run through the normal queue and retry infrastructure.
 * Tests use the synchronous queue, preserving deterministic assertions.
 */
class GenerateBankFileOnPayrollFinalized implements ShouldQueue
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(private readonly BankFileService $bankFiles, private readonly ?SettingsService $settings = null) {}

    public function handle(PayrollPeriodFinalized $event): void
    {
        $outcome = DB::transaction(function () use ($event): array {
            $period = PayrollPeriod::query()
                ->lockForUpdate()
                ->find($event->period->id);
            if (! $period) {
                throw new BusinessRuleException('The finalized payroll period no longer exists.');
            }

            if ($period->bank_file_status === BankFileGenerationStatus::Generated
                || $period->bankFileRecords()->exists()) {
                // A replay after a successful manual or automatic generation is
                // a read, not a second bank artifact.
                if ($period->bank_file_status !== BankFileGenerationStatus::Generated) {
                    $period->markBankFileGenerated();
                }
                return [
                    'status' => 'skipped',
                    'code' => 'bank_file_already_generated',
                    'message' => null,
                ];
            }

            $roles = array_values(array_filter(
                (array) ($this->settings ?? app(SettingsService::class))->get('system.automation.actor_roles', []),
                static fn ($role): bool => is_string($role) && $role !== '',
            ));
            $generator = User::query()
                ->whereHas('role', fn ($q) => $q->whereIn('slug', $roles))
                ->where('is_active', true)
                ->orderBy('id')
                ->first();

            if (! $generator) {
                $note = 'No active automation actor is available to attribute the bank file. Generate it manually after assigning an eligible system actor.';
                $period->markBankFileManualRequired($note);
                Log::warning('GenerateBankFileOnPayrollFinalized: manual generation required', [
                    'period_id' => $period->id,
                    'reason' => $note,
                ]);
                return [
                    'status' => 'manual_required',
                    'code' => 'bank_file_automation_actor_missing',
                    'message' => $note,
                ];
            }

            try {
                $record = $this->bankFiles->generate($period, $generator);

                Log::info('GenerateBankFileOnPayrollFinalized: bank file generated', [
                    'period_id'  => $period->id,
                    'record_id'  => $record->id,
                    'file_path'  => $record->file_path,
                    'rows'       => $record->record_count,
                    'total'      => (string) $record->total_amount,
                ]);
                return [
                    'status' => 'completed',
                    'code' => 'bank_file_generated',
                    'message' => "Generated bank file {$record->file_path}.",
                ];
            } catch (BusinessRuleException $e) {
                $period->fresh()->markBankFileManualRequired($e->getMessage());
                Log::warning('GenerateBankFileOnPayrollFinalized: manual generation required', [
                    'period_id' => $period->id,
                    'reason' => $e->getMessage(),
                ]);
                return [
                    'status' => 'manual_required',
                    'code' => 'bank_file_business_rule_requires_manual',
                    'message' => $e->getMessage(),
                ];
            } catch (\Throwable $e) {
                $note = 'Automatic bank-file generation failed. Fix the reported issue and generate the file manually.';
                $period->fresh()->markBankFileManualRequired($note);
                Log::error('GenerateBankFileOnPayrollFinalized: manual generation required after failure', [
                    'period_id' => $period->id,
                    'message' => $e->getMessage(),
                ]);
                return [
                    'status' => 'manual_required',
                    'code' => 'bank_file_generation_requires_manual_recovery',
                    'message' => $note,
                ];
            }
        });

        app(ChainListenerRunService::class)->recordOutcome(
            $outcome['status'],
            $outcome['code'],
            $outcome['message'],
        );
    }
}
