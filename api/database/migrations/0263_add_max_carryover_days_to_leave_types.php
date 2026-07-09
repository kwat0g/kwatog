<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REC-10 — carry-forward cap for non-convertible leave types.
 *
 * At year-end a non-convertible leave type carries forward
 * min(remaining, max_carryover_days) and forfeits the excess. NULL means no
 * cap (carry the full remaining). Convertible types ignore this column — they
 * encash instead of carrying.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->decimal('max_carryover_days', 5, 1)->nullable()->after('conversion_rate');
        });
    }

    public function down(): void
    {
        Schema::table('leave_types', function (Blueprint $table) {
            $table->dropColumn('max_carryover_days');
        });
    }
};
