<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OGAMI-104 — Link ReturnManagement RMA flow to Quality inspections and NCRs.
 *
 * Adds nullable FK columns so that when an RMA is inspected, the resulting
 * Quality Inspection (and any NCR from a failed inspection) are traced back
 * to the originating ReturnRequest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->foreignId('inspection_id')->nullable()->after('inspected_at')
                ->constrained('inspections')->nullOnDelete();

            $table->foreignId('ncr_id')->nullable()->after('inspection_id')
                ->constrained('non_conformance_reports')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('return_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ncr_id');
            $table->dropConstrainedForeignId('inspection_id');
        });
    }
};
