<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table): void {
            $table->foreignId('cancelled_by')
                ->nullable()
                ->after('rejection_reason')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable()->after('cancelled_by');
            $table->index(['status', 'cancelled_at'], 'ot_status_cancelled_idx');
        });
    }

    public function down(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table): void {
            $table->dropIndex('ot_status_cancelled_idx');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn('cancelled_at');
        });
    }
};
