<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Common\Events\ChainStepAdvanced;
use App\Common\Services\ChainBroadcaster;
use App\Common\Services\OutboxEventCodec;
use App\Common\Services\OutboxService;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Production\Enums\WorkOrderStatus;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Series C — Task C4. Unit tests for ChainBroadcaster.
 *
 * Uses Event::fake() — we don't actually want to talk to Reverb during
 * tests. We're verifying that the right payload is dispatched.
 */
class ChainBroadcasterTest extends TestCase
{
    public function test_chain_envelope_round_trips_without_internal_or_cross_scope_fields(): void
    {
        $event = new ChainStepAdvanced(
            entityType: 'purchase_order',
            entityHashId: 'purchase-order-hash',
            docNumber: 'PO-PRIVATE-42',
            newStatus: 'sent',
            activeStep: 'supplier',
            completedSteps: ['approved'],
            actorName: 'Internal User',
            eventId: 'chain-event-42',
            driverHashIds: ['driver-hash'],
            customerHashId: 'customer-hash',
            supplierVendorHashId: 'vendor-hash',
            relatedPurchaseRequestHashIds: ['purchase-request-hash'],
            action: 'status_changed',
            actorHashId: 'actor-hash',
            version: '2026-11-03T14:00:00+08:00',
            timestamp: '2026-11-03T14:00:01+08:00',
        );
        $expected = [
            'refresh' => true,
            'event_id' => 'chain-event-42',
            'entity_type' => 'purchase_order',
            'entity_id' => 'purchase-order-hash',
            'action' => 'status_changed',
            'version' => '2026-11-03T06:00:00+00:00',
            'actor_id' => 'actor-hash',
            'related' => [],
            'timestamp' => '2026-11-03T14:00:01+08:00',
        ];

        $codec = app(OutboxEventCodec::class);
        $encoded = $codec->encode($event);
        $decoded = $codec->decode($encoded['event_type'], $encoded['payload']);

        $this->assertSame($expected, $event->broadcastWith());
        $this->assertSame($expected, $decoded->broadcastWith());
        $this->assertArrayNotHasKey('docNumber', $event->broadcastWith());
        $this->assertArrayNotHasKey('newStatus', $event->broadcastWith());
        $this->assertArrayNotHasKey('actorName', $event->broadcastWith());
        $this->assertArrayNotHasKey('supplierVendorHashId', $event->broadcastWith());
        $this->assertArrayNotHasKey('relatedPurchaseRequestHashIds', $event->broadcastWith());

        $legacyPayload = $encoded['payload'];
        unset(
            $legacyPayload['action'],
            $legacyPayload['actorHashId'],
            $legacyPayload['version'],
            $legacyPayload['timestamp'],
        );
        $legacyDecoded = $codec->decode($encoded['event_type'], $legacyPayload);
        $this->assertSame('status_changed', $legacyDecoded->action);
        $this->assertNull($legacyDecoded->actorHashId);
        $this->assertSame($legacyDecoded->timestamp, $legacyDecoded->broadcastWith()['version']);
    }

    public function test_stages_chain_step_advanced_for_known_model(): void
    {
        $captured = null;
        $outbox = \Mockery::mock(OutboxService::class);
        $outbox->shouldReceive('recordForChain')
            ->once()
            ->withArgs(function ($event, $entity, $chain, $type, $step, $dedupeKey) use (&$captured): bool {
                $captured = $event;

                return $entity instanceof SalesOrder
                    && $chain === 'o2c'
                    && $type === 'sales_order'
                    && $step === 'confirmed'
                    && str_starts_with($dedupeKey, 'chain-step:');
            });
        $this->app->instance(OutboxService::class, $outbox);

        $so = new SalesOrder;
        $so->id = 42;
        $so->so_number = 'SO-202604-0042';
        $so->updated_at = CarbonImmutable::parse('2026-11-03T14:00:00+00:00');
        $so->syncOriginal();
        $actor = new User;
        $actor->id = 23;

        $b = app(ChainBroadcaster::class);
        $ok = $b->broadcastFor($so, 'confirmed', $actor);

        $this->assertTrue($ok);
        $this->assertInstanceOf(ChainStepAdvanced::class, $captured);
        $this->assertSame('sales_order', $captured->entityType);
        $this->assertSame('confirmed', $captured->newStatus);
        $this->assertSame('confirmed', $captured->activeStep);
        $this->assertSame('SO-202604-0042', $captured->docNumber);
        $this->assertSame('chain.refresh', $captured->broadcastAs());
        $this->assertSame([
            'refresh' => true,
            'event_id' => $captured->eventId,
            'entity_type' => 'sales_order',
            'entity_id' => $captured->entityHashId,
            'action' => 'status_changed',
            'version' => '2026-11-03T06:00:00+00:00',
            'actor_id' => app('hashids')->encode(23),
            'related' => [],
            'timestamp' => $captured->timestamp,
        ], $captured->broadcastWith());
    }

