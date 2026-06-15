<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ncr_actions', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('performed_at');
            $table->foreignId('owner_id')->nullable()->after('due_date')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('owner_id');
            $table->foreignId('verified_by')->nullable()->after('verified_at')
                ->constrained('users')->nullOnDelete();
            $table->index(['ncr_id', 'action_type']);
        });

        Schema::table('non_conformance_reports', function (Blueprint $table) {
            $table->unsignedSmallInteger('escalation_level')->default(0)->after('status');
            $table->timestamp('last_escalated_at')->nullable()->after('escalation_level');
            $table->foreignId('recurrence_of_ncr_id')->nullable()->after('last_escalated_at')
                ->constrained('non_conformance_reports')->nullOnDelete();
            $table->foreignId('rework_work_order_id')->nullable()->after('replacement_work_order_id')
                ->constrained('work_orders')->nullOnDelete();
            $table->index(['status', 'severity', 'last_escalated_at'], 'ix_ncr_open_severity');
            $table->index(['product_id', 'created_at'], 'ix_ncr_recurrence');
        });
    }

    public function down(): void
    {
        Schema::table('non_conformance_reports', function (Blueprint $table) {
            $table->dropIndex('ix_ncr_recurrence');
            $table->dropIndex('ix_ncr_open_severity');
            $table->dropConstrainedForeignId('rework_work_order_id');
            $table->dropConstrainedForeignId('recurrence_of_ncr_id');
            $table->dropColumn(['escalation_level', 'last_escalated_at']);
        });
        Schema::table('ncr_actions', function (Blueprint $table) {
            $table->dropIndex(['ncr_id', 'action_type']);
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn('verified_at');
            $table->dropConstrainedForeignId('owner_id');
            $table->dropColumn('due_date');
        });
    }
};
