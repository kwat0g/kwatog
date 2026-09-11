<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\AuditLog;
use App\Common\Services\NotificationService;
use App\Common\Support\HashIdFilter;
use App\Common\Support\Money;
use App\Common\Support\SearchOperator;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Services\StatementOfAccountService;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Enums\ComplaintStatus;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Enums\SalesOrderSubmissionSource;
use App\Modules\CRM\Exceptions\NoPriceAgreementException;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesOrderResponse;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Services\ComplaintService;
use App\Modules\CRM\Services\PriceAgreementService;
use App\Modules\CRM\Services\SalesOrderResponseService;
use App\Modules\CRM\Services\SalesOrderService;
use App\Modules\Quality\Enums\NcrStatus;
use App\Common\Services\SystemUserResolver;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Services\DeliveryService;
use App\Modules\Production\Enums\WorkOrderStatus;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Business logic for the Customer B2B Portal.
 *
 * Every method receives the owning customer_id as the first argument so that
 * row-level scoping is guaranteed — the controller resolves the authenticated
 * portal user and passes `$user->customer_id`. Scoping is always explicit;
 * complaint creation performs one separate guard lookup only to attach
 * durable external-actor audit metadata.
 */
class CustomerPortalService
{
    public function __construct(
        private readonly SalesOrderService $salesOrderService,
        private readonly SalesOrderResponseService $salesOrderResponses,
        private readonly PriceAgreementService $prices,
        private readonly ComplaintService $complaintService,
        private readonly StatementOfAccountService $soa,
        private readonly SystemUserResolver $systemUser,
        private readonly NotificationService $notifications,
        private readonly DeliveryService $deliveryService,
        private readonly ReturnRequestService $returnRequests,
    ) {}

    /* ─── Dashboard ──────────────────────────────────────────────── */

    public function dashboard(int $customerId): array
    {
        $openSoCount = SalesOrder::where('customer_id', $customerId)
            ->whereIn('status', [SalesOrderStatus::Draft->value, SalesOrderStatus::Confirmed->value])->count();

        $pendingDeliveryCount = Delivery::whereHas(
            'salesOrder',
            fn ($q) => $q->where('customer_id', $customerId),
        )->whereIn('status', [
            DeliveryStatus::Scheduled->value,
            DeliveryStatus::Loading->value,
            DeliveryStatus::InTransit->value,
        ])->count();

        $openInvoices = Invoice::where('customer_id', $customerId)
            ->whereIn('status', [InvoiceStatus::Finalized, InvoiceStatus::Partial]);
        $openInvoiceCount = (clone $openInvoices)->count();

        $totalOutstanding = (clone $openInvoices)
            ->pluck('balance')
            ->reduce(
                static fn (string $total, mixed $balance): string => Money::add($total, (string) $balance),
                Money::zero(),
            );

        $recentOrders = SalesOrder::where('customer_id', $customerId)
            ->withCount('items')
            ->orderByDesc('created_at')->limit(5)->get();

        $recentInvoices = Invoice::where('customer_id', $customerId)
            ->whereIn('status', [InvoiceStatus::Finalized, InvoiceStatus::Partial, InvoiceStatus::Paid])
            ->orderByDesc('created_at')->limit(5)->get();

        $recentDeliveries = Delivery::whereHas(
            'salesOrder',
            fn ($q) => $q->where('customer_id', $customerId),
        )->orderByDesc('created_at')->limit(5)->get();

        $recentComplaints = CustomerComplaint::where('customer_id', $customerId)
            ->orderByDesc('created_at')->limit(5)->get();

        return [
            'open_so_count' => $openSoCount,
            'pending_delivery_count' => $pendingDeliveryCount,
            'open_invoice_count' => $openInvoiceCount,
            'total_outstanding' => Money::add((string) $totalOutstanding),
            'recent_orders' => $recentOrders,
            'recent_invoices' => $recentInvoices,
            'recent_deliveries' => $recentDeliveries,
            'recent_complaints' => $recentComplaints,
        ];
    }

    /* ─── Sales Orders ───────────────────────────────────────────── */

