<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dss_recommendation_events', function ($table): void {
            $table->date('viewed_on')->nullable();
        });

        $timezone = config('app.timezone');
        $start = CarbonImmutable::now($timezone)->startOfDay();
        $end = $start->addDay();
        DB::statement(<<<'SQL'
            WITH current_day_views AS (
                SELECT id, recommendation_id, actor_id,
                       row_number() OVER (PARTITION BY recommendation_id, actor_id ORDER BY id) AS position
                FROM dss_recommendation_events
                WHERE event = 'viewed' AND actor_id IS NOT NULL
                  AND created_at >= ? AND created_at < ?
            )
            UPDATE dss_recommendation_events AS events
            SET viewed_on = ?::date
            FROM current_day_views
            WHERE events.id = current_day_views.id AND current_day_views.position = 1
            SQL, [$start->utc(), $end->utc(), $start->toDateString()]);

        DB::statement("CREATE UNIQUE INDEX dss_rec_events_daily_view_unique ON dss_recommendation_events (recommendation_id, actor_id, viewed_on) WHERE event = 'viewed' AND actor_id IS NOT NULL AND viewed_on IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS dss_rec_events_daily_view_unique');
        Schema::table('dss_recommendation_events', function ($table): void {
            $table->dropColumn('viewed_on');
        });
    }
};
