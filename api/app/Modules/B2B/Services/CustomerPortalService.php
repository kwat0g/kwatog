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
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\ReturnManagement\Services\ReturnCaseService;
use App\Modules\ReturnManagement\Services\ReturnCaseSettlementService;
use App\Modules\ReturnManagement\Services\ReturnRequestService;
use App\Modules\SupplyChain\Enums\DeliveryStatus;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\DeliveryItem;
use App\Modules\SupplyChain\Services\DeliveryService;
use App\Modules\Production\Enums\WorkOrderStatus;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
            ->whereIn('status', [SalesOrderStatus::Draft->value, SalesOrderStatus::AwaitingConfirmation->value, SalesOrderStatus::DeliveryNegotiation->value, SalesOrderStatus::Confirmed->value])->count();

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
     * resolved from eligible products and applicable selling prices.
     *
     * @return list<array{id: string, part_number: ?string, name: ?string, unit_of_measure: ?string, unit_price: ?string, pricing_issue: ?string, pricing_method: ?string}>
     */
    public function catalog(int $customerId, ?string $asOf = null, ?string $search = null): array
    {
        $date = $asOf !== null && $asOf !== ''
            ? Carbon::parse($asOf)->toDateString()
            : now()->toDateString();

        $query = Product::query()->active()->where(function ($query) use ($customerId): void {
            $query->where('portal_visibility', 'standard')
                ->orWhereHas('portalCustomers', fn ($customers) => $customers->whereKey($customerId));
        });
        if ($search !== null && trim($search) !== '') {
            $term = '%'.trim($search).'%';
            $query->where(fn ($q) => $q->where('part_number', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }
        $catalog = [];
        foreach ($query->orderBy('part_number')->get() as $product) {
            try {
                $quote = $this->prices->quote($customerId, $product->id, Carbon::parse($date), '1');
                $pricingIssue = null;
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $message = collect($exception->errors())->flatten()->implode(' ');
                $pricingIssue = match (true) {
                    str_contains($message, 'No base selling price or applicable customer agreement') => 'No price is available for this delivery date. Contact Sales.',
                    str_contains($message, 'selling price requires review by sales') => 'This price needs Sales review before you can order it.',
                    default => null,
                };
                if ($pricingIssue === null) {
                    continue;
                }
                $quote = null;
            }
            $catalog[] = [
                'id' => $product->hash_id, 'part_number' => $product->part_number, 'name' => $product->name,
                'unit_of_measure' => $product->unit_of_measure, 'unit_price' => $quote['unit_price'] ?? null,
                'image_url' => $product->image_path ? "/api/v1/b2b/customer/catalog/{$product->hash_id}/image" : null,
                'price_source' => $quote['price_source'] ?? null, 'pricing_method' => isset($quote['price_tier_min_qty']) ? 'tiered' : ($quote ? 'flat' : null),
                'pricing_issue' => $pricingIssue,
            ];
        }

        return $catalog;
    }

    /**
     * Place a sales order on behalf of the authenticated portal customer.
     *
     * Each line uses the customer's available catalog and current pricing.
     * The order awaits Sales confirmation, with system attribution retained
     * for audit integrity. Confirmation starts MRP; submission does not.
     */
    /** @return array{order: SalesOrder, replayed: bool} */
    public function placeOrder(int $customerId, array $data, CustomerPortalUser $portalUser): array
    {
        $idempotencyKey = (string) $data['idempotency_key'];
        unset($data['idempotency_key']);

        $orderDateString = today()->toDateString();

        $items = [];
        foreach ($data['items'] as $idx => $item) {
            $productId = HashIdFilter::decode((string) $item['product_id'], Product::class);
            if ($productId === null) {
                throw new BusinessRuleException("items.{$idx}.product_id is not a valid product.");
            }

            $deliveryDate = Carbon::parse($item['delivery_date']);
            $items[] = [
                'product_id'    => $productId,
                'quantity'      => bcadd((string) $item['quantity'], '0', 2),
                'delivery_date' => $deliveryDate->toDateString(),
            ];
        }

        $fingerprintData = [
            'customer_id' => $customerId,
            'date' => $orderDateString,
            'notes' => $data['notes'] ?? null,
            'items' => $items,
        ];

        // Portal users are not internal users — impersonate a system user so
        // HasAuditLog writes a valid users.id into audit_logs.user_id and the
        // SO's created_by FK points at a real user. Submission provenance is
        // carried on the SO itself via submission_source.
        [$so, $replayed] = DB::transaction(function () use (
            $customerId,
            $orderDateString,
            $items,
            $data,
            $idempotencyKey,
            $fingerprintData,
            $portalUser,
        ): array {
            // Serialise first-order submissions per customer. The unique index
            // below is still the final arbiter; this lock also makes retries
            // return the original order rather than surfacing a unique-key 500.
            $this->prices->lockPricingReferences(array_column($items, 'product_id'), [$customerId]);
            $customer = Customer::query()->lockForUpdate()->find($customerId);
            if (! $customer || ! $customer->is_active) {
                throw new BusinessRuleException('This customer account is inactive. Contact Ogami support.');
            }

            $existing = SalesOrder::query()
                ->where('customer_id', $customerId)
                ->where('portal_idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            // A retry uses the original submission date, even after midnight.
            // The submitted date never controls a new customer order's date.
            $fingerprintData['date'] = $existing?->date->toDateString() ?? $orderDateString;
            $fingerprint = hash('sha256', json_encode($fingerprintData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            if ($existing) {
                if (! hash_equals((string) $existing->portal_idempotency_fingerprint, $fingerprint)) {
                    throw new BusinessRuleException('This idempotency key was already used for a different order payload.');
                }

                return [$this->salesOrderService->show($existing), true];
            }

            foreach ($items as $idx => $item) {
                $this->assertRequestedDeliveryDate($item['delivery_date'], $idx);

            }

            $so = $this->systemUser->impersonate(function () use ($customerId, $orderDateString, $items, $data, $idempotencyKey, $fingerprint): SalesOrder {
                return $this->salesOrderService->create([
                    'customer_id' => $customerId,
                    'date' => $orderDateString,
                    'items' => $items,
                    'notes' => $data['notes'] ?? null,
                    'submission_source' => SalesOrderSubmissionSource::CustomerPortal->value,
                    'quote_signature' => $data['quote_signature'] ?? null,
                    'portal_idempotency_key' => $idempotencyKey,
                    'portal_idempotency_fingerprint' => $fingerprint,
                ], $this->systemUser->user()->id);
            });

            // Keep external-actor attribution in the same transaction as the
            // source order so a committed order cannot lose its portal audit.
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

            return [$so, false];
        });

        if (! $replayed) {
            $this->notifySalesOrderReview($so, $portalUser);
            $this->notifications->sendPortalInApp([$portalUser], 'sales_order.submitted', [
                'title' => $so->so_number.' submitted', 'message' => 'Your order awaits Sales confirmation. You can edit or cancel it before confirmation.',
                'link_to' => '/portal/customer/orders/'.$so->hash_id, 'entity_type' => 'sales_order', 'entity_id' => $so->hash_id,
            ], 'so:'.$so->id.':submitted');
        }

        return ['order' => $so, 'replayed' => $replayed];
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
            $query->where('so_number', SearchOperator::like(), SearchOperator::contains($filters['search']));
        }

        $perPage = min((int) ($filters['per_page'] ?? 25), 100);

        $result = $query->paginate($perPage);
        app(\App\Modules\CRM\Services\SalesOrderSupplyService::class)->prepareCoverage($result->getCollection());
        return $result;
    }

    public function salesOrderDetail(int $customerId, SalesOrder $salesOrder): SalesOrder
    {
        abort_if($salesOrder->customer_id !== $customerId, 403);

        $salesOrder->load([
            'items.product:id,part_number,name',
            'latestResponse.items',
            'deliveryNegotiations',
            'deliveries:id,sales_order_id,delivery_number,status,delivered_at,confirmed_at',
            'invoices:id,sales_order_id,invoice_number,total_amount,status,created_at',
            'workOrders:id,sales_order_id,wo_number,status,quantity_target,quantity_produced,planned_start',
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

    public function quoteOrder(CustomerPortalUser $user, array $data): array
    {
        $existing = null;
        if (! empty($data['sales_order_id'])) {
            $id = SalesOrder::tryDecodeHash($data['sales_order_id']);
            $existing = SalesOrder::query()->where('customer_id', $user->customer_id)->findOrFail($id);
            if (! $existing->status->isBeforeConfirmation() || $existing->submission_source !== SalesOrderSubmissionSource::CustomerPortal) {
                throw new BusinessRuleException('Only your unconfirmed customer order can be quoted for editing.');
            }
        }
        $items = [];
        foreach ($data['items'] as $index => $item) {
            $this->assertRequestedDeliveryDate($item['delivery_date'], $index);
            $productId = Product::tryDecodeHash($item['product_id']);
            if ($productId === null) { throw new BusinessRuleException('Invalid product in quote.'); }
            $items[] = ['product_id' => $productId, 'quantity' => $item['quantity'], 'delivery_date' => $item['delivery_date']];
        }
        $quote = $this->salesOrderService->quoteOrder($user->customer_id, $items, $existing, true);
        $quote['items'] = array_map(function ($line): array {
            $line['product_id'] = app('hashids')->encode($line['product_id']);
            $line['price_agreement_id'] = $line['price_agreement_id'] ? app('hashids')->encode($line['price_agreement_id']) : null;
            unset($line['existing_id']);
            return $line;
        }, $quote['items']);
        return $quote;
    }

    private function assertRequestedDeliveryDate(string $date, int $index): void
    {
        $earliest = today()->addDays(4)->toDateString();
        if ($date < $earliest) {
            throw new BusinessRuleException("items.{$index}.delivery_date must be more than 3 days from today. Earliest date: {$earliest}.");
        }
    }

    public function updateOrder(SalesOrder $salesOrder, CustomerPortalUser $user, array $data): SalesOrder
    {
        return DB::transaction(function () use ($salesOrder, $user, $data): SalesOrder {
            $row = SalesOrder::query()->lockForUpdate()->findOrFail($salesOrder->id);
            abort_if($row->customer_id !== $user->customer_id, 403);
            $before = $this->salesOrderDetail($user->customer_id, $row)->toArray();
            $items = [];
            foreach ($data['items'] as $idx => $item) {
                $productId = HashIdFilter::decode((string) $item['product_id'], Product::class);
                if ($productId === null) {
                    throw new BusinessRuleException("items.{$idx}.product_id is not a valid product.");
                }
                $this->assertRequestedDeliveryDate($item['delivery_date'], $idx);
                $items[] = ['product_id' => $productId, 'quantity' => $item['quantity'], 'delivery_date' => $item['delivery_date']];
            }
            $updated = $this->systemUser->impersonate(fn (): SalesOrder => $this->salesOrderService->update(
                $row, ['items' => $items, 'notes' => $data['notes'] ?? null, 'quote_signature' => $data['quote_signature'] ?? null], $user,
            ));
            $this->recordCustomerAudit('customer.order.updated', $updated, $user->id, $user->customer_id, [
                'before' => $before, 'after' => $updated->toArray(),
            ]);
            $this->notifySalesOrderReview($updated, $user);
            return $updated;
        });
    }

    public function cancelOrder(SalesOrder $salesOrder, CustomerPortalUser $user, ?string $reason): SalesOrder
    {
        return DB::transaction(function () use ($salesOrder, $user, $reason): SalesOrder {
            DB::statement('SELECT pg_advisory_xact_lock(20261009, 1)');
            $row = SalesOrder::query()->lockForUpdate()->findOrFail($salesOrder->id);
            abort_if($row->customer_id !== $user->customer_id, 403);
            if ($row->submission_source !== SalesOrderSubmissionSource::CustomerPortal) {
                throw new BusinessRuleException('Only orders submitted through your customer portal can be cancelled here.');
            }

            if (in_array($row->status, [SalesOrderStatus::Confirmed, SalesOrderStatus::InProduction], true)) {
                $response = $this->salesOrderResponses->requestCancellation($row, $user, $reason);
                if ($response->wasRecentlyCreated) {
                    $this->recordCustomerAudit('customer.order.cancellation_requested', $response, $user->id, $user->customer_id, [
                        'sales_order_id' => $row->hash_id,
                        'reason' => $reason,
                    ]);
                }
                $requested = $this->salesOrderService->show($row->fresh());
                $requested->setAttribute('cancellation_outcome', 'requested');

                return $requested;
            }

            $blocker = $this->salesOrderService->cancellationBlocker($row, true);
            if ($blocker === null) {
                $cancelled = $this->systemUser->impersonate(fn (): SalesOrder => $this->salesOrderService->cancel($row, $reason, $user));
                $this->recordCustomerAudit('customer.order.cancelled', $cancelled, $user->id, $user->customer_id, ['reason' => $reason]);
                $cancelled->setAttribute('cancellation_outcome', 'cancelled');

                return $cancelled;
            }

            throw new BusinessRuleException($blocker);
        });
    }

    public function salesOrderChain(int $customerId, SalesOrder $salesOrder): array
    {
        abort_if($salesOrder->customer_id !== $customerId, 403);

        return array_map(static function (array $step): array {
            unset($step['href']);
            if (in_array($step['label'], ['Ready from stock', 'Stock + production'], true)) {
                $step['label'] = $step['state'] === 'done' ? 'Supply ready' : 'Preparing supply';
            }
            if ($step['label'] === 'QC approved stock') $step['label'] = 'Quality approved';
            $step['description'] = $step['state'] === 'rejected'
                ? 'Order preparation needs attention. Our team is resolving it; contact sales for an update.' : null;
            return $step;
        }, $this->salesOrderService->chain($salesOrder));
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
            ->whereNull('delivery_attempt_outcome_id')
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
        abort_if($returnRequest->delivery_attempt_outcome_id !== null, 404);

        $returnRequest->load([
            'items.product:id,part_number,name',
            'returnCase' => fn ($query) => $query->where('customer_id', $customerId)->select(['id', 'case_number', 'return_request_id']),
        ]);

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

        $invoice->load(['salesOrder:id,so_number', 'items', 'collections', 'paymentSubmissions']);

        return $invoice;
    }

    /* ─── Deliveries ─────────────────────────────────────────────── */

    public function deliveries(int $customerId, array $filters): LengthAwarePaginator
    {
        $query = Delivery::whereHas(
            'salesOrder',
            fn ($q) => $q->where('customer_id', $customerId),
        )->with(['salesOrder:id,so_number', 'driver:id,name', 'attemptOutcome:id,delivery_id'])
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
            'items.salesOrderItem.product:id,part_number,name,unit_of_measure',
            'proofs',
            'blockingReturnCase',
            'driver:id,name',
            'attemptOutcome:id,delivery_id',
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
     *   receiver_name: string,
     *   receiver_position?: string|null,
     *   delivery_remarks?: string|null,
     *   lines: array<array{delivery_item_id: string, received_quantity: string}>,
     * } $receiverData
     */
    public function confirmDelivery(int $customerId, Delivery $delivery, array $receiverData, CustomerPortalUser $portalUser): Delivery
    {
        return DB::transaction(function () use ($customerId, $delivery, $receiverData, $portalUser): Delivery {
            $salesOrderId = Delivery::query()->whereKey($delivery->id)->value('sales_order_id');
            $order = $salesOrderId ? SalesOrder::query()->lockForUpdate()->find($salesOrderId) : null;
            $locked = Delivery::query()->lockForUpdate()->findOrFail($delivery->id);
            abort_if(! $order || (int) $order->customer_id !== $customerId || (int) $locked->sales_order_id !== (int) $salesOrderId
                || (int) $portalUser->customer_id !== $customerId, 403);

            if ($locked->status === DeliveryStatus::Confirmed) {
                return $this->deliveryDetail($customerId, $locked);
            }

            $lines = $receiverData['lines'] ?? [];
            $this->validateReceiptLines($locked, $lines);

            $confirmed = $this->systemUser->impersonate(function () use ($locked, $receiverData): Delivery {
                return $this->deliveryService->confirm($locked, $this->systemUser->user(), $receiverData);
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
                    'lines' => $lines,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'source_command' => request()?->route()?->getName() ?? 'b2b.customer.deliveries.confirm',
                'correlation_id' => request()?->attributes->get('request_id') ?? request()?->header('X-Request-ID'),
                'created_at' => now(),
            ]);

            return $this->deliveryDetail($customerId, $confirmed);
        });
    }

    private function validateReceiptLines(Delivery $delivery, array $lines): void
    {
        $items = $delivery->items()->with('salesOrderItem.product:id,unit_of_measure')->lockForUpdate()->get()->keyBy('id');
        if (! is_array($lines) || $items->isEmpty() || count($lines) !== $items->count()) {
            throw ValidationException::withMessages(['lines' => ['Confirm the received quantity for every delivery item.']]);
        }
        $seen = [];
        foreach ($lines as $index => $line) {
            $itemId = HashIdFilter::decode($line['delivery_item_id'] ?? null, DeliveryItem::class);
            $item = $itemId ? $items->get($itemId) : null;
            if (! $item || isset($seen[$itemId])) {
                throw ValidationException::withMessages(["lines.{$index}.delivery_item_id" => ['Select each item from this delivery exactly once.']]);
            }
            $seen[$itemId] = true;
            $quantity = $line['received_quantity'] ?? null;
            if (! is_string($quantity) || ! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $quantity)) {
                throw ValidationException::withMessages(["lines.{$index}.received_quantity" => ['Enter a non-negative quantity with up to two decimal places.']]);
            }
            if (in_array(strtolower(trim((string) $item->salesOrderItem?->product?->unit_of_measure)), ['pcs', 'pieces', 'pc'], true)
                && bccomp($quantity, bcadd($quantity, '0', 0), 3) !== 0) {
                throw ValidationException::withMessages(["lines.{$index}.received_quantity" => ['Enter a whole number of pieces.']]);
            }
            if (bccomp($quantity, (string) ($item->customer_received_quantity ?? $item->quantity), 3) !== 0) {
                throw ValidationException::withMessages(["lines.{$index}.received_quantity" => ['Received quantity must match the recorded delivery quantity. Report a problem before confirming a shortage or excess.']]);
            }
        }
    }

    /** The relationship, not a matching product, determines which shipments can be reviewed together. */
    public function receiptGroup(int $customerId, Delivery $anchor): ?array
    {
        $anchor = Delivery::with('salesOrder')->findOrFail($anchor->id);
        abort_unless((int) $anchor->salesOrder?->customer_id === $customerId, 403);
        $cases = ReturnCase::query()->where('customer_id', $customerId)->where('type', 'customer')
            ->where('resolution', 'redelivery')->whereIn('status', ['in_progress', 'resolved']);
        if ($anchor->salesOrder->return_case_id) {
            $cases->whereKey($anchor->salesOrder->return_case_id)->where('replacement_sales_order_id', $anchor->sales_order_id);
        } else {
            $cases->where('delivery_id', $anchor->id)->whereNotNull('replacement_sales_order_id');
        }
        $matches = $cases->limit(2)->get();
        if ($matches->count() !== 1) {
            return null;
        }
        $case = $matches->first()->load('lines', 'replacementSalesOrder.items', 'returnRequest.items', 'delivery.salesOrder');
        $order = $case->replacementSalesOrder;
        if (! $order || (int) $order->return_case_id !== (int) $case->id || (int) $order->customer_id !== $customerId
            || ! $case->delivery || (int) $case->delivery->salesOrder?->customer_id !== $customerId) {
            return null;
        }
        $replacements = Delivery::with('salesOrder')->where('sales_order_id', $order->id)->whereIn('status', ['delivered', 'confirmed'])->orderBy('id')->get();
        if ($anchor->id !== $case->delivery_id && ! $replacements->contains('id', $anchor->id)) {
            return null;
        }
        $shipments = collect([$case->delivery])->concat($replacements)->map(fn (Delivery $delivery): array => [
            'kind' => $delivery->id === $case->delivery_id ? 'original' : 'replacement',
            'already_confirmed' => $delivery->status === DeliveryStatus::Confirmed,
            'delivery' => $this->deliveryDetail($customerId, $delivery),
        ])->all();
        $reason = $replacements->isEmpty() ? 'Replacement shipment has not arrived yet.' : null;
        if ($order->status->value === 'cancelled' || $order->items->isEmpty()
            || bccomp((string) $order->subtotal, '0', 2) !== 0 || bccomp((string) $order->vat_amount, '0', 2) !== 0
            || bccomp((string) $order->total_amount, '0', 2) !== 0
            || $order->items->contains(fn ($line): bool => bccomp((string) $line->unit_price, '0', 2) !== 0 || bccomp((string) $line->total, '0', 2) !== 0)) {
            $reason = 'The replacement order must be approved with zero prices before confirming both shipments.';
        }
        $coverage = [];
        foreach ($shipments as $shipment) {
            $delivery = $shipment['delivery'];
            if (! in_array($delivery->status, [DeliveryStatus::Delivered, DeliveryStatus::Confirmed], true)) {
                $reason ??= 'Both shipments must arrive before their receipts can be confirmed together.';
            }
            if (! $delivery->proofs->contains(fn ($proof): bool => in_array($proof->proof_type, ['photo', 'signed_dr', 'customer_po_confirmation'], true))) {
                $reason ??= 'Awaiting driver proof of delivery for '.$delivery->delivery_number.'.';
            }
            $hold = $delivery->blockingReturnCase;
            if ($hold && ($shipment['kind'] !== 'original' || (int) $hold->id !== (int) $case->id)) {
                $reason ??= 'Another problem report must be resolved before confirming both shipments.';
            }
            if ($shipment['kind'] === 'original' && ReturnCase::where('delivery_id', $delivery->id)->whereKeyNot($case->id)
                ->whereNotIn('status', ['resolved', 'withdrawn', 'rejected'])
                ->where(fn ($q) => $q->where('intake_kind', 'delivery_trace')->orWhereHas('lines', fn ($line) => $line->where('missing_quantity', '>', 0)->orWhere('defective_quantity', '>', 0)))->exists()) {
                $reason ??= 'Another problem report must be resolved before confirming both shipments.';
            }
            if ($delivery->items->isEmpty()) {
                $reason ??= 'Shipment quantities need review before confirming.';
            }
            foreach ($delivery->items as $item) {
                if ($shipment['kind'] === 'replacement') {
                    $productId = $item->salesOrderItem?->product_id;
                    $coverage[$productId] = bcadd($coverage[$productId] ?? '0', (string) ($item->customer_received_quantity ?? $item->quantity), 3);
                }
            }
        }
        foreach ($case->lines->groupBy('product_id') as $productId => $lines) {
            $required = $lines->reduce(fn ($sum, $line) => bcadd($sum, bcadd((string) ($line->verified_missing_quantity ?? '0'), (string) ($line->verified_defective_quantity ?? '0'), 3), 3), '0');
            if (bccomp($coverage[$productId] ?? '0', $required, 3) < 0 || bccomp($required, '0', 3) <= 0) {
                $reason ??= 'Replacement shipments do not yet cover all verified quantities. Confirm arrived shipments separately.';
            }
        }
        try {
            app(ReturnCaseSettlementService::class)->assertPhysicalReturnComplete($case);
        } catch (BusinessRuleException $error) {
            $reason ??= $error->getMessage();
        }
        $snapshot = [
            'case' => [$case->id, $case->status->value, $case->resolution?->value, $case->replacement_sales_order_id],
            'order' => [$order->id, $order->status->value, $order->subtotal, $order->vat_amount, $order->total_amount,
                $order->items->sortBy('id')->map(fn ($line) => [$line->id, $line->product_id, $line->quantity, $line->unit_price, $line->total])->values()->all()],
            'lines' => $case->lines->sortBy('id')->map(fn ($line) => [$line->id, $line->product_id, $line->verified_missing_quantity, $line->verified_defective_quantity])->values()->all(),
            'return' => $case->returnRequest ? [$case->returnRequest->id, $case->returnRequest->status->value,
                $case->returnRequest->items->sortBy('id')->map(fn ($line) => [$line->id, $line->source_delivery_item_id, $line->quantity, $line->returned_quantity, $line->reversal_already_applied])->values()->all()] : null,
            'shipments' => array_map(static fn ($shipment) => [
                $shipment['delivery']->id, $shipment['delivery']->sales_order_id, $shipment['delivery']->status->value,
                $shipment['delivery']->items->sortBy('id')->map(fn ($line) => [$line->id, $line->sales_order_item_id, $line->quantity, $line->customer_received_quantity])->values()->all(),
                $shipment['delivery']->proofs->sortBy('id')->map(fn ($proof) => [$proof->id, $proof->proof_type])->values()->all(),
            ], $shipments),
        ];
        return [
            'case' => $case, 'version' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'can_confirm' => $reason === null && collect($shipments)->contains(fn ($shipment) => ! $shipment['already_confirmed']),
            'blocked_reason' => $reason, 'shipments' => $shipments,
        ];
    }

    public function confirmReceiptGroup(int $customerId, Delivery $anchor, array $data, CustomerPortalUser $portalUser): array
    {
        abort_unless((int) $portalUser->customer_id === $customerId, 403);
        return DB::transaction(function () use ($customerId, $anchor, $data, $portalUser): array {
            $initial = $this->receiptGroup($customerId, $anchor);
            if (! $initial) {
                throw ValidationException::withMessages(['shipments' => 'These shipments cannot be confirmed together.']);
            }
            $orderIds = collect($initial['shipments'])->pluck('delivery.sales_order_id')->unique()->sort()->values();
            SalesOrder::whereIn('id', $orderIds)->orderBy('id')->lockForUpdate()->get();
            // Holding both orders prevents shipment membership from changing during the receipt.
            $deliveryIds = collect($initial['shipments'])->pluck('delivery.id')->sort()->values();
            Delivery::whereIn('id', $deliveryIds)->orderBy('id')->lockForUpdate()->get();
            DeliveryItem::whereIn('delivery_id', $deliveryIds)->orderBy('id')->lockForUpdate()->get();
            ReturnCase::whereKey($initial['case']->id)->lockForUpdate()->firstOrFail();
            $group = $this->receiptGroup($customerId, $anchor);
            if (! $group || $group['version'] !== $initial['version']) {
                throw ValidationException::withMessages(['version' => 'Shipment details changed. Review the refreshed quantities before confirming.']);
            }
            $expected = collect($group['shipments'])->keyBy(fn ($shipment) => $shipment['delivery']->id);
            $payloads = [];
            foreach ($data['shipments'] as $index => $shipment) {
                $id = HashIdFilter::decode($shipment['delivery_id'], Delivery::class);
                if (! $id || ! $expected->has($id) || isset($payloads[$id])) {
                    throw ValidationException::withMessages(["shipments.{$index}.delivery_id" => 'Select each reviewed shipment exactly once.']);
                }
                try {
                    $this->validateReceiptLines($expected[$id]['delivery'], $shipment['lines']);
                } catch (ValidationException $error) {
                    $errors = [];
                    foreach ($error->errors() as $field => $messages) {
                        $errors["shipments.{$index}.{$field}"] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                }
                $payloads[$id] = $shipment;
            }
            if (count($payloads) !== $expected->count()) {
                throw ValidationException::withMessages(['shipments' => 'Review every shipment shown before confirming.']);
            }
            if (! $group['blocked_reason'] && $expected->every(fn ($shipment) => $shipment['already_confirmed'])) {
                return $group;
            }
            if (! hash_equals($group['version'], $data['version'])) {
                throw ValidationException::withMessages(['version' => 'Shipment details changed. Review the refreshed quantities before confirming.']);
            }
            if (! $group['can_confirm']) {
                throw ValidationException::withMessages(['shipments' => $group['blocked_reason'] ?? 'These receipts are already confirmed.']);
            }
            $receiver = array_intersect_key($data, array_flip(['receiver_name', 'receiver_position', 'delivery_remarks']));
            foreach ($group['shipments'] as $shipment) {
                if ($shipment['kind'] === 'replacement' && ! $shipment['already_confirmed']) {
                    $delivery = $shipment['delivery'];
                    $this->confirmDelivery($customerId, $delivery, $receiver + ['lines' => $payloads[$delivery->id]['lines']], $portalUser);
                }
            }
            $case = $group['case'];
            app(ReturnCaseService::class)->synchronizeReplacement((int) $case->id);
            if ($case->fresh()->status->value !== 'resolved') {
                throw new BusinessRuleException('Replacement work is not complete. Review the problem report before confirming both shipments.');
            }
            $original = $group['shipments'][0]['delivery'];
            if ($original->status !== DeliveryStatus::Confirmed) {
                $this->confirmDelivery($customerId, $original, $receiver + ['lines' => $payloads[$original->id]['lines']], $portalUser);
            }
            return $this->receiptGroup($customerId, $anchor);
        }, 3);
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
