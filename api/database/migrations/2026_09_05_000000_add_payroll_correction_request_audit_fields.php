<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->foreignId('correction_requested_by')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('correction_requested_at')->nullable()->after('correction_requested_by');
            $table->text('correction_reason')->nullable()->after('correction_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('correction_requested_by');
            $table->dropColumn(['correction_requested_at', 'correction_reason']);
        });
    }
};
