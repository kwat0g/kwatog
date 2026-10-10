<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\NotificationService;
use App\Common\Services\SystemUserResolver;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\PortalShippingDocument;
use App\Modules\B2B\Models\SupplierShipment;
use App\Modules\B2B\Models\SupplierShipmentUpdate;
use App\Modules\B2B\Policies\SupplierPoCapabilities;
use App\Modules\Inventory\Services\GrnService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Supplier-side shipment tracking and shipping documents for a purchase order.
 * Every method takes the owning vendor_id explicitly; see SupplierPortalService.
 */
class SupplierShipmentService
{
    public function __construct(
        private readonly SystemUserResolver $systemUser,
        private readonly SupplierPortalAuditRecorder $audit,
        private readonly NotificationService $notifications,
    ) {}

    /** Report a new shipment (one PO may ship in several lots). */
    public function createShipment(int $vendorId, int $portalUserId, PurchaseOrder $purchaseOrder, array $data, UploadedFile $photo): SupplierShipment
    {
        $path = null;
        try {
            return $this->write(
                $vendorId,
                $portalUserId,
                $purchaseOrder,
                $data,
                static fn (PurchaseOrder $po): SupplierShipment => new SupplierShipment(['purchase_order_id' => $po->id]),
                'supplier_ship.create',
                function (SupplierShipment $shipment) use ($photo, $purchaseOrder, $portalUserId, $vendorId, &$path): void {
                    $hash = hash_file('sha256', (string) $photo->getRealPath());
                    if (! is_string($hash) || $hash === '') {
                        throw new \RuntimeException('Unable to calculate the dispatch photo fingerprint.');
                    }
                    if (PortalShippingDocument::query()->where('purchase_order_id', $purchaseOrder->id)
                        ->where('document_type', 'dispatch_photo')->where('content_sha256', $hash)->exists()) {
                        throw new BusinessRuleException('This dispatch photo is already attached to the purchase order. Take a new photo for this shipment.');
                    }
                    $path = $photo->store("portal/shipping-docs/{$purchaseOrder->id}", 'local');
                    if ($path === false) {
                        throw new \RuntimeException('Unable to store the dispatch photo.');
                    }
                    $document = PortalShippingDocument::create([
                        'purchase_order_id' => $purchaseOrder->id,
                        'supplier_shipment_id' => $shipment->id,
                        'document_type' => 'dispatch_photo',
                        'file_path' => $path,
                        'original_filename' => $photo->getClientOriginalName(),
                        'file_size_bytes' => $photo->getSize(),
                        'content_sha256' => $hash,
                        'mime_type' => $photo->getMimeType(),
                        'uploaded_by' => $portalUserId,
                        'uploaded_at' => now(),
                    ]);
                    $this->audit->record('supplier_doc.upload', $document, $portalUserId, $vendorId);
                },
            );
        } catch (\Throwable $e) {
            if ($path && ! PortalShippingDocument::query()->where('file_path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }

    /** Amend one shipment of the PO. A shipment of another PO is a 404. */
    public function updateShipment(
        int $vendorId,
        int $portalUserId,
        PurchaseOrder $purchaseOrder,
        SupplierShipment $supplierShipment,
        array $data,
    ): SupplierShipment {
        abort_if((int) $supplierShipment->purchase_order_id !== (int) $purchaseOrder->id, 404);

        return $this->write($vendorId, $portalUserId, $purchaseOrder, $data, static fn (): SupplierShipment => SupplierShipment::query()->lockForUpdate()->findOrFail($supplierShipment->id), 'supplier_ship.update');
    }

    /**
     * Legacy single-shipment endpoint can amend an existing shipment only.
     */
    public function updateShipmentLegacy(int $vendorId, int $portalUserId, PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        $this->write($vendorId, $portalUserId, $purchaseOrder, $data, static fn (PurchaseOrder $po): SupplierShipment => SupplierShipment::query()->where('purchase_order_id', $po->id)->lockForUpdate()->orderByDesc('id')->first()
                ?? throw new BusinessRuleException('Use Add shipment to record dispatched quantities.'), 'supplier_ship.update');

        return $purchaseOrder->fresh()->load('supplierShipment');
    }

    /**
     * @param  callable(PurchaseOrder): SupplierShipment  $resolve  called under the PO lock
     */
    private function write(int $vendorId, int $portalUserId, PurchaseOrder $purchaseOrder, array $data, callable $resolve, string $auditAction, ?callable $afterSave = null): SupplierShipment
    {
        abort_if((int) $purchaseOrder->vendor_id !== $vendorId, 403);

        $shipment = $this->systemUser->impersonate(fn (): SupplierShipment => DB::transaction(function () use ($purchaseOrder, $data, $vendorId, $portalUserId, $resolve, $auditAction, $afterSave): SupplierShipment {
            // Shipments share the PO row with acceptance, cancellation and
            // receiving; lock it so a stale portal page cannot report against
            // a PO that has since moved on.
            $row = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);
            abort_if((int) $row->vendor_id !== $vendorId, 403);
            SupplierPoCapabilities::assert($row, 'ship');

            $shipment = $resolve($row);
            if ($auditAction === 'supplier_ship.update'
                && $shipment->goodsReceiptNotes()->whereNotNull('received_date')->exists()) {
                throw new BusinessRuleException('This shipment has already been received and cannot be edited.');
            }
            $return = null;
            if ($auditAction === 'supplier_ship.create' && ! empty($data['return_request_id'])) {
                $returnId = HashIdFilter::decode($data['return_request_id'], ReturnRequest::class);
                $return = $returnId ? ReturnRequest::query()
                    ->whereKey($returnId)
                    ->where('purchase_order_id', $row->id)
                    ->where('vendor_id', $vendorId)
                    ->where('status', 'completed')
                    ->whereNull('replacement_purchase_order_id')
                    ->where('source_key', 'like', 'grn-rejection:%')
                    ->with('items')
                    ->first() : null;
                if (! $return || ! SupplierPoCapabilities::qcReplacementPending($return)) {
                    throw new BusinessRuleException('Select a pending quality return on this purchase order.');
                }
                $shipment->return_request_id = $return->id;
            }
            if ($auditAction === 'supplier_ship.create'
                && SupplierShipment::query()->where('purchase_order_id', $row->id)->whereDoesntHave('items')->exists()) {
                throw new BusinessRuleException('Record quantities on the earlier shipment before adding another.');
            }
            $recordQuantities = $auditAction === 'supplier_ship.create' || array_key_exists('items', $data);
            if ($recordQuantities && $shipment->exists && $shipment->items()->exists()) {
                throw new BusinessRuleException('Recorded shipment quantities cannot be changed.');
            }
            $values = [
                'shipped_date' => array_key_exists('shipped_date', $data)
                    ? $data['shipped_date']
                    : optional($shipment->shipped_date)->toDateString(),
                'carrier' => array_key_exists('carrier', $data) ? $this->clean($data['carrier']) : $shipment->carrier,
                'tracking_number' => array_key_exists('tracking_number', $data) ? $this->clean($data['tracking_number']) : $shipment->tracking_number,
                'estimated_arrival' => array_key_exists('estimated_arrival', $data)
                    ? $data['estimated_arrival']
                    : optional($shipment->estimated_arrival)->toDateString(),
                'notes' => array_key_exists('notes', $data) ? $this->clean($data['notes']) : $shipment->notes,
                'portal_user_id' => $portalUserId,
            ];
            $this->assertDates($values, $data);

            $shipment->forceFill($values)->save();
            if ($recordQuantities) {
                $poItems = $row->items()->get()->keyBy('id');
                $replacementRemaining = [];
                if ($return) {
                    foreach ($return->items as $returnLine) {
                        $item = $poItems->get($returnLine->source_po_item_id);
                        if (! $item) {
                            throw new BusinessRuleException('The quality return contains an item outside this purchase order.');
                        }
                        $itemId = (int) $item->id;
                        $replacementRemaining[$itemId] = Money::add(
                            $replacementRemaining[$itemId] ?? '0',
                            $item->purchaseQuantity((string) $returnLine->quantity, 6),
                        );
                    }
                    $alreadyReplaced = DB::table('supplier_shipment_items as line')
                        ->join('supplier_shipments as shipment', 'shipment.id', '=', 'line.supplier_shipment_id')
                        ->where('shipment.return_request_id', $return->id)
                        ->selectRaw('line.purchase_order_item_id, SUM(line.quantity_shipped) AS total_shipped')
                        ->groupBy('line.purchase_order_item_id')
                        ->pluck('total_shipped', 'purchase_order_item_id');
                    foreach ($replacementRemaining as $itemId => $quantity) {
                        $replacementRemaining[$itemId] = Money::sub($quantity, (string) ($alreadyReplaced[$itemId] ?? '0'));
                    }
                }
                $shipped = DB::table('supplier_shipment_items')
                    ->whereIn('purchase_order_item_id', $poItems->keys()->all())
                    ->selectRaw('purchase_order_item_id, SUM(quantity_shipped) AS total_shipped')
                    ->groupBy('purchase_order_item_id')
                    ->pluck('total_shipped', 'purchase_order_item_id');
                $remainingByItem = [];
                $rejectedAllowance = SupplierPoCapabilities::rejectedDispatchAllowance($row);
                foreach ($poItems as $itemId => $poItem) {
                    $remainingByItem[$itemId] = Money::sub(
                        Money::add((string) $poItem->quantity, $rejectedAllowance[$itemId] ?? '0'),
                        (string) ($shipped[$itemId] ?? '0'),
                    );
                }
                $seen = [];
                foreach ($data['items'] as $line) {
                    $itemId = HashIdFilter::decode($line['purchase_order_item_id'], PurchaseOrderItem::class);
                    if ($itemId === null || ! $poItems->has($itemId) || isset($seen[$itemId])
                        || ! Money::gt((string) $line['quantity_shipped'], '0')) {
                        throw new BusinessRuleException('Each shipment line must identify a unique PO item and positive quantity.');
                    }
                    $remaining = $remainingByItem[$itemId];
                    if (Money::gt((string) $line['quantity_shipped'], $remaining)) {
                        throw new BusinessRuleException("Shipped quantity exceeds the remaining ordered quantity ({$remaining}).");
                    }
                    if ($return) {
                        $replacementQuantity = $replacementRemaining[$itemId] ?? '0';
                        if (Money::gt((string) $line['quantity_shipped'], $replacementQuantity)) {
                            throw new BusinessRuleException("Replacement shipment exceeds the remaining quality return quantity ({$replacementQuantity}).");
                        }
                        $replacementRemaining[$itemId] = Money::sub($replacementQuantity, (string) $line['quantity_shipped']);
                    }
                    $remainingByItem[$itemId] = Money::sub($remaining, (string) $line['quantity_shipped']);
                    $seen[$itemId] = true;
                    $shipment->items()->create([
                        'purchase_order_item_id' => $itemId,
                        'quantity_shipped' => $line['quantity_shipped'],
                    ]);
                }
                if (array_filter($remainingByItem, static fn (string $remaining): bool => Money::gt($remaining, '0'))
                    && $values['notes'] === null) {
                    throw new BusinessRuleException('Reason is required when a shipment leaves PO quantity undispatched.');
                }
            }
            SupplierShipmentUpdate::create([
                'supplier_shipment_id' => $shipment->id,
                'portal_user_id' => $portalUserId,
                'payload' => $values,
                'created_at' => now(),
            ]);
            if ($afterSave) {
                $afterSave($shipment);
            }
            if ($auditAction === 'supplier_ship.create') {
                app(GrnService::class)->createDraftForSupplierShipment($shipment);
            }

            // The ETA deliberately no longer writes purchase_orders.confirmed_delivery_date.
            // The agreed date comes from the accept/propose flow; an ETA is a
            // forecast, and letting it move the agreed date let a late supplier
            // rewrite its own on-time record.
            return $shipment->fresh()->load('items.purchaseOrderItem', 'purchaseOrder', 'returnRequest');
        }));

        // Anchored on the PO like every other supplier action on it.
        $this->audit->record($auditAction, $purchaseOrder, $portalUserId, $vendorId);
        $deadline = SupplierPoCapabilities::shipmentDeadline($shipment);
        $eta = $shipment->estimated_arrival?->toDateString();
        if ($deadline && $eta && $eta > $deadline->toDateString()) {
            $audience = User::query()->where('is_active', true)
                ->whereHas('role.permissions', static fn ($query) => $query->where('slug', 'purchasing.delivery_schedules.review'))
                ->get();
            $this->notifications->sendInApp($audience, 'supplier.shipment_late', [
                'title' => "PO {$purchaseOrder->po_number}: supplier ETA is late",
                'message' => "Supplier ETA {$eta} is after the required delivery date {$deadline->toDateString()}.",
                'link_to' => "/purchasing/purchase-orders/{$purchaseOrder->hash_id}",
                'entity_type' => 'purchase_order',
                'entity_id' => $purchaseOrder->hash_id,
            ], "supplier:shipment:late:{$shipment->id}:{$eta}");
        }

        return $shipment;
    }

    /**
     * Past/future limits apply to the dates being SET: an ETA that has since
     * passed must not lock the supplier out of correcting a tracking number.
     * The ordering check applies to the merged result.
     *
     * @param  array{shipped_date: ?string, estimated_arrival: ?string}  $merged
     */
    private function assertDates(array $merged, array $incoming): void
    {
        $today = now()->toDateString();
        if (! empty($incoming['shipped_date']) && $incoming['shipped_date'] > $today) {
            throw new BusinessRuleException('Shipped date cannot be in the future.');
        }
        if (! empty($incoming['estimated_arrival']) && $incoming['estimated_arrival'] < $today) {
            throw new BusinessRuleException('Estimated arrival cannot be in the past.');
        }
        if (! empty($merged['shipped_date']) && ! empty($merged['estimated_arrival'])
            && $merged['estimated_arrival'] < $merged['shipped_date']) {
            throw new BusinessRuleException('Estimated arrival must be on or after the shipped date.');
        }
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /* ─── Shipping Documents ─────────────────────────────────────── */

    public function uploadShippingDocument(
        int $vendorId,
        int $portalUserId,
        PurchaseOrder $purchaseOrder,
        UploadedFile $file,
        array $data,
    ): PortalShippingDocument {
        abort_if($purchaseOrder->vendor_id !== $vendorId, 403);

        $folder = "portal/shipping-docs/{$purchaseOrder->id}";
        $contentHash = hash_file('sha256', (string) $file->getRealPath());
        if (! is_string($contentHash) || $contentHash === '') {
            throw new \RuntimeException('Unable to calculate the shipping document fingerprint.');
        }

        $path = $file->store($folder, 'local');
        if ($path === false) {
            // Storage fault. The supplier already passed validation and the
            // upload itself succeeded; there is nothing on their side to fix.
            throw new \RuntimeException('Unable to store the shipping document.');
        }

        try {
            // Idempotent — a double-click or retried request that re-uploads
            // the same bytes (same PO + type + content digest) must not stack
            // a second document row or orphan a second stored file. Lock the
            // PO row to serialize concurrent uploads, then return the existing
            // row and delete the just-stored duplicate. The content-digest
            // unique index backs this guard at the DB level.
            $document = DB::transaction(function () use ($vendorId, $purchaseOrder, $portalUserId, $path, $file, $data, $contentHash): PortalShippingDocument {
                $po = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);
                abort_if((int) $po->vendor_id !== $vendorId, 403);

                // Documents allowed after supplier accepted (acknowledged or partially received).
                SupplierPoCapabilities::assert($po, 'document');

                $shipmentId = null;
                if (! empty($data['supplier_shipment_id'])) {
                    $decodedId = HashIdFilter::decode($data['supplier_shipment_id'], SupplierShipment::class);
                    $linked = $decodedId ? SupplierShipment::query()->find($decodedId) : null;
                    if (! $linked || (int) $linked->purchase_order_id !== (int) $po->id) {
                        throw new BusinessRuleException('Supplier shipment does not belong to this purchase order.');
                    }
                    $shipmentId = $linked->id;
                }

                $existing = PortalShippingDocument::query()
                    ->where('purchase_order_id', $po->id)
                    ->where('document_type', $data['document_type'])
                    ->where('content_sha256', $contentHash)
                    ->first();

                if ($existing) {
                    if ((int) $existing->supplier_shipment_id !== (int) $shipmentId) {
                        throw new BusinessRuleException('This file is already attached to a different shipment or the whole PO.');
                    }
                    Storage::disk('local')->delete($path);

                    return $existing;
                }

                return PortalShippingDocument::create([
                    'purchase_order_id' => $po->id,
                    'supplier_shipment_id' => $shipmentId,
                    'document_type' => $data['document_type'],
                    'file_path' => $path,
                    'original_filename' => $file->getClientOriginalName(),
                    'file_size_bytes' => $file->getSize(),
                    'content_sha256' => $contentHash,
                    'mime_type' => $file->getMimeType(),
                    'notes' => $data['notes'] ?? null,
                    'uploaded_by' => $portalUserId,
                    'uploaded_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            // The cleanup window ends at COMMIT. Past that point a persisted
            // document row owns this path, and deleting the file would leave a
            // readable record pointing at nothing — worse than the orphan file
            // this guard exists to prevent. So only the transaction is wrapped;
            // a later load/audit failure must not take the stored file with it.
            Storage::disk('local')->delete($path);
            throw $e;
        }

        $document->load(['purchaseOrder', 'supplierShipment', 'uploader']);
        $this->audit->record('supplier_doc.upload', $document, $portalUserId, $vendorId);

        return $document;
    }

    public function shippingDocuments(int $vendorId, PurchaseOrder $purchaseOrder): Collection
    {
        abort_if($purchaseOrder->vendor_id !== $vendorId, 403);

        return PortalShippingDocument::where('purchase_order_id', $purchaseOrder->id)
            ->with(['purchaseOrder', 'supplierShipment', 'uploader'])
            ->orderByDesc('uploaded_at')
            ->get();
    }

    public function downloadShippingDocument(int $vendorId, string $hashId): PortalShippingDocument
    {
        $doc = PortalShippingDocument::findOrFail(
            HashIdFilter::decode($hashId, PortalShippingDocument::class),
        );

        $po = $doc->purchaseOrder;
        abort_if(! $po || $po->vendor_id !== $vendorId, 403);

        if (! Storage::disk('local')->exists($doc->file_path)) {
            abort(404, 'File not found.');
        }

        return $doc;
    }
}
