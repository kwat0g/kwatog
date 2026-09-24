<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Material Review Board (MRB) setting for incoming QC failures.
 *
 * When true, failed incoming inspections await MRB disposition (scrap, rework, RTV, use-as-is)
 * before GRN settlement. The GRN stays at pending_qc until the NCR disposition is decided.
 * When false, use the legacy behavior: auto-reject failed lines immediately.
 */
return new class extends Migration
{
    private const ROWS = [
        [
            'quality.incoming_failure.mrb_review',
            true,
            'quality',
            'Incoming QC Failure MRB Review',
            'When true, failed incoming inspections await material review board disposition before GRN acceptance/rejection. When false, use legacy auto-reject behavior.',
        ],
    ];

    public function up(): void
    {
        foreach (self::ROWS as [$key, $value, $group, $label, $description]) {
            DB::table('settings')->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value), 'group' => $group,
                'label' => $label, 'description' => $description,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::ROWS, 0))->delete();
    }
};
