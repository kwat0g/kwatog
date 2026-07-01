<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spc_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_chart_id')->constrained('spc_control_charts')->cascadeOnDelete();
            $table->foreignId('data_point_id')->constrained('spc_data_points')->cascadeOnDelete();
            $table->string('rule_code', 50);
            $table->string('severity', 20)->default('warning');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['control_chart_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spc_alerts');
    }
};
