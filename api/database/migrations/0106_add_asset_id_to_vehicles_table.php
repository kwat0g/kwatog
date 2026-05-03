<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 8 — Task 70. Add asset_id to vehicles for parity with machines/molds.
 * (machines.asset_id already exists from 0075; molds.asset_id from 0076.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            if (! Schema::hasColumn('vehicles', 'asset_id')) {
                $table->foreignId('asset_id')->nullable()->after('plate_number')->constrained('assets')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            if (Schema::hasColumn('vehicles', 'asset_id')) {
                $table->dropConstrainedForeignId('asset_id');
            }
        });
    }
};
