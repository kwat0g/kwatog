<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\MaterialIssueStatus;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialIssueSlipItem;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\MaterialIssueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

/**
 * F-18 — MIS cancel() was dead code: create() landed slips in Issued and
 * cancel() only accepted Draft, so issued stock was irreversible. Now an
 * Issued slip cancels by reversing stock back into inventory.
 */
class MaterialIssueCancelTest extends TestCase
{
    use RefreshDatabase;

    private MaterialIssueService $service;
    private User $user;
    private Item $item;
    private WarehouseLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->service  = app(MaterialIssueService::class);
        $this->user     = User::factory()->create();
        $this->item     = Item::factory()->create(['name' => 'Cancel Material']);
        $this->location = WarehouseLocation::factory()->create(['code' => 'CANCEL-1']);

        StockLevel::create([
            'item_id'           => $this->item->id,
            'location_id'       => $this->location->id,
            'quantity'          => '100.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '25.0000',
        ]);
    }

    private function issue(string $qty): MaterialIssueSlip
    {
        return $this->service->create([
            'issued_date' => now()->toDateString(),
            'items' => [[
                'item_id'          => $this->item->id,
                'location_id'      => $this->location->id,
                'quantity_issued'  => $qty,
            ]],
        ], $this->user);
    }

    public function test_cancel_issued_slip_reverses_stock(): void
    {
        $slip = $this->issue('10');

        $level = StockLevel::where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)->first();
        $this->assertSame('90.000', (string) $level->quantity);

        $this->service->cancel($slip, $this->user);

        $this->assertSame(MaterialIssueStatus::Cancelled, $slip->fresh()->status);

        $level->refresh();
        $this->assertSame('100.000', (string) $level->quantity, 'Stock restored after cancel');

        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'material_issue_slip',
            'reference_id'   => $slip->id,
            'movement_type'  => 'adjustment_in',
        ]);
    }

    public function test_cancel_twice_throws(): void
    {
        $slip = $this->issue('5');
        $this->service->cancel($slip, $this->user);

        $this->expectException(\App\Common\Exceptions\BusinessRuleException::class);
        $this->service->cancel($slip, $this->user);
    }

    public function test_cancel_route_reverses_stock(): void
    {
        $slip = $this->issue('7');

        $warehouseStaff = User::factory()->create([
            'role_id' => \App\Modules\Auth\Models\Role::query()->where('slug', 'warehouse_staff')->value('id'),
            'email'   => 'wh+'.substr(uniqid(), -8).'@t.local',
            'is_active' => true,
        ]);

        $response = $this->actingAs($warehouseStaff)->deleteJson("/api/v1/inventory/material-issues/{$slip->hash_id}");
        $response->assertOk();

        $response->assertJson(fn (AssertableJson $json) => $json
            ->where('data.status', MaterialIssueStatus::Cancelled->value)
            ->etc());

        $level = StockLevel::where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)->first();
        $this->assertSame('100.000', (string) $level->quantity);
    }
}