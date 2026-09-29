<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pieces measured per toleranced parameter in a lot-checklist inspection.
 *
 * Stage-agnostic: incoming, in-process and outgoing all seed this many piece
 * rows. The AQL sample size is unaffected — it stays the declared count of
 * pieces inspected visually for defectives, which is why the two numbers are
 * separate settings. `quality.incoming.measured_pieces` is the predecessor and
 * is read as a fallback for one release.
 */
return new class extends Migration {
    private const KEY = 'quality.inspection.measured_pieces';

    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'key' => self::KEY,
            'value' => json_encode(5),
            'group' => 'quality',
            'label' => 'Inspection Measured Pieces',
            'description' => 'Pieces measured per toleranced parameter in lot-checklist inspections, for every stage.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('settings')->where('key', self::KEY)->delete();
    }
};
