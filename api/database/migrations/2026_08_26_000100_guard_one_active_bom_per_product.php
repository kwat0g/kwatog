<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bill_of_materials')) {
            return;
        }

        // Keep the newest version active when old data contains duplicates.
        // Historical versions remain available; only the invalid active flag
        // is repaired before the database invariant is installed.
        $duplicates = DB::table('bill_of_materials')
            ->select('product_id')
            ->where('is_active', true)
            ->when(Schema::hasColumn('bill_of_materials', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->groupBy('product_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('product_id');

        foreach ($duplicates as $productId) {
            $active = DB::table('bill_of_materials')
                ->where('product_id', $productId)
                ->where('is_active', true)
                ->when(Schema::hasColumn('bill_of_materials', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                ->orderByDesc('version')
                ->orderByDesc('id')
                ->get(['id']);

            foreach ($active->skip(1) as $row) {
                DB::table('bill_of_materials')->where('id', $row->id)->update([
                    'is_active' => false,
                    'updated_at' => now(),
                ]);
            }
        }

        $where = 'is_active = TRUE';
        if (Schema::hasColumn('bill_of_materials', 'deleted_at')) {
            $where .= ' AND deleted_at IS NULL';
        }

        $driver = DB::getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement("CREATE UNIQUE INDEX bill_of_materials_one_active_per_product ON bill_of_materials (product_id) WHERE {$where}");

            return;
        }

        throw new RuntimeException("The active BOM invariant requires PostgreSQL or SQLite; received {$driver}.");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS bill_of_materials_one_active_per_product');
    }
};
