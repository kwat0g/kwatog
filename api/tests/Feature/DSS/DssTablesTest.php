<?php

declare(strict_types=1);

namespace Tests\Feature\DSS;

use App\Modules\DSS\Models\DssRecommendation;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DssTablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dss_tables_exist(): void
    {
        foreach ([
            'dss_recommendations', 'dss_recommendation_events', 'dss_parameters',
            'dss_forecast_runs', 'dss_scenarios', 'dss_run_logs', 'stock_level_snapshots',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table {$table}.");
        }
    }

    public function test_open_dedupe_key_blocks_second_open_row(): void
    {
        $attrs = [
            'detector_id' => 'test.detector', 'domain' => 'test', 'type' => 'test_type',
            'severity' => 'warning', 'rationale' => ['rule' => 'x'], 'dedupe_key' => 'dup-1',
        ];

        DssRecommendation::create($attrs);

        $this->expectException(QueryException::class);
        DssRecommendation::create($attrs);
    }

    public function test_terminal_row_frees_dedupe_key(): void
    {
        $attrs = [
            'detector_id' => 'test.detector', 'domain' => 'test', 'type' => 'test_type',
            'severity' => 'warning', 'rationale' => ['rule' => 'x'], 'dedupe_key' => 'dup-2',
        ];

        $first = DssRecommendation::create($attrs);
        $first->status = 'dismissed';
        $first->save();

        $second = DssRecommendation::create($attrs);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, DssRecommendation::query()->where('dedupe_key', 'dup-2')->count());
    }

    public function test_same_key_allowed_across_detectors(): void
    {
        foreach (['test.a', 'test.b'] as $detector) {
            DssRecommendation::create([
                'detector_id' => $detector, 'domain' => 'test', 'type' => 'test_type',
                'severity' => 'info', 'rationale' => ['rule' => 'x'], 'dedupe_key' => 'shared',
            ]);
        }

        $this->assertSame(2, DssRecommendation::query()->where('dedupe_key', 'shared')->count());
    }

    public function test_partial_index_definition(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Partial unique index is PostgreSQL-specific.');
        }

        $rows = DB::select(
            "SELECT indexdef FROM pg_indexes
              WHERE schemaname = current_schema()
                AND indexname = 'dss_recommendations_open_dedupe_unique'"
        );

        $this->assertCount(1, $rows);
        $definition = strtolower($rows[0]->indexdef);
        $this->assertStringContainsString('unique', $definition);
        $this->assertStringContainsString('where', $definition);
        $this->assertStringContainsString('dedupe_key', $definition);
    }

    public function test_status_is_not_mass_assignable(): void
    {
        // Mass-assignment hardening: status passed to create() throws —
        // transitions go through RecommendationService (W0-3), never create().
        $this->expectException(MassAssignmentException::class);

        DssRecommendation::create([
            'detector_id' => 'test.detector', 'domain' => 'test', 'type' => 'test_type',
            'severity' => 'warning', 'rationale' => ['rule' => 'x'], 'dedupe_key' => 'ma-1',
            'status' => 'executed',
        ]);
    }
}
