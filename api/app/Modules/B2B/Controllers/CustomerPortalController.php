<?php

declare(strict_types=1);

namespace App\Modules\B2B\Controllers;

use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Resources\InvoiceResource;
use App\Modules\Accounting\Services\PdfService;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\B2B\Requests\Customer\CreateComplaintRequest;
use App\Modules\B2B\Requests\Customer\CustomerStoreDeliveryScheduleRequest;
use App\Modules\B2B\Resources\DeliveryScheduleResource;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Resources\SalesOrderResource;
use App\Modules\CRM\Services\SalesOrderService;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class CustomerPortalController extends Controller
{
    public function __construct(
        private readonly PdfService $pdf,
        private readonly SalesOrderService $salesOrderService,
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
        $customerId = $user->customer_id;

        $openSoCount = SalesOrder::where('customer_id', $customerId)
            ->whereIn('status', ['draft', 'confirmed'])->count();

        $pendingDeliveryCount = Delivery::whereHas('salesOrder', fn ($q) => $q->where('customer_id', $customerId))
            ->whereIn('status', ['scheduled', 'loading', 'in_transit'])->count();

        $openInvoiceCount = Invoice::where('customer_id', $customerId)
            ->whereIn('status', ['sent', 'overdue', 'partial'])->count();

        $totalOutstanding = Invoice::where('customer_id', $customerId)
            ->whereIn('status', ['sent', 'overdue', 'partial'])->sum('balance');

        $recentOrders = SalesOrder::where('customer_id', $customerId)
            ->orderByDesc('created_at')->limit(5)->get();

        $recentInvoices = Invoice::where('customer_id', $customerId)
            ->orderByDesc('created_at')->limit(5)->get();

        $recentDeliveries = Delivery::whereHas('salesOrder', fn ($q) => $q->where('customer_id', $customerId))
            ->orderByDesc('created_at')->limit(5)->get();

        $recentComplaints = CustomerComplaint::where('customer_id', $customerId)
            ->orderByDesc('created_at')->limit(5)->get();

        return response()->json([
            'data' => [
                'open_so_count'          => $openSoCount,
                'pending_delivery_count'  => $pendingDeliveryCount,
                'open_invoice_count'      => $openInvoiceCount,
                'total_outstanding'       => number_format((float) $totalOutstanding, 2),
                'recent_orders'           => SalesOrderResource::collection($recentOrders),
                'recent_invoices'         => InvoiceResource::collection($recentInvoices),
                'recent_deliveries'       => $recentDeliveries,
                'recent_complaints'       => $recentComplaints,
            ],
        ]);
    }

    /**
     * GET /api/v1/b2b/customer/sales-orders
     */
    public function salesOrders(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);

        $query = SalesOrder::where('customer_id', $user->customer_id)
            ->with(['items.product:id,part_number,name'])
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->query('search')) {
            $query->where('so_number', 'like', "%{$search}%");
        }

        return SalesOrderResource::collection($query->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    /**
     * GET /api/v1/b2b/customer/sales-orders/{id}
     */
    public function salesOrderShow(SalesOrder $salesOrder, Request $request): SalesOrderResource
    {
        $user = $this->user($request);
        abort_if($salesOrder->customer_id !== $user->customer_id, 403);

        $salesOrder->load([
            'items.product:id,part_number,name',
            'deliveries:id,delivery_number,status,delivered_at,confirmed_at',
            'invoices:id,invoice_number,total_amount,status,created_at',
            'workOrders:id,wo_number,status',
        ]);

        return new SalesOrderResource($salesOrder);
    }

    /**
     * GET /api/v1/b2b/customer/sales-orders/{id}/chain
     * Order-to-Cash chain visualization steps.
     */
    public function salesOrderChain(SalesOrder $salesOrder, Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_if($salesOrder->customer_id !== $user->customer_id, 403);

        return response()->json([
            'data' => $this->salesOrderService->chain($salesOrder),
        ]);
    }

    /**
     * GET /api/v1/b2b/customer/invoices
     */
    public function invoices(Request $request): AnonymousResourceCollection
    {
        $user = $this->user($request);

        $query = Invoice::where('customer_id', $user->customer_id)
            ->with(['salesOrder:id,so_number'])
            ->orderByDesc('created_at');

        return InvoiceResource::collection($query->paginate(min((int) $request->query('per_page', 25), 100)));
    }

    /**
     * GET /api/v1/b2b/customer/invoices/{id}
     */
    public function invoiceDetail(Invoice $invoice, Request $request): InvoiceResource
    {
        $user = $this->user($request);
        abort_if($invoice->customer_id !== $user->customer_id, 403);

        $invoice->load(['salesOrder:id,so_number', 'items', 'payments']);

        return new InvoiceResource($invoice);
    }

    /**
     * GET /api/v1/b2b/customer/deliveries
     */
    public function deliveries(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $query = Delivery::whereHas('salesOrder', fn ($q) => $q->where('customer_id', $user->customer_id))
            ->with(['salesOrder:id,so_number', 'driver:id,name'])
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    /**
     * GET /api/v1/b2b/customer/invoices/{id}/pdf
     * Download invoice as PDF.
     */
    public function invoicePdf(Invoice $invoice, Request $request)
    {
        $user = $this->user($request);
        abort_if($invoice->customer_id !== $user->customer_id, 403);

        return $this->pdf->invoice($invoice);
    }

    /**
     * GET /api/v1/b2b/customer/deliveries/{id}
     */
    public function deliveryDetail(Delivery $delivery, Request $request): JsonResponse
    {
        $user = $this->user($request);

        // Ensure delivery belongs to this customer's sales order
        abort_if(
            !$delivery->salesOrder || $delivery->salesOrder->customer_id !== $user->customer_id,
            403
        );

        $delivery->load([
            'salesOrder:id,so_number',
            'items',
            'proofs',
            'driver:id,name',
        ]);

        return response()->json(['data' => $delivery]);
    }

    /**
     * GET /api/v1/b2b/customer/complaints
     */
    public function complaints(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $query = CustomerComplaint::where('customer_id', $user->customer_id)
            ->orderByDesc('created_at');

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    /**
     * POST /api/v1/b2b/customer/complaints
     */
    public function createComplaint(CreateComplaintRequest $request): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validated();

        $complaint = CustomerComplaint::create([
            'customer_id'       => $user->customer_id,
            'sales_order_id'    => $validated['order_id'] ?? null,
            'severity'          => $validated['severity'],
            'description'       => $validated['description'],
            'affected_quantity' => $validated['affected_quantity'],
            'status'            => 'open',
            'complaint_number'  => 'CC-' . strtoupper(uniqid()),
            'received_date'     => now(),
        ]);

        return response()->json([
            'data'    => $complaint,
            'message' => 'Complaint submitted successfully.',
        ], 201);
    }

    /**
     * GET /api/v1/b2b/customer/complaints/{complaint}/8d-report
     * View the 8D report for a resolved/closed complaint.
     */
    public function complaint8dReport(CustomerComplaint $complaint, Request $request): JsonResponse
    {
        $user = $this->user($request);
        abort_if($complaint->customer_id !== $user->customer_id, 403);

        $report = $complaint->eightDReport;

        if (! $report) {
            return response()->json(['message' => 'No 8D report available for this complaint yet.'], 404);
        }

        return response()->json([
            'data' => [
                'complaint_number' => $complaint->complaint_number,
                'complaint_status' => $complaint->status?->value ?? $complaint->status,
                'severity'         => $complaint->severity?->value ?? $complaint->severity,
                'description'      => $complaint->description,
                'report' => [
                    'id'               => $report->hash_id,
                    'd1_team'          => $report->d1_team,
                    'd2_problem'       => $report->d2_problem,
                    'd3_containment'   => $report->d3_containment,
                    'd4_root_cause'    => $report->d4_root_cause,
                    'd5_corrective_action' => $report->d5_corrective_action,
                    'd6_verification'  => $report->d6_verification,
                    'd7_prevention'    => $report->d7_prevention,
                    'd8_recognition'   => $report->d8_recognition,
                    'finalized_at'     => optional($report->finalized_at)->toIso8601String(),
                ],
            ],
        ]);
    }

    /**
     * GET /api/v1/b2b/customer/statement-of-account
     * Customer financial summary with aging buckets and open invoices.
     */
    public function statementOfAccount(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $customerId = $user->customer_id;

        $openInvoices = Invoice::where('customer_id', $customerId)
            ->whereIn('status', ['sent', 'overdue', 'partial', 'finalized'])
            ->with(['salesOrder:id,so_number'])
            ->orderBy('due_date')
            ->get();

        $agingBuckets = [
            'current'  => 0,
            'd1_30'    => 0,
            'd31_60'   => 0,
            'd61_90'   => 0,
            'd91_plus' => 0,
        ];

        $totalOutstanding = 0;

        foreach ($openInvoices as $inv) {
            $balance = (float) $inv->balance;
            $bucket = $inv->agingBucket();
            if (isset($agingBuckets[$bucket])) {
                $agingBuckets[$bucket] += $balance;
            }
            $totalOutstanding += $balance;
        }

        return response()->json([
            'data' => [
                'customer_name'    => $user->customer?->name,
                'total_outstanding'=> number_format($totalOutstanding, 2),
                'aging_buckets'    => array_map(fn ($v) => number_format($v, 2), $agingBuckets),
                'open_invoices'    => InvoiceResource::collection($openInvoices),
                'as_of_date'       => now()->toDateString(),
            ],
        ]);
    }

    /**
     * GET /api/v1/b2b/customer/delivery-schedules
     * List the customer's monthly delivery requirement submissions.
     */
    public function deliverySchedules(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $schedules = DeliverySchedule::where('customer_id', $user->customer_id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => DeliveryScheduleResource::collection($schedules),
        ]);
    }

    /**
     * POST /api/v1/b2b/customer/delivery-schedules
     * Submit monthly delivery requirements.
     */
    public function storeDeliverySchedule(CustomerStoreDeliveryScheduleRequest $request): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validated();

        $schedule = DeliverySchedule::create([
            'customer_id' => $user->customer_id,
            'month'       => $validated['month'],
            'status'      => 'submitted',
            'lines'       => $validated['lines'],
        ]);

        return response()->json([
            'data'    => new DeliveryScheduleResource($schedule),
            'message' => 'Delivery schedule submitted successfully.',
        ], 201);
    }
}