    public function test_rejects_unsupported_model_instead_of_silently_losing_chain_evidence(): void
    {
        Event::fake([ChainStepAdvanced::class]);

        $other = new class extends Model
        {
            protected $table = 'fake';

            public $id = 1;
        };

        $b = app(ChainBroadcaster::class);

        $this->expectException(\InvalidArgumentException::class);
        $b->broadcastFor($other, 'whatever');
        Event::assertNotDispatched(ChainStepAdvanced::class);
    }

    public function test_invoice_refresh_targets_the_customer_account_without_adding_payload_data(): void
    {
        $captured = null;
        $outbox = \Mockery::mock(OutboxService::class);
        $outbox->shouldReceive('recordForChain')
            ->once()
            ->withArgs(function ($event) use (&$captured): bool {
                $captured = $event;

                return true;
            });
        $this->app->instance(OutboxService::class, $outbox);

        $invoice = new Invoice;
        $invoice->id = 42;
        $invoice->customer_id = 93;
        $invoice->invoice_number = 'INV-REALTIME-42';

        app(ChainBroadcaster::class)->broadcastFor($invoice, 'draft');

        $customerHashId = app('hashids')->encode(93);
        $this->assertSame($customerHashId, $captured->customerHashId);
        $channelNames = array_map(static fn ($channel): string => $channel->name, $captured->broadcastOn());
        $this->assertContains("private-customer.invoice.{$customerHashId}", $channelNames);
        $this->assertSame([
            'refresh' => true,
            'event_id' => $captured->eventId,
            'entity_type' => 'invoice',
            'entity_id' => $captured->entityHashId,
            'action' => 'status_changed',
            'version' => CarbonImmutable::parse($captured->timestamp)->utc()->toIso8601String(),
            'actor_id' => null,
            'related' => [],
            'timestamp' => $captured->timestamp,
        ], $captured->broadcastWith());
    }

    public function test_bill_refresh_targets_its_purchase_request_detail_without_exposing_the_parent_id(): void
    {
        $captured = null;
        $outbox = \Mockery::mock(OutboxService::class);
        $outbox->shouldReceive('recordForChain')->once()->withArgs(function ($event) use (&$captured): bool {
            $captured = $event;

            return true;
        });
        $this->app->instance(OutboxService::class, $outbox);

        $purchaseOrder = new PurchaseOrder;
        $purchaseOrder->id = 23;
        $purchaseOrder->purchase_request_id = 93;

        $bill = new Bill;
        $bill->id = 42;
        $bill->bill_number = 'BILL-REALTIME-42';
        $bill->purchase_order_id = 23;
        $bill->goods_receipt_note_id = 77;
        $bill->setRelation('purchaseOrder', $purchaseOrder);

        app(ChainBroadcaster::class)->broadcastFor($bill, 'unpaid');

        $purchaseRequestHashId = (string) app('hashids')->encode(93);
        $purchaseOrderHashId = (string) app('hashids')->encode(23);
        $grnHashId = (string) app('hashids')->encode(77);
        $channelNames = array_map(static fn ($channel): string => $channel->name, $captured->broadcastOn());
        $this->assertContains("private-chain.purchase_request.{$purchaseRequestHashId}", $channelNames);
        $this->assertContains('private-entity.purchase_request.visible', $channelNames);
        $this->assertContains("private-chain.purchase_order.{$purchaseOrderHashId}", $channelNames);
        $this->assertContains('private-entity.purchase_order.visible', $channelNames);
        $this->assertContains("private-chain.grn.{$grnHashId}", $channelNames);
        $this->assertSame([$purchaseRequestHashId], $captured->relatedPurchaseRequestHashIds);
        $this->assertSame([$purchaseOrderHashId], $captured->relatedPurchaseOrderHashIds);
        $this->assertSame([$grnHashId], $captured->relatedGrnHashIds);
        $this->assertSame([
            'refresh' => true,
            'event_id' => $captured->eventId,
            'entity_type' => 'bill',
            'entity_id' => $captured->entityHashId,
            'action' => 'status_changed',
            'version' => CarbonImmutable::parse($captured->timestamp)->utc()->toIso8601String(),
            'actor_id' => null,
            'related' => [],
            'timestamp' => $captured->timestamp,
        ], $captured->broadcastWith());
        $this->assertArrayNotHasKey('relatedGrnHashIds', $captured->broadcastWith());
    }

