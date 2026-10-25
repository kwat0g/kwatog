<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Modules\Auth\Models\User;
use RuntimeException;
use Tests\TestCase;

class DssLifecycleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_up_refuses_duplicate_active_keys_before_changing_schema_or_index(): void
    {
        $this->requirePostgres();
        $migration = require database_path('migrations/2026_10_23_140000_add_dss_lifecycle_evidence.php');
        $migration->down();
        foreach ([1, 2] as $_) {
            DB::table('dss_recommendations')->insert([
                'detector_id' => 'test.lifecycle', 'domain' => 'test', 'type' => 'test',
                'status' => 'in_progress', 'severity' => 'warning', 'rationale' => '{}',
                'dedupe_key' => 'duplicate-active-key',
            ]);
        }

        try {
            $migration->up();
            $this->fail('Expected duplicate-key refusal.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('duplicate active keys', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('dss_recommendations', 'verification_status'));
        $this->assertStringNotContainsString('in_progress', $this->dedupeIndexDefinition());
    }

    public function test_down_refuses_live_lifecycle_evidence_before_changing_schema_or_index(): void
    {
        $this->requirePostgres();
        DB::table('dss_recommendations')->insert([
            'detector_id' => 'test.lifecycle', 'domain' => 'test', 'type' => 'test',
            'status' => 'in_progress', 'severity' => 'warning', 'rationale' => '{}',
            'dedupe_key' => 'live-evidence', 'resolution_evidence' => '{"proof":"source"}',
        ]);

        $migration = require database_path('migrations/2026_10_23_140000_add_dss_lifecycle_evidence.php');

        try {
            $migration->down();
            $this->fail('Expected rollback refusal.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Cannot roll back DSS lifecycle schema', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('dss_recommendations', 'resolution_evidence'));
        $this->assertTrue(Schema::hasColumn('dss_recommendations', 'impact_currency'));
        $this->assertStringContainsString('in_progress', $this->dedupeIndexDefinition());
    }

    public function test_clean_down_preserves_legacy_rows_and_restores_old_dedupe_predicate(): void
    {
        $this->requirePostgres();
        DB::table('dss_recommendations')->insert([
            'detector_id' => 'test.lifecycle', 'domain' => 'test', 'type' => 'test',
            'severity' => 'warning', 'rationale' => '{"legacy":"kept"}',
            'dedupe_key' => 'legacy-row', 'impact_unit' => 'PHP',
        ]);

        $migration = require database_path('migrations/2026_10_23_140000_add_dss_lifecycle_evidence.php');
        $migration->down();

        $this->assertFalse(Schema::hasColumn('dss_recommendations', 'decision_code'));
        $this->assertFalse(Schema::hasColumn('dss_recommendations', 'resolution_evidence'));
        $row = DB::table('dss_recommendations')->where('dedupe_key', 'legacy-row')->first();
        $this->assertSame('PHP', $row->impact_unit);
        $this->assertSame(['legacy' => 'kept'], json_decode($row->rationale, true));
        $definition = $this->dedupeIndexDefinition();
        $this->assertStringContainsString("'open'", $definition);
        $this->assertStringContainsString("'snoozed'", $definition);
        $this->assertStringNotContainsString('in_progress', $definition);
    }

    public function test_daily_view_migration_backfills_only_one_current_local_day_view(): void
    {
        $this->requirePostgres();
        $migration = require database_path('migrations/2026_10_23_230000_add_dss_daily_view_idempotency.php');
        $migration->down();
        $recommendationId = DB::table('dss_recommendations')->insertGetId([
            'detector_id' => 'test.lifecycle', 'domain' => 'test', 'type' => 'test',
            'status' => 'accepted', 'severity' => 'warning', 'rationale' => '{}',
            'dedupe_key' => 'view-backfill-'.uniqid(),
        ]);
        $actorId = User::factory()->create()->id;
        $today = now(config('app.timezone'))->startOfDay();
        foreach ([$today->copy()->addHour(), $today->copy()->addHours(2), $today->copy()->subDay()] as $timestamp) {
            DB::table('dss_recommendation_events')->insert([
                'recommendation_id' => $recommendationId, 'event' => 'viewed', 'actor_id' => $actorId,
                'created_at' => $timestamp->utc(), 'updated_at' => $timestamp->utc(),
            ]);
        }

        $migration->up();

        $this->assertSame(1, DB::table('dss_recommendation_events')->where('recommendation_id', $recommendationId)
            ->whereNotNull('viewed_on')->count());
        $this->assertSame($today->toDateString(), DB::table('dss_recommendation_events')
            ->where('recommendation_id', $recommendationId)->whereNotNull('viewed_on')->value('viewed_on'));
        $this->assertSame(2, DB::table('dss_recommendation_events')->where('recommendation_id', $recommendationId)
            ->whereNull('viewed_on')->count());
        $this->assertSame(1, DB::select("SELECT count(*) AS count FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'dss_rec_events_daily_view_unique'")[0]->count);
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('DSS partial unique index migrations require PostgreSQL.');
        }
    }

    private function dedupeIndexDefinition(): string
    {
        $rows = DB::select("SELECT indexdef FROM pg_indexes WHERE schemaname = current_schema() AND indexname = 'dss_recommendations_open_dedupe_unique'");

        $this->assertCount(1, $rows);

        return strtolower($rows[0]->indexdef);
    }
}
