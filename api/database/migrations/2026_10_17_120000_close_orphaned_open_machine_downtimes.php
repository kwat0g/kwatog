<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $orphanWorkOrderIds = DB::table('work_orders')
            ->where('status', 'cancelled')
            ->orWhereNotNull('deleted_at')
            ->pluck('id');

        if ($orphanWorkOrderIds->isEmpty()) {
            return;
        }

        DB::table('machine_downtimes')
            ->whereNull('end_time')
            ->whereIn('work_order_id', $orphanWorkOrderIds)
            ->update([
                'end_time' => now(),
                'duration_minutes' => DB::raw('GREATEST(0, ROUND(EXTRACT(EPOCH FROM (now() - start_time)) / 60))'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Data repair only; closed rows are not reopened.
    }
};
