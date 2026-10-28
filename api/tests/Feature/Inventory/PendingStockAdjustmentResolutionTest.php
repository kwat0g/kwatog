<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Common\Models\AuditLog;
use App\Common\Services\SettingsService;
use App\Modules\Auth\Models\Permission;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Enums\StockAdjustmentReason;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockLevel;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\StockAdjustmentService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PendingStockAdjustmentResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;

    private WarehouseLocation $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->item = Item::factory()->create(['is_active' => true]);
        $this->location = WarehouseLocation::factory()->create();
        StockLevel::query()->create([
            'item_id' => $this->item->id,
            'location_id' => $this->location->id,
            'quantity' => '1000.000',
            'reserved_quantity' => '0.000',
            'weighted_avg_cost' => '10.0000',
            'lock_version' => 0,
        ]);
        app(SettingsService::class)->set('inventory.adjustment_approval_threshold', 1);
    }

    public function test_requester_can_withdraw_pending_adjustment_without_posting_stock(): void
    {
        $requester = $this->userWith('warehouse_staff', ['inventory.adjust']);
        $adjustment = $this->pendingAdjustment($requester);
        $quantityBefore = $this->stockQuantity();
        $movementCount = StockMovement::query()->count();

        $response = $this->actingAs($requester)
            ->patchJson($this->url($adjustment, 'withdraw'))
            ->assertOk()
            ->assertJsonPath('data.status', 'withdrawn')
            ->assertJsonPath('data.resolved_by.id', $requester->hash_id)
            ->assertJsonPath('data.resolution_reason', null);

        $this->assertNotEmpty($response->json('data.resolved_at'));
        $this->assertSame('withdrawn', $adjustment->fresh()->status->value);
        $this->assertNull($adjustment->fresh()->stock_movement_id);
        $this->assertNull($adjustment->fresh()->approved_by);
        $this->assertSame($quantityBefore, $this->stockQuantity());
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertAuditTransition($adjustment, $requester, 'pending', 'withdrawn', null);

        $auditsBeforeRetry = $this->adjustmentAuditCount($adjustment);
        $this->actingAs($requester)
            ->patchJson($this->url($adjustment, 'withdraw'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Adjustment is no longer pending.');

        $this->assertSame($auditsBeforeRetry, $this->adjustmentAuditCount($adjustment));
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame($quantityBefore, $this->stockQuantity());
    }

    public function test_finance_rejects_pending_adjustment_only_with_a_reason_and_no_stock_effect(): void
    {
        $requester = $this->userWith('warehouse_staff', ['inventory.adjust']);
        $finance = $this->userWith('finance_officer', ['inventory.adjust.approve']);
        $adjustment = $this->pendingAdjustment($requester);
        $approvalNotice = DB::table('notifications')
            ->where('notifiable_id', $finance->id)
            ->where('type', 'approval.action_required')
            ->whereRaw("data->>'entity_id' = ?", [$adjustment->hash_id])
            ->first();
        $this->assertNotNull($approvalNotice);
        $approvalData = json_decode((string) $approvalNotice->data, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(StockAdjustment::class, $approvalData['subject_type']);
        $this->assertSame((string) $adjustment->id, $approvalData['subject_id']);
        $quantityBefore = $this->stockQuantity();
        $movementCount = StockMovement::query()->count();

        $this->actingAs($finance)
            ->patchJson($this->url($adjustment, 'reject'), ['reason' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $this->assertSame('pending', $adjustment->fresh()->status->value);
        $this->assertNull($adjustment->fresh()->resolution_reason);
        $this->assertSame(1, $this->adjustmentAuditCount($adjustment));
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame($quantityBefore, $this->stockQuantity());

        $response = $this->actingAs($finance)
            ->patchJson($this->url($adjustment, 'reject'), [
                'reason' => '  Count sheet does not support this adjustment.  ',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.resolved_by.id', $finance->hash_id)
            ->assertJsonPath('data.resolution_reason', 'Count sheet does not support this adjustment.');

        $this->assertNotEmpty($response->json('data.resolved_at'));
        $this->assertSame('rejected', $adjustment->fresh()->status->value);
        $this->assertNull($adjustment->fresh()->stock_movement_id);
        $outcomeNotice = DB::table('notifications')
            ->where('notifiable_id', $requester->id)
            ->where('type', 'approval.rejected')
            ->whereRaw("data->>'entity_id' = ?", [$adjustment->hash_id])
            ->first();
        $this->assertNotNull($outcomeNotice);
        $outcomeData = json_decode((string) $outcomeNotice->data, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(StockAdjustment::class, $outcomeData['subject_type']);
        $this->assertSame((string) $adjustment->id, $outcomeData['subject_id']);
        $this->assertNull($adjustment->fresh()->approved_by);
        $this->assertSame($quantityBefore, $this->stockQuantity());
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertAuditTransition(
            $adjustment,
            $finance,
            'pending',
            'rejected',
            'Count sheet does not support this adjustment.',
        );

        $this->actingAs($finance)
            ->getJson('/api/v1/inventory/stock-adjustments?status=rejected')
            ->assertOk()
            ->assertJsonPath('data.0.id', $adjustment->hash_id);
    }

    public function test_outcome_notice_requires_a_permission_that_can_open_the_adjustment_list(): void
    {
        $finance = $this->userWith('finance_officer', ['inventory.adjust.approve']);
        $requester = $this->userWith('warehouse_staff', ['inventory.adjust']);
        $adjustment = $this->pendingAdjustment($requester);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $requester->id,
            'permission_id' => Permission::query()->where('slug', 'inventory.adjust')->value('id'),
            'type' => 'revoke',
            'granted_by' => $finance->id,
            'reason' => 'Adjustment outcome notice access test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $requester->flushPermissionsCache();

        $this->actingAs($finance)
            ->patchJson($this->url($adjustment, 'reject'), ['reason' => 'Access revoked before decision.'])
            ->assertOk();

        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $requester->id,
            'type' => 'approval.rejected',
        ]);

        $approverOnlyRequester = $this->userWith('warehouse_staff', ['inventory.adjust']);
        $approvalPermission = Permission::query()->where('slug', 'inventory.adjust.approve')->firstOrFail();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $approverOnlyRequester->id,
            'permission_id' => $approvalPermission->id,
            'type' => 'grant',
            'granted_by' => $finance->id,
            'reason' => 'Adjustment list permission test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $approverOnlyRequester->flushPermissionsCache();
        $visibleAdjustment = $this->pendingAdjustment($approverOnlyRequester);
        DB::table('user_permission_overrides')->insert([
            'user_id' => $approverOnlyRequester->id,
            'permission_id' => Permission::query()->where('slug', 'inventory.adjust')->value('id'),
            'type' => 'revoke',
            'granted_by' => $finance->id,
            'reason' => 'Adjustment list permission test.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $approverOnlyRequester->flushPermissionsCache();

        $this->actingAs($finance)
            ->patchJson($this->url($visibleAdjustment, 'approve'))
            ->assertOk();

        $notice = DB::table('notifications')
            ->where('notifiable_id', $approverOnlyRequester->id)
            ->where('type', 'approval.completed')
            ->whereRaw("data->>'entity_type' = 'stock_adjustment'")
            ->whereRaw("data->>'entity_id' = ?", [$visibleAdjustment->hash_id])
            ->first();
        $this->assertNotNull($notice);
        $this->assertSame('/inventory/stock-adjustments', json_decode((string) $notice->data, true, flags: JSON_THROW_ON_ERROR)['link_to']);
    }

    public function test_wrong_actors_and_every_action_after_a_terminal_state_are_rejected_without_writes(): void
    {
        $requester = $this->userWith('warehouse_staff', ['inventory.adjust']);
        $otherRequester = $this->userWith('warehouse_staff', ['inventory.adjust']);
        $finance = $this->userWith('finance_officer', ['inventory.adjust.approve']);
        $adjustment = $this->pendingAdjustment($requester);
        $quantityBefore = $this->stockQuantity();
        $movementCount = StockMovement::query()->count();

        $this->actingAs($requester)
            ->patchJson($this->url($adjustment, 'reject'), ['reason' => 'Not an approver on this request.'])
            ->assertForbidden();
        $this->actingAs($otherRequester)
            ->patchJson($this->url($adjustment, 'withdraw'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only the requester may withdraw this adjustment.');
        $this->actingAs($finance)
            ->patchJson($this->url($adjustment, 'withdraw'))
            ->assertForbidden();

        $this->assertSame('pending', $adjustment->fresh()->status->value);
        $this->assertSame(1, $this->adjustmentAuditCount($adjustment));
        $this->assertSame($quantityBefore, $this->stockQuantity());
        $this->assertSame($movementCount, StockMovement::query()->count());

        $dualRoleRequester = $this->userWith('system_admin', ['inventory.adjust', 'inventory.adjust.approve']);
        $selfAdjustment = $this->pendingAdjustment($dualRoleRequester);
        $this->actingAs($dualRoleRequester)
            ->patchJson($this->url($selfAdjustment, 'approve'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The requester cannot approve their own adjustment.');
        $this->actingAs($dualRoleRequester)
            ->patchJson($this->url($selfAdjustment, 'reject'), ['reason' => 'I reviewed my own request.'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The requester cannot reject their own adjustment.');
        $this->assertSame('pending', $selfAdjustment->fresh()->status->value);
        $this->assertNull($selfAdjustment->fresh()->stock_movement_id);

        $terminal = [];
        $approved = $this->pendingAdjustment($requester);
        $this->actingAs($finance)->patchJson($this->url($approved, 'approve'))->assertOk();
        $terminal['approved'] = [$approved, $requester, $finance, 'approved'];

        $rejected = $this->pendingAdjustment($requester);
        $this->actingAs($finance)->patchJson($this->url($rejected, 'reject'), ['reason' => 'Rejected for audit test.'])->assertOk();
        $terminal['rejected'] = [$rejected, $requester, $finance, 'rejected'];

        $withdrawn = $this->pendingAdjustment($requester);
        $this->actingAs($requester)->patchJson($this->url($withdrawn, 'withdraw'))->assertOk();
        $terminal['withdrawn'] = [$withdrawn, $requester, $finance, 'withdrawn'];

        foreach ($terminal as [$record, $owner, $reviewer, $state]) {
            $record = $record->fresh();
            $auditCount = $this->adjustmentAuditCount($record);
            $movementCount = StockMovement::query()->count();
            $quantity = $this->stockQuantity();

            $this->actingAs($reviewer)
                ->patchJson($this->url($record, 'approve'))
                ->assertUnprocessable();
            $this->actingAs($reviewer)
                ->patchJson($this->url($record, 'reject'), ['reason' => 'Late state change.'])
                ->assertUnprocessable();
            $this->actingAs($owner)
                ->patchJson($this->url($record, 'withdraw'))
                ->assertUnprocessable();

            $this->assertSame($state, $record->fresh()->status->value);
            $this->assertSame($auditCount, $this->adjustmentAuditCount($record));
            $this->assertSame($movementCount, StockMovement::query()->count());
            $this->assertSame($quantity, $this->stockQuantity());
        }
    }

    public function test_database_constraint_blocks_a_rejected_status_without_resolution_evidence(): void
    {
        $requester = $this->userWith('warehouse_staff', ['inventory.adjust']);
        $adjustment = $this->pendingAdjustment($requester);

        try {
            DB::transaction(static function () use ($adjustment): void {
                DB::table('stock_adjustments')->where('id', $adjustment->id)->update(['status' => 'rejected']);
            });
            $this->fail('The database must reject rejection without actor, time, and reason evidence.');
        } catch (QueryException) {
            $this->assertSame('pending', $adjustment->fresh()->status->value);
        }
    }

    public function test_database_constraint_requires_resolution_actor_for_terminal_resolution(): void
    {
        $requester = $this->userWith('warehouse_staff', ['inventory.adjust']);
        $adjustment = $this->pendingAdjustment($requester);

        try {
            DB::transaction(static function () use ($adjustment): void {
                DB::table('stock_adjustments')->where('id', $adjustment->id)->update([
                    'status' => 'rejected',
                    'resolved_at' => now(),
                    'resolution_reason' => 'Evidence exists but actor is missing.',
                ]);
            });
            $this->fail('The database must reject a resolution that has no recorded actor.');
        } catch (QueryException) {
            $this->assertSame('pending', $adjustment->fresh()->status->value);
            $this->assertNull($adjustment->fresh()->resolved_by);
        }
    }

    public function test_migration_can_roll_back_and_reapply_before_resolution_data_exists(): void
    {
        $migration = require database_path('migrations/2026_10_08_190000_add_stock_adjustment_resolution_fields.php');
        $migration->down();

        $this->assertFalse(Schema::hasColumn('stock_adjustments', 'resolved_by'));
        $this->assertFalse(Schema::hasColumn('stock_adjustments', 'resolved_at'));
        $this->assertFalse(Schema::hasColumn('stock_adjustments', 'resolution_reason'));

        $migration->up();

        $this->assertTrue(Schema::hasColumn('stock_adjustments', 'resolved_by'));
        $this->assertTrue(Schema::hasColumn('stock_adjustments', 'resolved_at'));
        $this->assertTrue(Schema::hasColumn('stock_adjustments', 'resolution_reason'));
    }

    public function test_migration_rollback_preserves_rejected_records_and_their_evidence(): void
    {
        $requester = $this->userWith('warehouse_staff', ['inventory.adjust']);
        $finance = $this->userWith('finance_officer', ['inventory.adjust.approve']);
        $adjustment = $this->pendingAdjustment($requester);
        $this->actingAs($finance)
            ->patchJson($this->url($adjustment, 'reject'), ['reason' => 'Keep this audit evidence.'])
            ->assertOk();

        $migration = require database_path('migrations/2026_10_08_190000_add_stock_adjustment_resolution_fields.php');
        try {
            $migration->down();
            $this->fail('Rollback must preserve resolved statuses and their evidence.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('resolved records exist', $error->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('stock_adjustments', 'resolved_by'));
        $this->assertSame('rejected', $adjustment->fresh()->status->value);
        $this->assertSame('Keep this audit evidence.', $adjustment->fresh()->resolution_reason);
    }

    private function userWith(string $roleSlug, array $permissions): User
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug]);
        $role->permissions()->syncWithoutDetaching(
            Permission::query()->whereIn('slug', $permissions)->pluck('id')->all(),
        );

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function pendingAdjustment(User $requester): StockAdjustment
    {
        return app(StockAdjustmentService::class)->create(
            itemId: $this->item->id,
            locationId: $this->location->id,
            direction: 'out',
            qty: '5',
            unitCost: '10.0000',
            reason: 'Resolution test adjustment '.uniqid(),
            by: $requester,
            reasonCode: StockAdjustmentReason::Damage,
        );
    }

    private function url(StockAdjustment $adjustment, string $action): string
    {
        return "/api/v1/inventory/stock-adjustments/{$adjustment->hash_id}/{$action}";
    }

    private function stockQuantity(): string
    {
        return (string) StockLevel::query()
            ->where('item_id', $this->item->id)
            ->where('location_id', $this->location->id)
            ->value('quantity');
    }

    private function adjustmentAuditCount(StockAdjustment $adjustment): int
    {
        return AuditLog::query()
            ->where('model_type', StockAdjustment::class)
            ->where('model_id', $adjustment->id)
            ->count();
    }

    private function assertAuditTransition(
        StockAdjustment $adjustment,
        User $actor,
        string $from,
        string $to,
        ?string $reason,
    ): void {
        $audit = AuditLog::query()
            ->where('model_type', StockAdjustment::class)
            ->where('model_id', $adjustment->id)
            ->where('action', 'updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($actor->id, $audit->user_id);
        $this->assertSame($from, $audit->old_values['status']);
        $this->assertSame($to, $audit->new_values['status']);
        $this->assertSame($reason, $audit->new_values['resolution_reason'] ?? null);
    }
}
