<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Services\AccountingPeriodService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Quality\Services\InspectionService;
use Carbon\Carbon;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * GRN GL Posting with Closed Accounting Periods.
 *
 * When a GRN is received on a date whose accounting period is later closed,
 * the GRN accept must not fail. The JE posting date rolls forward to today
 * (an open period), and the description notes when this happens.
 */
class GrnClosedPeriodPostingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    // Maker-checker: an incoming inspection counts only when a different user checks it.
    private User $checker;
    private User $admin;
    private GrnService $grnSvc;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        $this->seed(ChartOfAccountsSeeder::class);

        // Create admin user for closing periods
        $adminRole = Role::firstOrCreate(['slug' => 'system_admin'], ['name' => 'System Admin']);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);

        // Create warehouse staff
        $role = Role::firstOrCreate(['slug' => 'warehouse_staff'], ['name' => 'Warehouse Staff']);
        foreach ([
            'inventory.grn.create' => 'inventory',
            'inventory.view' => 'inventory',
            'quality.inspections.manage' => 'quality',
            'accounting.journal.post' => 'accounting',
            'accounting.journal.view' => 'accounting',
        ] as $slug => $module) {
            $permission = Permission::firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'module' => $module]
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $this->checker = User::factory()->create(['is_active' => true]);

        $this->grnSvc = app(GrnService::class);

        $this->enableAccounting(true);
    }

    private function enableAccounting(bool $enabled = true): void
    {
        app(SettingsService::class)->set('modules.accounting', $enabled, 'modules');
    }

    /**
     * Build a pending_qc GRN with received_date set to the given date.
     */
    private function buildGrnWithReceivedDate(string $receivedDate, ItemType $itemType = ItemType::RawMaterial): GoodsReceiptNote
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->user->id,
        ]);

        $grn = GoodsReceiptNote::create([
            'grn_number' => 'GRN-T-' . substr(uniqid(), -5),
            'purchase_order_id' => $po->id,
            'vendor_id' => $po->vendor_id,
            'received_date' => $receivedDate,
            'received_by' => $this->user->id,
            'status' => GrnStatus::PendingQc,
        ]);

        $item = Item::factory()->create([
            'item_type' => $itemType->value,
            'is_active' => true,
        ]);
        $location = WarehouseLocation::factory()->create();

        $poi = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Test material',
            'quantity' => '100.000',
            'unit' => 'pcs',
            'unit_price' => '10.00',
            'total' => '1000.00',
            'quantity_received' => '0.000',
        ]);

        GrnItem::create([
            'goods_receipt_note_id' => $grn->id,
            'purchase_order_item_id' => $poi->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_received' => '100.000',
            'quantity_accepted' => 0,
            'unit_cost' => '10.00',
        ]);

        // Create and pass incoming QC
        $inspection = app(InspectionService::class)->createIncomingForItem(
            $item,
            100,
            $grn->id,
            $this->user,
            null,
            (int) $grn->items->first()->id,
        );
        $inspection->update([
            'status' => 'passed',
            'reviewed_by' => $this->checker->id,
            'reviewed_at' => now(),
        ]);

        return $grn->fresh(['items']);
    }

    /**
     * Test: GRN received in a month that is later closed.
     * Accept must succeed with JE date rolled forward to today.
     */
    public function test_grn_received_in_closed_period_posts_to_open_period(): void
    {
        // Use August 30, 2026
        $receivedDate = '2026-08-30';
        $grn = $this->buildGrnWithReceivedDate($receivedDate);

        // Verify the GRN has the closed-period-to-be date
        $this->assertSame($receivedDate, $grn->received_date->format('Y-m-d'));

        // Close August 2026
        app(AccountingPeriodService::class)->close(2026, 8, $this->admin);

        // Accept the GRN — this should succeed and post to today's period
        $accepted = $this->grnSvc->accept($grn, $this->user);

        $this->assertNotNull($accepted->journal_entry_id, 'GRN must have a JE link');

        // Verify JE was created
        $je = DB::table('journal_entries')->where('id', $accepted->journal_entry_id)->first();
        $this->assertNotNull($je);
        $this->assertSame('posted', $je->status);

        // JE date must be TODAY, not the received_date
        $todayStr = now()->toDateString();
        $this->assertSame($todayStr, $je->date, "JE date must be today ({$todayStr}) since received_date's period is closed");

        // Description must note the rollover
        $this->assertStringContainsString('posted in open period', $je->description);
        $this->assertStringContainsString("received {$receivedDate}", $je->description);
    }

    /**
     * Test: GRN received in an open month stays on received_date.
     * This is the baseline — no rollover.
     */
    public function test_grn_received_in_open_period_posts_on_received_date(): void
    {
        // Use today's date (always open)
        $receivedDate = now()->toDateString();
        $grn = $this->buildGrnWithReceivedDate($receivedDate);

        // Accept without closing the period
        $accepted = $this->grnSvc->accept($grn, $this->user);

        $this->assertNotNull($accepted->journal_entry_id);

        $je = DB::table('journal_entries')->where('id', $accepted->journal_entry_id)->first();
        $this->assertNotNull($je);

        // JE date must equal received_date
        $this->assertSame($receivedDate, $je->date);

        // Description must NOT mention "posted in open period" (no rollover occurred)
        $this->assertStringNotContainsString('posted in open period', $je->description);
    }

    /**
     * Test: HTTP POST GRN with received_date = tomorrow should fail validation.
     */
    public function test_store_grn_with_future_received_date_fails_validation(): void
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->user->id,
        ]);

        $item = Item::factory()->create(['is_active' => true]);
        $location = WarehouseLocation::factory()->create();

        $poItem = PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Test',
            'quantity' => '100.000',
            'unit' => 'pcs',
            'unit_price' => '10.00',
            'total' => '1000.00',
            'quantity_received' => '0.000',
        ]);

        $tomorrow = now()->addDay()->toDateString();

        $this->actingAs($this->user)
            ->postJson('/api/v1/inventory/grn', [
                'purchase_order_id' => $po->hash_id,
                'received_date' => $tomorrow,
                'items' => [[
                    'purchase_order_item_id' => $poItem->hash_id,
                    'item_id' => $item->hash_id,
                    'location_id' => $location->hash_id,
                    'quantity_received' => '100.000',
                    'unit_cost' => '10.00',
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.received_date.0', 'The received date field must be a date before or equal to today.');
    }
}
