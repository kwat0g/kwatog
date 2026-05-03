<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 8 — Task 70 follow-up.
 *
 * The 0075_create_machines_table comment said "asset_id FK is intentionally
 * absent here; Sprint 8 Task 70 introduces it" but the column was never
 * actually added. Sprint8DemoSeeder writes back machines.asset_id, so the
 * column needs to exist. This migration finally adds it, mirroring vehicles
 * (0106) and molds (0076).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            if (! Schema::hasColumn('machines', 'asset_id')) {
                $table->foreignId('asset_id')
                    ->nullable()
                    ->after('machine_code')
                    ->constrained('assets')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            if (Schema::hasColumn('machines', 'asset_id')) {
                $table->dropConstrainedForeignId('asset_id');
            }
        });
    }
};
