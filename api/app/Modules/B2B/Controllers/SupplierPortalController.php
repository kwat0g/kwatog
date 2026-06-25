<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Resources\BillResource;
use App\Modules\Accounting\Services\PdfService;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Requests\Supplier\AcknowledgePoRequest;
use App\Modules\B2B\Requests\Supplier\ShipmentUpdateRequest;
use App\Modules\B2B\Requests\Supplier\StoreDeliveryScheduleRequest;
use App\Modules\B2B\Requests\Supplier\SubmitInvoiceRequest;
use App\Modules\B2B\Requests\Supplier\UploadShippingDocumentsRequest;
use App\Modules\B2B\Resources\DeliveryScheduleResource;
use App\Modules\B2B\Resources\PortalShippingDocumentResource;
use App\Modules\B2B\Services\SupplierPortalService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Resources\PurchaseOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupplierPortalController extends Controller
{
    public function __construct(
        private readonly SupplierPortalService $service,
        private readonly PdfService $pdf,
    ) {}

    private function user(Request $request): SupplierPortalUser
    {
        /** @var SupplierPortalUser $user */
        $user = $request->user('supplier_portal');
        return $user;
    }

    /**
     * GET /api/v1/b2b/supplier/dashboard
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $this->service->dashboard($user->vendor_id);

        // Wrap collection fields in API Resources for consistent serialization.
        $data['recent_pos']      = PurchaseOrderResource::collection($data['recent_pos']);
        $data['recent_invoices'] = BillResource::collection($data['recent_invoices']);

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/v1/b2b/supplier/purchase-orders
     */
    public function purchaseOrders(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);

        $paginator = $this->service->purchaseOrders($user->vendor_id, [
            'status'   => $request->query('status'),
            'search'   => $request->query('search'),
            'sort'     => $request->query('sort', 'created_at'),
            'dir'      => $request->query('dir', 'desc'),
            'per_page' => $request->query('per_page', 25),
        ]);

        return PurchaseOrderResource::collection($paginator);
    }

    /**
     * GET /api/v1/b2b/supplier/purchase-orders/{id}
     */
    public function purchaseOrderShow(PurchaseOrder $purchaseOrder, Request $request): PurchaseOrderResource
    {
        $user = $this->user($request);
        $purchaseOrder = $this->service->purchaseOrderDetail($user->vendor_id, $purchaseOrder);

        return new PurchaseOrderResource($purchaseOrder);
    }

    /**
     * GET /api/v1/b2b/supplier/purchase-orders/{id}/pdf
     */
    public function poPdf(PurchaseOrder $purchaseOrder, Request $request)
    {
        $user = $this->user($request);
        // Ownership check via service
        $this->service->purchaseOrderDetail($user->vendor_id, $purchaseOrder);

        return $this->pdf->purchaseOrder($purchaseOrder);
    }

    /**
     * GET /api/v1/b2b/supplier/invoices/{id}/pdf
     */
    public function invoicePdf(Invoice $invoice, Request $request)
    {
        $user = $this->user($request);
        abort_if(
            ! $invoice->purchaseOrder || $invoice->purchaseOrder->vendor_id !== $user->vendor_id,
            403,
            'You do not have access to this invoice.',
        );

        return $this->pdf->invoice($invoice);
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
     * POST /api/v1/b2b/supplier/purchase-orders/{id}/submit-invoice
     */
    public function submitInvoice(PurchaseOrder $purchaseOrder, SubmitInvoiceRequest $request): JsonResponse
    {
        $user = $this->user($request);

        try {
            $result = $this->service->submitInvoice(
                $user->vendor_id,
                $user->id,
                $purchaseOrder,
                $request->validated(),
                $request->hasFile('file') ? $request->file('file') : null,
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code'    => 'bill_creation_failed',
            ], 422);
        }

        $bill = $result['bill'];

        return response()->json([
            'data'    => [
                'id'           => $bill->hash_id,
                'bill_number'  => $bill->bill_number,
                'total_amount' => (string) $bill->total_amount,
                'status'       => (string) $bill->status?->value,
            ],
            'message' => $result['message'],
        ], 201);
    }

    /**
     * GET /api/v1/b2b/supplier/statement-of-account
     */
    public function statementOfAccount(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $this->service->statementOfAccount($user->vendor_id);

        // Wrap open_bills in BillResource for consistent serialization
        $data['open_bills'] = BillResource::collection($data['open_bills']);

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/v1/b2b/supplier/delivery-schedules
     */
    public function deliverySchedules(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $schedules = $this->service->deliverySchedules($user->vendor_id);

        return response()->json([
            'data' => DeliveryScheduleResource::collection($schedules),
        ]);
    }

    /**
     * POST /api/v1/b2b/supplier/delivery-schedules
     */
    public function storeDeliverySchedule(StoreDeliveryScheduleRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $schedule = $this->service->storeDeliverySchedule($user->vendor_id, $request->validated());

        return response()->json([
            'data'    => new DeliveryScheduleResource($schedule),
            'message' => 'Delivery schedule submitted successfully.',
        ], 201);
    }

    /**
     * POST /api/v1/b2b/supplier/purchase-orders/{id}/acknowledge
     */
    public function acknowledgePo(PurchaseOrder $purchaseOrder, AcknowledgePoRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $this->service->acknowledgePo($user->vendor_id, $purchaseOrder, $request->validated());

        return response()->json(['message' => 'Purchase order acknowledged.']);
    }

    /**
     * POST /api/v1/b2b/supplier/purchase-orders/{id}/shipment-update
     */
    public function updateShipment(PurchaseOrder $purchaseOrder, ShipmentUpdateRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $this->service->updateShipment($user->vendor_id, $purchaseOrder, $request->validated());

        return response()->json(['message' => 'Shipment information updated.']);
    }

    /**
     * GET /api/v1/b2b/supplier/invoices
     */
    public function invoices(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);

        $paginator = $this->service->invoices($user->vendor_id, [
            'per_page' => $request->query('per_page', 25),
        ]);

        return BillResource::collection($paginator);
    }

    /**
     * GET /api/v1/b2b/supplier/invoices/{id}
     */
    public function invoiceDetail(Bill $invoice, Request $request): BillResource
    {
        $user = $this->user($request);
        $invoice = $this->service->invoiceDetail($user->vendor_id, $invoice);

        return new BillResource($invoice);
    }

    /**
     * GET /api/v1/b2b/supplier/deliveries
     */
    public function deliveries(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $deliveries = $this->service->deliveries($user->vendor_id, [
            'status' => $request->query('status'),
        ]);

        return response()->json(['data' => $deliveries]);
    }

    /**
     * GET /api/v1/b2b/supplier/ppap-submissions
     */
    public function ppapSubmissions(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $paginator = $this->service->ppapSubmissions($user->vendor_id, [
            'status'   => $request->query('status'),
            'per_page' => $request->query('per_page', 25),
        ]);

        return response()->json([
            'data' => \App\Modules\Quality\Resources\PpapSubmissionResource::collection($paginator),
        ]);
    }
}
