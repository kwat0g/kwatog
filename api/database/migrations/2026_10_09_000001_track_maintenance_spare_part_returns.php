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
        Schema::table('spare_part_usage', function (Blueprint $table): void {
            $table->uuid('issue_group_key')->nullable()->index();
            $table->decimal('returned_quantity', 15, 3)->default(0);
            $table->decimal('returned_cost', 15, 2)->default(0);
        });
    }

    public function down(): void
    {
        if (DB::table('spare_part_usage')->where('returned_quantity', '>', 0)->exists()) {
            throw new RuntimeException('Cannot drop maintenance return balances after parts have been returned.');
        }
        Schema::table('spare_part_usage', function (Blueprint $table): void {
            $table->dropColumn(['issue_group_key', 'returned_quantity', 'returned_cost']);
        });
    }
};
