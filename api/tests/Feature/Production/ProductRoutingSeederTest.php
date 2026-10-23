<?php

declare(strict_types=1);

namespace Tests\Feature\Production;

use App\Modules\CRM\Models\Product;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\Machine;
use App\Modules\Production\Models\ProductRouting;
use App\Modules\Production\Models\RoutingOperation;
use Database\Seeders\BomSeeder;
use Database\Seeders\DemoBomCostBasisSeeder;
use Database\Seeders\InventoryItemSeeder;
use Database\Seeders\MachineSeeder;
use Database\Seeders\MoldCompatibilitySeeder;
use Database\Seeders\MoldSeeder;
use Database\Seeders\ProductRoutingSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductRoutingSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_seed_creates_compatible_processes_and_can_repair_an_empty_active_route(): void
    {
        $this->seed([ProductSeeder::class, MachineSeeder::class, MoldSeeder::class, MoldCompatibilitySeeder::class]);

        $product = Product::query()->where('part_number', 'WB-001')->firstOrFail();
        Machine::query()->where('machine_code', 'IMM-05')->update(['status' => 'maintenance']);
        ProductRouting::query()->create([
            'product_id' => $product->id,
            'version' => 1,
            'is_active' => true,
            'total_cycle_time' => '0.00',
            'notes' => 'Legacy empty route.',
        ]);

        $this->seed(ProductRoutingSeeder::class);
        $this->seed(ProductRoutingSeeder::class);

        $activeRoutes = ProductRouting::query()->where('is_active', true)->with('operations.machine', 'operations.mold')->get();
        $this->assertCount(8, $activeRoutes);
        $this->assertSame(9, ProductRouting::query()->count());
        $this->assertSame(8, RoutingOperation::query()->count());

        foreach ($activeRoutes as $routing) {
            $this->assertCount(1, $routing->operations);
            $operation = $routing->operations->first();
            $this->assertNotNull($operation->machine);
            $this->assertNotNull($operation->mold);
            $this->assertGreaterThan(0, (float) $operation->cycle_time_minutes);
            $this->assertTrue($operation->mold->compatibleMachines()->whereKey($operation->machine_id)->exists());
            $this->assertSame('0.0000', (string) $operation->labor_rate_per_hour);
            $this->assertSame('0.0000', (string) $operation->machine_rate_per_hour);
            $this->assertSame('0.0000', (string) $operation->overhead_rate_per_hour);
        }

        $rcProductId = Product::query()->where('part_number', 'RC-001')->value('id');
        $rcRouting = $activeRoutes->firstWhere('product_id', $rcProductId);
        $this->assertNotNull($rcRouting);
        $this->assertNotSame('IMM-05', $rcRouting->operations->first()->machine->machine_code);
    }

    public function test_demo_seed_uses_labeled_example_cost_rates(): void
    {
        $this->seed([ProductSeeder::class, MachineSeeder::class, MoldSeeder::class, MoldCompatibilitySeeder::class]);
        config(['app.seed_demo_data' => true]);

        $this->seed(ProductRoutingSeeder::class);

        $operation = RoutingOperation::query()->firstOrFail();
        $this->assertSame('120.0000', (string) $operation->labor_rate_per_hour);
        $this->assertSame('250.0000', (string) $operation->machine_rate_per_hour);
        $this->assertSame('80.0000', (string) $operation->overhead_rate_per_hour);
        $this->assertStringContainsString('Demo costing assumptions only', (string) $operation->routing->notes);
    }

    public function test_demo_bom_cost_basis_amortizes_setup_over_seeded_lot_and_is_idempotent(): void
    {
        config(['app.seed_demo_data' => false]);
        $this->seed([ProductSeeder::class, InventoryItemSeeder::class, BomSeeder::class, MachineSeeder::class, MoldSeeder::class, MoldCompatibilitySeeder::class]);
        config(['app.seed_demo_data' => true]);

        $this->seed(ProductRoutingSeeder::class);
        $this->seed(DemoBomCostBasisSeeder::class);
        $this->seed(DemoBomCostBasisSeeder::class);

        $product = Product::query()->where('part_number', 'WB-001')->firstOrFail();
        $activeBom = Bom::query()->where('product_id', $product->id)->where('is_active', true)->firstOrFail();

        $this->assertSame('500.000', (string) $activeBom->cost_batch_size);
        $this->assertSame(2, Bom::query()->where('product_id', $product->id)->count());
        $this->assertGreaterThan(0, (float) $activeBom->labor_cost);
        $this->assertLessThan(1, (float) $activeBom->labor_cost);
        $this->assertGreaterThan(0, (float) $activeBom->machine_cost);
        $this->assertSame('standard_cost+routing', $activeBom->cost_basis);
    }
}
