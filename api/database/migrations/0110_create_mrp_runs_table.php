<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task A1 — MRP run history. Each row records one execution of
 * MrpEngineService::runForAllActiveSalesOrders() (scheduled or manual).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mrp_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('run_at');
            $table->string('triggered_by', 20); // scheduled | manual
            $table->foreignId('triggered_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->unsignedInteger('sales_orders_evaluated')->default(0);
            $table->unsignedInteger('shortages_found')->default(0);
            $table->unsignedInteger('prs_created')->default(0);
            $table->unsignedInteger('prs_updated')->default(0);
            $table->unsignedInteger('plans_generated')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('status', 20)->default('running'); // running | completed | failed
            $table->text('error_message')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index('run_at');
            $table->index(['status', 'run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mrp_runs');
    }
};
