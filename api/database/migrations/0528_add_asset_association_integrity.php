<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['machines', 'molds', 'vehicles'] as $table) {
            $orphaned = DB::table($table.' as operational')
                ->whereNotNull('operational.asset_id')
                ->whereNotExists(function ($query): void {
                    $query->select(DB::raw('1'))
                        ->from('assets')
                        ->whereColumn('assets.id', 'operational.asset_id');
                })
                ->exists();
            if ($orphaned) {
                throw new RuntimeException("Cannot enforce asset association integrity: {$table}.asset_id contains an unknown asset.");
            }

            $duplicates = DB::table($table)
                ->select('asset_id')
                ->whereNotNull('asset_id')
                ->groupBy('asset_id')
                ->havingRaw('COUNT(*) > 1')
                ->pluck('asset_id');
            if ($duplicates->isNotEmpty()) {
                throw new RuntimeException("Cannot enforce one-to-one asset association: {$table}.asset_id has duplicate values.");
            }

            Schema::table($table, function ($blueprint) use ($table): void {
                $blueprint->unique('asset_id', "{$table}_asset_id_unique");
            });
        }

        // Machines and vehicles already have this FK from the original asset
        // migrations. Molds had the column but never received the promised FK.
        Schema::table('molds', function ($blueprint): void {
            $blueprint->foreign('asset_id', 'molds_asset_id_foreign')
                ->references('id')->on('assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('molds', function ($blueprint): void {
            $blueprint->dropForeign('molds_asset_id_foreign');
        });
        foreach (['machines', 'molds', 'vehicles'] as $table) {
            Schema::table($table, function ($blueprint) use ($table): void {
                $blueprint->dropUnique("{$table}_asset_id_unique");
            });
        }
    }
};
