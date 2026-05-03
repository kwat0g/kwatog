<?php

declare(strict_types=1);

namespace App\Common\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Atomic document number generator.
 *
 * Per-document-type configuration controls reset granularity:
 *   - 'monthly' (default): {PREFIX}-{YYYYMM}-{NNNN}
 *   - 'yearly':            {PREFIX}-{YYYY}-{NNNN}
 *
 * Concurrency safety: SELECT ... FOR UPDATE inside a serializable transaction
 * with row-level locking on the per-(type, year, month) row.
 */
class DocumentSequenceService
{
    /**
     * @return array<string, array{prefix: string, reset: 'monthly'|'yearly', pad: int}>
     */
    private const CONFIG = [
        'employee'      => ['prefix' => 'OGM',  'reset' => 'yearly',  'pad' => 4],
        'purchase_order'=> ['prefix' => 'PO',   'reset' => 'monthly', 'pad' => 4],
        'invoice'       => ['prefix' => 'INV',  'reset' => 'monthly', 'pad' => 4],
        'journal_entry' => ['prefix' => 'JE',   'reset' => 'monthly', 'pad' => 4],
        'work_order'    => ['prefix' => 'WO',   'reset' => 'monthly', 'pad' => 4],
        'ncr'           => ['prefix' => 'NCR',  'reset' => 'monthly', 'pad' => 4],
        'grn'           => ['prefix' => 'GRN',  'reset' => 'monthly', 'pad' => 4],
        'sales_order'   => ['prefix' => 'SO',   'reset' => 'monthly', 'pad' => 4],
        'mrp_plan'      => ['prefix' => 'MRP',  'reset' => 'monthly', 'pad' => 4],
        'leave_request' => ['prefix' => 'LR',   'reset' => 'monthly', 'pad' => 4],
        'inspection'    => ['prefix' => 'QC',   'reset' => 'monthly', 'pad' => 4],
        'pr'            => ['prefix' => 'PR',   'reset' => 'monthly', 'pad' => 4],
        'delivery'      => ['prefix' => 'DR',   'reset' => 'monthly', 'pad' => 4],
        'bill'          => ['prefix' => 'BILL', 'reset' => 'monthly', 'pad' => 4],
        'bank_payment'  => ['prefix' => 'BPAY', 'reset' => 'monthly', 'pad' => 4],
        'loan'          => ['prefix' => 'LN',   'reset' => 'monthly', 'pad' => 4],
        'cash_advance'  => ['prefix' => 'CA',   'reset' => 'monthly', 'pad' => 4],
        'complaint'     => ['prefix' => 'CMP',  'reset' => 'monthly', 'pad' => 4],
        'shipment'      => ['prefix' => 'SHP',  'reset' => 'monthly', 'pad' => 4],
        'maintenance_wo'=> ['prefix' => 'MWO',  'reset' => 'monthly', 'pad' => 4],
        'asset'         => ['prefix' => 'AST',  'reset' => 'yearly',  'pad' => 4],
        'clearance'     => ['prefix' => 'CLR',  'reset' => 'monthly', 'pad' => 4],
    ];

    /**
     * Generate the next document number for the given type.
     *
     * Format:
     *   monthly  →  {PREFIX}-{YYYYMM}-{NNNN}
     *   yearly   →  {PREFIX}-{YYYY}-{NNNN}
     */
    public function generate(string $documentType): string
    {
        if (! isset(self::CONFIG[$documentType])) {
            throw new InvalidArgumentException("Unknown document type: {$documentType}");
        }

        ['prefix' => $prefix, 'reset' => $reset, 'pad' => $pad] = self::CONFIG[$documentType];

        $now = now();
        $year = (int) $now->format('Y');
        $month = $reset === 'yearly' ? 0 : (int) $now->format('n');

        return DB::transaction(function () use ($documentType, $prefix, $reset, $pad, $year, $month) {
            // Lock-or-create the sequence row.
            $row = DB::table('document_sequences')
                ->where('document_type', $documentType)
                ->where('year', $year)
                ->where('month', $month)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                DB::table('document_sequences')->insert([
                    'document_type' => $documentType,
                    'prefix'        => $prefix,
                    'year'          => $year,
                    'month'         => $month,
                    'last_number'   => 0,
                ]);
                $row = DB::table('document_sequences')
                    ->where('document_type', $documentType)
                    ->where('year', $year)
                    ->where('month', $month)
                    ->lockForUpdate()
                    ->first();
            }

            $next = (int) $row->last_number + 1;
            DB::table('document_sequences')
                ->where('id', $row->id)
                ->update(['last_number' => $next]);

            $datePart = $reset === 'yearly'
                ? sprintf('%04d', $year)
                : sprintf('%04d%02d', $year, $month);

            return sprintf('%s-%s-%s', $prefix, $datePart, str_pad((string) $next, $pad, '0', STR_PAD_LEFT));
        });
    }

    /** @return array<int, string> */
    public function knownTypes(): array
    {
        return array_keys(self::CONFIG);
    }
}
