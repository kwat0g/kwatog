<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_payments', function (Blueprint $table) {
            // Settlements recorded by FinalPayService carry the clearance that
            // absorbed them, so finalize's JE re-derivation can keep them
            // deducted while externally settled loans fall out (no double
            // deduction). Plain nullable column + index, mirroring payroll_id.
            $table->unsignedBigInteger('clearance_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('loan_payments', function (Blueprint $table) {
            $table->dropColumn('clearance_id');
        });
    }
};
