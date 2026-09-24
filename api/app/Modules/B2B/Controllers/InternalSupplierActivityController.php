<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\B2B\Enums\SupplierShippingDocumentType;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\B2B\Models\PortalShippingDocument;
use App\Modules\B2B\Resources\DeliveryScheduleResource;
use App\Modules\B2B\Resources\SupplierShipmentResource;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Policies\PurchaseOrderAccessPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What the supplier reported through the portal, read by OGAMI staff.
 *
 * Shipments, shipping documents and delivery schedules were write-only until
 * now: suppliers filed them and nothing internal ever read them back. The PO
 * endpoints reuse the purchase-order row scope (PurchaseOrderAccessPolicy) —
 * the permission alone says "may open purchasing", not "may see this PO".
 */
class InternalSupplierActivityController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderAccessPolicy $access,
    ) {}

    /**
     * GET /api/v1/b2b/purchase-orders/{purchaseOrder}/supplier-activity
     */
    public function purchaseOrderActivity(Request $request, PurchaseOrder $purchaseOrder): JsonResponse
    {
        abort_unless($this->access->canView($request->user(), $purchaseOrder), 404);

        $shipments = $purchaseOrder->supplierShipments()->withCount('updates')->get();
        $schedules = DeliverySchedule::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->whereNotNull('vendor_id')
            ->orderByDesc('month')
            ->orderByDesc('id')
            ->get();

        return response()->json(['data' => [
            'shipments' => $shipments->map(static fn ($shipment): array => [
                ...(new SupplierShipmentResource($shipment))->resolve(),
                'updates_count' => (int) $shipment->updates_count,
            ])->values()->all(),
            'documents' => $this->documentRows($this->documentsFor($purchaseOrder)),
            'delivery_schedules' => DeliveryScheduleResource::collection($schedules)->resolve(),
        ]]);
    }

    /**
     * GET /api/v1/b2b/goods-receipt-notes/{goodsReceiptNote}/supplier-documents
     *
     * Incoming QC reads the supplier's CoA from the GRN it is inspecting, so
     * certificates sort first.
     */
    public function grnSupplierDocuments(GoodsReceiptNote $goodsReceiptNote): JsonResponse
    {
        $po = $goodsReceiptNote->purchaseOrder;
        if (! $po) {
            return response()->json(['data' => ['shipment' => null, 'documents' => []]]);
        }

        $documents = $this->documentsFor($po)->sortBy(
            static fn (PortalShippingDocument $doc): int => $doc->document_type === SupplierShippingDocumentType::CertificateOfAnalysis->value ? 0 : 1,
        );

        return response()->json(['data' => [
            'shipment' => $po->supplierShipment ? (new SupplierShipmentResource($po->supplierShipment))->resolve() : null,
            'documents' => $this->documentRows($documents),
        ]]);
    }

    /**
     * GET /api/v1/b2b/supplier-documents/{id}/download
     *
     * Allowed to anyone who can see the owning PO, or — for the GRN panel —
     * anyone who can view inventory receipts of that PO.
     */
    public function downloadDocument(Request $request, string $id): StreamedResponse
    {
        $decoded = app('hashids')->decode($id);
        abort_if($decoded === [], 404);

        $document = PortalShippingDocument::query()->with('purchaseOrder')->findOrFail((int) $decoded[0]);
        $po = $document->purchaseOrder;
        abort_if($po === null, 404);

        $user = $request->user();
        $viaPurchasing = $user->hasPermission('purchasing.view') && $this->access->canView($user, $po);
        $viaReceiving = $user->hasPermission('inventory.view')
            && GoodsReceiptNote::query()->where('purchase_order_id', $po->id)->exists();
        abort_unless($viaPurchasing || $viaReceiving, 404);

        abort_unless(Storage::disk('local')->exists($document->file_path), 404, 'File not found.');

        return Storage::disk('local')->download($document->file_path, $document->original_filename);
    }

    /** @return Collection<int, PortalShippingDocument> */
    private function documentsFor(PurchaseOrder $po): Collection
    {
        return PortalShippingDocument::query()
            ->where('purchase_order_id', $po->id)
            ->orderByDesc('uploaded_at')
            ->get();
    }

    /**
     * @param  Collection<int, PortalShippingDocument>  $documents
     * @return list<array<string, mixed>>
     */
    private function documentRows(Collection $documents): array
    {
        return $documents->map(static fn (PortalShippingDocument $doc): array => [
            'id' => $doc->hash_id,
            'document_type' => $doc->document_type,
            'document_type_label' => SupplierShippingDocumentType::tryFrom((string) $doc->document_type)?->label()
                ?? ($doc->document_type === 'supplier_invoice' ? 'Supplier invoice' : (string) $doc->document_type),
            'original_filename' => $doc->original_filename,
            'file_size_bytes' => $doc->file_size_bytes,
            'uploaded_at' => $doc->uploaded_at?->toIso8601String(),
        ])->values()->all();
    }
}
