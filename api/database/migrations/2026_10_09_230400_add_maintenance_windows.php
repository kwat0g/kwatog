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
        Schema::table('maintenance_schedules', function (Blueprint $table): void {
            $table->unsignedSmallInteger('estimated_duration_minutes')->default(60);
        });
        DB::statement('ALTER TABLE maintenance_schedules ADD CONSTRAINT maintenance_duration_valid CHECK (estimated_duration_minutes BETWEEN 15 AND 480)');
        Schema::create('maintenance_windows', function (Blueprint $table): void {
            $table->id();
            $table->string('maintainable_type', 20);
            $table->unsignedBigInteger('maintainable_id');
            $table->foreignId('work_order_id')->constrained('maintenance_work_orders');
            $table->timestamp('planned_start_at');
            $table->timestamp('planned_end_at');
            $table->string('state', 20)->default('proposed');
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['maintainable_type', 'maintainable_id', 'state']);
        });
        DB::statement("ALTER TABLE maintenance_windows ADD CONSTRAINT maintenance_window_valid CHECK (planned_start_at < planned_end_at AND maintainable_type IN ('machine', 'mold') AND state IN ('proposed', 'confirmed', 'released') AND (state <> 'confirmed' OR (confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL)))");
        DB::statement("CREATE UNIQUE INDEX maintenance_window_one_confirmed ON maintenance_windows (work_order_id) WHERE state = 'confirmed'");
        DB::statement("ALTER TABLE maintenance_windows ADD CONSTRAINT maintenance_windows_no_overlap EXCLUDE USING gist (maintainable_type WITH =, maintainable_id WITH =, tsrange(planned_start_at, planned_end_at, '[)') WITH &&) WHERE (state = 'confirmed')");
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_windows');
        Schema::table('maintenance_schedules', function (Blueprint $table): void {
            $table->dropColumn('estimated_duration_minutes');
        });
    }
};
