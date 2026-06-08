<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * C-1 — Wire revenue_account_id end-to-end for auto-invoice on delivery confirm.
 *
 * Adds a per-product override for the GL revenue account used when the SupplyChain
 * delivery-confirm flow auto-creates a draft invoice. DeliveryService falls back
 * to the setting `accounting.default_sales_revenue_account_code` when this is NULL.
 *
 * Nullable + nullOnDelete so the product master is never blocked by a missing or
 * removed account, and so existing rows backfill to NULL safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasTable('accounts')) {
            return;
        }

        if (Schema::hasColumn('products', 'revenue_account_id')) {
            return;
        }

        Schema::table('products', function (Blueprint $t) {
            $t->unsignedBigInteger('revenue_account_id')->nullable()->after('standard_cost');
            $t->foreign('revenue_account_id')
                ->references('id')->on('accounts')
                ->nullOnDelete();
            $t->index('revenue_account_id', 'products_revenue_account_id_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'revenue_account_id')) {
            return;
        }

        Schema::table('products', function (Blueprint $t) {
            try {
                $t->dropForeign(['revenue_account_id']);
            } catch (\Throwable $e) {
                // FK may have already been dropped by a partial rerun.
            }
            try {
                $t->dropIndex('products_revenue_account_id_idx');
            } catch (\Throwable $e) {
                // Index may have already been dropped.
            }
            $t->dropColumn('revenue_account_id');
        });
    }
};
