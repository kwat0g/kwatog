<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\Accounting\Resources\InvoiceResource;
use App\Modules\Accounting\Services\PdfService;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Requests\Customer\CreateComplaintRequest;
use App\Modules\B2B\Requests\Customer\CustomerStoreDeliveryScheduleRequest;
use App\Modules\B2B\Resources\DeliveryScheduleResource;
use App\Modules\B2B\Services\CustomerPortalService;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Resources\SalesOrderResource;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class CustomerPortalController extends Controller
{
    public function __construct(
        private readonly CustomerPortalService $service,
        private readonly PdfService $pdf,
    ) {}

    private function user(Request $request): CustomerPortalUser
    {
        /** @var CustomerPortalUser $user */
        $user = $request->user('customer_portal');
        return $user;
    }

    /**
     * GET /api/v1/b2b/customer/dashboard
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $this->service->dashboard($user->customer_id);

        // Wrap collection fields in API Resources for consistent serialization.
        $data['recent_orders']   = SalesOrderResource::collection($data['recent_orders']);
        $data['recent_invoices'] = InvoiceResource::collection($data['recent_invoices']);

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/v1/b2b/customer/sales-orders
     */
    public function salesOrders(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);

        $paginator = $this->service->salesOrders($user->customer_id, [
            'status'   => $request->query('status'),
            'search'   => $request->query('search'),
            'per_page' => $request->query('per_page', 25),
        ]);

        return SalesOrderResource::collection($paginator);
    }

    /**
     * GET /api/v1/b2b/customer/sales-orders/{id}
     */
    public function salesOrderShow(SalesOrder $salesOrder, Request $request): SalesOrderResource
    {
        $user = $this->user($request);
        $salesOrder = $this->service->salesOrderDetail($user->customer_id, $salesOrder);

        return new SalesOrderResource($salesOrder);
    }

    /**
     * GET /api/v1/b2b/customer/sales-orders/{id}/chain
     */
    public function salesOrderChain(SalesOrder $salesOrder, Request $request): JsonResponse
    {
        $user = $this->user($request);
        $chain = $this->service->salesOrderChain($user->customer_id, $salesOrder);

        return response()->json(['data' => $chain]);
    }

    /**
     * GET /api/v1/b2b/customer/invoices
     */
    public function invoices(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);

        $paginator = $this->service->invoices($user->customer_id, [
            'per_page' => $request->query('per_page', 25),
        ]);

        return InvoiceResource::collection($paginator);
    }

    /**
     * GET /api/v1/b2b/customer/invoices/{id}
     */
    public function invoiceDetail(Invoice $invoice, Request $request): InvoiceResource
    {
        $user = $this->user($request);
        $invoice = $this->service->invoiceDetail($user->customer_id, $invoice);

        return new InvoiceResource($invoice);
    }

    /**
     * GET /api/v1/b2b/customer/deliveries
     */
    public function deliveries(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $deliveries = $this->service->deliveries($user->customer_id, [
            'status' => $request->query('status'),
        ]);

        return response()->json(['data' => $deliveries]);
    }

    /**
     * GET /api/v1/b2b/customer/invoices/{id}/pdf
     */
    public function invoicePdf(Invoice $invoice, Request $request)
    {
        $user = $this->user($request);
        // Ownership check via service
        $this->service->invoiceDetail($user->customer_id, $invoice);

        return $this->pdf->invoice($invoice);
    }

    /**
     * GET /api/v1/b2b/customer/deliveries/{id}
     */
    public function deliveryDetail(Delivery $delivery, Request $request): JsonResponse
    {
        $user = $this->user($request);
        $delivery = $this->service->deliveryDetail($user->customer_id, $delivery);

        return response()->json(['data' => $delivery]);
    }

    /**
     * GET /api/v1/b2b/customer/complaints
     */
    public function complaints(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $complaints = $this->service->complaints($user->customer_id);

        return response()->json(['data' => $complaints]);
    }

    /**
     * POST /api/v1/b2b/customer/complaints
     */
    public function createComplaint(CreateComplaintRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $complaint = $this->service->createComplaint($user->customer_id, $request->validated());

        return response()->json([
            'data'    => $complaint,
            'message' => 'Complaint submitted successfully.',
        ], 201);
    }

    /**
     * GET /api/v1/b2b/customer/complaints/{complaint}/8d-report
     */
    public function complaint8dReport(CustomerComplaint $complaint, Request $request): JsonResponse
    {
        $user = $this->user($request);
        $report = $this->service->complaint8dReport($user->customer_id, $complaint);

        if ($report === null) {
            return response()->json(['message' => 'No 8D report available for this complaint yet.'], 404);
        }

        return response()->json(['data' => $report]);
    }

    /**
     * GET /api/v1/b2b/customer/statement-of-account
     */
    public function statementOfAccount(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $customer = $user->customer;

        if (! $customer) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        $asOf = $request->query('as_of');
        $result = $this->service->statementOfAccount($customer, $asOf);

        return response()->json(['data' => $result]);
    }

    /**
     * GET /api/v1/b2b/customer/delivery-schedules
     */
    public function deliverySchedules(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $schedules = $this->service->deliverySchedules($user->customer_id);

        return response()->json([
            'data' => DeliveryScheduleResource::collection($schedules),
        ]);
    }

    /**
     * POST /api/v1/b2b/customer/delivery-schedules
     */
    public function storeDeliverySchedule(CustomerStoreDeliveryScheduleRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $schedule = $this->service->storeDeliverySchedule($user->customer_id, $request->validated());

        return response()->json([
            'data'    => new DeliveryScheduleResource($schedule),
            'message' => 'Delivery schedule submitted successfully.',
        ], 201);
    }
}
