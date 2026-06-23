<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CAPA Effectiveness Loop (IATF 16949 §10.2.1) — activate effectiveness tracking
 * on NCR actions. The verified_at/verified_by/due_date/owner_id columns already
 * exist on ncr_actions but were never populated; these add the verification
 * lifecycle fields plus an NCR-level rollup.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ncr_actions', function (Blueprint $table) {
            $table->string('effectiveness_status', 25)->nullable()->after('verified_by');
            $table->timestamp('effectiveness_checked_at')->nullable()->after('effectiveness_status');
            $table->text('effectiveness_notes')->nullable()->after('effectiveness_checked_at');
            $table->unsignedInteger('effectiveness_check_count')->default(0)->after('effectiveness_notes');
            $table->date('next_effectiveness_check_at')->nullable()->after('effectiveness_check_count');
            $table->index('next_effectiveness_check_at', 'ncr_actions_next_check_idx');
        });

        Schema::table('non_conformance_reports', function (Blueprint $table) {
            $table->string('effectiveness_status', 25)->nullable()->after('closed_at');
            $table->timestamp('effectiveness_closed_at')->nullable()->after('effectiveness_status');
        });
    }

    public function down(): void
    {
        Schema::table('ncr_actions', function (Blueprint $table) {
            $table->dropIndex('ncr_actions_next_check_idx');
            $table->dropColumn([
                'effectiveness_status', 'effectiveness_checked_at', 'effectiveness_notes',
                'effectiveness_check_count', 'next_effectiveness_check_at',
            ]);
        });
        Schema::table('non_conformance_reports', function (Blueprint $table) {
            $table->dropColumn(['effectiveness_status', 'effectiveness_closed_at']);
        });
    }
};
