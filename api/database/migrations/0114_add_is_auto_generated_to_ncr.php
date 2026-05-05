<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task A6 — Auto-NCR on inspection failure. Flag distinguishes system-created
 * NCRs from manually raised ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('non_conformance_reports', function (Blueprint $table) {
            $table->boolean('is_auto_generated')->default(false)->after('status');
            $table->index('is_auto_generated');
        });
    }

    public function down(): void
    {
        Schema::table('non_conformance_reports', function (Blueprint $table) {
            $table->dropIndex(['is_auto_generated']);
            $table->dropColumn('is_auto_generated');
        });
    }
};