    public function test_sales_order_refresh_targets_only_its_customer_portal_account(): void
    {
        $captured = null;
        $outbox = \Mockery::mock(OutboxService::class);
        $outbox->shouldReceive('recordForChain')->once()->withArgs(function ($event) use (&$captured): bool {
            $captured = $event;

            return true;
        });
        $this->app->instance(OutboxService::class, $outbox);

        $salesOrder = new SalesOrder;
        $salesOrder->id = 42;
        $salesOrder->customer_id = 93;
        $salesOrder->so_number = 'SO-REALTIME-42';

        app(ChainBroadcaster::class)->broadcastFor($salesOrder, 'confirmed');

        $customerHashId = app('hashids')->encode(93);
        $channelNames = array_map(static fn ($channel): string => $channel->name, $captured->broadcastOn());
        $this->assertSame($customerHashId, $captured->customerHashId);
        $this->assertContains("private-customer.order.{$customerHashId}", $channelNames);
        $this->assertSame('sales_order', $captured->broadcastWith()['entity_type']);
        $this->assertSame($captured->entityHashId, $captured->broadcastWith()['entity_id']);
        $this->assertArrayNotHasKey('customerHashId', $captured->broadcastWith());
    }

    public function test_work_order_refresh_targets_private_record_and_visible_channels_without_chain_ledger(): void
    {
        $captured = null;
        $outbox = \Mockery::mock(OutboxService::class);
        $outbox->shouldReceive('record')->once()->withArgs(function ($event, string $dedupeKey) use (&$captured): bool {
            $captured = $event;

            return $event instanceof ChainStepAdvanced
                && str_starts_with($dedupeKey, 'chain-refresh:');
        });
        $this->app->instance(OutboxService::class, $outbox);

        $workOrder = new WorkOrder;
        $workOrder->id = 42;
        $workOrder->wo_number = 'WO-REALTIME-42';
        $workOrder->status = WorkOrderStatus::Paused;

        app(ChainBroadcaster::class)->refreshWorkOrder($workOrder);

        $this->assertSame('work_order', $captured->entityType);
        $this->assertSame(WorkOrderStatus::Paused->value, $captured->newStatus);
        $channels = array_map(static fn ($channel): string => $channel->name, $captured->broadcastOn());
        $this->assertContains('private-chain.work_order.'.$workOrder->hash_id, $channels);
        $this->assertContains('private-entity.work_order.visible', $channels);
        $this->assertSame('work_order', $captured->broadcastWith()['entity_type']);
    }

