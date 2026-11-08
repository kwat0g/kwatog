<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Models\ChainListenerRun;
use App\Common\Services\OutboxEventCodec;
use App\Common\Services\SettingsService;
use App\Modules\Accounting\Models\Account;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\MovementGlHandoffStatus;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Events\InventoryStockChanged;
use App\Modules\Inventory\Events\StockMovementCompleted;
use App\Modules\Inventory\Events\StockMovementGlPostingRequested;
use App\Modules\Inventory\Exceptions\InvalidMovementException;
use App\Modules\Inventory\Listeners\PostStockMovementToGlOnRequested;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockMovementService;
use App\Modules\Inventory\Support\StockMovementInput;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * F-05 — every value-changing stock movement posts a balanced JE.
 *
 * Before the fix, only GRN receipts posted to the GL (GrnGlPostingService).
 * Adjustments, material issues, scrap, supplier returns and production
 * receipts moved the inventory ledger without ever reaching the GL, so the
 * inventory balance drifted silently from the accounting books. MovementGlPostingService
 * (wired into StockMovementService::move()) closes the gap.
 */
class MovementGlPostingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([StockMovementCompleted::class]);

        $this->seed(ChartOfAccountsSeeder::class);
        app(SettingsService::class)->set('modules.accounting', true, 'modules');

        $this->movements = app(StockMovementService::class);
        $this->item = Item::factory()->create(['is_active' => true]);
        $this->location = WarehouseLocation::factory()->create();
    }

    private StockMovementService $movements;

    private Item $item;

    private WarehouseLocation $location;

    /** @return array<string, int> code → account id */
    private function accountIds(): array
    {
        return Account::query()->pluck('id', 'code')->all();
    }

    /** @param list<string> $permissionSlugs */
    private function userWithPermissions(array $permissionSlugs): User
    {
        $role = Role::query()->create([
            'name' => 'Movement realtime test',
            'slug' => 'movement_realtime_'.bin2hex(random_bytes(4)),
            'is_system' => false,
        ]);
        foreach ($permissionSlugs as $slug) {
            $permission = Permission::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug, 'module' => explode('.', $slug)[0]],
            );
            $role->permissions()->attach($permission);
        }

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function assertMovementPosted(StockMovement $movement, string $drCode, string $drAmount, string $crCode, string $crAmount): void
    {
        $this->assertNotNull($movement->journal_entry_id, 'movement must be back-linked to its JE');

        $je = DB::table('journal_entries')->where('id', $movement->journal_entry_id)->firstOrFail();
        $this->assertSame('posted', $je->status);
        $this->assertSame('stock_movement', $je->reference_type);
        $this->assertSame((string) $je->total_debit, (string) $je->total_credit, 'JE must balance');

        $ids = $this->accountIds();
        $this->assertSame($drAmount, (string) DB::table('journal_entry_lines')
            ->where('journal_entry_id', $je->id)->where('account_id', $ids[$drCode])->value('debit'));
        $this->assertSame($crAmount, (string) DB::table('journal_entry_lines')
            ->where('journal_entry_id', $je->id)->where('account_id', $ids[$crCode])->value('credit'));
    }

    public function test_adjustment_in_debits_inventory_credits_cogs(): void
    {
        $m = $this->movements->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: $this->item->id,
            toLocationId: $this->location->id,
            quantity: '10.000',
            unitCost: '5.00',
            referenceType: 'opening',
            createdBy: User::factory()->create()->id,
        ));

        $this->assertMovementPosted($m, '1200', '50.00', '5010', '50.00');
    }

    public function test_material_issue_debits_material_consumption_credits_inventory(): void
    {
        $this->movements->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: $this->item->id,
            toLocationId: $this->location->id,
            quantity: '10.000',
            unitCost: '5.00',
            referenceType: 'opening',
        ));

        $m = $this->movements->move(new StockMovementInput(
            type: StockMovementType::MaterialIssue,
            itemId: $this->item->id,
            fromLocationId: $this->location->id,
            toLocationId: null,
            quantity: '4.000',
            unitCost: '5.00',
            referenceType: 'opening',
        ));

        $this->assertMovementPosted($m, '5010', '20.00', '1200', '20.00');
    }

    public function test_source_linked_material_return_debits_inventory_and_credits_material_consumption(): void
    {
        $this->movements->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: $this->item->id,
            toLocationId: $this->location->id,
            quantity: '10.000',
            unitCost: '5.00',
            referenceType: 'opening',
        ));
        $source = $this->movements->move(new StockMovementInput(
            type: StockMovementType::MaterialIssue,
            itemId: $this->item->id,
            fromLocationId: $this->location->id,
            quantity: '4.000',
            unitCost: '5.00',
            referenceType: 'opening',
        ));

        $returned = $this->movements->move(new StockMovementInput(
            type: StockMovementType::MaterialReturn,
            itemId: $this->item->id,
            toLocationId: $this->location->id,
            quantity: '2.000',
            unitCost: '5.00',
            referenceType: 'stock_movement',
            referenceId: $source->id,
            totalCostOverride: '10.00',
        ));

        $this->assertSame('stock_movement', $returned->reference_type);
        $this->assertSame($source->id, (int) $returned->reference_id);
        $this->assertMovementPosted($returned, '1200', '10.00', '5010', '10.00');
        $this->assertSame('8.000', (string) StockLevel::query()
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->value('quantity'));
    }

    public function test_source_value_override_is_rejected_for_non_return_movement_types(): void
    {
        $this->expectException(InvalidMovementException::class);
        $this->expectExceptionMessage('Source-value overrides are allowed only on a source-linked material or delivery return.');

        $this->movements->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: $this->item->id,
            toLocationId: $this->location->id,
            quantity: '1.000',
            unitCost: '5.00',
            referenceType: 'opening',
            totalCostOverride: '1.00',
        ));
    }

    public function test_return_to_vendor_debits_grni_credits_inventory(): void
    {
        $this->movements->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: $this->item->id,
            toLocationId: $this->location->id,
            quantity: '10.000',
            unitCost: '5.00',
            referenceType: 'opening',
        ));

        $m = $this->movements->move(new StockMovementInput(
            type: StockMovementType::ReturnToVendor,
            itemId: $this->item->id,
            fromLocationId: $this->location->id,
            toLocationId: null,
            quantity: '2.000',
            unitCost: '5.00',
            referenceType: 'opening',
        ));

        $this->assertMovementPosted($m, '2110', '10.00', '1200', '10.00');
    }

    public function test_transfer_posts_no_journal_entry(): void
    {
        $other = WarehouseLocation::factory()->create();

        $this->movements->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: $this->item->id,
            toLocationId: $this->location->id,
            quantity: '10.000',
            unitCost: '5.00',
            referenceType: 'opening',
        ));

        $m = $this->movements->move(new StockMovementInput(
            type: StockMovementType::Transfer,
            itemId: $this->item->id,
            fromLocationId: $this->location->id,
            toLocationId: $other->id,
            quantity: '3.000',
            unitCost: '5.00',
            referenceType: 'opening',
        ));

        $this->assertNull($m->journal_entry_id, 'location moves have no ledger impact');
        // Only the seeding adjustment posted a JE; the transfer added none.
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_zero_value_movement_is_not_posted(): void
    {
        $m = $this->movements->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: $this->item->id,
            toLocationId: $this->location->id,
            quantity: '5.000',
            unitCost: '0.00',
            referenceType: 'opening',
        ));

        $this->assertNull($m->journal_entry_id);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_grn_receipt_is_not_posted_by_movement_service(): void
    {
        // GRN receipts are owned by GrnGlPostingService; the movement-level
        // service must not double-post them.
        $m = $this->movements->move(new StockMovementInput(
            type: StockMovementType::GrnReceipt,
            itemId: $this->item->id,
            fromLocationId: null,
            toLocationId: $this->location->id,
            quantity: '10.000',
            unitCost: '5.00',
            referenceType: 'goods_receipt_note',
            referenceId: GoodsReceiptNote::factory()->create()->id,
        ));

        $this->assertNull($m->journal_entry_id);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_missing_gl_configuration_commits_stock_and_replays_idempotently(): void
    {
        $this->movements->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: $this->item->id,
            toLocationId: $this->location->id,
            quantity: '10.000',
            unitCost: '5.00',
            referenceType: 'opening',
        ));

        app(SettingsService::class)->set('accounting.accounts.material_consumption_code', '999999', 'accounting');

        $movement = $this->movements->move(new StockMovementInput(
            type: StockMovementType::MaterialIssue,
            itemId: $this->item->id,
            fromLocationId: $this->location->id,
            quantity: '4.000',
            unitCost: '5.00',
            referenceType: 'opening',
        ));

        $this->assertSame(MovementGlHandoffStatus::ManualRequired, $movement->gl_handoff_status);
        $this->assertNull($movement->journal_entry_id);
        $this->assertSame('6.000', (string) StockLevel::query()
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->value('quantity'));

        $outbox = DB::table('event_outbox')
            ->where('event_type', StockMovementGlPostingRequested::class)
            ->where('dedupe_key', 'stock-movement-gl-request:'.$movement->id)
            ->first();
        $this->assertNotNull($outbox);
        $this->assertDatabaseHas('chain_step_runs', [
            'outbox_id' => $outbox->id,
            'chain' => 'inventory',
            'entity_type' => 'stock_movement',
            'entity_id' => $movement->id,
            'step' => 'gl_handoff',
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('chain_listener_runs', [
            'outbox_id' => $outbox->id,
            'listener_class' => PostStockMovementToGlOnRequested::class,
            'outcome_status' => ChainListenerRun::OUTCOME_MANUAL_REQUIRED,
            'outcome_code' => 'movement_gl_posting_manual_required',
        ]);

        $event = app(OutboxEventCodec::class)->decode(
            $outbox->event_type,
            json_decode($outbox->payload, true, 512, JSON_THROW_ON_ERROR),
        );
        $this->assertInstanceOf(StockMovementGlPostingRequested::class, $event);

        app(SettingsService::class)->set('accounting.accounts.material_consumption_code', '5010', 'accounting');
        app(PostStockMovementToGlOnRequested::class)->handle($event);
        app(PostStockMovementToGlOnRequested::class)->handle($event);

        $posted = $movement->fresh();
        $this->assertSame(MovementGlHandoffStatus::Generated, $posted->gl_handoff_status);
        $this->assertNotNull($posted->journal_entry_id);
        $this->assertSame(2, DB::table('journal_entries')->count(), 'the opening JE plus one replayed movement JE');
        $this->assertSame(1, DB::table('journal_entries')
            ->where('reference_type', 'stock_movement')
            ->where('reference_id', $movement->id)
            ->count(), 'the movement must not be double-posted');
    }

    public function test_accounting_disabled_marks_value_change_not_required_without_recovery_event(): void
    {
        app(SettingsService::class)->set('modules.accounting', false, 'modules');

        $movement = $this->movements->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: $this->item->id,
            toLocationId: $this->location->id,
            quantity: '5.000',
            unitCost: '5.00',
            referenceType: 'opening',
        ));

        $this->assertSame(MovementGlHandoffStatus::NotRequired, $movement->gl_handoff_status);
        $this->assertNull($movement->journal_entry_id);
        $this->assertSame(0, DB::table('event_outbox')
            ->where('event_type', StockMovementGlPostingRequested::class)
            ->count());
    }

    public function test_retry_gl_route_requires_post_permission_and_posts_with_hashed_id(): void
    {
        app(SettingsService::class)->set('accounting.accounts.inventory_raw_material_code', '999999', 'accounting');
        $movement = $this->movements->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: $this->item->id,
            toLocationId: $this->location->id,
            quantity: '2.000',
            unitCost: '5.00',
            referenceType: 'opening',
        ));
        app(SettingsService::class)->set('accounting.accounts.inventory_raw_material_code', '1200', 'accounting');

        $this->assertSame(
            0,
            DB::table('event_outbox')->where('event_type', InventoryStockChanged::class)->count(),
            'The original movement event is faked here, isolating the retry signal.',
        );

        $role = Role::query()->create([
            'name' => 'GL Retry Test',
            'slug' => 'gl_retry_test_'.bin2hex(random_bytes(3)),
            'is_system' => false,
        ]);
        $permission = Permission::query()->create([
            'name' => 'Post journal entries',
            'slug' => 'accounting.journal.post',
            'module' => 'accounting',
        ]);
        $role->permissions()->attach($permission);
        $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/inventory/stock-movements/'.$movement->hash_id.'/retry-gl')
            ->assertOk()
            ->assertJsonPath('data.gl_handoff.status', MovementGlHandoffStatus::Generated->value)
            ->assertJsonPath('data.version', 3);

        $this->assertNotNull($movement->fresh()->journal_entry_id);
        $refresh = DB::table('event_outbox')->where('event_type', InventoryStockChanged::class)->first();
        $this->assertNotNull($refresh, 'A GL retry must stage a peer movement and WorkOrder refresh.');
        $payload = json_decode($refresh->payload, true, flags: JSON_THROW_ON_ERROR);
        $event = app(OutboxEventCodec::class)->decode($refresh->event_type, $payload);
        $this->assertInstanceOf(InventoryStockChanged::class, $event);
        $wirePayload = $event->broadcastWith();
        $this->assertSame('inventory_stock', $wirePayload['entity_type']);
        $this->assertArrayNotHasKey('movement_id', $wirePayload);
        $this->assertArrayNotHasKey('journal_entry_id', $wirePayload);
    }

    public function test_stock_movement_resource_projects_gl_retry_action_and_version_per_viewer(): void
    {
        app(SettingsService::class)->set('accounting.accounts.inventory_raw_material_code', '999999', 'accounting');
        $movement = $this->movements->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: $this->item->id,
            toLocationId: $this->location->id,
            quantity: '2.000',
            unitCost: '5.00',
            referenceType: 'opening',
        ));
        app(SettingsService::class)->set('accounting.accounts.inventory_raw_material_code', '1200', 'accounting');
        $actor = $this->userWithPermissions(['inventory.view', 'accounting.journal.post']);

        $this->actingAs($actor, 'sanctum')
            ->getJson('/api/v1/inventory/stock-movements?movement_id='.$movement->hash_id)
            ->assertOk()
            ->assertJsonPath('data.0.permissions.retry_gl', true)
            ->assertJsonPath('data.0.available_actions', ['retry_gl'])
            ->assertJsonPath('data.0.version', 2);
    }

    public function test_retry_gl_rejects_a_stale_action_after_the_movement_is_resolved(): void
    {
        app(SettingsService::class)->set('accounting.accounts.inventory_raw_material_code', '999999', 'accounting');
        $movement = $this->movements->move(new StockMovementInput(
            type: StockMovementType::AdjustmentIn,
            itemId: $this->item->id,
            toLocationId: $this->location->id,
            quantity: '2.000',
            unitCost: '5.00',
            referenceType: 'opening',
        ));
        app(SettingsService::class)->set('accounting.accounts.inventory_raw_material_code', '1200', 'accounting');
        $actor = $this->userWithPermissions(['accounting.journal.post']);
        $url = '/api/v1/inventory/stock-movements/'.$movement->hash_id.'/retry-gl';

        $this->actingAs($actor, 'sanctum')
            ->postJson($url)
            ->assertOk()
            ->assertJsonPath('data.gl_handoff.status', MovementGlHandoffStatus::Generated->value);

        $this->postJson($url)
            ->assertStatus(409)
            ->assertJsonPath('code', 'stock_movement_state_conflict');

        $this->assertSame(1, DB::table('journal_entries')
            ->where('reference_type', 'stock_movement')
            ->where('reference_id', $movement->id)
            ->count());
    }
}
