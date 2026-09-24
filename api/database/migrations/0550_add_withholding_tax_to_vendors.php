<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vendors', 'withholding_tax_type')) {
            return;
        }

        Schema::table('vendors', function (Blueprint $table): void {
            $table->string('withholding_tax_type', 20)
                ->default('none')
                ->after('payment_terms_days');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table): void {
            if (Schema::hasColumn('vendors', 'withholding_tax_type')) {
                $table->dropColumn('withholding_tax_type');
            }
        });
    }
};
