<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OGAMI-008 — BIR-compliant Sales Invoice fields.
 *
 * Adds VAT classification (vatable | zero_rated | vat_exempt), a Senior/PWD
 * discount deduction line, and the buyer-TIN / ATP / serial-range / original
 * marker that BIR requires on a printed Sales Invoice. Default classification
 * is 'vatable' so existing (delivery → invoice) creation paths keep the current
 * 12% behavior with zero migration churn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // vatable | zero_rated | vat_exempt
            $table->string('vat_classification', 20)->default('vatable')->after('is_vatable');
            $table->decimal('senior_pwd_discount', 15, 2)->default(0)->after('vat_amount');
            $table->string('buyer_tin', 20)->nullable()->after('senior_pwd_discount');
            $table->string('atp_number', 50)->nullable()->after('buyer_tin');
            $table->string('serial_range', 50)->nullable()->after('atp_number');
            $table->boolean('is_original')->default(true)->after('serial_range');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'vat_classification',
                'senior_pwd_discount',
                'buyer_tin',
                'atp_number',
                'serial_range',
                'is_original',
            ]);
        });
    }
};
