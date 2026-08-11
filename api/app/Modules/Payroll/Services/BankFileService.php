<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Common\Services\SettingsService;
use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Enums\BankFileFormat;
use App\Modules\Payroll\Models\BankFileRecord;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generates the bank disbursement CSV.
 *
 * The file lives on the private disk only; serve it back to the browser via
 * the controller — never expose a public URL.
 *
 * Supports multiple bank formats configured through the payroll settings.
 */
class BankFileService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Employees in this period who cannot be paid by bank transfer.
     *
     * Every format builder skips a row with no bank_account_no. That skip was
     * silent: the file simply came out short, its total no longer matched the
     * approved payroll, and the GL still posted the full amount — so the
     * difference was money the company had recognised as paid but never
     * actually sent. Nothing in the UI or the audit row hinted at it.
     *
     * @return \Illuminate\Support\Collection<int, Payroll>
     */
    public function unbankableRows(PayrollPeriod $period): \Illuminate\Support\Collection
    {
        return Payroll::query()
            ->with('employee:id,employee_no,first_name,last_name,bank_account_no')
            ->where('payroll_period_id', $period->id)
            ->whereNull('error_message')
            ->where('net_pay', '>', 0)
            ->get()
            ->filter(fn (Payroll $p) => blank($p->employee?->bank_account_no))
            ->values();
    }

    /**
     * Refuse to generate a file that would quietly under-pay the period.
     *
     * Deliberately a hard stop rather than a warning: the file goes straight to
     * the bank, and a short one is discovered when an employee reports missing
     * pay. HR fixes the bank details and regenerates — the period is already
     * finalized, so nothing else has to be redone.
     */
    private function assertEveryoneIsBankable(PayrollPeriod $period): void
    {
        $unbankable = $this->unbankableRows($period);
        if ($unbankable->isEmpty()) {
            return;
        }

        $missing = $unbankable->sum(fn (Payroll $p) => (float) $p->net_pay);
        $sample  = $unbankable->take(3)
            ->map(fn (Payroll $p) => sprintf(
                '%s %s',
                $p->employee?->employee_no ?? '?',
                trim(($p->employee?->first_name ?? '').' '.($p->employee?->last_name ?? '')),
            ))
            ->implode(', ');

        throw new BusinessRuleException(sprintf(
            '%d employee(s) in this period have no bank account on file, so %s would be left out of the bank file and never paid (e.g. %s). Add their bank details, then generate the file again.',
            $unbankable->count(),
            number_format($missing, 2),
            $sample,
        ));
    }

    /**
     * Build the CSV in memory, persist a copy to private storage, write a
     * BankFileRecord audit row, and return that record.
     */
    public function generate(PayrollPeriod $period, User $generator, ?string $format = null): BankFileRecord
    {
        $writtenPath = null;

        try {
            $format ??= $this->defaultFormat();
            if (! in_array($format, $this->formats(), true)) {
                throw new BusinessRuleException("Unsupported bank file format: {$format}");
            }

            return DB::transaction(function () use ($period, $generator, $format, &$writtenPath) {
                // The route-bound period can be stale while finalization,
                // disbursement, or another download is in flight. The locked
                // row is the authority for both the lifecycle and the payroll
                // rows that the file claims to pay.
                $lockedPeriod = PayrollPeriod::query()
                    ->lockForUpdate()
                    ->find($period->id);
                if (! $lockedPeriod) {
                    throw new BusinessRuleException('The payroll period no longer exists.');
                }
                if (! in_array($lockedPeriod->status, [PayrollPeriodStatus::Finalized, PayrollPeriodStatus::Disbursed], true)) {
                    throw new BusinessRuleException('Bank file can only be generated for finalized or disbursed periods.');
                }

                $this->assertEveryoneIsBankable($lockedPeriod);

                $payrolls = Payroll::query()
                    ->with('employee')
                    ->where('payroll_period_id', $lockedPeriod->id)
                    ->whereNull('error_message')
                    ->where('net_pay', '>', 0)
                    ->get();

                $rows = match ($format) {
                    'bdo'       => $this->buildBdo($payrolls, $lockedPeriod),
                    'bpi'       => $this->buildBpi($payrolls, $lockedPeriod),
                    'metrobank' => $this->buildMetrobank($payrolls),
                    default     => $this->buildGeneric($payrolls),
                };

                $total = $rows['total'];
                $count = $rows['count'];
                $data  = $rows['data'];

                // Reconcile the file against the payroll it claims to pay. The
                // builders each accumulate their own total independently of the
                // period, so a builder bug (a skipped row, a mis-parsed amount)
                // would produce a plausible-looking file that quietly disburses
                // the wrong sum. This is the last point before the numbers leave
                // for the bank.
                $expected = Payroll::query()
                    ->where('payroll_period_id', $lockedPeriod->id)
                    ->whereNull('error_message')
                    ->where('net_pay', '>', 0)
                    ->get(['net_pay'])
                    ->reduce(fn (string $carry, Payroll $p) => bcadd($carry, (string) $p->net_pay, 2), '0.00');

                if (bccomp($total, $expected, 2) !== 0) {
                    throw new BusinessRuleException(sprintf(
                        'Bank file failed reconciliation: the file totals %s but this period owes %s. Nothing was generated — this is a bug, please report it.',
                        number_format((float) $total, 2),
                        number_format((float) $expected, 2),
                    ));
                }

                $csv = '';
                foreach ($data as $r) {
                    $csv .= implode(',', array_map(fn ($v) => $this->escape((string) $v), $r))."\n";
                }

                $disk = Storage::disk('local');
                $dir  = 'bank-files';
                if (! $disk->exists($dir)) $disk->makeDirectory($dir);

                $filename = sprintf(
                    'bank_%s_%s_%s.csv',
                    $lockedPeriod->id,
                    $lockedPeriod->period_start?->format('Ymd'),
                    bin2hex(random_bytes(4)),
                );
                $relative = $dir.DIRECTORY_SEPARATOR.$filename;
                $disk->put($relative, $csv);
                $writtenPath = $relative;

                $record = BankFileRecord::create([
                    'payroll_period_id' => $lockedPeriod->id,
                    'file_path'         => $relative,
                    'format'            => $format,
                    'record_count'      => $count,
                    'total_amount'      => $total,
                    'generated_by'      => $generator->id,
                    'generated_at'      => now(),
                    'created_at'        => now(),
                ]);

                $lockedPeriod->markBankFileGenerated();

                return $record;
            });
        } catch (\Throwable $e) {
            if ($writtenPath !== null) {
                try {
                    Storage::disk('local')->delete($writtenPath);
                } catch (\Throwable $cleanupError) {
                    Log::warning('BankFileService: failed to clean up an uncommitted file', [
                        'file_path' => $writtenPath,
                        'error' => $cleanupError->getMessage(),
                    ]);
                }
            }

            // A finalized payroll must expose a recovery state even when the
            // manual endpoint, rather than the automatic listener, hit the
            // failure. Keep the original exception for the caller's response.
            try {
                $failedPeriod = $period->fresh();
                if ($failedPeriod && in_array($failedPeriod->status, [PayrollPeriodStatus::Finalized, PayrollPeriodStatus::Disbursed], true)) {
                    $note = $e instanceof BusinessRuleException
                        ? $e->getMessage()
                        : 'Bank-file generation failed. Fix the reported issue and generate the file manually.';
                    $failedPeriod->markBankFileManualRequired($note);
                }
            } catch (\Throwable $stateError) {
                Log::warning('BankFileService: could not persist generation failure state', [
                    'period_id' => $period->id,
                    'error' => $stateError->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Generate (or regenerate) and stream the file as an attachment download.
     */
    public function stream(PayrollPeriod $period, User $generator, ?string $format = null): StreamedResponse
    {
        $record = $this->generate($period, $generator, $format);
        $contents = Storage::disk('local')->get($record->file_path);
        $filename = sprintf('bank_%s_%s.csv', $record->format, $period->period_start?->format('Y-m-d'));

        return response()->streamDownload(
            fn () => print $contents,
            $filename,
            [
                'Content-Type' => 'text/csv',
                'Cache-Control' => 'no-store',
            ],
        );
    }

    /**
     * Return the first N rows of the given format as an array (for preview).
     */
    public function preview(PayrollPeriod $period, ?string $format = null, int $limit = 3): array
    {
        $format ??= $this->defaultFormat();
        if (! in_array($format, $this->formats(), true)) {
            throw new BusinessRuleException("Unsupported bank file format: {$format}");
        }

        $payrolls = Payroll::query()
            ->with('employee')
            ->where('payroll_period_id', $period->id)
            ->whereNull('error_message')
            ->where('net_pay', '>', 0)
            ->get();

        $rows = match ($format) {
            'bdo'       => $this->buildBdo($payrolls, $period),
            'bpi'       => $this->buildBpi($payrolls, $period),
            'metrobank' => $this->buildMetrobank($payrolls),
            default     => $this->buildGeneric($payrolls),
        };

        // Return header + up to $limit data rows
        $previewRows = array_slice($rows['data'], 0, $limit + 1);

        // Surface the same shortfall generate() refuses on, so the UI can warn
        // BEFORE the user clicks download rather than only failing at the end.
        // Preview must not throw — it is a read-only look at an unfinalized
        // period too — so the problem is reported as data instead.
        $unbankable = $this->unbankableRows($period);

        return [
            'format'  => $format,
            'rows'    => $previewRows,
            'total'   => $rows['total'],
            'count'   => $rows['count'],
            'unbankable_count'  => $unbankable->count(),
            'unbankable_amount' => number_format(
                $unbankable->sum(fn (Payroll $p) => (float) $p->net_pay), 2, '.', '',
            ),
            'unbankable_sample' => $unbankable->take(5)->map(fn (Payroll $p) => [
                'employee_no' => $p->employee?->employee_no,
                'name'        => trim(($p->employee?->first_name ?? '').' '.($p->employee?->last_name ?? '')),
                'net_pay'     => (string) $p->net_pay,
            ])->values()->all(),
        ];
    }

    /** @return list<string> */
    private function formats(): array
    {
        return array_map(static fn (BankFileFormat $format): string => $format->value, BankFileFormat::cases());
    }

    public function defaultFormat(): string
    {
        return (string) $this->settings->requiredString('payroll.bank_file.default_format');
    }

    // ---------------------------------------------------------------
    //  Format builders
    // ---------------------------------------------------------------

    /**
     * Generic format: employee_no, full_name, bank_name, account_number, net_pay
     */
    private function buildGeneric($payrolls): array
    {
        $data = [];
        $data[] = ['employee_no', 'full_name', 'bank_name', 'account_number', 'net_pay'];
        $total = '0.00';
        $count = 0;

        foreach ($payrolls as $p) {
            $emp = $p->employee;
            if (! $emp) continue;
            $bankAcct = $emp->bank_account_no ?? '';
            if ($bankAcct === '') continue;

            $data[] = [
                $emp->employee_no,
                $emp->full_name,
                $emp->bank_name ?? '',
                $bankAcct,
                number_format((float) $p->net_pay, 2, '.', ''),
            ];
            $total = bcadd($total, (string) $p->net_pay, 2);
            $count++;
        }

        return ['data' => $data, 'total' => $total, 'count' => $count];
    }

    /**
     * BDO format: employee_no, account_number, full_name, net_pay, currency, reference
     * Currency is the configured functional currency; reference uses the configured prefix and payroll date.
     */
    private function buildBdo($payrolls, PayrollPeriod $period): array
    {
        $data = [];
        $currency = $this->settings->requiredString('accounting.functional_currency_code');
        $data[] = ['employee_no', 'account_number', 'full_name', 'net_pay', 'currency', 'reference'];
        $total = '0.00';
        $count = 0;
        $prefix = strtoupper(trim($this->settings->requiredString('payroll.bank_file.reference_prefix')));
        $reference = sprintf('%s_%s', $prefix, $period->period_start?->format('Ymd') ?? '');

        foreach ($payrolls as $p) {
            $emp = $p->employee;
            if (! $emp) continue;
            $bankAcct = $emp->bank_account_no ?? '';
            if ($bankAcct === '') continue;

            $data[] = [
                $emp->employee_no,
                $bankAcct,
                $emp->full_name,
                number_format((float) $p->net_pay, 2, '.', ''),
                $currency,
                $reference,
            ];
            $total = bcadd($total, (string) $p->net_pay, 2);
            $count++;
        }

        return ['data' => $data, 'total' => $total, 'count' => $count];
    }

    /**
     * BPI format: account_number, name, amount, reference_code, branch_code_mandatory
     * Amount in centavos (multiply by 100, no decimal).
     * Reference uses the configured payroll bank-file prefix plus the period date.
     */
    private function buildBpi($payrolls, PayrollPeriod $period): array
    {
        $data = [];
        $data[] = ['account_number', 'name', 'amount', 'reference_code', 'branch_code'];
        $total = '0.00';
        $count = 0;
        $prefix = strtoupper(trim($this->settings->requiredString('payroll.bank_file.reference_prefix')));
        $reference = sprintf('%s_SALARY_%s', $prefix, $period->period_start?->format('Ymd') ?? '');

        foreach ($payrolls as $p) {
            $emp = $p->employee;
            if (! $emp) continue;
            $bankAcct = $emp->bank_account_no ?? '';
            if ($bankAcct === '') continue;

            // Amount in centavos (integer, no decimal)
            $centavos = bcmul((string) $p->net_pay, '100', 0);

            $data[] = [
                $bankAcct,
                $emp->full_name,
                $centavos,
                $reference,
                '', // branch code — empty string, field included for mandatory column header
            ];
            $total = bcadd($total, (string) $p->net_pay, 2);
            $count++;
        }

        return ['data' => $data, 'total' => $total, 'count' => $count];
    }

    /**
     * Metrobank format: employee_no, last_name, first_name, middle_initial, account_number, amount, transaction_code
     * Amount with 2 decimal places, transaction_code = SALARY
     */
    private function buildMetrobank($payrolls): array
    {
        $data = [];
        $data[] = ['employee_no', 'last_name', 'first_name', 'middle_initial', 'account_number', 'amount', 'transaction_code'];
        $total = '0.00';
        $count = 0;

        foreach ($payrolls as $p) {
            $emp = $p->employee;
            if (! $emp) continue;
            $bankAcct = $emp->bank_account_no ?? '';
            if ($bankAcct === '') continue;

            $data[] = [
                $emp->employee_no,
                $emp->last_name ?? '',
                $emp->first_name ?? '',
                $emp->middle_name ? strtoupper(substr($emp->middle_name, 0, 1)) : '',
                $bankAcct,
                number_format((float) $p->net_pay, 2, '.', ''),
                'SALARY',
            ];
            $total = bcadd($total, (string) $p->net_pay, 2);
            $count++;
        }

        return ['data' => $data, 'total' => $total, 'count' => $count];
    }

    /**
     * CSV-escape a value, and neutralise spreadsheet formula injection.
     *
     * A value beginning = + - @ (or tab / CR, which Excel strips before parsing)
     * is executed as a formula when the file is opened. Employee names reach
     * this file, and the CSV importer applies no character validation at all, so
     * a name like `=cmd|' /C calc'!A0` would run on the finance workstation that
     * opens the bank file — the classic CSV-injection path, and this file is
     * opened by someone who can move money.
     *
     * The guard is a leading apostrophe, which Excel and LibreOffice both treat
     * as "the rest is literal text". The displayed value is unchanged, so a bank
     * parsing the file positionally still reads the same field.
     */
    private function escape(string $v): string
    {
        if ($v !== '' && str_contains("=+-@\t\r", $v[0])) {
            $v = "'".$v;
        }

        if (str_contains($v, ',') || str_contains($v, '"') || str_contains($v, "\n") || str_contains($v, "\r")) {
            return '"'.str_replace('"', '""', $v).'"';
        }

        return $v;
    }
}
