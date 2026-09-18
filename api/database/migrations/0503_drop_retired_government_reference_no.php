<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employee_loans', 'government_reference_no')) {
            Schema::table('employee_loans', function (Blueprint $table): void {
                $table->dropColumn('government_reference_no');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('employee_loans', 'government_reference_no')) {
            Schema::table('employee_loans', function (Blueprint $table): void {
                $table->string('government_reference_no', 50)->nullable()->after('loan_no');
            });
        }
    }
};
