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
        if (! Schema::hasTable('activity_events')) {
            return;
        }

        Schema::table('activity_events', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable()->unique();
        });

        // Activity events are operational evidence. PostgreSQL enforces the
        // append-only decision even when a raw query bypasses Eloquent.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS activity_events_prevent_update ON activity_events;
DROP TRIGGER IF EXISTS activity_events_prevent_delete ON activity_events;

CREATE OR REPLACE FUNCTION prevent_activity_event_modification()
RETURNS TRIGGER AS $$
BEGIN
    RAISE EXCEPTION 'Activity events are immutable.';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER activity_events_prevent_update
BEFORE UPDATE ON activity_events
FOR EACH ROW EXECUTE FUNCTION prevent_activity_event_modification();

CREATE TRIGGER activity_events_prevent_delete
BEFORE DELETE ON activity_events
FOR EACH ROW EXECUTE FUNCTION prevent_activity_event_modification();
SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql' && Schema::hasTable('activity_events')) {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS activity_events_prevent_update ON activity_events;
DROP TRIGGER IF EXISTS activity_events_prevent_delete ON activity_events;
DROP FUNCTION IF EXISTS prevent_activity_event_modification();
SQL);
        }

        if (Schema::hasTable('activity_events') && Schema::hasColumn('activity_events', 'idempotency_key')) {
            Schema::table('activity_events', function (Blueprint $table): void {
                $table->dropColumn('idempotency_key');
            });
        }
    }
};
