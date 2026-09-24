<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('products')
            ->selectRaw('UPPER(part_number) as normalized_part_number')
            ->groupByRaw('UPPER(part_number)')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('normalized_part_number');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot enforce case-insensitive product part numbers; normalize or disposition duplicates: '.$duplicates->implode(', '),
            );
        }

        DB::statement('CREATE UNIQUE INDEX products_part_number_upper_unique ON products (UPPER(part_number))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_part_number_upper_unique');
    }
};
