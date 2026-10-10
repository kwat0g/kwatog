<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $t) {
            $t->unsignedInteger('minimum_workers')->default(1);
            $t->unsignedInteger('maximum_workers')->nullable();
        });
        DB::statement('ALTER TABLE work_orders ADD CONSTRAINT wo_staffing_bounds CHECK (minimum_workers >= 1 AND (maximum_workers IS NULL OR maximum_workers >= minimum_workers))');
        Schema::create('work_order_crews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('work_order_id')->constrained()->restrictOnDelete();
            $t->foreignId('rework_ncr_id')->nullable()->constrained('non_conformance_reports')->restrictOnDelete();
            $t->foreignId('recorder_employee_id')->constrained('employees')->restrictOnDelete();
            $t->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->timestampTz('assigned_at');
            $t->timestampTz('ended_at')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX wo_current_production_crew ON work_order_crews (work_order_id) WHERE ended_at IS NULL AND rework_ncr_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX wo_current_repair_crew ON work_order_crews (work_order_id, rework_ncr_id) WHERE ended_at IS NULL AND rework_ncr_id IS NOT NULL');
        Schema::create('work_order_crew_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('work_order_crew_id')->constrained()->restrictOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->jsonb('employee_snapshot');
            $t->unique(['work_order_crew_id', 'employee_id']);
        });
        Schema::table('work_order_outputs', function (Blueprint $t) {
            $t->foreignId('work_order_crew_id')->nullable()->constrained()->restrictOnDelete();
            $t->jsonb('crew_snapshot')->nullable();
            $t->text('recording_reason')->nullable();
        });
        $permission = DB::table('permissions')->where('slug', 'production.wo.assign')->value('id');
        if (! $permission) {
            $permission = DB::table('permissions')->insertGetId(['slug' => 'production.wo.assign', 'name' => 'Assign work-order crew',
                'module' => 'production', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (DB::table('roles')->whereIn('slug', ['ppc_head', 'production_manager'])->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permission]);
            Cache::forget("auth:role_perms:{$roleId}");
        }
    }

    public function down(): void
    {
        // Crew history is production evidence. Application rollback keeps additive schema.
    }
};
