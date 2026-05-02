<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->boolean('has_variances')->default(false)->after('balance');
            $table->json('three_way_match_snapshot')->nullable()->after('has_variances');
            $table->boolean('three_way_overridden')->default(false)->after('three_way_match_snapshot');
            $table->foreignId('three_way_overridden_by')->nullable()->after('three_way_overridden')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('three_way_overridden_at')->nullable()->after('three_way_overridden_by');
            $table->text('three_way_override_reason')->nullable()->after('three_way_overridden_at');

            // Now PO link uses an actual FK constraint.
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders')->nullOnDelete();
            $table->index('purchase_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropForeign(['purchase_order_id']);
            $table->dropIndex(['purchase_order_id']);
            $table->dropColumn([
                'has_variances',
                'three_way_match_snapshot',
                'three_way_overridden',
                'three_way_overridden_by',
                'three_way_overridden_at',
                'three_way_override_reason',
            ]);
        });
    }
};
