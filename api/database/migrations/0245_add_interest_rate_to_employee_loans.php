<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_loans', function (Blueprint $t) {
            if (! Schema::hasColumn('employee_loans', 'government_reference_no')) {
                $t->string('government_reference_no', 50)->nullable()->after('loan_no');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employee_loans', function (Blueprint $t) {
            $t->dropColumn(['government_reference_no']);
        });
    }
};
