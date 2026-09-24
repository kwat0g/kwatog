<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Resources\SupplierBillResource;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\B2B\Requests\Supplier\AcknowledgePoRequest;
use App\Modules\B2B\Requests\Supplier\RespondToPurchaseOrderRequest;
use App\Modules\B2B\Resources\SupplierDeliveryResource;
use App\Modules\B2B\Resources\SupplierPpapSubmissionResource;
use App\Modules\B2B\Services\SupplierPortalService;
use App\Modules\B2B\Services\SupplierPortalPdfService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\B2B\Resources\SupplierPurchaseOrderResource;
use App\Modules\Purchasing\Resources\PurchaseOrderResponseResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class SupplierPortalController extends Controller
{
    public function __construct(
        private readonly SupplierPortalService $service,
        private readonly SupplierPortalPdfService $pdf,
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
        $data['recent_pos']      = SupplierPurchaseOrderResource::collection($data['recent_pos']);
        $data['recent_invoices'] = SupplierBillResource::collection($data['recent_invoices']);

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

        return SupplierPurchaseOrderResource::collection($paginator);
    }

    /**
     * GET /api/v1/b2b/supplier/purchase-orders/{id}
     */
    public function purchaseOrderShow(PurchaseOrder $purchaseOrder, Request $request): SupplierPurchaseOrderResource
    {
        $user = $this->user($request);
        $purchaseOrder = $this->service->purchaseOrderDetail($user->vendor_id, $purchaseOrder);

        return new SupplierPurchaseOrderResource($purchaseOrder);
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
    public function invoicePdf(Bill $invoice, Request $request)
    {
        $user = $this->user($request);
        $bill = $this->service->invoiceDetail($user->vendor_id, $invoice);

        return $this->pdf->bill($bill);
    }

    /**
     * GET /api/v1/b2b/supplier/statement-of-account
     */
    public function statementOfAccount(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $this->service->statementOfAccount($user->vendor_id);

        // Keep supplier bill data separate from internal AP exception evidence.
        $data['open_bills'] = SupplierBillResource::collection($data['open_bills']);

        return response()->json(['data' => $data]);
    }

    /**
     * POST /api/v1/b2b/supplier/purchase-orders/{id}/respond
     */
    public function respondToPo(PurchaseOrder $purchaseOrder, RespondToPurchaseOrderRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $response = $this->service->respondToPo(
            $user->vendor_id,
            $user->id,
            $purchaseOrder,
            $request->validated(),
        );

        return response()->json([
            'data'    => new PurchaseOrderResponseResource($response),
            'message' => 'Supplier response recorded.',
        ], 201);
    }

    /**
     * POST /api/v1/b2b/supplier/purchase-orders/{id}/acknowledge
     */
    public function acknowledgePo(PurchaseOrder $purchaseOrder, AcknowledgePoRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $response = $this->service->acknowledgePo($user->vendor_id, $user->id, $purchaseOrder, $request->validated());

        return response()->json([
            'data'    => new PurchaseOrderResponseResource($response),
            'message' => 'Purchase order acknowledged.',
        ]);
    }

    /**
     * GET /api/v1/b2b/supplier/invoices
     */
    public function invoices(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);

        $paginator = $this->service->invoices($user->vendor_id, [
            'status'   => $request->query('status'),
            'per_page' => $request->query('per_page', 25),
        ]);

        return SupplierBillResource::collection($paginator);
    }

    /**
     * GET /api/v1/b2b/supplier/invoices/{id}
     */
    public function invoiceDetail(Bill $invoice, Request $request): SupplierBillResource
    {
        $user = $this->user($request);
        $invoice = $this->service->invoiceDetail($user->vendor_id, $invoice);

        return new SupplierBillResource($invoice);
    }

    /**
     * GET /api/v1/b2b/supplier/deliveries
     */
    public function deliveries(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);

        $deliveries = $this->service->deliveries($user->vendor_id, [
            'status' => $request->query('status'),
            'per_page' => $request->query('per_page', 25),
        ]);

        return SupplierDeliveryResource::collection($deliveries);
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
            'data' => SupplierPpapSubmissionResource::collection($paginator),
        ]);
    }
}
