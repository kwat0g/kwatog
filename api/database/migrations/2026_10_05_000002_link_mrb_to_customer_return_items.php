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
        Schema::table('material_review_records', function (Blueprint $table): void {
            $table->dropForeign(['source_location_id']);
            $table->unsignedBigInteger('source_location_id')->nullable()->change();
            $table->foreign('source_location_id')
                ->references('id')->on('warehouse_locations')->restrictOnDelete();

            $table->foreignId('return_request_item_id')->nullable()->after('inspection_id')
                ->constrained('return_request_items')->restrictOnDelete();
            $table->unique('return_request_item_id');
        });
    }

    public function down(): void
    {
        // Older MRBs require a source location. Preserve new return MRBs by
        // using their quarantine bin as the source when rolling this migration back.
        DB::table('material_review_records')
            ->whereNull('source_location_id')
            ->update(['source_location_id' => DB::raw('quarantine_location_id')]);

        Schema::table('material_review_records', function (Blueprint $table): void {
            $table->dropForeign(['return_request_item_id']);
            $table->dropUnique(['return_request_item_id']);
            $table->dropColumn('return_request_item_id');

            $table->dropForeign(['source_location_id']);
            $table->unsignedBigInteger('source_location_id')->nullable(false)->change();
            $table->foreign('source_location_id')
                ->references('id')->on('warehouse_locations')->restrictOnDelete();
        });
    }
};
