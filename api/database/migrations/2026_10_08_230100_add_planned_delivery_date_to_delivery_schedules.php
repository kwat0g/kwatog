<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('delivery_schedules', fn (Blueprint $table) => $table->date('planned_delivery_date')->nullable());
    }

    public function down(): void
    {
        Schema::table('delivery_schedules', fn (Blueprint $table) => $table->dropColumn('planned_delivery_date'));
    }
};
