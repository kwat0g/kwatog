<?php

declare(strict_types=1);

namespace App\Modules\B2B\Services;

use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Services\StatementOfAccountService;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Services\SalesOrderService;
use App\Modules\Edge\Services\EdgeSystemUserResolver;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Business logic for the Customer B2B Portal.
 *
 * Every method receives the owning customer_id as the first argument so that
 * row-level scoping is guaranteed — the controller resolves the authenticated
 * portal user and passes `$user->customer_id`. This service NEVER reads the
 * auth guard directly; scoping is always explicit.
 */
class CustomerPortalService
{
    public function __construct(
        private readonly SalesOrderService $salesOrderService,
        private readonly StatementOfAccountService $soa,
        private readonly EdgeSystemUserResolver $systemUser,
    ) {}

    /* ─── Dashboard ──────────────────────────────────────────────── */

    public function dashboard(int $customerId): array
    {
        $openSoCount = SalesOrder::where('customer_id', $customerId)
            ->whereIn('status', ['draft', 'confirmed'])->count();

        $pendingDeliveryCount = Delivery::whereHas(
            'salesOrder',
            fn ($q) => $q->where('customer_id', $customerId),
        )->whereIn('status', ['scheduled', 'loading', 'in_transit'])->count();

        $openInvoiceCount = Invoice::where('customer_id', $customerId)
            ->whereIn('status', ['sent', 'overdue', 'partial'])->count();

        $totalOutstanding = Invoice::where('customer_id', $customerId)
            ->whereIn('status', ['sent', 'overdue', 'partial'])->sum('balance');

        $recentOrders = SalesOrder::where('customer_id', $customerId)
            ->withCount('items')
            ->orderByDesc('created_at')->limit(5)->get();

        $recentInvoices = Invoice::where('customer_id', $customerId)
            ->orderByDesc('created_at')->limit(5)->get();

        $recentDeliveries = Delivery::whereHas(
            'salesOrder',
            fn ($q) => $q->where('customer_id', $customerId),
        )->orderByDesc('created_at')->limit(5)->get();

        $recentComplaints = CustomerComplaint::where('customer_id', $customerId)
            ->orderByDesc('created_at')->limit(5)->get();

        return [
            'open_so_count'          => $openSoCount,
            'pending_delivery_count' => $pendingDeliveryCount,
            'open_invoice_count'     => $openInvoiceCount,
            'total_outstanding'      => number_format((float) $totalOutstanding, 2),
            'recent_orders'          => $recentOrders,
            'recent_invoices'        => $recentInvoices,
            'recent_deliveries'      => $recentDeliveries,
            'recent_complaints'      => $recentComplaints,
        ];
    }

    /* ─── Sales Orders ───────────────────────────────────────────── */

    public function salesOrders(int $customerId, array $filters): LengthAwarePaginator
    {
        $query = SalesOrder::where('customer_id', $customerId)
            ->with(['items.product:id,part_number,name'])
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
            'deliveries:id,delivery_number,status,delivered_at,confirmed_at',
            'invoices:id,invoice_number,total_amount,status,created_at',
            'workOrders:id,wo_number,status',
        ]);

        return $salesOrder;
    }

    public function salesOrderChain(int $customerId, SalesOrder $salesOrder): array
    {
        abort_if($salesOrder->customer_id !== $customerId, 403);

        return $this->salesOrderService->chain($salesOrder);
    }

    /* ─── Invoices ───────────────────────────────────────────────── */

    public function invoices(int $customerId, array $filters): LengthAwarePaginator
    {
        $query = Invoice::where('customer_id', $customerId)
            ->with(['salesOrder:id,so_number'])
            ->orderByDesc('created_at');

        $perPage = min((int) ($filters['per_page'] ?? 25), 100);

        return $query->paginate($perPage);
    }

    public function invoiceDetail(int $customerId, Invoice $invoice): Invoice
    {
        abort_if($invoice->customer_id !== $customerId, 403);

        $invoice->load(['salesOrder:id,so_number', 'items', 'payments']);

        return $invoice;
    }

    /* ─── Deliveries ─────────────────────────────────────────────── */

    public function deliveries(int $customerId, array $filters): Collection
    {
        $query = Delivery::whereHas(
            'salesOrder',
            fn ($q) => $q->where('customer_id', $customerId),
        )->with(['salesOrder:id,so_number', 'driver:id,name'])
            ->orderByDesc('created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->get();
    }

    public function deliveryDetail(int $customerId, Delivery $delivery): Delivery
    {
        abort_if(
            ! $delivery->salesOrder || $delivery->salesOrder->customer_id !== $customerId,
            403,
        );

        $delivery->load([
            'salesOrder:id,so_number',
            'items',
            'proofs',
            'driver:id,name',
        ]);

        return $delivery;
    }

    /* ─── Complaints ─────────────────────────────────────────────── */

    public function complaints(int $customerId): Collection
    {
        return CustomerComplaint::where('customer_id', $customerId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function createComplaint(int $customerId, array $data): CustomerComplaint
    {
        // Portal users are not internal users — impersonate a system user so
        // HasAuditLog writes a valid users.id into audit_logs.user_id.
        return $this->systemUser->impersonate(function () use ($customerId, $data) {
            return CustomerComplaint::create([
                'customer_id'       => $customerId,
                'sales_order_id'    => $data['order_id'] ?? null,
                'severity'          => $data['severity'],
                'description'       => $data['description'],
                'affected_quantity' => $data['affected_quantity'],
                'status'            => 'open',
                'complaint_number'  => 'CC-' . strtoupper(uniqid()),
                'received_date'     => now(),
                'created_by'        => $this->systemUser->id(),
            ]);
        });
    }

    public function complaint8dReport(int $customerId, CustomerComplaint $complaint): ?array
    {
        abort_if($complaint->customer_id !== $customerId, 403);

        $report = $complaint->eightDReport;

        if (! $report) {
            return null;
        }

        return [
            'complaint_number' => $complaint->complaint_number,
            'complaint_status' => $complaint->status?->value ?? $complaint->status,
            'severity'         => $complaint->severity?->value ?? $complaint->severity,
            'description'      => $complaint->description,
            'report' => [
                'id'                   => $report->hash_id,
                'd1_team'              => $report->d1_team,
                'd2_problem'           => $report->d2_problem,
                'd3_containment'       => $report->d3_containment,
                'd4_root_cause'        => $report->d4_root_cause,
                'd5_corrective_action' => $report->d5_corrective_action,
                'd6_verification'      => $report->d6_verification,
                'd7_prevention'        => $report->d7_prevention,
                'd8_recognition'       => $report->d8_recognition,
                'finalized_at'         => optional($report->finalized_at)->toIso8601String(),
            ],
        ];
    }

    /* ─── Statement of Account ───────────────────────────────────── */

    public function statementOfAccount(Customer $customer, ?string $asOf = null): array
    {
        return $this->soa->forCustomer($customer, $asOf);
    }

    /* ─── Delivery Schedules ─────────────────────────────────────── */

    public function deliverySchedules(int $customerId): Collection
    {
        return DeliverySchedule::where('customer_id', $customerId)
            ->orderByDesc('created_at')
            ->get();
    }

    public function storeDeliverySchedule(int $customerId, array $data): DeliverySchedule
    {
        return DeliverySchedule::create([
            'customer_id' => $customerId,
            'month'       => $data['month'],
            'status'      => 'submitted',
            'lines'       => $data['lines'],
        ]);
    }
}
