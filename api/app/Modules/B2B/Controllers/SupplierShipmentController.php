<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\B2B\Enums\SupplierShippingDocumentType;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Requests\Supplier\ShipmentUpdateRequest;
use App\Modules\B2B\Requests\Supplier\UploadShippingDocumentsRequest;
use App\Modules\B2B\Resources\PortalShippingDocumentResource;
use App\Modules\B2B\Services\SupplierShipmentService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Supplier shipment tracking and shipping documents.
 */
class SupplierShipmentController extends Controller
{
    public function __construct(
        private readonly SupplierShipmentService $service,
    ) {}

    private function user(Request $request): SupplierPortalUser
    {
        /** @var SupplierPortalUser $user */
        $user = $request->user('supplier_portal');
        return $user;
    }

    /**
     * GET /api/v1/b2b/supplier/purchase-orders/shipping-documents/options
     */
    public function shippingDocumentOptions(): JsonResponse
    {
        return response()->json(['data' => [
            'document_types' => array_map(
                static fn (SupplierShippingDocumentType $type): array => ['value' => $type->value, 'label' => $type->label()],
                SupplierShippingDocumentType::cases(),
            ),
        ]]);
    }

    /**
     * POST /api/v1/b2b/supplier/purchase-orders/{id}/shipping-documents
     */
    public function uploadShippingDocuments(PurchaseOrder $purchaseOrder, UploadShippingDocumentsRequest $request): JsonResponse
    {
        $user = $this->user($request);

        $doc = $this->service->uploadShippingDocument(
            $user->vendor_id,
            $user->id,
            $purchaseOrder,
            $request->file('file'),
            $request->validated(),
        );

        return response()->json([
            'data'    => new PortalShippingDocumentResource($doc),
            'message' => 'Shipping document uploaded successfully.',
        ], 201);
    }

    /**
     * GET /api/v1/b2b/supplier/purchase-orders/{id}/shipping-documents
     */
    public function shippingDocuments(PurchaseOrder $purchaseOrder, Request $request): JsonResponse
    {
        $user = $this->user($request);
        $docs = $this->service->shippingDocuments($user->vendor_id, $purchaseOrder);

        return response()->json([
            'data' => PortalShippingDocumentResource::collection($docs),
        ]);
    }

    /**
     * GET /api/v1/b2b/supplier/shipping-documents/{id}/download
     */
    public function downloadShippingDocument(string $id, Request $request): StreamedResponse
    {
        $user = $this->user($request);
        $doc = $this->service->downloadShippingDocument($user->vendor_id, $id);

        return Storage::disk('local')->download($doc->file_path, $doc->original_filename);
    }

    /**
     * POST /api/v1/b2b/supplier/purchase-orders/{purchaseOrder}/shipments
     *
     * Create a new shipment for the PO.
     */
    public function storeShipment(PurchaseOrder $purchaseOrder, ShipmentUpdateRequest $request): JsonResponse
    {
        $user = $this->user($request);

        $shipment = $this->service->createShipment(
            $user->vendor_id,
            $user->id,
            $purchaseOrder,
            $request->validated(),
        );

        return response()->json([
            'data' => new \App\Modules\B2B\Resources\SupplierShipmentResource($shipment),
            'message' => 'Shipment created successfully.',
        ], 201);
    }

    /**
     * PUT /api/v1/b2b/supplier/purchase-orders/{purchaseOrder}/shipments/{supplierShipment}
     *
     * Update an existing shipment.
     */
    public function updateShipmentById(
        PurchaseOrder $purchaseOrder,
        string $supplierShipmentId,
        ShipmentUpdateRequest $request
    ): JsonResponse {
        $user = $this->user($request);

        // Decode the hash ID to find the shipment.
        $decoded = app('hashids')->decode($supplierShipmentId);
        if (empty($decoded)) {
            abort(404);
        }

        $shipment = \App\Modules\B2B\Models\SupplierShipment::findOrFail((int) $decoded[0]);

        // Verify it belongs to this PO and vendor.
        if ($shipment->purchase_order_id !== $purchaseOrder->id || $purchaseOrder->vendor_id !== $user->vendor_id) {
            abort(404);
        }

        $updated = $this->service->updateShipment(
            $user->vendor_id,
            $user->id,
            $purchaseOrder,
            $shipment,
            $request->validated(),
        );

        return response()->json([
            'data' => new \App\Modules\B2B\Resources\SupplierShipmentResource($updated),
            'message' => 'Shipment updated successfully.',
        ]);
    }

    /**
     * POST /api/v1/b2b/supplier/purchase-orders/{id}/shipment-update
     *
     * Legacy endpoint: update the latest shipment or create the first.
     * Kept for backward compatibility.
     */
    public function updateShipment(PurchaseOrder $purchaseOrder, ShipmentUpdateRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $this->service->updateShipmentLegacy($user->vendor_id, $user->id, $purchaseOrder, $request->validated());

        return response()->json(['message' => 'Shipment information updated.']);
    }
}
