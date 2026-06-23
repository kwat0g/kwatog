<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset depreciation method selection + basic insurance tracking. Default
 * 'straight_line' preserves existing behaviour for all current assets.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('depreciation_method', 25)->default('straight_line')->after('useful_life_years');
            $table->string('insurance_policy_no', 100)->nullable()->after('location');
            $table->string('insurance_provider', 150)->nullable()->after('insurance_policy_no');
            $table->date('insurance_expiry')->nullable()->after('insurance_provider');
            $table->decimal('insured_value', 15, 2)->nullable()->after('insurance_expiry');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn([
                'depreciation_method', 'insurance_policy_no',
                'insurance_provider', 'insurance_expiry', 'insured_value',
            ]);
        });
    }
};
