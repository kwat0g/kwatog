<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Vendor;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\Purchasing\Contracts\SupplierDispatchGateway;
use App\Modules\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Purchasing\Enums\SupplierDispatchStatus;
use App\Modules\Purchasing\Events\PurchaseOrderApproved;
use App\Modules\Purchasing\Events\PurchaseOrderCancelled;
use App\Modules\Purchasing\Listeners\CloseSupplierDispatchOnPurchaseOrderCancelled;
use App\Modules\Purchasing\Listeners\PrepareSupplierDispatch;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierOrderDispatch;
use App\Modules\Purchasing\Services\SupplierDispatchService;
use App\Modules\Purchasing\Support\SupplierDispatchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class SupplierDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_po_is_published_to_the_supplier_portal_once(): void
    {
        $vendor = Vendor::factory()->create();
        SupplierPortalUser::create([
            'vendor_id' => $vendor->id,
            'name' => 'Portal recipient',
            'email' => 'portal-'.uniqid().'@test.local',
            'password' => bcrypt('Password1!'),
            'is_active' => true,
        ]);
        $po = $this->approvedPo($vendor);

        (new PrepareSupplierDispatch(app(SupplierDispatchService::class)))
            ->handle(new PurchaseOrderApproved($po));
        (new PrepareSupplierDispatch(app(SupplierDispatchService::class)))
            ->handle(new PurchaseOrderApproved($po->fresh()));

        $dispatch = SupplierOrderDispatch::query()->where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertSame(SupplierDispatchStatus::PortalAvailable, $dispatch->status);
        $this->assertSame('supplier_portal', $dispatch->channel);
        $this->assertSame(1, $dispatch->recipient_count);
        $this->assertSame(1, $dispatch->attempts);
        $this->assertSame(1, SupplierOrderDispatch::query()->where('purchase_order_id', $po->id)->count());
    }

    public function test_missing_portal_user_creates_an_actionable_manual_dispatch(): void
    {
        $po = $this->approvedPo();

        app(SupplierDispatchService::class)->prepareForApproved($po);

        $dispatch = SupplierOrderDispatch::query()->where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertSame(SupplierDispatchStatus::ManualRequired, $dispatch->status);
        $this->assertSame('manual', $dispatch->channel);
        $this->assertStringContainsString('No active supplier portal user', (string) $dispatch->last_error);
        $this->assertSame('send_pdf_and_confirm', $dispatch->metadata['next_action']);
    }

    public function test_replayed_approval_after_po_is_sent_only_reconciles_and_does_not_publish(): void
    {
        $po = $this->approvedPo();
        $po->forceFill(['status' => PurchaseOrderStatus::Sent])->save();
        $calls = 0;

        app()->instance(SupplierDispatchGateway::class, new class($calls) implements SupplierDispatchGateway
        {
            public function __construct(private int &$calls) {}

            public function publish(PurchaseOrder $purchaseOrder, string $idempotencyKey): SupplierDispatchResult
            {
                $this->calls++;

                return SupplierDispatchResult::portalAvailable(1, ['idempotency_key' => $idempotencyKey]);
            }
        });

        $dispatch = app(SupplierDispatchService::class)->prepareForApproved($po->fresh());

        $this->assertNotNull($dispatch);
        $this->assertSame(SupplierDispatchStatus::Confirmed, $dispatch->status);
        $this->assertSame(0, $calls);
    }

    public function test_provider_failure_is_persisted_and_a_later_retry_can_recover(): void
    {
        $po = $this->approvedPo();
        $calls = 0;

        app()->instance(SupplierDispatchGateway::class, new class($calls) implements SupplierDispatchGateway
        {
            public function __construct(private int &$calls) {}

            public function publish(PurchaseOrder $purchaseOrder, string $idempotencyKey): SupplierDispatchResult
            {
                $this->calls++;
                if ($this->calls === 1) {
                    throw new RuntimeException('provider unavailable');
                }

                return SupplierDispatchResult::portalAvailable(1, ['idempotency_key' => $idempotencyKey]);
            }
        });

        try {
            app(SupplierDispatchService::class)->prepareForApproved($po);
            $this->fail('The provider failure should be rethrown for queue retry.');
        } catch (RuntimeException $e) {
            $this->assertSame('provider unavailable', $e->getMessage());
        }

        $failed = SupplierOrderDispatch::query()->where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertSame(SupplierDispatchStatus::Failed, $failed->status);

        $recovered = app(SupplierDispatchService::class)->prepareForApproved($po->fresh());
        $this->assertNotNull($recovered);
        $this->assertSame(SupplierDispatchStatus::PortalAvailable, $recovered->status);
        $this->assertSame(2, $recovered->attempts);
    }

    public function test_provider_acceptance_before_local_crash_reuses_the_same_idempotency_key_on_retry(): void
    {
        $po = $this->approvedPo();
        $calls = 0;
        $keys = [];

        app()->instance(SupplierDispatchGateway::class, new class($calls, $keys) implements SupplierDispatchGateway
        {
            /** @param array<int, string> $keys */
            public function __construct(private int &$calls, private array &$keys) {}

            public function publish(PurchaseOrder $purchaseOrder, string $idempotencyKey): SupplierDispatchResult
            {
                $this->calls++;
                $this->keys[] = $idempotencyKey;

                // The provider accepted the idempotency key, but the worker
                // died before it could persist the success response locally.
                if ($this->calls === 1) {
                    throw new RuntimeException('provider accepted before local finalization');
                }

                return SupplierDispatchResult::portalAvailable(1, [
                    'provider_receipt' => 'accepted-on-retry',
                ]);
            }
        });

        try {
            app(SupplierDispatchService::class)->prepareForApproved($po);
            $this->fail('The simulated post-acceptance crash should reach retry handling.');
        } catch (RuntimeException $e) {
            $this->assertSame('provider accepted before local finalization', $e->getMessage());
        }

        $this->assertSame(
            SupplierDispatchStatus::Failed,
            SupplierOrderDispatch::query()->where('purchase_order_id', $po->id)->firstOrFail()->status,
        );

        $recovered = app(SupplierDispatchService::class)->prepareForApproved($po->fresh());

        $this->assertSame(SupplierDispatchStatus::PortalAvailable, $recovered?->status);
        $this->assertCount(2, $keys);
        $this->assertSame($keys[0], $keys[1]);
        $this->assertSame(2, $recovered?->attempts);
    }

    public function test_confirming_sent_is_idempotent_and_closes_the_dispatch_boundary(): void
    {
        $po = $this->approvedPo();
        $service = app(SupplierDispatchService::class);

        $service->prepareForApproved($po);
        $first = $service->confirmSent($po, 'manual_email');
        $second = $service->confirmSent($po->fresh(), 'manual_email');

        $this->assertSame(SupplierDispatchStatus::Confirmed, $second->status);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, SupplierOrderDispatch::query()->where('purchase_order_id', $po->id)->count());
    }

    public function test_scheduled_recovery_reclaims_a_stale_pending_dispatch(): void
    {
        $po = $this->approvedPo();
        $staleAt = Carbon::now()->subMinutes(20);

        SupplierOrderDispatch::create([
            'purchase_order_id' => $po->id,
            'idempotency_key' => "purchase-order:{$po->id}:approved:v1",
            'status' => SupplierDispatchStatus::Pending,
            'attempts' => 1,
            'queued_at' => $staleAt,
            'last_attempt_at' => $staleAt,
        ]);

        $this->artisan('supplier:dispatch-recover')
            ->assertSuccessful()
            ->expectsOutputToContain('recovered 1');

        $dispatch = SupplierOrderDispatch::query()->where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertSame(SupplierDispatchStatus::ManualRequired, $dispatch->status);
        $this->assertSame(2, $dispatch->attempts);
    }

    public function test_failed_dispatch_requires_explicit_retry_flag(): void
    {
        $po = $this->approvedPo();
        $failedAt = Carbon::now()->subMinutes(20);

        SupplierOrderDispatch::create([
            'purchase_order_id' => $po->id,
            'idempotency_key' => "purchase-order:{$po->id}:approved:v1",
            'status' => SupplierDispatchStatus::Failed,
            'attempts' => 1,
            'last_attempt_at' => $failedAt,
            'last_error' => 'provider unavailable',
        ]);

        $this->artisan('supplier:dispatch-recover')
            ->assertSuccessful()
            ->expectsOutputToContain('scanned 0');
        $this->assertSame(
            SupplierDispatchStatus::Failed,
            SupplierOrderDispatch::query()->where('purchase_order_id', $po->id)->firstOrFail()->status,
        );

        $this->artisan('supplier:dispatch-recover', ['--retry-failed' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('recovered 1');
        $this->assertSame(
            SupplierDispatchStatus::ManualRequired,
            SupplierOrderDispatch::query()->where('purchase_order_id', $po->id)->firstOrFail()->status,
        );
    }

    public function test_purchase_order_cancellation_closes_dispatch_and_replay_is_idempotent(): void
    {
        $po = $this->approvedPo();
        app(SupplierDispatchService::class)->prepareForApproved($po);

        $listener = app(CloseSupplierDispatchOnPurchaseOrderCancelled::class);
        $event = new PurchaseOrderCancelled($po);
        $listener->handle($event);
        $listener->handle(new PurchaseOrderCancelled($po->fresh()));

        $dispatch = SupplierOrderDispatch::query()->where('purchase_order_id', $po->id)->firstOrFail();
        $this->assertSame(SupplierDispatchStatus::Cancelled, $dispatch->status);
        $this->assertTrue((bool) ($dispatch->metadata['cancelled_by_process'] ?? false));
    }

    private function approvedPo(?Vendor $vendor = null): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create([
            'vendor_id' => ($vendor ?? Vendor::factory()->create())->id,
        ]);
        $po->forceFill(['status' => 'approved'])->save();

        return $po->fresh();
    }
}
