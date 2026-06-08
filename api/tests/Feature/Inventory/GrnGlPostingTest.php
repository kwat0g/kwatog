<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\GrnStatus;
use App\Modules\Inventory\Enums\ItemType;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\GrnItem;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\GrnGlPostingService;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Task 5 — GRN → GL posting (GRNI clearing).
 *
 * GRNs that are accepted (in full or in part) must produce a balanced
 * journal entry: DR Inventory (routed by item category) / CR 2110 GRNI.
 * The post is flag-gated on `modules.accounting`, idempotent via
 * `goods_receipt_notes.journal_entry_id`, and must not block GRN
 * acceptance if the GL post itself fails.
 */
class GrnGlPostingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private GrnService $grnSvc;
    private GrnGlPostingService $glSvc;

    protected function setUp(): void
    {
        parent::setUp();

        // The auto-replenishment listener tries to create a PR with
        // requested_by=1 (system user), which won't exist in test DB.
        // Suppress it — these tests pin GL posting, not replenishment.
        Event::fake([StockMovementCompleted::class]);

        $this->seed(ChartOfAccountsSeeder::class);

        $role = Role::firstOrCreate(['slug' => 'warehouse'], ['name' => 'Warehouse']);
        $this->user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $this->grnSvc = app(GrnService::class);
        $this->glSvc  = app(GrnGlPostingService::class);
    }

    private function enableAccounting(bool $enabled = true): void
    {
        app(SettingsService::class)->set('modules.accounting', $enabled, 'modules');
    }

    /**
     * Build a pending_qc GRN with the given lines.
     *
     * @param  array<int, array{item_type:string, quantity:string, unit_cost:string}>  $lines
     */
    private function buildGrn(array $lines): GoodsReceiptNote
    {
        $po = PurchaseOrder::factory()->create([
            'status'     => PurchaseOrderStatus::Approved->value,
            'created_by' => $this->user->id,
        ]);

        $grn = GoodsReceiptNote::create([
            'grn_number'        => 'GRN-' . substr(uniqid(), -10),
            'purchase_order_id' => $po->id,
            'vendor_id'         => $po->vendor_id,
            'received_date'     => now()->toDateString(),
            'received_by'       => $this->user->id,
            'status'            => GrnStatus::PendingQc,
        ]);

        foreach ($lines as $row) {
            $item = Item::factory()->create([
                'item_type' => $row['item_type'],
                'is_active' => true,
            ]);
            $location = WarehouseLocation::factory()->create();

            $poi = PurchaseOrderItem::create([
                'purchase_order_id' => $po->id,
                'item_id'           => $item->id,
                'description'       => 'Test line',
                'quantity'          => $row['quantity'],
                'unit'              => 'pcs',
                'unit_price'        => $row['unit_cost'],
                'total'             => bcmul($row['quantity'], $row['unit_cost'], 2),
                'quantity_received' => '0.000',
            ]);

            GrnItem::create([
                'goods_receipt_note_id'  => $grn->id,
                'purchase_order_item_id' => $poi->id,
                'item_id'                => $item->id,
                'location_id'            => $location->id,
                'quantity_received'      => $row['quantity'],
                'quantity_accepted'      => 0,
                'unit_cost'              => $row['unit_cost'],
            ]);
        }

        return $grn->fresh(['items']);
    }

    public function test_accept_posts_balanced_je_when_accounting_enabled(): void
    {
        $this->enableAccounting(true);

        $grn = $this->buildGrn([
            ['item_type' => ItemType::RawMaterial->value, 'quantity' => '100', 'unit_cost' => '10.00'],
            ['item_type' => ItemType::Packaging->value,   'quantity' => '50',  'unit_cost' => '4.00'],
        ]);

        $accepted = $this->grnSvc->accept($grn, $this->user);

        $this->assertNotNull($accepted->journal_entry_id, 'GRN must be back-linked to its JE');

        $je = DB::table('journal_entries')->where('id', $accepted->journal_entry_id)->first();
        $this->assertNotNull($je);
        $this->assertSame('posted', $je->status);
        $this->assertSame('goods_receipt_note', $je->reference_type);
        $this->assertSame((int) $accepted->id, (int) $je->reference_id);
        $this->assertSame((string) $je->total_debit, (string) $je->total_credit, 'JE must balance');

        // Raw materials = 1200; Packaging = 1220; GRNI = 2110.
        $accountIds = DB::table('accounts')->whereIn('code', ['1200', '1220', '2110'])->pluck('id', 'code');

        $rawDebit = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $je->id)
            ->where('account_id', $accountIds['1200'])
            ->value('debit');
        $this->assertSame('1000.00', (string) $rawDebit, 'Raw-materials DR must equal 100 × 10');

        $pkgDebit = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $je->id)
            ->where('account_id', $accountIds['1220'])
            ->value('debit');
        $this->assertSame('200.00', (string) $pkgDebit, 'Packaging DR must equal 50 × 4');

        $grniCredit = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $je->id)
            ->where('account_id', $accountIds['2110'])
            ->value('credit');
        $this->assertSame('1200.00', (string) $grniCredit, 'GRNI CR must equal total accepted value');
    }

    public function test_accept_skips_when_accounting_disabled(): void
    {
        $this->enableAccounting(false);

        $grn = $this->buildGrn([
            ['item_type' => ItemType::RawMaterial->value, 'quantity' => '10', 'unit_cost' => '5.00'],
        ]);

        $accepted = $this->grnSvc->accept($grn, $this->user);

        $this->assertSame(GrnStatus::Accepted, $accepted->status, 'GRN must still be accepted');
        $this->assertNull($accepted->journal_entry_id, 'No JE should be linked when accounting is disabled');
        $this->assertSame(0, DB::table('journal_entries')->count(), 'No JE rows should exist');
    }

    public function test_accept_is_idempotent_does_not_double_post(): void
    {
        $this->enableAccounting(true);

        $grn = $this->buildGrn([
            ['item_type' => ItemType::RawMaterial->value, 'quantity' => '10', 'unit_cost' => '5.00'],
        ]);

        $accepted = $this->grnSvc->accept($grn, $this->user);
        $firstId  = $accepted->journal_entry_id;
        $this->assertNotNull($firstId);

        // Call the GL service directly a second time — should be a no-op.
        $second = $this->glSvc->post($accepted->fresh());

        $this->assertSame((int) $firstId, (int) $second, 'Second post must return the existing JE id');
        $this->assertSame(
            1,
            DB::table('journal_entries')->where('reference_id', $accepted->id)->count(),
            'Only one JE may exist for the GRN',
        );
    }

    public function test_partial_accept_posts_only_accepted_value(): void
    {
        $this->enableAccounting(true);

        $grn = $this->buildGrn([
            ['item_type' => ItemType::RawMaterial->value, 'quantity' => '100', 'unit_cost' => '10.00'],
            ['item_type' => ItemType::Packaging->value,   'quantity' => '40',  'unit_cost' => '5.00'],
        ]);

        // Line 1 (raw materials) fully accepted (100), line 2 (packaging) half accepted (20).
        $map = [];
        foreach ($grn->items as $row) {
            $item = Item::query()->whereKey($row->item_id)->first();
            $isPackaging = ($item->item_type instanceof ItemType
                ? $item->item_type->value
                : (string) $item->item_type) === ItemType::Packaging->value;
            $map[$row->id] = $isPackaging ? '20' : (string) $row->quantity_received;
        }

        $accepted = $this->grnSvc->partialAccept($grn, $map, $this->user);

        // Sanity check: the underlying grn_items rows show the accepted qty we passed.
        $packagingAccepted = DB::table('grn_items')
            ->join('items', 'items.id', '=', 'grn_items.item_id')
            ->where('grn_items.goods_receipt_note_id', $accepted->id)
            ->where('items.item_type', ItemType::Packaging->value)
            ->value('grn_items.quantity_accepted');
        $this->assertSame('20.000', (string) $packagingAccepted, 'Packaging line must record 20 accepted in DB');

        $this->assertNotNull($accepted->journal_entry_id);
        $je = DB::table('journal_entries')->where('id', $accepted->journal_entry_id)->first();
        $this->assertSame((string) $je->total_debit, (string) $je->total_credit);

        $accountIds = DB::table('accounts')->whereIn('code', ['1200', '1220', '2110'])->pluck('id', 'code');

        $rawDebit = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $je->id)
            ->where('account_id', $accountIds['1200'])
            ->value('debit');
        $this->assertSame('1000.00', (string) $rawDebit, 'Raw-materials DR uses accepted qty (100 × 10)');

        $pkgDebit = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $je->id)
            ->where('account_id', $accountIds['1220'])
            ->value('debit');
        $this->assertSame('100.00', (string) $pkgDebit, 'Packaging DR uses accepted qty only (20 × 5), NOT received qty');

        $grniCredit = DB::table('journal_entry_lines')
            ->where('journal_entry_id', $je->id)
            ->where('account_id', $accountIds['2110'])
            ->value('credit');
        $this->assertSame('1100.00', (string) $grniCredit, 'GRNI CR equals only accepted value');
    }

    public function test_gl_post_failure_does_not_block_grn_acceptance(): void
    {
        $this->enableAccounting(true);

        // Sabotage the GL post by deleting the GRNI account.
        DB::table('accounts')->where('code', '2110')->delete();

        $grn = $this->buildGrn([
            ['item_type' => ItemType::RawMaterial->value, 'quantity' => '10', 'unit_cost' => '5.00'],
        ]);

        $accepted = $this->grnSvc->accept($grn, $this->user);

        $this->assertSame(
            GrnStatus::Accepted,
            $accepted->status,
            'GRN must still reach Accepted status even when GL posting fails',
        );
        $this->assertNull(
            $accepted->journal_entry_id,
            'No JE should be linked when the GL post fails',
        );
    }
}