    /**
     * Self-service order catalog — every product this customer may order,
     * resolved from their active price agreements.
     *
     * @return list<array{id: string, part_number: ?string, name: ?string, unit_of_measure: ?string, unit_price: string, pricing_method: string}>
     */
    public function catalog(int $customerId, ?string $asOf = null, ?string $search = null): array
    {
        $date = $asOf !== null && $asOf !== ''
            ? Carbon::parse($asOf)->toDateString()
            : now()->toDateString();

        $query = PriceAgreement::query()
            ->with('product:id,part_number,name,unit_of_measure')
            ->where('customer_id', $customerId)
            ->whereHas('customer', fn ($q) => $q->active())
            ->whereHas('product', fn ($q) => $q->active())
            ->whereDate('effective_from', '<=', $date)
            ->whereDate('effective_to', '>=', $date)
            ->orderByDesc('effective_from');

        if ($search !== null && trim($search) !== '') {
            $term = '%'.trim($search).'%';
            $query->whereHas('product', function ($q) use ($term): void {
                $q->where('part_number', 'ilike', $term)
                    ->orWhere('name', 'ilike', $term);
            });
        }

        $catalog = [];
        $seenProductIds = [];
        foreach ($query->get() as $agreement) {
            $product = $agreement->product;
            if (! $product || isset($seenProductIds[$product->id])) {
                continue;
            }
            $seenProductIds[$product->id] = true;

            $catalog[] = [
                'id'              => $product->hash_id,
                'part_number'     => $product->part_number,
                'name'            => $product->name,
                'unit_of_measure' => $product->unit_of_measure,
                'unit_price'      => $this->prices->resolveUnitPrice($agreement, '1'),
                'pricing_method'  => $agreement->pricing_method?->value ?? 'flat',
            ];
        }

        return $catalog;
    }

    /**
     * Place a sales order on behalf of the authenticated portal customer.
     *
     * Every line is gated against the customer's own active price agreements
     * (same source as catalog()), so a portal user can never order a product
     * they have no price for — or, by construction, another customer's
     * product. The SO is created as a Draft, owned by the system user for
     * audit-row integrity, and internal sales is notified to review + confirm
     * (confirmation is what triggers MRP).
     */
    public function placeOrder(int $customerId, array $data, CustomerPortalUser $portalUser): SalesOrder
    {
        $orderDate = ($data['date'] ?? null) !== null && ($data['date'] ?? '') !== ''
            ? Carbon::parse($data['date'])
            : now();
        $orderDateString = $orderDate->toDateString();

        $items = [];
        foreach ($data['items'] as $idx => $item) {
            $productId = HashIdFilter::decode((string) $item['product_id'], Product::class);
            if ($productId === null) {
                throw new BusinessRuleException("items.{$idx}.product_id is not a valid product.");
            }

            $deliveryDate = Carbon::parse($item['delivery_date']);
            if ($deliveryDate->lt($orderDate)) {
                throw new BusinessRuleException("items.{$idx}.delivery_date must be on or after the order date.");
            }

            try {
                $this->prices->resolve($customerId, $productId, $deliveryDate);
            } catch (NoPriceAgreementException) {
                throw new BusinessRuleException(
                    "items.{$idx}.product_id is not available for ordering on the requested delivery date.",
                );
            }

            $items[] = [
                'product_id'    => $productId,
                'quantity'      => (string) $item['quantity'],
                'delivery_date' => $deliveryDate->toDateString(),
            ];
        }

        // Portal users are not internal users — impersonate a system user so
        // HasAuditLog writes a valid users.id into audit_logs.user_id and the
        // SO's created_by FK points at a real user. Submission provenance is
        // carried on the SO itself via submission_source.
        $so = $this->systemUser->impersonate(function () use ($customerId, $orderDateString, $items, $data): SalesOrder {
            return $this->salesOrderService->create([
                'customer_id'       => $customerId,
                'date'              => $orderDateString,
                'items'             => $items,
                'notes'             => $data['notes'] ?? null,
                'submission_source' => SalesOrderSubmissionSource::CustomerPortal->value,
            ], $this->systemUser->user()->id);
        });

        // Append-only external-actor audit event — attributable without
        // weakening the internal-user FK on sales_orders.created_by.
        AuditLog::create([
            'user_id' => null,
            'actor_type' => 'customer_portal',
            'action' => 'customer.order.placed',
            'model_type' => SalesOrder::class,
            'model_id' => $so->getKey(),
            'old_values' => null,
            'new_values' => [
                'portal_user_id' => $portalUser->hash_id,
                'email' => $portalUser->email,
                'customer_id' => app('hashids')->encode($customerId),
                'submission_source' => SalesOrderSubmissionSource::CustomerPortal->value,
            ],
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'source_command' => request()?->route()?->getName() ?? 'b2b.customer.orders.store',
            'correlation_id' => request()?->attributes->get('request_id') ?? request()?->header('X-Request-ID'),
            'created_at' => now(),
        ]);

        $this->notifySalesOrderReview($so, $portalUser);

        return $so;
    }

