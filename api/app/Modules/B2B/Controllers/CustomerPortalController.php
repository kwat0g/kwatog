<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\B2B\Resources\CustomerPortalInvoiceResource;
use App\Modules\Accounting\Services\PdfService;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Requests\Customer\ConfirmPortalDeliveryRequest;
use App\Modules\B2B\Requests\Customer\CreateComplaintRequest;
use App\Modules\B2B\Requests\Customer\CustomerStoreDeliveryScheduleRequest;
use App\Modules\B2B\Requests\Customer\RespondToSalesOrderRequest;
use App\Modules\B2B\Requests\Customer\StoreCustomerReturnRequest;
use App\Modules\B2B\Requests\Customer\StorePortalOrderRequest;
use App\Modules\B2B\Resources\CustomerPortalComplaintResource;
use App\Modules\B2B\Resources\CustomerReturnRequestResource;
use App\Modules\B2B\Resources\CustomerDeliveryResource;
use App\Modules\B2B\Resources\DeliveryScheduleResource;
use App\Modules\B2B\Services\CustomerPortalService;
use App\Modules\CRM\Enums\ComplaintStatus;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Resources\SalesOrderResource;
use App\Modules\CRM\Resources\SalesOrderResponseResource;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\DeliveryProof;
use App\Modules\Quality\Enums\NcrSeverity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\Rule;

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
        $data['recent_orders'] = SalesOrderResource::collection($data['recent_orders']);
        $data['recent_invoices'] = CustomerPortalInvoiceResource::collection($data['recent_invoices']);
        $data['recent_deliveries'] = CustomerDeliveryResource::collection($data['recent_deliveries']);
        $data['recent_complaints'] = CustomerPortalComplaintResource::collection($data['recent_complaints']);

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/v1/b2b/customer/catalog
     */
    public function catalog(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $params = $request->validate([
            'as_of'  => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $catalog = $this->service->catalog(
            $user->customer_id,
            $params['as_of'] ?? null,
            $params['search'] ?? null,
        );

        return response()->json(['data' => $catalog]);
    }

    /**
     * POST /api/v1/b2b/customer/orders
     */
    public function storeOrder(StorePortalOrderRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $so = $this->service->placeOrder($user->customer_id, $request->validated(), $user);

        return response()->json([
            'data'    => new SalesOrderResource($so),
            'message' => 'Order submitted. It will be confirmed by our sales team.',
        ], 201);
    }

    /**
     * GET /api/v1/b2b/customer/sales-orders
     */
    public function salesOrders(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(SalesOrderStatus::class)],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $paginator = $this->service->salesOrders($user->customer_id, $filters);

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
     * POST /api/v1/b2b/customer/orders/{salesOrder}/respond
     */
    public function respondToSalesOrder(SalesOrder $salesOrder, RespondToSalesOrderRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $response = $this->service->respondToSalesOrder(
            $user->customer_id,
            (int) $user->id,
            $salesOrder,
            $request->validated(),
        );

        return response()->json([
            'data'    => new SalesOrderResponseResource($response),
            'message' => 'Your response was submitted to our sales team.',
        ], 201);
    }

    /**
     * GET /api/v1/b2b/customer/invoices
     */
    public function invoices(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(InvoiceStatus::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $paginator = $this->service->invoices($user->customer_id, $filters);

        return CustomerPortalInvoiceResource::collection($paginator);
    }

    /**
     * GET /api/v1/b2b/customer/invoices/{id}
     */
    public function invoiceDetail(Invoice $invoice, Request $request): CustomerPortalInvoiceResource
    {
        $user = $this->user($request);
        $invoice = $this->service->invoiceDetail($user->customer_id, $invoice);

        return new CustomerPortalInvoiceResource($invoice);
    }

    /**
     * GET /api/v1/b2b/customer/deliveries
     */
    public function deliveries(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);

        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(DeliveryStatus::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $deliveries = $this->service->deliveries($user->customer_id, $filters);

        return CustomerDeliveryResource::collection($deliveries);
    }

    /**
     * GET /api/v1/b2b/customer/invoices/{id}/pdf
     */
    public function invoicePdf(Invoice $invoice, Request $request)
    {
        $user = $this->user($request);
        // Ownership check via service
        $invoice = $this->service->invoiceDetail($user->customer_id, $invoice);

        return $this->pdf->customerInvoice($invoice);
    }

    /**
     * GET /api/v1/b2b/customer/deliveries/{id}
     */
    public function deliveryDetail(Delivery $delivery, Request $request): CustomerDeliveryResource
    {
        $user = $this->user($request);
        $delivery = $this->service->deliveryDetail($user->customer_id, $delivery);

        return new CustomerDeliveryResource($delivery);
    }

    /**
     * POST /api/v1/b2b/customer/deliveries/{id}/confirm
     */
    public function confirmDelivery(Delivery $delivery, ConfirmPortalDeliveryRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $confirmed = $this->service->confirmDelivery($user->customer_id, $delivery, $request->validated(), $user);

        return response()->json([
            'data'    => new CustomerDeliveryResource($confirmed),
            'message' => 'Delivery confirmed. Thank you for confirming receipt.',
        ]);
    }

    public function deliveryProof(Delivery $delivery, DeliveryProof $proof, Request $request): StreamedResponse
    {
        $user = $this->user($request);
        $this->service->deliveryDetail($user->customer_id, $delivery);
        abort_if($proof->delivery_id !== $delivery->id, 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($proof->file_path), 404, 'Proof file not found on disk.');
        $mime = $proof->mime_type ?? $disk->mimeType($proof->file_path) ?? 'application/octet-stream';

        $stream = $disk->readStream($proof->file_path);
        abort_unless(is_resource($stream), 404, 'Proof file could not be opened.');
        $filename = basename((string) $proof->file_name);
        // Strip every C0 control character, DEL, the quote and the backslash:
        // all of them can terminate or forge a Content-Disposition parameter.
        //
        // The class is written with hex escapes and a doubled backslash on
        // purpose. The obvious spelling — '/[\r\n"\\]/' — is a trap: in a
        // single-quoted PHP string `\\` collapses to ONE backslash, so PCRE
        // received `[\r\n"\]`, read `\]` as an escaped literal `]`, and never
        // closed the class. preg_replace() then failed to compile, returned
        // null, and the warning Laravel promotes to ErrorException turned every
        // proof download into a 500.
        $filename = preg_replace('/[\x00-\x1f\x7f"\\\\]/', '', $filename) ?: 'delivery-proof';
        $asciiFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'delivery-proof';

        return response()->stream(
            static function () use ($stream): void {
                fpassthru($stream);
                fclose($stream);
            },
            200,
            [
                'Content-Type' => $mime,
                'Cache-Control' => 'private, no-store, max-age=0',
                'Content-Disposition' => sprintf(
                    'inline; filename="%s"; filename*=UTF-8\'\'%s',
                    $asciiFilename,
                    rawurlencode($filename),
                ),
            ],
        );
    }

    /**
     * GET /api/v1/b2b/customer/complaints
     */
    public function complaints(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $filters = $request->validate([
            'status' => ['sometimes', Rule::enum(ComplaintStatus::class)],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return CustomerPortalComplaintResource::collection(
            $this->service->complaints($user->customer_id, $filters),
        );
    }

    public function complaintOptions(): JsonResponse
    {
        return response()->json(['data' => [
            'severities' => array_map(
                static fn (NcrSeverity $severity): array => ['value' => $severity->value, 'label' => ucfirst($severity->value)],
                NcrSeverity::cases(),
            ),
            'statuses' => array_map(
                static fn (ComplaintStatus $status): array => [
                    'value' => $status->value,
                    'label' => ucfirst($status->value),
                ],
                ComplaintStatus::cases(),
            ),
        ]]);
    }

    /**
     * POST /api/v1/b2b/customer/complaints
     */
    public function createComplaint(CreateComplaintRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $complaint = $this->service->createComplaint($user->customer_id, $request->validated());

        return response()->json([
            'data' => new CustomerPortalComplaintResource($complaint),
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

        $asOf = $request->validate([
            'as_of' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ])['as_of'] ?? null;
        $result = $this->service->statementOfAccount($customer, $asOf);

        return response()->json(['data' => $result]);
    }

    /**
     * GET /api/v1/b2b/customer/delivery-schedules
     */
    public function deliverySchedules(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $schedules = $this->service->deliverySchedules($user->customer_id, $filters);

        return DeliveryScheduleResource::collection($schedules)->response();
    }

    /**
     * POST /api/v1/b2b/customer/delivery-schedules
     */
    public function storeDeliverySchedule(CustomerStoreDeliveryScheduleRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $schedule = $this->service->storeDeliverySchedule($user->customer_id, $request->validated());

        return response()->json([
            'data' => new DeliveryScheduleResource($schedule),
            'message' => 'Delivery schedule submitted successfully.',
        ], 201);
    }

    /**
     * GET /api/v1/b2b/customer/return-requests/source-options
     */
    public function returnSourceOptions(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        return response()->json([
            'data' => $this->service->returnSourceOptions($user->customer_id, $filters),
        ]);
    }

    /**
     * GET /api/v1/b2b/customer/return-requests
     */
    public function returnRequests(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $filters = $request->validate([
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return CustomerReturnRequestResource::collection(
            $this->service->returnRequests($user->customer_id, $filters),
        );
    }

    /**
     * GET /api/v1/b2b/customer/return-requests/{returnRequest}
     */
    public function returnRequestShow(ReturnRequest $returnRequest, Request $request): CustomerReturnRequestResource
    {
        $user = $this->user($request);

        return new CustomerReturnRequestResource(
            $this->service->returnRequestDetail($user->customer_id, $returnRequest),
        );
    }

    /**
     * POST /api/v1/b2b/customer/return-requests
     */
    public function storeReturnRequest(StoreCustomerReturnRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $rma = $this->service->createReturn($user->customer_id, $request->validated(), $user);

        return response()->json([
            'data'    => new CustomerReturnRequestResource($rma->load('items.product')),
            'message' => 'Return request submitted. Our team will review it and get back to you.',
        ], 201);
    }
}