    public function test_delivery_refresh_resolves_its_customer_from_the_related_sales_order(): void
    {
        $captured = null;
        $outbox = \Mockery::mock(OutboxService::class);
        $outbox->shouldReceive('recordForChain')->once()->withArgs(function ($event) use (&$captured): bool {
            $captured = $event;

            return true;
        });
        $this->app->instance(OutboxService::class, $outbox);

        $salesOrder = new SalesOrder;
        $salesOrder->id = 42;
        $salesOrder->customer_id = 93;
        $delivery = new Delivery;
        $delivery->id = 77;
        $delivery->sales_order_id = 42;
        $delivery->delivery_number = 'DEL-REALTIME-77';
        $delivery->setRelation('salesOrder', $salesOrder);

        app(ChainBroadcaster::class)->broadcastFor($delivery, DeliveryStatus::Scheduled->value);

        $customerHashId = app('hashids')->encode(93);
        $channelNames = array_map(static fn ($channel): string => $channel->name, $captured->broadcastOn());
        $this->assertSame($customerHashId, $captured->customerHashId);
        $this->assertContains("private-customer.order.{$customerHashId}", $channelNames);
        $this->assertSame('delivery', $captured->broadcastWith()['entity_type']);
    }

    public function test_purchase_order_refresh_includes_its_supplier_vendor_channel(): void
    {
        $captured = null;
        $outbox = \Mockery::mock(OutboxService::class);
        $outbox->shouldReceive('recordForChain')->once()->withArgs(function ($event) use (&$captured): bool {
            $captured = $event;

            return true;
        });
        $this->app->instance(OutboxService::class, $outbox);

        $purchaseOrder = new PurchaseOrder;
        $purchaseOrder->id = 42;
        $purchaseOrder->vendor_id = 93;
        $purchaseOrder->purchase_request_id = 94;
        $purchaseOrder->po_number = 'PO-REALTIME-42';

        app(ChainBroadcaster::class)->broadcastFor($purchaseOrder, 'sent');

        $vendorHashId = app('hashids')->encode(93);
        $channelNames = array_map(static fn ($channel): string => $channel->name, $captured->broadcastOn());
        $this->assertSame($vendorHashId, $captured->supplierVendorHashId);
        $this->assertContains("private-supplier.vendor.{$vendorHashId}", $channelNames);
        $purchaseRequestHashId = (string) app('hashids')->encode(94);
        $this->assertContains("private-chain.purchase_request.{$purchaseRequestHashId}", $channelNames);
        $this->assertContains('private-entity.purchase_request.visible', $channelNames);
        $this->assertSame('purchase_order', $captured->broadcastWith()['entity_type']);
        $this->assertSame($captured->entityHashId, $captured->broadcastWith()['entity_id']);
        $this->assertArrayNotHasKey('supplierVendorHashId', $captured->broadcastWith());
        $this->assertArrayNotHasKey('newStatus', $captured->broadcastWith());
    }

    public function test_purchase_order_related_refresh_stages_a_content_free_private_ping(): void
    {
        $captured = null;
        $outbox = \Mockery::mock(OutboxService::class);
        $outbox->shouldReceive('record')->once()->withArgs(function ($event, string $dedupeKey) use (&$captured): bool {
            $captured = $event;

            return $event instanceof ChainStepAdvanced
                && str_starts_with($dedupeKey, 'chain-refresh:');
        });
        $this->app->instance(OutboxService::class, $outbox);

        $purchaseOrder = new PurchaseOrder;
        $purchaseOrder->id = 42;
        $purchaseOrder->vendor_id = 93;
        $purchaseOrder->purchase_request_id = 94;
        $purchaseOrder->po_number = 'PO-REALTIME-42';
        $purchaseOrder->status = 'approved';

        app(ChainBroadcaster::class)->refreshPurchaseOrder($purchaseOrder);

        $vendorHashId = app('hashids')->encode(93);
        $channels = $captured->broadcastOn();
        $channelNames = array_map(static fn ($channel): string => $channel->name, $channels);
        $this->assertSame('purchase_order', $captured->entityType);
        $this->assertSame('approved', $captured->newStatus);
        $this->assertSame([
            'refresh' => true,
            'event_id' => $captured->eventId,
            'entity_type' => 'purchase_order',
            'entity_id' => $captured->entityHashId,
            'action' => 'updated',
            'version' => CarbonImmutable::parse($captured->timestamp)->utc()->toIso8601String(),
            'actor_id' => null,
            'related' => [],
            'timestamp' => $captured->timestamp,
        ], $captured->broadcastWith());
        $this->assertCount(5, $channels);
        $this->assertContains('private-chain.purchase_order.'.$purchaseOrder->hash_id, $channelNames);
        $this->assertContains('private-entity.purchase_order.visible', $channelNames);
        $this->assertContains("private-supplier.vendor.{$vendorHashId}", $channelNames);
        $purchaseRequestHashId = (string) app('hashids')->encode(94);
        $this->assertContains("private-chain.purchase_request.{$purchaseRequestHashId}", $channelNames);
        $this->assertContains('private-entity.purchase_request.visible', $channelNames);
    }

