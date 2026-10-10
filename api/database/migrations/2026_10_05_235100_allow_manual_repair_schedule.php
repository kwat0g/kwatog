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
        Schema::table('production_schedules', function (Blueprint $table): void {
            $table->unsignedBigInteger('machine_id')->nullable()->change();
            $table->unsignedBigInteger('mold_id')->nullable()->change();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE production_schedules ADD CONSTRAINT production_schedule_equipment_context CHECK ((machine_id IS NOT NULL AND mold_id IS NOT NULL) OR (rework_ncr_id IS NOT NULL AND machine_id IS NULL AND mold_id IS NULL))');

        }
    }

    public function down(): void
    {
        if (DB::table('production_schedules')->whereNull('machine_id')->orWhereNull('mold_id')->exists()) {
            throw new RuntimeException('Manual repair schedule history exists. Export and resolve it before restoring required equipment columns.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE production_schedules DROP CONSTRAINT IF EXISTS production_schedule_equipment_context');
        }
        Schema::table('production_schedules', function (Blueprint $table): void {
            $table->unsignedBigInteger('machine_id')->nullable(false)->change();
            $table->unsignedBigInteger('mold_id')->nullable(false)->change();
        });
    }
};
