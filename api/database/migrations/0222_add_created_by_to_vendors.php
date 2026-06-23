<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OGAMI audit DEFECT-2 — activate the PO vendor-creator SoD guard.
 *
 * PurchaseOrderService::assertVendorSod() blocks a PO approver who is also the
 * creator of the PO's vendor, but it short-circuits when the vendors table has
 * no `created_by` column. This adds that column so the guard fires. Nullable +
 * nullOnDelete so historical rows (unknown maker) keep the guard inert for them.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->foreignId('created_by')
                ->nullable()
                ->after('is_active')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
