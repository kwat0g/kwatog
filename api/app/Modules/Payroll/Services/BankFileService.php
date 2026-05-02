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
 */
class BankFileService
{
    /**
     * Build the CSV in memory, persist a copy to private storage, write a
     * BankFileRecord audit row, and return that record.
     */
    public function generate(PayrollPeriod $period, User $generator): BankFileRecord
    {
        if ($period->status !== PayrollPeriodStatus::Finalized) {
            throw new RuntimeException('Bank file can only be generated for finalized periods.');
        }

        return DB::transaction(function () use ($period, $generator) {
            $payrolls = Payroll::query()
                ->with('employee')
                ->where('payroll_period_id', $period->id)
                ->whereNull('error_message')
                ->where('net_pay', '>', 0)
                ->get();

            $rows = [];
            $rows[] = ['employee_no', 'full_name', 'bank_name', 'account_number', 'net_pay'];
            $total = '0.00';
            $count = 0;

            foreach ($payrolls as $p) {
                $emp = $p->employee;
                if (! $emp) continue;
                $bankName = $emp->bank_name ?? '';
                $bankAcct = $emp->bank_account_no ?? ''; // decrypted via cast
                if ($bankAcct === '') continue; // skip unbanked employees

                $rows[] = [
                    $emp->employee_no,
                    $emp->full_name,
                    $bankName,
                    $bankAcct,
                    number_format((float) $p->net_pay, 2, '.', ''),
                ];
                $total = bcadd($total, (string) $p->net_pay, 2);
                $count++;
            }

            $csv = '';
            foreach ($rows as $r) {
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
    public function stream(PayrollPeriod $period, User $generator): StreamedResponse
    {
        $record = $this->generate($period, $generator);
        $contents = Storage::disk('local')->get($record->file_path);
        $filename = sprintf('bank_%s.csv', $period->period_start?->format('Y-m-d'));

        return response()->streamDownload(
            fn () => print $contents,
            $filename,
            [
                'Content-Type' => 'text/csv',
                'Cache-Control' => 'no-store',
            ],
        );
    }

    private function escape(string $v): string
    {
        if (str_contains($v, ',') || str_contains($v, '"') || str_contains($v, "\n")) {
            return '"'.str_replace('"', '""', $v).'"';
        }
        return $v;
    }
}
