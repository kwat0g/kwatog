<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Dashboard\Enums\RenderKind;
use App\Modules\Dashboard\Models\DashboardWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RenderKindTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_render_kind_defaults_to_scalar(): void
    {
        $widget = DashboardWidget::create([
            'key' => 'test.widget',
            'name' => 'Test Widget',
            'module' => 'platform',
            'permission' => null,
        ]);

        $this->assertSame(RenderKind::Scalar, $widget->fresh()->render_kind);
    }

    public function test_widget_render_kind_round_trips(): void
    {
        $widget = DashboardWidget::create([
            'key' => 'test.trend',
            'name' => 'Test Trend',
            'module' => 'platform',
            'permission' => null,
            'render_kind' => RenderKind::Trend,
        ]);

        $this->assertSame(RenderKind::Trend, $widget->fresh()->render_kind);
    }

    /**
     * An unrecognised value must degrade to a scalar tile, never throw — a
     * stale row from a rolled-back deploy must not break every dashboard.
     */
    public function test_unknown_kind_falls_back_to_scalar(): void
    {
        $this->assertSame(RenderKind::Scalar, RenderKind::fromNullable('sparkline'));
        $this->assertSame(RenderKind::Scalar, RenderKind::fromNullable(null));
        $this->assertSame(RenderKind::Gauge, RenderKind::fromNullable('gauge'));
    }
}
