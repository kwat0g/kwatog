<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecomputeSafetyStockCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Wipe cached settings so a prior run's test_feature_flag_off does
        // not leak the disabled flag into a fresh run via the Redis cache.
        \Illuminate\Support\Facades\Cache::forget('settings:inventory.safety_stock.enabled');
        \Illuminate\Support\Facades\Cache::forget('settings:inventory.safety_stock.service_level_z');
        \Illuminate\Support\Facades\Cache::forget('settings:inventory.safety_stock.history_days');
        \Illuminate\Support\Facades\Cache::forget('settings:inventory.safety_stock.min_demand_days');
    }

    public function test_command_recomputes_safety_stock_from_issue_history(): void
    {
        // Pin settings inline so a leaked cache from a prior test cannot
        // disable the recompute under our feet.
        app(\App\Common\Services\SettingsService::class)
            ->set('inventory.safety_stock.enabled', true, 'inventory');
        \Illuminate\Support\Facades\Cache::forget('settings:inventory.safety_stock.enabled');

        $item = Item::factory()->create([
            'lead_time_days'      => 4,
            'safety_stock'        => 0,
            'safety_stock_locked' => false,
            'is_active'           => true,
        ]);

        $start = Carbon::now()->subDays(30)->startOfDay();
        for ($i = 0; $i < 30; $i++) {
            DB::table('stock_movements')->insert([
                'item_id'        => $item->id,
                'movement_type'  => 'material_issue',
                'quantity'       => $i % 3 === 0 ? 10 : ($i % 3 === 1 ? 6 : 14),
                'unit_cost'      => 0,
                'total_cost'     => 0,
                'created_at'     => $start->copy()->addDays($i),
            ]);
        }

        $svc = app(\App\Modules\Inventory\Services\SafetyStockRecomputeService::class);
        $svc->recomputeAll();

        $fresh = $item->fresh();
        $this->assertGreaterThan(0, (float) $fresh->safety_stock);
        $this->assertNotNull($fresh->safety_stock_recomputed_at);
    }

    public function test_locked_items_are_skipped(): void
    {
        $item = Item::factory()->create([
            'lead_time_days'      => 4,
            'safety_stock'        => 99,
            'safety_stock_locked' => true,
            'is_active'           => true,
        ]);

        $start = Carbon::now()->subDays(30)->startOfDay();
        for ($i = 0; $i < 30; $i++) {
            DB::table('stock_movements')->insert([
                'item_id'        => $item->id,
                'movement_type'  => 'material_issue',
                'quantity'       => 10,
                'unit_cost'      => 0,
                'total_cost'     => 0,
                'created_at'     => $start->copy()->addDays($i),
            ]);
        }

        $this->artisan('inventory:recompute-safety-stock')->assertExitCode(0);

        $this->assertSame('99.000', (string) $item->fresh()->safety_stock);
        $this->assertNull($item->fresh()->safety_stock_recomputed_at);
    }

    public function test_items_without_enough_demand_are_skipped(): void
    {
        $item = Item::factory()->create([
            'lead_time_days'      => 4,
            'safety_stock'        => 5,
            'safety_stock_locked' => false,
            'is_active'           => true,
        ]);

        $start = Carbon::now()->subDays(5)->startOfDay();
        for ($i = 0; $i < 3; $i++) {
            DB::table('stock_movements')->insert([
                'item_id'       => $item->id,
                'movement_type' => 'material_issue',
                'quantity'      => 10,
                'unit_cost'     => 0,
                'total_cost'    => 0,
                'created_at'    => $start->copy()->addDays($i),
            ]);
        }

        $this->artisan('inventory:recompute-safety-stock')->assertExitCode(0);

        $this->assertSame('5.000', (string) $item->fresh()->safety_stock);
        $this->assertNull($item->fresh()->safety_stock_recomputed_at);
    }

    public function test_feature_flag_off_disables_command(): void
    {
        app(\App\Common\Services\SettingsService::class)
            ->set('inventory.safety_stock.enabled', false, 'inventory');
        \Illuminate\Support\Facades\Cache::forget('settings:inventory.safety_stock.enabled');

        $item = Item::factory()->create([
            'lead_time_days'      => 4,
            'safety_stock'        => 0,
            'safety_stock_locked' => false,
            'is_active'           => true,
        ]);

        $start = Carbon::now()->subDays(30)->startOfDay();
        for ($i = 0; $i < 30; $i++) {
            DB::table('stock_movements')->insert([
                'item_id'       => $item->id,
                'movement_type' => 'material_issue',
                'quantity'      => 10,
                'unit_cost'     => 0,
                'total_cost'    => 0,
                'created_at'    => $start->copy()->addDays($i),
            ]);
        }

        $this->artisan('inventory:recompute-safety-stock')->assertExitCode(0);

        $this->assertSame('0.000', (string) $item->fresh()->safety_stock);
    }
}
