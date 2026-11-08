<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $table): void {
            $table->jsonb('quantity_result')->nullable();
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('inspections')->whereNotNull('quantity_result')->exists()) {
            throw new \RuntimeException('Cannot remove recorded quantity inspection evidence.');
        }
        Schema::table('inspections', fn (Blueprint $table) => $table->dropColumn('quantity_result'));
    }
};
