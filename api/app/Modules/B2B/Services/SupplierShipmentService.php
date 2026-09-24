<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Services\SystemUserResolver;
use App\Common\Support\HashIdFilter;
use App\Modules\B2B\Models\PortalShippingDocument;
use App\Modules\B2B\Models\SupplierShipment;
use App\Modules\B2B\Models\SupplierShipmentUpdate;
use App\Modules\B2B\Policies\SupplierPoCapabilities;
use App\Modules\Purchasing\Models\PurchaseOrder;
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
    ) {}

    /** Report a new shipment (one PO may ship in several lots). */
    public function createShipment(int $vendorId, int $portalUserId, PurchaseOrder $purchaseOrder, array $data): SupplierShipment
    {
        return $this->write($vendorId, $portalUserId, $purchaseOrder, $data, static fn (PurchaseOrder $po): SupplierShipment
            => new SupplierShipment(['purchase_order_id' => $po->id]), 'supplier_ship.create');
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

        return $this->write($vendorId, $portalUserId, $purchaseOrder, $data, static fn (): SupplierShipment
            => SupplierShipment::query()->lockForUpdate()->findOrFail($supplierShipment->id), 'supplier_ship.update');
    }

    /**
     * Legacy single-shipment endpoint: amends the latest shipment, or creates
     * the first one.
     */
    public function updateShipmentLegacy(int $vendorId, int $portalUserId, PurchaseOrder $purchaseOrder, array $data): PurchaseOrder
    {
        $this->write($vendorId, $portalUserId, $purchaseOrder, $data, static fn (PurchaseOrder $po): SupplierShipment
            => SupplierShipment::query()->where('purchase_order_id', $po->id)->lockForUpdate()->orderByDesc('id')->first()
                ?? new SupplierShipment(['purchase_order_id' => $po->id]), 'supplier_ship.update');

        return $purchaseOrder->fresh()->load('supplierShipment');
    }

    /**
     * @param  callable(PurchaseOrder): SupplierShipment  $resolve  called under the PO lock
     */
    private function write(int $vendorId, int $portalUserId, PurchaseOrder $purchaseOrder, array $data, callable $resolve, string $auditAction): SupplierShipment
    {
        abort_if((int) $purchaseOrder->vendor_id !== $vendorId, 403);

        $shipment = $this->systemUser->impersonate(fn (): SupplierShipment => DB::transaction(function () use ($purchaseOrder, $data, $vendorId, $portalUserId, $resolve): SupplierShipment {
            // Shipments share the PO row with acceptance, cancellation and
            // receiving; lock it so a stale portal page cannot report against
            // a PO that has since moved on.
            $row = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);
            abort_if((int) $row->vendor_id !== $vendorId, 403);
            SupplierPoCapabilities::assert($row, 'ship');

            $shipment = $resolve($row);
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
            SupplierShipmentUpdate::create([
                'supplier_shipment_id' => $shipment->id,
                'portal_user_id' => $portalUserId,
                'payload' => $values,
                'created_at' => now(),
            ]);

            // The ETA deliberately no longer writes purchase_orders.confirmed_delivery_date.
            // The agreed date comes from the accept/propose flow; an ETA is a
            // forecast, and letting it move the agreed date let a late supplier
            // rewrite its own on-time record.
            return $shipment->fresh();
        }));

        // Anchored on the PO like every other supplier action on it.
        $this->audit->record($auditAction, $purchaseOrder, $portalUserId, $vendorId);

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

                $existing = PortalShippingDocument::query()
                    ->where('purchase_order_id', $po->id)
                    ->where('document_type', $data['document_type'])
                    ->where('content_sha256', $contentHash)
                    ->first();

                if ($existing) {
                    Storage::disk('local')->delete($path);

                    return $existing;
                }

                return PortalShippingDocument::create([
                    'purchase_order_id' => $po->id,
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

        $document->load(['purchaseOrder', 'uploader']);
        $this->audit->record('supplier_doc.upload', $document, $portalUserId, $vendorId);

        return $document;
    }

    public function shippingDocuments(int $vendorId, PurchaseOrder $purchaseOrder): Collection
    {
        abort_if($purchaseOrder->vendor_id !== $vendorId, 403);

        return PortalShippingDocument::where('purchase_order_id', $purchaseOrder->id)
            ->with(['purchaseOrder', 'uploader'])
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
