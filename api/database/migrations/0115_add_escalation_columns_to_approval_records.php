<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task A7 — Overdue approval escalation. Tracks reminder + escalation timing
 * so the same record is never re-pinged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_records', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('acted_at');
            $table->timestamp('escalated_at')->nullable()->after('reminder_sent_at');
            $table->foreignId('escalated_to_user_id')->nullable()->after('escalated_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['action', 'reminder_sent_at']);
            $table->index(['action', 'escalated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('approval_records', function (Blueprint $table) {
            $table->dropIndex(['action', 'escalated_at']);
            $table->dropIndex(['action', 'reminder_sent_at']);
            $table->dropForeign(['escalated_to_user_id']);
            $table->dropColumn(['reminder_sent_at', 'escalated_at', 'escalated_to_user_id']);
        });
    }
};
