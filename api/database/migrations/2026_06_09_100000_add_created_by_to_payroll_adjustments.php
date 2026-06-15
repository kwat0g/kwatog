<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_adjustments', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('reason')->constrained('users');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_adjustments', function (Blueprint $table) {
            $table->dropColumn('created_by');
        });
    }
};