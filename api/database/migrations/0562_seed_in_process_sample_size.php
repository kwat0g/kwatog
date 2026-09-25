<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O2C audit 2026-09-25 — in-process QC sampled 100% of the work-order target:
 * a 200-piece WO opened 400 measurement rows before any output existed, and a
 * WO above quality.full_sampling.max_units got no in-process inspection at
 * all. In-process now takes a small fixed sample off the running line; the lot
 * is still gated by outgoing AQL before it can ship.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => 'quality.in_process.sample_size',
            'value' => json_encode(5),
            'group' => 'quality',
            'label' => 'In-process QC Sample Size',
            'description' => 'Pieces an in-process inspection samples off the running line (never more than the work order target). Outgoing AQL still gates the lot.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'quality.in_process.sample_size')->delete();
    }
};
