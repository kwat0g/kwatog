<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->timestamp('processing_started_at')->nullable()->after('status');
            $table->foreignId('force_unlocked_by')->nullable()->after('disbursed_by')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropColumn('processing_started_at');
            $table->dropConstrainedForeignId('force_unlocked_by');
        });
    }
};