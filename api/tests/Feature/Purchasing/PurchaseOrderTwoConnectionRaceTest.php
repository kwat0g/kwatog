<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Common\Exceptions\BusinessRuleException;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\PurchaseRequestStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\PurchaseRequestItem;
use App\Modules\Purchasing\Services\PurchaseOrderService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M037-F05 — prove draft mutation cannot slip past a concurrent lifecycle
 * transition after the request has already read a stale PO instance.
 *
 * These tests intentionally use two PostgreSQL connections. A same-process
 * stale-model test cannot prove that the row lock makes the losing request
 * wait before its state guard runs.
 */
class PurchaseOrderTwoConnectionRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkflowSeeder::class);
    }

    public function test_submit_wins_over_a_stale_draft_update(): void
    {
        $this->requireTwoConnectionHarness();
        [$po] = $this->makeDraftPo();
        $result = tempnam(sys_get_temp_dir(), 'purchase-order-update-race-');

        $this->beginHeldPurchaseOrderLock($po->id);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);

        if ($pid === 0) {
            $this->runInHarnessConnection(function () use ($po, $result): void {
                try {
                    $stale = PurchaseOrder::query()->findOrFail($po->id);
                    app(PurchaseOrderService::class)->update($stale, [
                        'remarks' => 'This stale update must not land.',
                    ]);
                    file_put_contents($result, 'success');
                } catch (BusinessRuleException $e) {
                    file_put_contents($result, 'error:'.$e->getMessage());
                } catch (\Throwable $e) {
                    file_put_contents($result, 'unexpected:'.$e::class.':'.$e->getMessage());
                }
            });
            exit(0);
        }

        try {
            usleep(250000);
            $this->assertSame('', (string) @file_get_contents($result), 'The stale update must wait on the PO lock.');

            app(PurchaseOrderService::class)->submit($po->fresh());
        } finally {
            $this->releaseHeldPurchaseOrderLock();
            pcntl_waitpid($pid, $status);
        }

        $this->assertStringContainsString('Only draft POs can be edited.', (string) file_get_contents($result));
        $fresh = $po->fresh();
        $this->assertSame(PurchaseOrderStatus::PendingApproval, $fresh->status);
        $this->assertNull($fresh->remarks);
        @unlink($result);
    }

    public function test_submit_wins_over_a_stale_delete_without_reopening_the_source_pr(): void
    {
        $this->requireTwoConnectionHarness();
        [$po, $pr] = $this->makeDraftPoWithConvertedPr();
        $result = tempnam(sys_get_temp_dir(), 'purchase-order-delete-race-');

        $this->beginHeldPurchaseOrderLock($po->id);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);

        if ($pid === 0) {
            $this->runInHarnessConnection(function () use ($po, $result): void {
                try {
                    $stale = PurchaseOrder::query()->findOrFail($po->id);
                    app(PurchaseOrderService::class)->delete($stale);
                    file_put_contents($result, 'success');
                } catch (BusinessRuleException $e) {
                    file_put_contents($result, 'error:'.$e->getMessage());
                } catch (\Throwable $e) {
                    file_put_contents($result, 'unexpected:'.$e::class.':'.$e->getMessage());
                }
            });
            exit(0);
        }

        try {
            usleep(250000);
            $this->assertSame('', (string) @file_get_contents($result), 'The stale delete must wait on the PO lock.');

            app(PurchaseOrderService::class)->submit($po->fresh());
        } finally {
            $this->releaseHeldPurchaseOrderLock();
            pcntl_waitpid($pid, $status);
        }

        $this->assertStringContainsString('Only draft POs can be deleted.', (string) file_get_contents($result));
        $this->assertSame(PurchaseOrderStatus::PendingApproval, $po->fresh()->status);
        $this->assertSame(PurchaseRequestStatus::Converted, $pr->fresh()->status);
        $this->assertDatabaseHas('purchase_orders', ['id' => $po->id]);
        @unlink($result);
    }

    public function test_approve_waits_for_a_concurrent_terminal_transition(): void
    {
        $this->requireTwoConnectionHarness();
        $this->seed([RolePermissionSeeder::class, SettingsSeeder::class]);
        [$po] = $this->makeDraftPo();
        $pending = app(PurchaseOrderService::class)->submit($po);
        $approver = User::factory()->create([
            'role_id' => Role::query()->where('slug', 'finance_officer')->value('id'),
        ]);
        $result = tempnam(sys_get_temp_dir(), 'purchase-order-approve-race-');

        $this->beginHeldPurchaseOrderLock($pending->id);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);

        if ($pid === 0) {
            $this->runInHarnessConnection(function () use ($pending, $approver, $result): void {
                try {
                    $stale = PurchaseOrder::query()->findOrFail($pending->id);
                    app(PurchaseOrderService::class)->approve($stale, $approver);
                    file_put_contents($result, 'success');
                } catch (BusinessRuleException $e) {
                    file_put_contents($result, 'error:'.$e->getMessage());
                } catch (\Throwable $e) {
                    file_put_contents($result, 'unexpected:'.$e::class.':'.$e->getMessage());
                }
            });
            exit(0);
        }

        try {
            usleep(250000);
            $this->assertSame('', (string) @file_get_contents($result), 'The stale approval must wait on the PO lock.');

            DB::table('purchase_orders')
                ->where('id', $pending->id)
                ->update(['status' => PurchaseOrderStatus::Cancelled->value]);
        } finally {
            $this->releaseHeldPurchaseOrderLock();
            pcntl_waitpid($pid, $status);
        }

        $this->assertStringContainsString('PO is not in an approvable state.', (string) file_get_contents($result));
        $this->assertSame(PurchaseOrderStatus::Cancelled, $pending->fresh()->status);
        @unlink($result);
    }

    private function requireTwoConnectionHarness(): void
    {
        if (! function_exists('pcntl_fork') || DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requires PostgreSQL and pcntl.');
        }
    }

    private function beginHeldPurchaseOrderLock(int $poId): void
    {
        // RefreshDatabase starts a transaction around the test. Commit it
        // before forking so the child can open an independent PostgreSQL
        // transaction instead of inheriting an unusable test transaction.
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }

        DB::beginTransaction();
        DB::table('purchase_orders')->where('id', $poId)->lockForUpdate()->first();
    }

    private function releaseHeldPurchaseOrderLock(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::commit();
        }
    }

    /** @param callable(): void $callback */
    private function runInHarnessConnection(callable $callback): void
    {
        $base = config('database.connections.'.config('database.default'));
        config(['database.connections.harness' => $base, 'database.default' => 'harness']);
        DB::connection('harness')->getPdo();
        $callback();
    }

    /** @return array{0: PurchaseOrder, 1?: PurchaseRequest} */
    private function makeDraftPoWithConvertedPr(): array
    {
        $user = User::factory()->create();
        $vendor = Vendor::factory()->create();
        $item = Item::factory()->create();
        $pr = PurchaseRequest::factory()->create([
            'requested_by' => $user->id,
            'department_id' => null,
        ]);
        $pr->forceFill(['status' => PurchaseRequestStatus::Converted->value])->save();
        $sourceLine = PurchaseRequestItem::create([
            'purchase_request_id' => $pr->id,
            'item_id' => $item->id,
            'description' => 'Race source line',
            'quantity' => '1.00',
            'estimated_unit_price' => '100.00',
        ]);

        $po = $this->createDraftPo($user, $vendor, $item, $pr, $sourceLine);

        return [$po, $pr];
    }

    /** @return array{0: PurchaseOrder} */
    private function makeDraftPo(): array
    {
        $user = User::factory()->create();
        $vendor = Vendor::factory()->create();
        $item = Item::factory()->create();

        return [$this->createDraftPo($user, $vendor, $item)];
    }

    private function createDraftPo(
        User $user,
        Vendor $vendor,
        Item $item,
        ?PurchaseRequest $pr = null,
        ?PurchaseRequestItem $sourceLine = null,
    ): PurchaseOrder {
        $po = PurchaseOrder::create([
            'po_number' => 'PO-RACE-'.strtoupper(substr(uniqid(), -8)),
            'vendor_id' => $vendor->id,
            'purchase_request_id' => $pr?->id,
            'date' => now()->toDateString(),
            'subtotal' => '100.00',
            'vat_amount' => '0.00',
            'total_amount' => '100.00',
            'is_vatable' => false,
            'created_by' => $user->id,
        ]);
        $po->forceFill([
            'status' => PurchaseOrderStatus::Draft,
            'current_approval_step' => 0,
        ])->save();
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'purchase_request_item_id' => $sourceLine?->id,
            'description' => 'Race test line',
            'quantity' => '1.00',
            'unit' => 'pcs',
            'unit_price' => '100.00',
            'total' => '100.00',
        ]);

        return $po->fresh();
    }
}
