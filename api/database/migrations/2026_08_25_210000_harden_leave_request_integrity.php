<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->json('attendance_snapshot')->nullable()->after('document_path');
            $table->foreignId('cancelled_by')->nullable()->after('hr_approved_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn(['attendance_snapshot', 'cancelled_by', 'cancelled_at']);
        });
    }
};
