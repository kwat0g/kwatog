<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'product_routings_one_active_per_product_unique';

    public function up(): void
    {
        // Keep the newest definition before adding the invariant. This makes
        // the migration safe for databases that already contain ambiguous
        // active state from the pre-invariant service.
        $active = DB::table('product_routings')
            ->where('is_active', true)
            ->orderBy('product_id')
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->get(['id', 'product_id']);

        $seenProducts = [];
        $deactivate = [];
        foreach ($active as $row) {
            if (isset($seenProducts[$row->product_id])) {
                $deactivate[] = $row->id;
                continue;
            }
            $seenProducts[$row->product_id] = true;
        }

        if ($deactivate !== []) {
            DB::table('product_routings')->whereIn('id', $deactivate)->update(['is_active' => false]);
        }

        // PostgreSQL is the deployed database; SQLite supports the same
        // partial-index syntax and is used by lightweight test harnesses.
        if (in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON product_routings (product_id) WHERE is_active = TRUE');
        }
    }

    public function down(): void
    {
        if (in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        }
    }
};
