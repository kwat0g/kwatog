<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->time('start_time');
            $table->time('end_time');
            $table->integer('break_minutes')->default(0);
            $table->boolean('is_night_shift')->default(false);
            $table->boolean('is_extended')->default(false);
            $table->decimal('auto_ot_hours', 3, 1)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
