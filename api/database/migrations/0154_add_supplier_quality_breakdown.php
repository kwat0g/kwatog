<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADV7 — Add QC quality breakdown columns to supplier performance snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_performance_snapshots', function (Blueprint $table) {
            $table->decimal('incoming_quality_rate', 5, 2)->nullable()->after('quality_pass_rate');
            $table->decimal('in_process_quality_rate', 5, 2)->nullable()->after('incoming_quality_rate');
            $table->decimal('outgoing_quality_rate', 5, 2)->nullable()->after('in_process_quality_rate');
            $table->decimal('ncr_rate', 5, 2)->nullable()->after('outgoing_quality_rate');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_performance_snapshots', function (Blueprint $table) {
            $table->dropColumn([
                'incoming_quality_rate',
                'in_process_quality_rate',
                'outgoing_quality_rate',
                'ncr_rate',
            ]);
        });
    }
};
