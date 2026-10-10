<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('non_conformance_reports', function (Blueprint $table): void {
            $table->unsignedInteger('recovery_batch_quantity')->nullable();
            $table->unsignedInteger('previously_released_quantity')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('non_conformance_reports')->where(function ($query): void {
            $query->whereNotNull('recovery_batch_quantity')->orWhereNotNull('previously_released_quantity');
        })->exists()) {
            throw new RuntimeException('Cannot remove QC recovery quantity snapshots while recovery evidence exists. Preserve the audit trail.');
        }

        Schema::table('non_conformance_reports', fn (Blueprint $table) => $table->dropColumn(['recovery_batch_quantity', 'previously_released_quantity']));
    }
};
