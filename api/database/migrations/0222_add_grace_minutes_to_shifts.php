<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add configurable grace period to shift definitions.
 *
 * When an employee clocks in within the grace period after shift start, the
 * system treats them as on-time (tardiness = 0). Only punches after
 * shift_start + grace_minutes count as tardy.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->integer('grace_minutes')
                ->default(0)
                ->after('break_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('grace_minutes');
        });
    }
};
