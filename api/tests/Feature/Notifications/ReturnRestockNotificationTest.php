<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026-08-08 — warehouse notification on customer-return restock.
 *
 * Disposing a customer-return line as 'restock'/'rework' moves the goods back
 * into sellable stock (AdjustmentIn) and alerts everyone with inventory.view
 * so the shelf team knows to receive and verify them. Best-effort: a failing
 * notification must never roll back the stock movement.
 */
class ReturnRestockNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', 'system_admin')->value('id'),
            'is_active' => true,
        ]);
    }

    /** A warehouse role holding only inventory.view. */
    private function warehouseUser(): User
    {
        $permission = Permission::query()->firstOrCreate(
            ['slug' => 'inventory.view'],
            ['name' => 'View Inventory', 'module' => 'inventory'],
        );
        $role = Role::query()->create(['name' => 'Warehouse Notify Test', 'slug' => 'warehouse-notify-test']);
        $role->permissions()->attach($permission);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    /** A role with no inventory access at all. */
    private function outsider(): User
    {
        $role = Role::query()->create(['name' => 'No Access Test', 'slug' => 'no-access-test']);

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function inspectedRma(User $by, Item $item): ReturnRequest
    {
        $customer = Customer::create(['name' => 'Notification Scenario Customer', 'payment_terms_days' => 30]);
        $invoice = Invoice::create([
            'invoice_number' => 'INV-NTF-' . substr(uniqid(), -5),
            'customer_id'    => $customer->id,
            'status'         => 'finalized',
            'subtotal'       => '1000.00',
            'vat_amount'     => '120.00',
            'total_amount'   => '1120.00',
            'balance'        => '1120.00',
            'date'           => now()->toDateString(),
            'due_date'       => now()->addDays(30)->toDateString(),
            'created_by'     => $by->id,
        ]);

        $rma = ReturnRequest::create([
            'rma_number'  => 'RMA-NTF-' . substr(uniqid(), -5),
            'type'        => 'customer_return',
            'status'      => ReturnRequestStatus::Inspected->value,
            'customer_id' => $customer->id,
            'invoice_id'  => $invoice->id,
            'reason_code' => 'defective',
            'return_date' => now()->toDateString(),
            'created_by'  => $by->id,
        ]);

        ReturnRequestItem::create([
            'return_request_id' => $rma->id,
            'item_id'           => $item->id,
            'quantity'          => '8.000',
            'returned_quantity' => '8.000',
            'unit_price'        => '100.00',
            'total'             => '800.00',
        ]);

        return $rma->load('items');
    }

    public function test_restock_dispose_notifies_everyone_with_inventory_view(): void
    {
        $admin = $this->admin();
        $warehouse = $this->warehouseUser();
        $item = Item::factory()->create();
        $loc = WarehouseLocation::factory()->create();
        $rma = $this->inspectedRma($admin, $item);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'restock',
                ]],
                'location_id'  => $loc->hash_id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $warehouse->id,
            'type'          => 'return.restocked',
        ]);

        $row = DB::table('notifications')
            ->where('notifiable_id', $warehouse->id)
            ->where('type', 'return.restocked')
            ->first();
        $data = json_decode($row->data, true);
        $this->assertStringContainsString($rma->rma_number, $data['message']);
        $this->assertStringContainsString('8 unit(s)', $data['message'], 'Quantity renders without trailing decimal zeros.');
        $this->assertSame("/return-management/{$rma->hash_id}", $data['link_to']);
    }

    public function test_restock_dispose_notifies_wildcard_system_admin(): void
    {
        // system_admin holds a '*' permission, not the explicit inventory.view
        // slug — the whereHas on role.permissions must still reach them.
        $admin = $this->admin();
        $item = Item::factory()->create();
        $loc = WarehouseLocation::factory()->create();
        $rma = $this->inspectedRma($admin, $item);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'restock',
                ]],
                'location_id'  => $loc->hash_id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $admin->id,
            'type'          => 'return.restocked',
        ]);
    }

    public function test_restock_dispose_skips_users_without_inventory_access(): void
    {
        $admin = $this->admin();
        $outsider = $this->outsider();
        $item = Item::factory()->create();
        $loc = WarehouseLocation::factory()->create();
        $rma = $this->inspectedRma($admin, $item);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'restock',
                ]],
                'location_id'  => $loc->hash_id,
            ])
            ->assertOk();

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $outsider->id,
            'type'          => 'return.restocked',
        ]);
    }

    public function test_scrap_dispose_fires_no_restock_notification(): void
    {
        $admin = $this->admin();
        $this->warehouseUser();
        $item = Item::factory()->create();
        $rma = $this->inspectedRma($admin, $item);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'scrap',
                ]],
            ])
            ->assertOk();

        $this->assertSame(0, DB::table('notifications')->where('type', 'return.restocked')->count());
    }

    public function test_supplier_return_ship_dispose_fires_no_restock_notification(): void
    {
        $admin = $this->admin();
        $this->warehouseUser();
        $item = Item::factory()->create();
        $rma = $this->inspectedRma($admin, $item);
        $rma->update(['type' => 'supplier_return']);

        // No vendor/PO lineage — but the location guard fires first for the
        // requested return_to_supplier movement, proving no restock alert leaks
        // from the supplier side even when movement is attempted.
        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'return_to_supplier',
                ]],
                'location_id'  => WarehouseLocation::factory()->create()->hash_id,
            ])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('notifications')->where('type', 'return.restocked')->count());
    }
}
