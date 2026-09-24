<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseRequest;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseRequestNeedByDateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(WorkflowSeeder::class);
        $this->user = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'purchasing_officer')->value('id'),
        ]);
    }

    public function test_storing_pr_via_api_with_required_delivery_date_persists_and_returns_it(): void
    {
        $item = Item::factory()->create();
        $futureDate = Carbon::today()->addDays(7)->toDateString();

        $response = $this->actingAs($this->user)->postJson('/api/v1/purchasing/purchase-requests', [
            'sourcing_method' => 'direct_po',
            'required_delivery_date' => $futureDate,
            'items' => [
                [
                    'item_id' => $item->hash_id,
                    'description' => 'Test Item',
                    'quantity' => '10.000',
                    'estimated_unit_price' => '100.00',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertEquals($futureDate, $data['required_delivery_date']);

        $pr = PurchaseRequest::query()->latest('id')->first();
        $this->assertEquals($futureDate, $pr->required_delivery_date->toDateString());
    }

    public function test_storing_pr_with_past_required_delivery_date_is_rejected(): void
    {
        $item = Item::factory()->create();
        $pastDate = Carbon::yesterday()->toDateString();

        $response = $this->actingAs($this->user)->postJson('/api/v1/purchasing/purchase-requests', [
            'sourcing_method' => 'direct_po',
            'required_delivery_date' => $pastDate,
            'items' => [
                [
                    'item_id' => $item->hash_id,
                    'description' => 'Test Item',
                    'quantity' => '10.000',
                    'estimated_unit_price' => '100.00',
                ],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('required_delivery_date', $response->json('errors'));
    }

    public function test_converting_approved_pr_with_future_required_delivery_date_creates_po_with_matching_expected_delivery_date(): void
    {
        $pr = PurchaseRequest::factory()->create([
            'requested_by' => $this->user->id,
            'required_delivery_date' => Carbon::today()->addDays(10),
        ]);
        $pr->forceFill(['status' => PurchaseRequestStatus::Approved])->save();

        $item = Item::factory()->create();
        $vendor = \App\Modules\Accounting\Models\Vendor::factory()->create();
        $prItem = $pr->items()->create([
            'item_id' => $item->id,
            'description' => 'Test Item',
            'quantity' => '10.000',
            'unit' => 'pcs',
            'estimated_unit_price' => '100.00',
        ]);

        $poService = app(\App\Modules\Purchasing\Services\PurchaseOrderService::class);
        $pos = $poService->convertFromPr($pr, [$prItem->id => $vendor->id], $this->user);

        $this->assertCount(1, $pos);
        $po = $pos[0];
        $this->assertEquals(
            Carbon::today()->addDays(10)->toDateString(),
            $po->expected_delivery_date->toDateString(),
        );
    }

    public function test_converting_approved_pr_with_past_required_delivery_date_creates_po_with_today(): void
    {
        $pr = PurchaseRequest::factory()->create([
            'requested_by' => $this->user->id,
            'required_delivery_date' => Carbon::yesterday(),
        ]);
        $pr->forceFill(['status' => PurchaseRequestStatus::Approved])->save();

        $item = Item::factory()->create();
        $vendor = \App\Modules\Accounting\Models\Vendor::factory()->create();
        $prItem = $pr->items()->create([
            'item_id' => $item->id,
            'description' => 'Test Item',
            'quantity' => '10.000',
            'unit' => 'pcs',
            'estimated_unit_price' => '100.00',
        ]);

        $poService = app(\App\Modules\Purchasing\Services\PurchaseOrderService::class);
        $pos = $poService->convertFromPr($pr, [$prItem->id => $vendor->id], $this->user);

        $this->assertCount(1, $pos);
        $po = $pos[0];
        $this->assertEquals(
            Carbon::today()->toDateString(),
            $po->expected_delivery_date->toDateString(),
        );
    }

    public function test_converting_pr_with_explicit_expected_delivery_date_wins_over_pr_required_delivery_date(): void
    {
        $pr = PurchaseRequest::factory()->create([
            'requested_by' => $this->user->id,
            'required_delivery_date' => Carbon::today()->addDays(10),
        ]);
        $pr->forceFill(['status' => PurchaseRequestStatus::Approved])->save();

        $item = Item::factory()->create();
        $vendor = \App\Modules\Accounting\Models\Vendor::factory()->create();
        $prItem = $pr->items()->create([
            'item_id' => $item->id,
            'description' => 'Test Item',
            'quantity' => '10.000',
            'unit' => 'pcs',
            'estimated_unit_price' => '100.00',
        ]);

        $explicitDate = Carbon::today()->addDays(5)->toDateString();
        $poService = app(\App\Modules\Purchasing\Services\PurchaseOrderService::class);
        $pos = $poService->convertFromPr(
            $pr,
            [$prItem->id => $vendor->id],
            $this->user,
            false,
            $explicitDate
        );

        $this->assertCount(1, $pos);
        $po = $pos[0];
        $this->assertEquals($explicitDate, $po->expected_delivery_date->toDateString());
    }
}
