<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable scheduler evidence.
 *
 * Laravel's schedule events are process-local. These two tables preserve the
 * scheduler heartbeat and the latest execution state of every task across a
 * container restart, so a supervisor can distinguish a healthy idle scheduler
 * from a dead process, a wedged task, or a task that keeps failing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduler_tick_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 20)->index();
            $table->unsignedInteger('failed_tasks')->default(0);
            $table->unsignedInteger('exit_code')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'started_at']);
        });

        Schema::create('scheduler_task_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('task_key', 64)->index();
            $table->string('task_name', 255);
            $table->text('command')->nullable();
            $table->string('expression', 255);
            $table->string('status', 20)->index();
            $table->uuid('scheduler_tick_id')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->decimal('runtime_seconds', 10, 2)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['task_key', 'started_at']);
            $table->index(['status', 'started_at']);
            $table->foreign('scheduler_tick_id')
                ->references('id')
                ->on('scheduler_tick_runs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_task_runs');
        Schema::dropIfExists('scheduler_tick_runs');
    }
};
