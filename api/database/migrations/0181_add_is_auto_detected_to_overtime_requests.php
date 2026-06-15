<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->boolean('is_auto_detected')->default(false)->after('rejection_reason');
            $table->index(['is_auto_detected', 'status'], 'ot_auto_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('overtime_requests', function (Blueprint $table) {
            $table->dropIndex('ot_auto_status_idx');
            $table->dropColumn('is_auto_detected');
        });
    }
};
