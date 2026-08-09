<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OGAMI purchase rule (2026-08-08): a PO must originate from an approved PR —
 * PR → approved → PO. The AutoPurchaseOrderService critical-shortage bypass is
 * a separate VP-routed path and is out of scope here.
 */
class PurchaseOrderRequiresPrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'system_admin')->value('id'),
        ]);
    }

    private function poPayload(array $overrides = []): array
    {
        return array_merge([
            'vendor_id' => Vendor::factory()->create()->hash_id,
            'date'      => now()->toDateString(),
            'items'     => [
                [
                    'item_id'     => Item::factory()->create()->hash_id,
                    'description' => 'Test line',
                    'quantity'    => 1,
                    'unit'        => 'pcs',
                    'unit_price'  => '100.00',
                ],
            ],
        ], $overrides);
    }

    public function test_po_creation_without_a_pr_is_rejected(): void
    {
        $this->actingAs($this->makeUser())
            ->postJson('/api/v1/purchasing/purchase-orders', $this->poPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('purchase_request_id');
    }

    public function test_po_creation_from_an_unapproved_pr_is_rejected(): void
    {
        $pr = PurchaseRequest::factory()->create();
        $pr->forceFill(['status' => PurchaseRequestStatus::Draft->value])->save();

        $this->actingAs($this->makeUser())
            ->postJson('/api/v1/purchasing/purchase-orders', $this->poPayload([
                'purchase_request_id' => $pr->hash_id,
            ]))
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'approved purchase requests'));
    }

    public function test_po_creation_from_an_approved_pr_succeeds(): void
    {
        $pr = PurchaseRequest::factory()->create();
        $pr->forceFill(['status' => PurchaseRequestStatus::Approved->value])->save();

        $this->actingAs($this->makeUser())
            ->postJson('/api/v1/purchasing/purchase-orders', $this->poPayload([
                'purchase_request_id' => $pr->hash_id,
            ]))
            ->assertStatus(201);
    }
}
