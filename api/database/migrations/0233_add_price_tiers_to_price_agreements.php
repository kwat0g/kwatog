<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6 — Task TBD.
 * Add volume-based price tier support to product_price_agreements.
 * Existing rows default to pricing_method = 'flat' and continue to use price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_price_agreements', function (Blueprint $table) {
            $table->string('pricing_method', 20)
                ->default('flat')
                ->after('effective_to');
            $table->json('tiers')
                ->nullable()
                ->after('pricing_method');
        });
    }

    public function down(): void
    {
        Schema::table('product_price_agreements', function (Blueprint $table) {
            $table->dropColumn(['pricing_method', 'tiers']);
        });
    }
};
