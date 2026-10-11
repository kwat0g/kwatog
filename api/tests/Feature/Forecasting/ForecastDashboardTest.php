<?php

declare(strict_types=1);

namespace Tests\Feature\Forecasting;

use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Dashboard\Services\ForecastingDashboardService;
use App\Modules\Dashboard\Services\PurchasingDashboardService;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 5 — forecasts surface inline on role dashboards, read-only.
 *
 * The supplier-risk panel is a COMPOSITION, not a new engine: stock-out rows
 * from StockOutProjectionService plus the demand MAPE/bias from
 * ForecastingService. It rides the purchasing payload behind
 * `forecasting.view` (PanelGate strips it server-side, never CSS-hidden), and
 * carries no override/mutate keys — override + MRP toggle stay on the
 * /forecasting pages.
 */
class ForecastDashboardTest extends TestCase
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
            'email' => 'fcst+'.substr(uniqid(), -8).'@t.test',
        ]);
    }

    /** @param array<int, string> $permissions */
    private function userWithPermissions(array $permissions): User
    {
        $role = Role::create([
            'name' => 'Fcst Role '.substr(uniqid(), -6),
            'slug' => 'fcst-'.substr(uniqid(), -6),
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('slug', $permissions)->pluck('id')->all(),
        );

        return User::factory()->create([
            'role_id' => $role->id,
            'email' => 'fcst+'.substr(uniqid(), -8).'@t.test',
        ]);
    }

    public function test_purchasing_payload_strips_supplier_risk_without_the_forecasting_grant(): void
    {
        $user = $this->userWithPermissions(['purchasing.view']);

        $panels = app(PurchasingDashboardService::class)->purchasing($user)['panels'];

        $this->assertArrayNotHasKey('supplier_risk', $panels);
        // Its own domain panels are untouched.
        $this->assertArrayHasKey('pr_action_queue', $panels);
    }

    public function test_purchasing_payload_carries_a_read_only_supplier_risk_panel_with_the_grant(): void
    {
        $panels = app(PurchasingDashboardService::class)
            ->purchasing($this->userWithRole('purchasing_officer'))['panels'];

        $this->assertArrayHasKey('supplier_risk', $panels);
        $this->assertSame(
            ['at_risk_count', 'critical_count', 'high_count', 'horizon_days', 'mape', 'bias', 'periods_evaluated', 'top_items'],
            array_keys($panels['supplier_risk']),
            'supplier_risk must be a fixed read-only shape: no override or mutate keys',
        );
    }

    public function test_supplier_risk_is_empty_but_shaped_on_an_empty_database(): void
    {
        $risk = app(ForecastingDashboardService::class)->supplierRisk();

        $this->assertSame(0, $risk['at_risk_count']);
        $this->assertSame(0, $risk['critical_count']);
        $this->assertSame([], $risk['top_items']);
        $this->assertNull($risk['mape']);
        $this->assertNull($risk['bias']);
        $this->assertSame(0, $risk['periods_evaluated']);
    }

    public function test_supplier_risk_composes_stock_out_rows_with_demand_mape(): void
    {
        $code = 'SR-'.substr(uniqid(), -6);
        $item = Item::factory()->create([
            'code' => $code,
            'safety_stock' => '100.000',
            'reorder_point' => '50.000',
            'lead_time_days' => 5,
        ]);
        StockLevel::factory()->create([
            'item_id' => $item->id,
            'quantity' => '10.000',
            'reserved_quantity' => '0.000',
        ]);
        // Consumption inside the demand-history window gives a daily rate;
        // on-hand below safety with no headroom depletes immediately.
        foreach (range(1, 3) as $i) {
            DB::table('stock_movements')->insert([
                'item_id' => $item->id,
                'movement_type' => 'material_issue',
                'quantity' => '10.000',
                'created_at' => now()->subDays($i),
            ]);
        }

        $risk = app(ForecastingDashboardService::class)->supplierRisk();

        $this->assertSame(1, $risk['at_risk_count']);
        $this->assertSame(1, $risk['critical_count']);
        $this->assertSame(0, $risk['high_count']);
        $this->assertSame($code, $risk['top_items'][0]['code']);
        $this->assertSame('critical', $risk['top_items'][0]['risk']);
        // No demand forecasts exist, so the accuracy half is null — wired,
        // not crashed.
        $this->assertNull($risk['mape']);
        $this->assertSame(0, $risk['periods_evaluated']);
    }
}
