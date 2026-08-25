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
        Schema::table('grn_items', function (Blueprint $table): void {
            $table->string('received_uom_code', 20)->nullable()->after('location_id');
        });

        Schema::table('material_issue_slip_items', function (Blueprint $table): void {
            $table->string('issued_uom_code', 20)->nullable()->after('location_id');
            $table->string('lot_number', 50)->nullable()->after('issued_uom_code');
            $table->index(['item_id', 'lot_number'], 'mis_items_item_lot_index');
        });

        // Negative stock is not supported by the central ledger. Retire the
        // misleading admin setting rather than leaving an editable no-op.
        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('key', 'inventory.allow_negative')->delete();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('settings')) {
            DB::table('settings')->insertOrIgnore([
                'key' => 'inventory.allow_negative',
                'value' => json_encode(false),
                'group' => 'inventory',
                'label' => 'Allow Negative Stock',
                'description' => 'Retired: the inventory ledger never permits negative stock.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('material_issue_slip_items', function (Blueprint $table): void {
            $table->dropIndex('mis_items_item_lot_index');
            $table->dropColumn(['issued_uom_code', 'lot_number']);
        });
        Schema::table('grn_items', function (Blueprint $table): void {
            $table->dropColumn('received_uom_code');
        });
    }
};