    /**
     * Notify the internal users who can confirm sales orders that a portal
     * submission is waiting for review. Best-effort — never blocks placement.
     */
    private function notifySalesOrderReview(SalesOrder $so, CustomerPortalUser $portalUser): void
    {
        try {
            $audience = \App\Modules\Auth\Models\User::query()
                ->whereHas('role.permissions', fn ($q) => $q->where('slug', 'crm.sales_orders.confirm'))
                ->where('is_active', true)
                ->get();

            if ($audience->isEmpty()) {
                return;
            }

            $this->notifications->send($audience, 'portal.order_awaiting_review', [
                'title'       => "Portal order {$so->so_number} awaits review",
                'message'     => "{$portalUser->name} placed a sales order for {$so->customer?->name} via the customer portal. Review and confirm it to start MRP planning.",
                'link_to'     => "/crm/sales-orders/{$so->hash_id}",
                'entity_type' => 'sales_order',
                'entity_id'   => $so->hash_id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Portal order review notification failed', [
                'sales_order_id' => $so->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function salesOrders(int $customerId, array $filters): LengthAwarePaginator
    {
        $query = SalesOrder::where('customer_id', $customerId)
            ->with(['items.product:id,part_number,name', 'latestResponse.items'])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['search'])) {
            $query->where('so_number', 'like', "%{$filters['search']}%");
        }

        $perPage = min((int) ($filters['per_page'] ?? 25), 100);

        return $query->paginate($perPage);
    }

    public function salesOrderDetail(int $customerId, SalesOrder $salesOrder): SalesOrder
    {
        abort_if($salesOrder->customer_id !== $customerId, 403);

        $salesOrder->load([
            'items.product:id,part_number,name',
            'latestResponse.items',
            'deliveries:id,sales_order_id,delivery_number,status,delivered_at,confirmed_at',
            'invoices:id,sales_order_id,invoice_number,total_amount,status,created_at',
            'workOrders:id,sales_order_id,wo_number,status',
        ]);

        $salesOrder->workOrders->each(function ($workOrder): void {
            // WorkOrder casts `status` to the WorkOrderStatus enum, so the
            // attribute is already an enum instance. Casting it to string to
            // feed tryFrom() raised "Object of class WorkOrderStatus could not
            // be converted to string" and turned this whole portal endpoint
            // into a 500. Accept either shape.
            $status = $workOrder->status;
            $workOrder->setAttribute(
                'status_label',
                $status instanceof WorkOrderStatus
                    ? $status->label()
                    : (WorkOrderStatus::tryFrom((string) $status)?->label() ?? (string) $status),
            );
        });

        return $salesOrder;
    }

    public function salesOrderChain(int $customerId, SalesOrder $salesOrder): array
    {
        abort_if($salesOrder->customer_id !== $customerId, 403);

        return $this->salesOrderService->chain($salesOrder);
    }

    /**
     * Accept / propose changes to / decline a sales order the sales team has
     * released for customer review. The order stays a draft; internal sales
     * resolves any counter-offer and confirms it separately.
     *
     * @param  array<string, mixed>  $data
     */
    public function respondToSalesOrder(
        int $customerId,
        int $portalUserId,
        SalesOrder $salesOrder,
        array $data,
    ): SalesOrderResponse {
        abort_if($salesOrder->customer_id !== $customerId, 403);

        $response = $this->systemUser->impersonate(fn (): SalesOrderResponse => $this->salesOrderResponses->respond(
            $salesOrder,
            $customerId,
            $portalUserId,
            $data,
        ));

        $this->recordCustomerAudit('customer.order.responded', $response, $portalUserId, $customerId, [
            'sales_order_id' => $salesOrder->hash_id,
            'response_type'  => $data['type'] ?? null,
        ]);

        return $response->load('items');
    }

    /* ─── Returns (RMA) ──────────────────────────────────────────── */

    /**
     * Customer-scoped returnable source lines for the portal RMA form. Each
     * product line carries a server-resolved finished-goods item id.
     *
     * @return array{customer: array{invoices: mixed, salesOrders: mixed, deliveries: mixed}}
     */
    public function returnSourceOptions(int $customerId, array $filters = []): array
    {
        return $this->returnRequests->sourceOptionsForCustomer($customerId, $filters);
    }

    /**
     * Create a draft customer-return RMA on behalf of the authenticated portal
     * customer. The RMA stays a draft; the internal ReturnRequestService
     * approval flow is the finance/ops gate.
     *
     * @param  array<string, mixed>  $data
     */
    public function createReturn(int $customerId, array $data, CustomerPortalUser $portalUser): ReturnRequest
    {
        $rma = $this->systemUser->impersonate(fn (): ReturnRequest => $this->returnRequests->createCustomerReturnFromPortal(
            $customerId,
            $data,
            $this->systemUser->user(),
        ));

        $this->recordCustomerAudit('customer.return_request.created', $rma, $portalUser->id, $customerId, [
            'rma_number' => $rma->rma_number,
        ]);

        return $rma;
    }

    public function returnRequests(int $customerId, array $filters = []): LengthAwarePaginator
    {
        return ReturnRequest::query()
            ->where('customer_id', $customerId)
            ->where('type', \App\Modules\ReturnManagement\Enums\ReturnRequestType::CustomerReturn->value)
            ->withCount('items')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
    }

    public function returnRequestDetail(int $customerId, ReturnRequest $returnRequest): ReturnRequest
    {
        // Route-model binding runs before the tenancy scope is registered, so
        // it must not be trusted on its own. This is the authoritative check.
        abort_if((int) $returnRequest->customer_id !== $customerId, 403, 'You do not have access to this return request.');

        $returnRequest->load(['items.product:id,part_number,name']);

        return $returnRequest;
    }

    /**
     * Append-only external-actor audit event. Keeps portal actions attributable
     * without weakening the internal-user FK contract on the owning rows.
     *
     * @param  array<string, mixed>  $extra
     */
    private function recordCustomerAudit(
        string $action,
        object $model,
        ?int $portalUserId,
        int $customerId,
        array $extra = [],
    ): void {
        AuditLog::create([
            'user_id' => null,
            'actor_type' => 'customer_portal',
            'action' => $action,
            'model_type' => $model::class,
            'model_id' => $model->getKey(),
            'old_values' => null,
            'new_values' => array_merge([
                'portal_user_id' => $portalUserId !== null ? app('hashids')->encode($portalUserId) : null,
                'customer_id' => app('hashids')->encode($customerId),
            ], $extra),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'source_command' => request()?->route()?->getName() ?? $action,
            'correlation_id' => request()?->attributes->get('request_id') ?? request()?->header('X-Request-ID'),
            'created_at' => now(),
        ]);
    }

    /* ─── Invoices ───────────────────────────────────────────────── */

    public function invoices(int $customerId, array $filters): LengthAwarePaginator
    {
        $query = Invoice::where('customer_id', $customerId)
            ->whereIn('status', [InvoiceStatus::Finalized, InvoiceStatus::Partial, InvoiceStatus::Paid])
            ->with(['salesOrder:id,so_number'])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $perPage = min((int) ($filters['per_page'] ?? 25), 100);

        return $query->paginate($perPage);
    }

    public function invoiceDetail(int $customerId, Invoice $invoice): Invoice
    {
        abort_if($invoice->customer_id !== $customerId, 403);
        abort_if(! in_array($invoice->status, [InvoiceStatus::Finalized, InvoiceStatus::Partial, InvoiceStatus::Paid], true), 404);

        $invoice->load(['salesOrder:id,so_number', 'items', 'collections']);

        return $invoice;
    }

    /* ─── Deliveries ─────────────────────────────────────────────── */

    public function deliveries(int $customerId, array $filters): LengthAwarePaginator
    {
        $query = Delivery::whereHas(
            'salesOrder',
            fn ($q) => $q->where('customer_id', $customerId),
        )->with(['salesOrder:id,so_number', 'driver:id,name'])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function deliveryDetail(int $customerId, Delivery $delivery): Delivery
    {
        abort_if(
            ! $delivery->salesOrder || $delivery->salesOrder->customer_id !== $customerId,
            403,
        );

        $delivery->load([
            'salesOrder:id,so_number',
            'items.salesOrderItem.product:id,part_number,name',
            'proofs',
            'driver:id,name',
        ]);

        return $delivery;
    }

    /**
     * Confirm receipt of one of the authenticated customer's own delivered
     * deliveries. Reuses the internal DeliveryService::confirm() flow so the SO
     * reconciliation, CoC attach, draft-invoice handoff and outbox events stay
     * identical; it is idempotent with the internal confirm (already-confirmed
     * is a no-op).
     *
     * @param array{
     *   receiver_name?: string|null,
     *   receiver_position?: string|null,
     *   delivery_remarks?: string|null,
     * } $receiverData
     */
    public function confirmDelivery(int $customerId, Delivery $delivery, array $receiverData, CustomerPortalUser $portalUser): Delivery
    {
        abort_if(! $delivery->salesOrder || $delivery->salesOrder->customer_id !== $customerId, 403);

        $confirmed = $this->systemUser->impersonate(function () use ($delivery, $receiverData): Delivery {
            return $this->deliveryService->confirm($delivery, $this->systemUser->user(), $receiverData);
        });

        AuditLog::create([
            'user_id' => null,
            'actor_type' => 'customer_portal',
            'action' => 'customer.delivery.confirmed',
            'model_type' => Delivery::class,
            'model_id' => $delivery->getKey(),
            'old_values' => null,
            'new_values' => [
                'portal_user_id' => $portalUser->hash_id,
                'email' => $portalUser->email,
                'customer_id' => app('hashids')->encode($customerId),
                'delivery_number' => $delivery->delivery_number,
            ],
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'source_command' => request()?->route()?->getName() ?? 'b2b.customer.deliveries.confirm',
            'correlation_id' => request()?->attributes->get('request_id') ?? request()?->header('X-Request-ID'),
            'created_at' => now(),
        ]);

        return $confirmed;
    }

    /* ─── Complaints ─────────────────────────────────────────────── */

    public function complaints(int $customerId, array $filters = []): LengthAwarePaginator
    {
        $query = CustomerComplaint::query()
            ->where('customer_id', $customerId);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('received_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('received_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.trim((string) $filters['search']).'%';
            $query->where(fn ($q) => $q
                ->where('complaint_number', SearchOperator::like(), $term)
                ->orWhere('description', SearchOperator::like(), $term));
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function createComplaint(int $customerId, array $data): CustomerComplaint
    {
        $salesOrderId = null;
        if (($data['order_id'] ?? null) !== null && ($data['order_id'] ?? '') !== '') {
            $salesOrderId = HashIdFilter::decode($data['order_id'], SalesOrder::class);

            $belongsToCustomer = $salesOrderId !== null
                && SalesOrder::query()
                    ->whereKey($salesOrderId)
                    ->where('customer_id', $customerId)
                    ->exists();

            if (! $belongsToCustomer) {
                throw new BusinessRuleException('The selected order does not belong to this customer.');
            }
        }

        // Portal users are not internal users — impersonate a system user so
        // HasAuditLog writes a valid users.id into audit_logs.user_id. Route
        // the write through CRM so the 8D record and durable NCR handoff are
        // identical to the internal complaint workflow.
        $portalUser = auth('customer_portal')->user();
        $complaint = $this->systemUser->impersonate(function () use ($customerId, $data, $salesOrderId) {
            return $this->complaintService->create([
                'customer_id' => $customerId,
                'sales_order_id' => $salesOrderId,
                'received_date' => now()->toDateString(),
                'severity' => $data['severity'],
                'description' => $data['description'],
                'affected_quantity' => $data['affected_quantity'],
            ], $this->systemUser->user());
        });

        // CRM's created_by is intentionally an internal-user FK. Add a second
        // append-only audit event carrying the external actor so the complaint
        // remains attributable without weakening that FK contract.
        AuditLog::create([
            'user_id' => null,
            'actor_type' => 'customer_portal',
            'action' => 'customer.complaint.submitted',
            'model_type' => CustomerComplaint::class,
            'model_id' => $complaint->getKey(),
            'old_values' => null,
            'new_values' => [
                'portal_user_id' => $portalUser?->hash_id,
                'email' => $portalUser?->email,
                'customer_id' => app('hashids')->encode($customerId),
            ],
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'source_command' => request()?->route()?->getName() ?? 'b2b.customer.complaints.create',
            'correlation_id' => request()?->attributes->get('request_id') ?? request()?->header('X-Request-ID'),
            'created_at' => now(),
        ]);

        return $complaint;
    }

    public function complaint8dReport(int $customerId, CustomerComplaint $complaint): ?array
    {
        abort_if($complaint->customer_id !== $customerId, 403);

        $report = $complaint->eightDReport;

        if (! $report) {
            return null;
        }

        $status = $complaint->status instanceof ComplaintStatus
            ? $complaint->status
            : ComplaintStatus::tryFrom((string) $complaint->status);
        $ncr = $complaint->ncr;
        if (! $report->finalized_at
            || ! in_array($status, [ComplaintStatus::Resolved, ComplaintStatus::Closed], true)
            || ! $ncr
            || $ncr->status !== NcrStatus::Closed
            || $ncr->disposition === null) {
            return null;
        }

        $statusValue = $status?->value ?? (string) $complaint->status;
        $severityValue = $complaint->severity instanceof \BackedEnum
            ? $complaint->severity->value
            : (string) $complaint->severity;

        return [
            'complaint_number' => $complaint->complaint_number,
            'complaint_status' => $statusValue,
            'complaint_status_label' => Str::headline($statusValue),
            'severity' => $severityValue,
            'severity_label' => Str::headline($severityValue),
            'description' => $complaint->description,
            'report' => [
                'id' => $report->hash_id,
                'd1_team' => $report->d1_team,
                'd2_problem' => $report->d2_problem,
                'd3_containment' => $report->d3_containment,
                'd4_root_cause' => $report->d4_root_cause,
                'd5_corrective_action' => $report->d5_corrective_action,
                'd6_verification' => $report->d6_verification,
                'd7_prevention' => $report->d7_prevention,
                'd8_recognition' => $report->d8_recognition,
                'finalized_at' => optional($report->finalized_at)->toIso8601String(),
            ],
        ];
    }

    /* ─── Statement of Account ───────────────────────────────────── */

    public function statementOfAccount(Customer $customer, ?string $asOf = null): array
    {
        return $this->soa->forCustomer($customer, $asOf);
    }

    /* ─── Delivery Schedules ─────────────────────────────────────── */

    public function deliverySchedules(int $customerId, array $filters = []): LengthAwarePaginator
    {
        return DeliverySchedule::where('customer_id', $customerId)
            ->orderByDesc('created_at')
            ->paginate(min((int) ($filters['per_page'] ?? 25), 100));
    }

    public function storeDeliverySchedule(int $customerId, array $data): DeliverySchedule
    {
        // Idempotent — a portal double-click or a retried request must not
        // stack a second schedule for the same customer + month. Lock the
        // existing submission first and return it; the partial unique index
        // `delivery_schedules_customer_month_unique` backs this guard at the
        // DB level.
        try {
            $schedule = DB::transaction(function () use ($customerId, $data): DeliverySchedule {
                $existing = DeliverySchedule::query()
                    ->where('customer_id', $customerId)
                    ->where('month', $data['month'])
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if ($this->scheduleFingerprint($existing->lines ?? []) !== $this->scheduleFingerprint($data['lines'])) {
                        throw new BusinessRuleException('A delivery schedule already exists for this customer and month with a different payload.');
                    }

                    return $existing;
                }

                return DeliverySchedule::create([
                    'customer_id' => $customerId,
                    'month' => $data['month'],
                    'status' => 'submitted',
                    'lines' => $data['lines'],
                ]);
            });
        } catch (QueryException $e) {
            // Two first submissions can both miss the row lock. The partial
            // unique index is the final arbiter; turn its race into the same
            // deterministic replay/conflict contract instead of a raw 500.
            if (! str_contains($e->getMessage(), 'delivery_schedules_customer_month_unique')) {
                throw $e;
            }

            $schedule = DeliverySchedule::query()
                ->where('customer_id', $customerId)
                ->where('month', $data['month'])
                ->firstOrFail();

            if ($this->scheduleFingerprint($schedule->lines ?? []) !== $this->scheduleFingerprint($data['lines'])) {
                throw new BusinessRuleException('A delivery schedule already exists for this customer and month with a different payload.');
            }
        }

        return $schedule;
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function scheduleFingerprint(array $lines): string
    {
        return hash('sha256', json_encode($lines, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
