<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REC-04 — Payroll maker-checker attribution.
 *
 * Adds the actor/timestamp columns that let us record WHO computed, approved,
 * and finalized a payroll run and enforce that the person who computed a run
 * cannot also approve it. All nullable + nullOnDelete so historical periods and
 * user deletions never break the FKs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->foreignId('computed_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('computed_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->foreignId('finalized_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable()->after('finalized_by');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_periods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finalized_by');
            $table->dropColumn('finalized_at');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
            $table->dropConstrainedForeignId('computed_by');
        });
    }
};
