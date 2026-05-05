<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task A8 — Critical-stock auto-PO. Distinguishes auto-created POs that
 * skipped the normal 4-level PR workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->boolean('is_auto_generated')->default(false)->after('status');
            $table->index('is_auto_generated');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex(['is_auto_generated']);
            $table->dropColumn('is_auto_generated');
        });
    }
};
