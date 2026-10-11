<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\Product;
use App\Modules\Dashboard\Services\Analytics\AnalyticsCache;
use App\Modules\Dashboard\Services\Analytics\AnalyticsService;
use App\Modules\Dashboard\Services\Analytics\Domains\DomainMetric;
use App\Modules\Dashboard\Services\Analytics\Domains\ReturnsPipelineAnalytics;
use App\Modules\Dashboard\Support\Analytics\AnalyticsPeriod;
use App\Modules\Dashboard\Support\Analytics\MetricDefinition;
use App\Modules\Dashboard\Support\Analytics\MetricRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 2 — the analytics layer's contract.
 *
 * The layer exists to stop three specific things from happening again, so the
 * tests are written against those: a payload reaching a caller whose module
 * would refuse it (gate), an aggregate recomputed on every poll (cache), and a
 * definition living only in prose (registry ↔ schema ↔ generated doc).
 */
class AnalyticsLayerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $slug)->value('id'),
            'email' => 'an+'.substr(uniqid(), -8).'@t.test',
        ]);
    }

    // ─── Dictionary visibility (permission-derived, never a role name) ────────

    public function test_catalog_returns_only_the_metrics_the_caller_may_read(): void
    {
        // warehouse_staff holds return_management.view but not production.dashboard.view.
        $warehouse = $this->userWithRole('warehouse_staff');
        $production = $this->userWithRole('production_manager');

        $codeList = fn (array $response): array => array_column($response['data'], 'code');

        $warehouseCodes = $this->actingAs($warehouse)
            ->getJson('/api/v1/dashboard/metrics')
            ->assertOk()
            ->json();
        $productionCodes = $this->actingAs($production)
            ->getJson('/api/v1/dashboard/metrics')
            ->assertOk()
            ->json();

        $this->assertSame(['returns.pipeline'], $codeList($warehouseCodes));
        $this->assertSame(
            ['production.wo_lead_time', 'returns.pipeline'],
            $codeList($productionCodes),
        );
        $this->assertSame(1, $warehouseCodes['meta']['count']);
        $this->assertSame('docs/dashboards/METRICS.md', $warehouseCodes['meta']['document']);
        $this->assertNotEmpty($warehouseCodes['meta']['fingerprint']);
    }

    public function test_catalog_can_be_filtered_by_module(): void
    {
        $payload = $this->actingAs($this->userWithRole('production_manager'))
            ->getJson('/api/v1/dashboard/metrics?module=returns')
            ->assertOk()
            ->json();

        $this->assertSame(['returns.pipeline'], array_column($payload['data'], 'code'));
    }

    public function test_unknown_metric_is_not_found_and_ungated_metric_is_forbidden(): void
    {
        $warehouse = $this->userWithRole('warehouse_staff');
        $employee = $this->userWithRole('employee');

        $this->actingAs($warehouse)
            ->getJson('/api/v1/dashboard/metrics/returns.nope')
            ->assertNotFound();

        // Exists, but the caller's module refuses it.
        $this->actingAs($warehouse)
            ->getJson('/api/v1/dashboard/metrics/production.wo_lead_time')
            ->assertForbidden();

        $this->actingAs($employee)
            ->getJson('/api/v1/dashboard/metrics/returns.pipeline')
            ->assertForbidden();
    }

    public function test_service_refuses_a_caller_without_the_gate(): void
    {
        $metric = app(ReturnsPipelineAnalytics::class);

        $this->expectException(AuthorizationException::class);

        $metric->resolve($this->userWithRole('employee'), AnalyticsPeriod::preset('month'));
    }

    // ─── Envelope + periods ──────────────────────────────────────────────────

    public function test_payload_carries_the_standard_envelope(): void
    {
        $response = $this->actingAs($this->userWithRole('warehouse_staff'))
            ->getJson('/api/v1/dashboard/metrics/returns.pipeline')
            ->assertOk()
            ->json();

        $this->assertSame('returns.pipeline', $response['data']['definition']['code']);
        $this->assertSame('return_management.view', $response['data']['definition']['permission']);
        $this->assertArrayHasKey('total', $response['data']['value']);

        $meta = $response['meta'];
        $this->assertSame('returns.pipeline', $meta['metric']);
        $this->assertSame('month', $meta['period']['preset']);
        $this->assertSame('company', $meta['scope']);
        $this->assertSame('live', $meta['refresh']);
        $this->assertGreaterThan(0, $meta['cache_ttl']);
        $this->assertNotEmpty($meta['generated_at']);
        $this->assertNotEmpty($meta['sources']);
        $this->assertNotEmpty($meta['indexes']);
        $this->assertNotEmpty($meta['cache_key']);
        $this->assertFalse($meta['cached'], 'First read must not claim a cache hit.');
        $this->assertSame(config('app.timezone'), $meta['period']['timezone']);
    }

    public function test_period_presets_and_custom_windows_carry_a_comparison_window(): void
    {
        $user = $this->userWithRole('warehouse_staff');

        $week = $this->actingAs($user)
            ->getJson('/api/v1/dashboard/metrics/returns.pipeline?period=week')
            ->assertOk()
            ->json('meta.period');

        $this->assertSame('week', $week['preset']);
        $this->assertSame(7, $week['days']);
        // The previous window is the same length, immediately before.
        $this->assertSame(
            Carbon::parse($week['from'])->subDays(7)->toDateString(),
            $week['compared_from'],
        );

        $custom = $this->actingAs($user)
            ->getJson('/api/v1/dashboard/metrics/returns.pipeline?period=custom&from=2026-09-01&to=2026-09-10')
            ->assertOk()
            ->json('meta.period');

        $this->assertSame('custom', $custom['preset']);
        $this->assertSame('2026-09-01', $custom['from']);
        $this->assertSame('2026-09-10', $custom['to']);
        $this->assertSame(10, $custom['days']);

        $this->actingAs($user)
            ->getJson('/api/v1/dashboard/metrics/returns.pipeline?period=fortnight')
            ->assertStatus(422);

        $this->actingAs($user)
            ->getJson('/api/v1/dashboard/metrics/returns.pipeline?period=custom&from=2026-09-01')
            ->assertStatus(422);
    }

    // ─── Cache: per scope + filters, with explicit invalidation ──────────────

    public function test_repeat_read_is_served_from_cache_and_flush_invalidates_it(): void
    {
        $user = $this->userWithRole('warehouse_staff');
        $url = '/api/v1/dashboard/metrics/returns.pipeline';

        $this->actingAs($user)->getJson($url)->assertOk()->json();

        $second = $this->actingAs($user)->getJson($url)->assertOk()->json();
        $this->assertTrue($second['meta']['cached'], 'A repeat read must be a cache hit.');

        app(AnalyticsCache::class)->flush('returns.pipeline');

        $third = $this->actingAs($user)->getJson($url)->assertOk()->json();
        $this->assertFalse($third['meta']['cached'], 'flush() must retire the cached payload.');
        $this->assertNotSame($second['meta']['cache_key'], $third['meta']['cache_key']);
    }

    public function test_cache_key_is_scoped_by_scope_and_filters(): void
    {
        $registry = app(MetricRegistry::class);
        $cache = app(AnalyticsCache::class);
        $definition = $registry->get('returns.pipeline');
        $this->assertInstanceOf(MetricDefinition::class, $definition);

        $company = $cache->key($definition, 'company', ['from' => '2026-09-01', 'to' => '2026-09-30']);
        $department = $cache->key($definition, 'department:7', ['from' => '2026-09-01', 'to' => '2026-09-30']);
        $otherWindow = $cache->key($definition, 'company', ['from' => '2026-08-01', 'to' => '2026-08-31']);
        $otherFilter = $cache->key($definition, 'company', ['from' => '2026-09-01', 'to' => '2026-09-30', 'machine_id' => 3]);

        $this->assertNotSame($company, $department, 'A department reading must not reuse the company entry.');
        $this->assertNotSame($company, $otherWindow);
        $this->assertNotSame($company, $otherFilter);
        $this->assertSame($company, $cache->key($definition, 'company', ['to' => '2026-09-30', 'from' => '2026-09-01']));
    }

    public function test_domain_metric_defaults_to_company_scope_and_can_be_department_scoped(): void
    {
        $user = $this->userWithRole('warehouse_staff');
        $registry = app(MetricRegistry::class);
        $cache = app(AnalyticsCache::class);

        $companyWide = app(ReturnsPipelineAnalytics::class);
        $this->assertSame('company', $companyWide->scopeLabel($user));

        $departmentScoped = new class($registry, $cache) extends DomainMetric
        {
            public function code(): string
            {
                return 'returns.pipeline';
            }

            public function scopeId(User $user): ?int
            {
                return 7;
            }

            protected function build(User $user, AnalyticsPeriod $period, array $filters): array
            {
                return ['total' => 0];
            }
        };

        $this->assertSame('department:7', $departmentScoped->scopeLabel($user));
    }

    // ─── SQL aggregation (no PHP loops) ──────────────────────────────────────

    public function test_returns_pipeline_aggregates_by_status_in_sql(): void
    {
        $now = Carbon::now();
        $this->insertReturn('XX-T-'.substr(uniqid(), -5), 'pending_approval', $now);
        $this->insertReturn('XX-T-'.substr(uniqid(), -5), 'pending_approval', $now->copy()->subDays(9));
        $this->insertReturn('XX-T-'.substr(uniqid(), -5), 'completed', $now);

        $value = $this->actingAs($this->userWithRole('warehouse_staff'))
            ->getJson('/api/v1/dashboard/metrics/returns.pipeline')
            ->assertOk()
            ->json('data.value');

        $this->assertSame(3, $value['total']);
        $this->assertSame(2, $value['awaiting_approval']);
        $this->assertSame(2, $value['open_total'], 'Completed returns are not open.');
        $this->assertSame(9, $value['oldest_open_days']);
        $this->assertSame(0, $value['handoff']['awaiting_inspection'], 'Nothing has been received yet.');
        $this->assertSame(0, $value['handoff']['in_inspection']);

        $segments = collect($value['segments'])->keyBy('label');
        $this->assertSame(2, $segments['Pending Approval']['value']);
        $this->assertSame('warning', $segments['Pending Approval']['tone']);
        $this->assertSame('success', $segments['Completed']['tone']);

        // Type mix is aggregated in SQL too: all three rows are customer returns.
        $this->assertCount(1, $value['by_type']);
        $this->assertSame(3, $value['by_type'][0]['value']);
    }

    public function test_returns_pipeline_window_excludes_returns_outside_it(): void
    {
        $this->insertReturn('XX-T-'.substr(uniqid(), -5), 'draft', Carbon::now()->subMonths(3));

        $value = $this->actingAs($this->userWithRole('warehouse_staff'))
            ->getJson('/api/v1/dashboard/metrics/returns.pipeline?period=month')
            ->assertOk()
            ->json('data.value');

        $this->assertSame(0, $value['total']);
        $this->assertSame([], $value['segments']);
        $this->assertNull($value['oldest_open_days']);
    }

    public function test_wo_lead_time_averages_completed_orders_and_excludes_open_ones(): void
    {
        $user = $this->userWithRole('production_manager');
        $product = Product::factory()->create();

        // Anchored inside the CURRENT month (day 6) so the assertion does not
        // depend on the calendar date the suite runs on.
        $anchor = Carbon::now()->startOfMonth()->addDays(5)->setTime(12, 0);
        $this->insertWorkOrder($user, $product, $anchor->copy()->subDays(2), 10);
        $this->insertWorkOrder($user, $product, $anchor, 20);
        // Open order: no actual_end. It must not count as a zero-hour order.
        $this->insertWorkOrder($user, $product, $anchor->copy()->subDay(), null);

        $value = $this->actingAs($user)
            ->getJson('/api/v1/dashboard/metrics/production.wo_lead_time?period=month')
            ->assertOk()
            ->json('data.value');

        $this->assertSame('hours', $value['unit']);
        $this->assertSame(2, $value['completed']);
        // 10h and 20h average to 15h — cast because JSON numbers decode as int
        // when they have no fractional part.
        $this->assertEqualsWithDelta(15.0, (float) $value['avg_hours'], 0.001);
        $this->assertNull($value['target_hours'], 'No target is seeded — the metric says so rather than inventing one.');
        $this->assertCount(1, $value['points'], 'Both orders finished in the same month.');
        $this->assertEqualsWithDelta(15.0, (float) $value['points'][0]['value'], 0.001);
    }

    // ─── Registry ↔ schema ↔ generated doc ───────────────────────────────────

    public function test_every_declared_source_column_and_index_exists(): void
    {
        $registry = app(MetricRegistry::class);

        $this->assertNotEmpty($registry->all(), 'The dictionary must not be empty.');

        foreach ($registry->all() as $code => $definition) {
            $this->assertSame($code, $definition->code, 'Registry keys must be the metric codes.');

            foreach ($definition->sources as $source) {
                [$table, $column] = explode('.', $source, 2);

                $this->assertTrue(
                    Schema::hasTable($table),
                    "Metric [{$code}] declares source table [{$table}], which does not exist."
                );
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "Metric [{$code}] declares source column [{$source}], which does not exist."
                );
            }

            foreach ($definition->indexes as $index) {
                $exists = DB::table('pg_indexes')->where('indexname', $index)->exists();

                $this->assertTrue(
                    $exists,
                    "Metric [{$code}] relies on index [{$index}], which is missing — the index batch did not apply."
                );
            }

            $this->assertNotEmpty($definition->caveats, "Metric [{$code}] must state its known caveats.");
        }
    }

    public function test_dictionary_is_generated_from_the_registry_and_check_mode_detects_drift(): void
    {
        $registry = app(MetricRegistry::class);
        $path = tempnam(sys_get_temp_dir(), 'metrics').'.md';

        try {
            $this->artisan('metrics:doc', ['--output' => $path])->assertExitCode(0);

            $rendered = (string) file_get_contents($path);
            $this->assertSame($registry->renderMarkdown(), $rendered);
            $this->assertStringContainsString($registry->fingerprint(), $rendered);

            foreach ($registry->all() as $definition) {
                $this->assertStringContainsString('`'.$definition->code.'`', $rendered);
                $this->assertStringContainsString($definition->permission, $rendered);
            }

            $this->artisan('metrics:doc', ['--check' => true, '--output' => $path])->assertExitCode(0);

            file_put_contents($path, "# stale\n");
            $this->artisan('metrics:doc', ['--check' => true, '--output' => $path])->assertExitCode(1);
        } finally {
            @unlink($path);
        }
    }

    public function test_definition_rejects_a_code_without_a_module_prefix_or_sources(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MetricDefinition(
            code: 'returns',
            label: 'Broken',
            module: 'returns',
            unit: 'count',
            direction: 'neutral',
            renderKind: 'scalar',
            permission: 'return_management.view',
            queryClass: DomainMetric::class,
            aggregation: 'n/a',
            sources: ['return_requests.status'],
            indexes: [],
            refresh: 'live',
        );
    }

    public function test_cached_metric_is_flushed_globally_when_no_code_is_given(): void
    {
        $user = $this->userWithRole('production_manager');
        $url = '/api/v1/dashboard/metrics/production.wo_lead_time';

        $this->actingAs($user)->getJson($url)->assertOk();
        $this->assertTrue($this->actingAs($user)->getJson($url)->json('meta.cached'));

        app(AnalyticsService::class)->flush();

        $this->assertFalse($this->actingAs($user)->getJson($url)->json('meta.cached'));
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    private function insertReturn(string $number, string $status, Carbon $returnDate): void
    {
        DB::table('return_requests')->insert([
            'rma_number' => $number,
            'type' => 'customer_return',
            'status' => $status,
            'return_date' => $returnDate->toDateString(),
            'created_at' => $returnDate,
            'updated_at' => $returnDate,
        ]);
    }

    private function insertWorkOrder(User $user, Product $product, Carbon $end, ?int $hours): void
    {
        $start = $end->copy();
        $actualStart = $hours === null ? null : $start->copy()->subHours($hours);

        DB::table('work_orders')->insert([
            'wo_number' => 'WO-T-'.substr(uniqid(), -5),
            'product_id' => $product->id,
            'quantity_target' => 100,
            'planned_start' => $end->copy()->subDays(3)->toDateString(),
            'planned_end' => $end->copy()->toDateString(),
            'created_by' => $user->id,
            'actual_start' => $actualStart,
            'actual_end' => $hours === null ? null : $end,
            'created_at' => $end->copy()->subDays(5),
            'updated_at' => $end,
        ]);
    }
}
