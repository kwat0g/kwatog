<?php

declare(strict_types=1);

namespace App\Common\Events;

use App\Common\Events\Concerns\BroadcastsOnDedicatedQueue;
use Carbon\Carbon;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Series C — Task C4. Single canonical broadcast for "this chain advanced
 * a step." Detail pages on the SPA subscribe to
 * `private-chain.{entityType}.{hashId}` and invalidate the matching
 * TanStack Query so the UI updates without a manual refresh.
 *
 * Why one event for many entity types: every chain (SO, PO, WO, Delivery,
 * GRN) has the same shape of "active step + completed prefix" and the SPA
 * already has a uniform `<ChainHeader>` component. A single event keeps
 * the SPA hook generic and avoids one bespoke channel per chain.
 *
 * **Security note:** the payload is a content-free refresh ping. The SPA
 * refetches the record through its normal authorized API route. Do NOT add
 * record details here; channel authorization mirrors the detail route.
 */
class ChainStepAdvanced implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use BroadcastsOnDedicatedQueue;
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $eventId;

    /**
     * @param  string  $entityType  one of ChainDefinitions::allowedTypes()
     * @param  string  $entityHashId  hashed id (never raw int)
     * @param  string  $docNumber  human-readable doc no (e.g. SO-202604-0003)
     * @param  string  $newStatus  the resulting status string
     * @param  string  $activeStep  derived from ChainDefinitions::resolve()
     * @param  array<int,string>  $completedSteps  prefix of chain that is already done
     * @param  string|null  $actorName  who triggered the change (display name)
     */
    public function __construct(
        public string $entityType,
        public string $entityHashId,
        public string $docNumber,
        public string $newStatus,
        public string $activeStep,
        public array $completedSteps,
        public ?string $actorName = null,
        ?string $eventId = null,
        /** @var list<string> Opaque IDs for personally scoped driver refreshes. */
        public array $driverHashIds = [],
        /** Customer account hash for scoped order, delivery and invoice refreshes. */
        public ?string $customerHashId = null,
        /** Owning supplier vendor hash for purchase-order portal refreshes. */
        public ?string $supplierVendorHashId = null,
        /** @var list<string> Internal customer-return detail channels that embed this order. */
        public array $relatedReturnCaseHashIds = [],
        /** @var list<string> Purchase Order detail channels that embed this child P2P record. */
        public array $relatedPurchaseOrderHashIds = [],
        /** @var list<string> Purchase Request detail channels that embed this P2P record. */
        public array $relatedPurchaseRequestHashIds = [],
        /** @var list<string> GRN detail channels that embed a linked supplier Bill. */
        public array $relatedGrnHashIds = [],
        public string $action = 'status_changed',
        public ?string $actorHashId = null,
        public ?string $version = null,
        ?string $timestamp = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::uuid();
        $this->timestamp = $timestamp ?? now()->toISOString();
    }

    public string $timestamp;

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel("chain.{$this->entityType}.{$this->entityHashId}"),
            new PrivateChannel("entity.{$this->entityType}.visible"),
        ];

        if ($this->entityType === 'delivery') {
            // Customer-only Return Management viewers can read eligible Delivery
            // sources through the same permission-gated source API.
            $channels[] = new PrivateChannel('entity.return_case.customer');
            $channels[] = new PrivateChannel('entity.return_case.all');
        } elseif ($this->entityType === 'purchase_order') {
            // Customer-only viewers cannot fetch supplier PO sources; keep the
            // PO reference off their channel and refresh the all-return audience.
            $channels[] = new PrivateChannel('entity.return_case.all');
        }

        if ($this->entityType === 'delivery') {
            foreach (array_values(array_unique($this->driverHashIds)) as $driverHashId) {
                // The dedicated channel uses the same permission as the
                // driver API. Keep the legacy user channel during rollout.
                $channels[] = new PrivateChannel("driver.{$driverHashId}");
                $channels[] = new PrivateChannel("user.{$driverHashId}");
            }
        }

        if (in_array($this->entityType, ['sales_order', 'delivery'], true) && $this->customerHashId !== null) {
            $channels[] = new PrivateChannel("customer.order.{$this->customerHashId}");
        }

        if ($this->entityType === 'sales_order') {
            // The fulfillment preview API also grants Production confirmers
            // who do not have CRM Sales Order detail access.
            $channels[] = new PrivateChannel("sales-order-fulfillment.{$this->entityHashId}");
        }

        if ($this->entityType === 'invoice' && $this->customerHashId !== null) {
            $channels[] = new PrivateChannel("customer.invoice.{$this->customerHashId}");
        }

        foreach (array_values(array_unique(array_filter($this->relatedReturnCaseHashIds))) as $returnCaseHashId) {
            $channels[] = new PrivateChannel("return_case.{$returnCaseHashId}");
        }

        foreach (array_values(array_unique(array_filter($this->relatedPurchaseRequestHashIds))) as $purchaseRequestHashId) {
            // Purchase Request views can depend on linked PO → GRN → Bill
            // state. Notify both authorized audiences with a content-free ping;
            // omit the parent ID from the payload.
            $channels[] = new PrivateChannel("chain.purchase_request.{$purchaseRequestHashId}");
            $channels[] = new PrivateChannel('entity.purchase_request.visible');
        }

        foreach (array_values(array_unique(array_filter($this->relatedPurchaseOrderHashIds))) as $purchaseOrderHashId) {
            // PO views can depend on child GRN and Bill state. Both audiences
            // are private and API-authorized; no parent ID is in the payload.
            $channels[] = new PrivateChannel("chain.purchase_order.{$purchaseOrderHashId}");
            $channels[] = new PrivateChannel('entity.purchase_order.visible');
        }

        foreach (array_values(array_unique(array_filter($this->relatedGrnHashIds))) as $grnHashId) {
            // A Bill transition changes the status embedded on its GRN detail.
            // The exact chain channel applies the GRN API's inventory.view gate;
            // only the refresh envelope is sent to this additional audience.
            $channels[] = new PrivateChannel("chain.grn.{$grnHashId}");
        }

        if (in_array($this->entityType, ['purchase_order', 'grn', 'bill'], true)
            && $this->supplierVendorHashId !== null) {
            $channels[] = new PrivateChannel("supplier.vendor.{$this->supplierVendorHashId}");
        }

        // Finance monitors accepted GRNs that have not produced a supplier
        // bill. This channel carries only the same content-free refresh ping.
        if ($this->entityType === 'grn') {
            $channels[] = new PrivateChannel('dashboard.finance');
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        // Old SPA bundles ignore this new name and retain their polling fallback.
        return 'chain.refresh';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $version = $this->version === null
            ? $this->timestamp
            : Carbon::parse($this->version)->utc()->toIso8601String();

        return BroadcastEventEnvelope::refresh(
            eventId: $this->eventId,
            entityType: $this->entityType,
            entityId: $this->entityHashId,
            action: $this->action,
            version: $version,
            actorId: $this->actorHashId,
            timestamp: $this->timestamp,
        );
    }
}
