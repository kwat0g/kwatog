<?php

declare(strict_types=1);

namespace Tests\Feature\ReturnManagement;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\InvoiceItem;
use App\Modules\Accounting\Models\Account;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Models\WarehouseZone;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Models\ReturnRequestItem;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-08-08 — restock at disposition time (O2C twin of GRN acceptance).
 *
 * Disposing a customer-return line as 'restock'/'rework' now creates the
 * AdjustmentIn inventory receipt immediately at the declared warehouse
 * location, so the goods re-enter sellable stock the moment the disposition is
 * recorded — not at a later, separate completion step. complete() is
 * idempotent and must not move those lines again.
 */
class CustomerReturnRestockOnDisposeTest extends TestCase
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
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create(['name' => 'Restock Scenario Customer', 'payment_terms_days' => 30]);
    }

    private function invoice(Customer $c, User $by): Invoice
    {
        return Invoice::create([
            'invoice_number' => 'INV-RS-' . substr(uniqid(), -5),
            'customer_id'    => $c->id,
            'status'         => 'finalized',
            'subtotal'       => '1000.00',
            'vat_amount'     => '120.00',
            'total_amount'   => '1120.00',
            'balance'        => '1120.00',
            'date'           => now()->toDateString(),
            'due_date'       => now()->addDays(30)->toDateString(),
            'created_by'     => $by->id,
        ]);
    }

    /** An inspected customer RMA: 10 requested, 8 physically returned. */
    private function inspectedRma(User $by, Customer $c, Invoice $inv, Item $item): ReturnRequest
    {
        $rma = ReturnRequest::create([
            'rma_number'  => 'RMA-RS-' . substr(uniqid(), -5),
            'type'        => 'customer_return',
            'status'      => ReturnRequestStatus::Inspected->value,
            'customer_id' => $c->id,
            'invoice_id'  => $inv->id,
            'reason_code' => 'defective',
            'return_date' => now()->toDateString(),
            'created_by'  => $by->id,
        ]);

        $invoiceLine = InvoiceItem::create([
            'invoice_id' => $inv->id,
            'revenue_account_id' => Account::query()->where('code', '4010')->firstOrFail()->id,
            'description' => 'Returned stock',
            'quantity' => '10.00',
            'unit_price' => '100.00',
            'total' => '1000.00',
        ]);

        $line = ReturnRequestItem::create([
            'return_request_id' => $rma->id,
            'item_id'           => $item->id,
            'quantity'          => 10,
            'returned_quantity' => 8,
            'unit_price'        => '100.00',
            'total'             => '1000.00',
            'source_invoice_item_id' => $invoiceLine->id,
        ]);

        $zone = WarehouseZone::factory()->create(['zone_type' => 'quarantine']);
        $quarantine = WarehouseLocation::factory()->create(['zone_id' => $zone->id]);
        $movement = app(StockMovementService::class)->move(new StockMovementInput(
            type: \App\Modules\Inventory\Enums\StockMovementType::AdjustmentIn,
            itemId: $item->id,
            toLocationId: $quarantine->id,
            quantity: '8.000',
            unitCost: '0.00',
            referenceType: 'return_request',
            referenceId: $rma->id,
            createdBy: $by->id,
        ));
        $line->update([
            'quarantine_location_id' => $quarantine->id,
            'quarantine_movement_id' => $movement->id,
            'quarantine_status' => 'held',
        ]);

        return $rma->load('items');
    }

    public function test_dispose_restock_without_a_location_is_rejected(): void
    {
        $admin = $this->admin();
        $item  = Item::factory()->create();
        $rma   = $this->inspectedRma($admin, $this->customer(), $this->invoice($this->customer(), $admin), $item);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'restock',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_id');

        $this->assertNull($rma->fresh()->disposition_status, 'Nothing may be disposed without naming the restock location.');
    }

    public function test_dispose_restock_receives_the_goods_into_stock_immediately(): void
    {
        $admin = $this->admin();
        $item  = Item::factory()->create();
        $loc   = WarehouseLocation::factory()->create();
        $rma   = $this->inspectedRma($admin, $this->customer(), $this->invoice($this->customer(), $admin), $item);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'restock',
                    'notes'       => 'Passed inspection — restockable',
                ]],
                'location_id'  => $loc->hash_id,
            ])
            ->assertOk();

        $rma->refresh();

        // 8 returned units land in the ledger the moment disposition is recorded.
        $movement = StockMovement::query()
            ->where('reference_type', 'return_request')
            ->where('reference_id', $rma->id)
            ->firstOrFail();
        $this->assertSame(StockMovementType::AdjustmentIn, $movement->movement_type);
        $this->assertSame('8.000', (string) $movement->quantity);

        $level = StockLevel::where('item_id', $item->id)->where('location_id', $loc->id)->firstOrFail();
        $this->assertSame('8.000', (string) $level->quantity, 'Stock must be up by the returned quantity immediately.');

        $line = $rma->items->first()->fresh();
        $this->assertSame('8.000', (string) $line->stock_movement_quantity);
        $this->assertNotNull($rma->stock_movement_id);

        // The credit note is still staged as a draft alongside the restock.
        $this->assertNotNull($rma->credit_note_id);
        $this->assertSame('draft', $rma->creditNote->status->value);

        // The API surfaces the restock facts for the detail page banner.
        $this->actingAs($admin)
            ->getJson("/api/v1/return-management/return-requests/{$rma->hash_id}")
            ->assertOk()
            ->assertJsonPath('data.moved_quantity', '8')
            ->assertJsonPath('data.stock_movement.to_location.code', $loc->code);
    }

    public function test_complete_after_dispose_restock_is_idempotent_and_needs_no_location(): void
    {
        $admin = $this->admin();
        $item  = Item::factory()->create();
        $loc   = WarehouseLocation::factory()->create();
        $rma   = $this->inspectedRma($admin, $this->customer(), $this->invoice($this->customer(), $admin), $item);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'restock',
                ]],
                'location_id'  => $loc->hash_id,
            ])
            ->assertOk();

        // Restock happened at dispose — completing closes the RMA without
        // asking for a location and without moving the goods a second time.
        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/complete", [])
            ->assertOk();

        $this->assertSame(ReturnRequestStatus::Completed, $rma->fresh()->status);
        $this->assertSame(
            2,
            StockMovement::query()
                ->where('reference_type', 'return_request')
                ->where('reference_id', $rma->id)
                ->count(),
            'Complete must not create a second movement for already-restocked lines.',
        );
    }

    public function test_dispose_scrap_needs_no_location_and_creates_no_movement(): void
    {
        $admin = $this->admin();
        $item  = Item::factory()->create();
        $rma   = $this->inspectedRma($admin, $this->customer(), $this->invoice($this->customer(), $admin), $item);

        $this->actingAs($admin)
            ->postJson("/api/v1/return-management/return-requests/{$rma->hash_id}/dispose", [
                'dispositions' => [[
                    'item_id'     => $rma->items->first()->hash_id,
                    'disposition' => 'scrap',
                ]],
            ])
            ->assertOk();

        $this->assertSame('disposed', $rma->fresh()->disposition_status);
        $this->assertSame(
            1,
            StockMovement::query()
                ->where('reference_type', 'return_request')
                ->where('reference_id', $rma->id)
                ->where('movement_type', StockMovementType::AdjustmentIn->value)
                ->count(),
            'Quarantine receipt is recorded before a scrap disposition.',
        );
        $this->assertSame(1, StockMovement::query()
            ->where('reference_type', 'return_request')
            ->where('reference_id', $rma->id)
            ->where('movement_type', StockMovementType::Scrap->value)
            ->count());
    }
}
