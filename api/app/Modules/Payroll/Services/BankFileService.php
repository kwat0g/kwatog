<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\BankFileRecord;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generates the bank disbursement CSV.
 *
 * The file lives on the private disk only; serve it back to the browser via
 * the controller — never expose a public URL.
 *
 * Supports multiple bank formats: generic (default), bdo, bpi, metrobank.
 */
class BankFileService
{
    private const FORMATS = ['generic', 'bdo', 'bpi', 'metrobank'];

    /**
     * Build the CSV in memory, persist a copy to private storage, write a
     * BankFileRecord audit row, and return that record.
     */
    public function generate(PayrollPeriod $period, User $generator, string $format = 'generic'): BankFileRecord
    {
        if (! in_array($format, self::FORMATS, true)) {
            throw new RuntimeException("Unsupported bank file format: {$format}");
        }

        if ($period->status !== PayrollPeriodStatus::Finalized) {
            throw new RuntimeException('Bank file can only be generated for finalized periods.');
        }

        return DB::transaction(function () use ($period, $generator, $format) {
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

            $total = $rows['total'];
            $count = $rows['count'];
            $data  = $rows['data'];

            $csv = '';
            foreach ($data as $r) {
                $csv .= implode(',', array_map(fn ($v) => $this->escape((string) $v), $r))."\n";
            }

            $disk = Storage::disk('local');
            $dir  = 'bank-files';
            if (! $disk->exists($dir)) $disk->makeDirectory($dir);

            $filename = sprintf(
                'bank_%s_%s_%s.csv',
                $period->id,
                $period->period_start?->format('Ymd'),
                bin2hex(random_bytes(4)),
            );
            $relative = $dir.DIRECTORY_SEPARATOR.$filename;
            $disk->put($relative, $csv);

            $record = BankFileRecord::create([
                'payroll_period_id' => $period->id,
                'file_path'         => $relative,
                'format'            => $format,
                'record_count'      => $count,
                'total_amount'      => $total,
                'generated_by'      => $generator->id,
                'generated_at'      => now(),
                'created_at'        => now(),
            ]);

            return $record;
        });
    }

    /**
     * Generate (or regenerate) and stream the file as an attachment download.
     */
    public function stream(PayrollPeriod $period, User $generator, string $format = 'generic'): StreamedResponse
    {
        $record = $this->generate($period, $generator, $format);
        $contents = Storage::disk('local')->get($record->file_path);
        $filename = sprintf('bank_%s_%s.csv', $format, $period->period_start?->format('Y-m-d'));

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
    public function preview(PayrollPeriod $period, string $format = 'generic', int $limit = 3): array
    {
        if (! in_array($format, self::FORMATS, true)) {
            throw new RuntimeException("Unsupported bank file format: {$format}");
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

        return [
            'format'  => $format,
            'rows'    => $previewRows,
            'total'   => $rows['total'],
            'count'   => $rows['count'],
        ];
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
     * Currency = PHP, Reference = company_name + payroll_date
     */
    private function buildBdo($payrolls, PayrollPeriod $period): array
    {
        $data = [];
        $data[] = ['employee_no', 'account_number', 'full_name', 'net_pay', 'currency', 'reference'];
        $total = '0.00';
        $count = 0;
        $reference = sprintf('OGAMI_%s', $period->period_start?->format('Ymd') ?? '');

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
                'PHP',
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
     * Reference = OGAMI_SALARY_YYYYMMDD
     */
    private function buildBpi($payrolls, PayrollPeriod $period): array
    {
        $data = [];
        $data[] = ['account_number', 'name', 'amount', 'reference_code', 'branch_code'];
        $total = '0.00';
        $count = 0;
        $reference = sprintf('OGAMI_SALARY_%s', $period->period_start?->format('Ymd') ?? '');

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

    private function escape(string $v): string
    {
        if (str_contains($v, ',') || str_contains($v, '"') || str_contains($v, "\n")) {
            return '"'.str_replace('"', '""', $v).'"';
        }
        return $v;
    }
}
