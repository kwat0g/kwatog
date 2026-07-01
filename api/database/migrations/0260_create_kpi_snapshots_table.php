<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('definition_id')->constrained('kpi_definitions')->cascadeOnDelete();
            $table->integer('period_year');
            $table->integer('period_month');
            $table->decimal('actual_value', 15, 4);
            $table->decimal('target_value', 15, 4);
            $table->decimal('previous_value', 15, 4)->nullable();
            $table->string('trend', 10);
            $table->string('status', 20);
            $table->json('breakdown')->nullable();
            $table->timestamp('computed_at');
            $table->timestamp('created_at')->nullable();

            $table->unique(['definition_id', 'period_year', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_snapshots');
    }
};