    public function test_grn_and_bill_refreshes_include_their_supplier_vendor_channel(): void
    {
        $captured = [];
        $outbox = \Mockery::mock(OutboxService::class);
        $outbox->shouldReceive('recordForChain')->twice()->withArgs(function ($event) use (&$captured): bool {
            $captured[] = $event;

            return true;
        });
        $this->app->instance(OutboxService::class, $outbox);

        $purchaseOrder = new PurchaseOrder;
        $purchaseOrder->id = 17;
        $purchaseOrder->purchase_request_id = 18;

        $grn = new GoodsReceiptNote;
        $grn->id = 42;
        $grn->vendor_id = 93;
        $grn->purchase_order_id = 17;
        $grn->grn_number = 'GRN-REALTIME-42';
        $grn->setRelation('purchaseOrder', $purchaseOrder);

        $bill = new Bill;
        $bill->id = 43;
        $bill->vendor_id = 93;
        $bill->purchase_order_id = 17;
        $bill->bill_number = 'BILL-REALTIME-43';
        $bill->setRelation('purchaseOrder', $purchaseOrder);

        $broadcaster = app(ChainBroadcaster::class);
        $broadcaster->broadcastFor($grn, 'pending_qc');
        $broadcaster->broadcastFor($bill, 'draft');

        $vendorHashId = app('hashids')->encode(93);
        $purchaseOrderHashId = (string) app('hashids')->encode(17);
        $purchaseRequestHashId = (string) app('hashids')->encode(18);
        foreach ($captured as $event) {
            $channelNames = array_map(static fn ($channel): string => $channel->name, $event->broadcastOn());
            $this->assertSame($vendorHashId, $event->supplierVendorHashId);
            $this->assertContains("private-supplier.vendor.{$vendorHashId}", $channelNames);
            $this->assertContains("private-chain.purchase_order.{$purchaseOrderHashId}", $channelNames);
            $this->assertContains('private-entity.purchase_order.visible', $channelNames);
            $this->assertContains("private-chain.purchase_request.{$purchaseRequestHashId}", $channelNames);
            $this->assertContains('private-entity.purchase_request.visible', $channelNames);
            $this->assertArrayNotHasKey('supplierVendorHashId', $event->broadcastWith());
            $this->assertArrayNotHasKey('relatedPurchaseRequestHashIds', $event->broadcastWith());
            $this->assertArrayNotHasKey('relatedPurchaseOrderHashIds', $event->broadcastWith());
        }
    }

    public function test_rejects_an_unmapped_status_instead_of_publishing_false_progress(): void
    {
        Event::fake([ChainStepAdvanced::class]);

        $so = new SalesOrder;
        $so->id = 42;
        $so->so_number = 'SO-202604-0042';

        $this->expectException(\InvalidArgumentException::class);
        app(ChainBroadcaster::class)->broadcastFor($so, 'future_status');
        Event::assertNotDispatched(ChainStepAdvanced::class);
    }

    public function test_durable_staging_failure_is_rethrown_for_transaction_rollback(): void
    {
        $outbox = \Mockery::mock(OutboxService::class);
        $outbox->shouldReceive('recordForChain')
            ->once()
            ->andThrow(new \RuntimeException('outbox unavailable'));
        $this->app->instance(OutboxService::class, $outbox);

        $so = new SalesOrder;
        $so->id = 42;
        $so->so_number = 'SO-202604-0042';

        $this->expectException(\RuntimeException::class);
        app(ChainBroadcaster::class)->broadcastFor($so, 'confirmed');
    }
}
