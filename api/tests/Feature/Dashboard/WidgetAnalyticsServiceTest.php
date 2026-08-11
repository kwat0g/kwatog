<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Enums\RenderKind;
use App\Modules\Dashboard\Services\WidgetAnalyticsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WidgetAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
        ]);
    }

    public function test_unknown_key_returns_empty_so_caller_falls_back_to_scalar(): void
    {
        $payload = app(WidgetAnalyticsService::class)
            ->payload('no.such.widget', RenderKind::Breakdown, $this->admin);

        $this->assertSame([], $payload);
    }

    public function test_scalar_kind_short_circuits(): void
    {
        $payload = app(WidgetAnalyticsService::class)
            ->payload('qc.pareto', RenderKind::Scalar, $this->admin);

        $this->assertSame([], $payload);
    }

    public function test_breakdown_payload_has_total_and_toned_segments(): void
    {
        $payload = app(WidgetAnalyticsService::class)
            ->payload('qc.pareto', RenderKind::Breakdown, $this->admin);

        $this->assertArrayHasKey('total', $payload);
        $this->assertArrayHasKey('segments', $payload);
        foreach ($payload['segments'] as $segment) {
            $this->assertArrayHasKey('label', $segment);
            $this->assertArrayHasKey('value', $segment);
            $this->assertContains($segment['tone'], ['neutral', 'info', 'success', 'warning', 'danger']);
        }
    }

    public function test_trend_payload_points_are_chronological(): void
    {
        $payload = app(WidgetAnalyticsService::class)
            ->payload('production.kpi', RenderKind::Trend, $this->admin);

        $this->assertArrayHasKey('points', $payload);
        $this->assertContains($payload['kind'], ['count', 'currency', 'percent', 'hours']);
        $labels = array_column($payload['points'], 'label');
        $sorted = $labels;
        sort($sorted);
        $this->assertSame($sorted, $labels, 'trend points must be oldest-first');
    }

    public function test_gauge_payload_is_bounded(): void
    {
        $payload = app(WidgetAnalyticsService::class)
            ->payload('oee.gauges', RenderKind::Gauge, $this->admin);

        $this->assertGreaterThanOrEqual($payload['min'], $payload['value']);
        $this->assertLessThanOrEqual($payload['max'], $payload['value']);
    }

    /**
     * A widget must never fail its whole dashboard. A provider that throws is
     * reported as an empty payload so the tile degrades to a scalar.
     */
    public function test_provider_failure_degrades_instead_of_throwing(): void
    {
        Schema::drop('inspection_measurements');

        $payload = app(WidgetAnalyticsService::class)
            ->payload('qc.pareto', RenderKind::Breakdown, $this->admin);

        $this->assertSame([], $payload);
    }
}
