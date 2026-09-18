<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('bom_items')
            ->select('bom_id', 'item_id')
            ->groupBy('bom_id', 'item_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $pairs = $duplicates
                ->map(static fn (object $row): string => "{$row->bom_id}:{$row->item_id}")
                ->implode(', ');

            throw new RuntimeException(
                'Cannot add the BOM component uniqueness constraint; duplicate bom_id/item_id pairs exist: '.$pairs
            );
        }

        Schema::table('bom_items', function (Blueprint $table): void {
            $table->unique(['bom_id', 'item_id'], 'bom_items_bom_id_item_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('bom_items', function (Blueprint $table): void {
            $table->dropUnique('bom_items_bom_id_item_id_unique');
        });
    }
};
