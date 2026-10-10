<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->json('scheduling_diagnostic')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', fn (Blueprint $table) => $table->dropColumn('scheduling_diagnostic'));
    }
};
