<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Enums\RenderKind;
use App\Modules\Dashboard\Models\DashboardWidget;
use App\Modules\Dashboard\Services\Analytics\AssetWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\BudgetWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\CoreWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\CrmWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\LoanWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\ReturnWidgetAnalytics;
use App\Modules\Dashboard\Services\Analytics\SupplyChainWidgetAnalytics;
use Database\Seeders\DashboardRoleLayoutSeeder;
use Database\Seeders\DashboardWidgetSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WidgetSeedIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(DashboardWidgetSeeder::class);
    }

    /** Every key any provider claims to serve. */
    private function handledKeys(): Collection
    {
        return collect([
            app(CoreWidgetAnalytics::class),
            app(CrmWidgetAnalytics::class),
            app(AssetWidgetAnalytics::class),
            app(SupplyChainWidgetAnalytics::class),
            app(ReturnWidgetAnalytics::class),
            app(BudgetWidgetAnalytics::class),
            app(LoanWidgetAnalytics::class),
        ])->flatMap(fn ($provider) => $provider->handles())->unique();
    }

    /**
     * A widget declaring a rich kind with no provider renders an empty tile —
     * worse than the scalar it replaced. The two lists must agree.
     */
    public function test_every_rich_widget_has_a_provider(): void
    {
        $rich = DashboardWidget::query()
            ->where('render_kind', '!=', RenderKind::Scalar->value)
            ->pluck('key');

        $orphans = $rich->diff($this->handledKeys())->values()->all();

        $this->assertSame([], $orphans, 'rich widgets with no analytics provider: '.implode(', ', $orphans));
    }

    /** Conversely, a provider for a key nobody seeds is dead code. */
    public function test_no_provider_handles_an_unseeded_key(): void
    {
        $orphans = $this->handledKeys()
            ->diff(DashboardWidget::query()->pluck('key'))
            ->values()
            ->all();

        $this->assertSame([], $orphans, 'providers serving unseeded keys: '.implode(', ', $orphans));
    }

    /**
     * Every provider-backed key must actually be seeded rich. A provider that
     * returns a breakdown for a widget still marked `scalar` never runs.
     */
    public function test_every_handled_key_is_seeded_rich(): void
    {
        $stillScalar = DashboardWidget::query()
            ->whereIn('key', $this->handledKeys()->all())
            ->where('render_kind', RenderKind::Scalar->value)
            ->pluck('key')
            ->values()
            ->all();

        $this->assertSame([], $stillScalar, 'handled but still scalar: '.implode(', ', $stillScalar));
    }

    /**
     * Widths must be a real 12-column layout, not the uniform `12` every row
     * carried while the SPA ignored the column entirely.
     */
    public function test_role_layouts_use_varied_widths(): void
    {
        $this->seed(DashboardRoleLayoutSeeder::class);

        $widths = DB::table('dashboard_layouts')
            ->where('owner_type', 'role')
            ->distinct()
            ->pluck('width');

        $this->assertGreaterThan(1, $widths->count(), 'every role row is still full-width');
        foreach ($widths as $width) {
            $this->assertContains((int) $width, [4, 6, 8, 12]);
        }
    }

    /** No role's row may overflow the 12-column grid. */
    public function test_no_role_row_exceeds_twelve_columns(): void
    {
        $this->seed(DashboardRoleLayoutSeeder::class);

        $overflows = DB::table('dashboard_layouts')
            ->where('owner_type', 'role')
            ->selectRaw('owner_id, position_y, SUM(width) AS total')
            ->groupBy('owner_id', 'position_y')
            ->havingRaw('SUM(width) > 12')
            ->get();

        $this->assertCount(0, $overflows, 'rows wider than the 12-column grid');
    }
}
