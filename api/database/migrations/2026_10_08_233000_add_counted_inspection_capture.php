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
        Schema::table('inspections', function (Blueprint $table): void {
            $table->jsonb('counted_capture')->nullable();
            $table->timestamp('coverage_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('inspections')->whereNotNull('counted_capture')->exists()) {
            throw new RuntimeException('Counted inspection evidence exists. Preserve the additive schema during application rollback.');
        }
        Schema::table('inspections', fn (Blueprint $table) => $table->dropColumn(['counted_capture', 'coverage_confirmed_at']));
    }
};
