<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_complaints', function (Blueprint $table) {
            $table->timestamp('d3_due_at')->nullable()->after('closed_at');
            $table->timestamp('d4_due_at')->nullable()->after('d3_due_at');
            $table->timestamp('finalize_due_at')->nullable()->after('d4_due_at');
            $table->jsonb('sla_alert_levels')->nullable()->after('finalize_due_at');
            $table->index(['status', 'd3_due_at'], 'ix_complaints_d3_sla');
            $table->index(['status', 'd4_due_at'], 'ix_complaints_d4_sla');
            $table->index(['status', 'finalize_due_at'], 'ix_complaints_fin_sla');
        });
    }

    public function down(): void
    {
        Schema::table('customer_complaints', function (Blueprint $table) {
            $table->dropIndex('ix_complaints_fin_sla');
            $table->dropIndex('ix_complaints_d4_sla');
            $table->dropIndex('ix_complaints_d3_sla');
            $table->dropColumn(['d3_due_at', 'd4_due_at', 'finalize_due_at', 'sla_alert_levels']);
        });
    }
};
