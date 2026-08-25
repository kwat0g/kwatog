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
            $table->decimal('running_hours_baseline', 10, 2)->nullable()->after('interval_value');
        });

        DB::table('maintenance_schedules')
            ->where('maintainable_type', 'machine')
            ->where('interval_type', 'hours')
            ->update(['next_due_at' => null]);

        DB::table('maintenance_schedules')
            ->where('maintainable_type', 'machine')
            ->where('interval_type', 'hours')
            ->whereNull('running_hours_baseline')
            ->get(['id', 'maintainable_id'])
            ->each(function (object $schedule): void {
                $hours = DB::table('machines')->where('id', $schedule->maintainable_id)->value('running_hours_total');
                if ($hours !== null) {
                    DB::table('maintenance_schedules')
                        ->where('id', $schedule->id)
                        ->update(['running_hours_baseline' => $hours]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('maintenance_schedules', function (Blueprint $table): void {
            $table->dropColumn('running_hours_baseline');
        });
    }
};
