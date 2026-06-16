<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OGAMI-005 — incoming resin QC attribute capture.
 *
 * IATF 16949 incoming verification for injection-molding resin requires the
 * Certificate of Analysis (COA) and a moisture reading before the lot is
 * accepted into stock. These live on the GRN line so they travel with the
 * received quantity and lot number (added in OGAMI-012). All nullable so
 * existing receipts are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grn_items', function (Blueprint $table) {
            if (! Schema::hasColumn('grn_items', 'moisture_percentage')) {
                $table->decimal('moisture_percentage', 6, 3)->nullable()->after('expiry_date');
            }
            if (! Schema::hasColumn('grn_items', 'coa_document_path')) {
                $table->string('coa_document_path')->nullable()->after('moisture_percentage');
            }
            if (! Schema::hasColumn('grn_items', 'coa_verified')) {
                $table->boolean('coa_verified')->default(false)->after('coa_document_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('grn_items', function (Blueprint $table) {
            $table->dropColumn(['moisture_percentage', 'coa_document_path', 'coa_verified']);
        });
    }
};
