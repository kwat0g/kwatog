<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * T3.3.A — add A/B/C/D tier letter to supplier_performance_snapshots.
 *
 * Nullable on purpose: legacy snapshots written before T3.3 stay NULL until
 * the next monthly recompute populates them. SPA renders NULL as '—'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_performance_snapshots', function (Blueprint $table) {
            $table->string('tier', 1)->nullable()->after('overall_score');
            $table->index(['period_year', 'period_month', 'tier'], 'ix_supplier_perf_period_tier');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_performance_snapshots', function (Blueprint $table) {
            $table->dropIndex('ix_supplier_perf_period_tier');
            $table->dropColumn('tier');
        });
    }
};
