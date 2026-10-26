<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Common\Services\ApprovalBoardService;
use App\Common\Services\SettingsService;
use App\Common\Support\DepartmentScope;
use App\Modules\Accounting\Enums\InvoiceStatus;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetTransfer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\HR\Enums\EmployeeTrainingStatus;
use App\Modules\HR\Enums\JobPostingStatus;
use App\Modules\HR\Models\EmployeeTraining;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Services\ProfileUpdateRequestService;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Landing\Models\ContactInquiry;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\MRP\Enums\MrpPlanStatus;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Policies\PurchaseRequestAccessPolicy;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\ReturnManagement\Enums\ReturnRequestStatus;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Support\ReturnManagementAccess;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\Shipment;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Polish Task S2 — Sidebar badge count system.
 *
 * Single source of truth for every numeric badge in the sidebar. Each entry is
 * a [permissions, counter, label, description] pair so a missing column /
 * model never poisons the whole payload — the failing key is just dropped from
 * the response.
 *
 * Per-user cache TTL is short (30s) because counts back UI hints; the SPA
 * also polls every 60s and the WebSocket layer invalidates instantly.
 *
 * Hardening (2026-08-08):
 *  - Settings thresholds + TTL are read ONCE per compute instead of once per
 *    badge, and fall back to sane defaults if the settings row is missing so
 *    a missing dashboard.badges.* row can never 500 the whole endpoint.
 *  - Every badge carries a static `label` + `description` so clients can
 *    render meaningful tooltips without maintaining a second taxonomy.
 *  - Scope widened: 10 more nav slots now have counts (inquiries, complaints,
 *    inspections, GRN, MRB holds, shipments, MRP plans, returns, invoices,
 *    bills) — see the definitions map.
 */
class BadgeService
{
    /** Badge counts disappear with the module that owns their screen. */
    private const FEATURE_BY_BADGE = [
        'purchase_requests' => 'purchasing',
        'leaves' => 'leave',
        'overtime' => 'attendance',
        'maintenance_wo' => 'maintenance',
        'maintenance_wo_unassigned' => 'maintenance',
        'low_stock' => 'inventory',
        'ncrs' => 'quality',
        'profile_requests' => 'hr',
        'payroll' => 'payroll',
        'training_expiry' => 'hr',
        'training_upcoming' => 'hr',
        'open_postings' => 'recruitment',
        'work_orders' => 'production',
        'work_orders_due_soon' => 'production',
        'work_orders_paused' => 'production',
        'deliveries' => 'supply_chain',
        'pending_so' => 'crm',
        'inquiries' => 'crm',
        'open_complaints' => 'crm',
        'pending_inspections' => 'quality',
        'pending_grn' => 'inventory',
        'mrb_holds' => 'inventory',
        'shipments' => 'supply_chain',
        'mrp_plans' => 'mrp',
        'pending_returns' => 'return_management',
        'draft_invoices' => 'accounting',
        'overdue_bills' => 'accounting',
        'unpaid_bills' => 'accounting',
        'due_soon_bills' => 'accounting',
        'pending_budgets' => 'budgeting',
        'pending_transfers' => 'budgeting',
    ];

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Build the per-slot badge map for the given user.
     *
     * Cached per-user for the configured TTL, keyed by a global version integer
     * so that a single call to touch() immediately invalidates every user's
     * cached map without needing to enumerate user IDs.
     *
     * @return array<string, array{count: int, severity: string, label: string, description: string}>
     */
    public function for(User $user): array
    {
        $ttl = $this->ttlSeconds();
        $version = self::version();

        return Cache::remember(
            "badges.user.{$user->id}.v{$version}",
            $ttl,
            fn () => $this->compute($user),
        );
    }

    /** Current global cache version (defaults to 1). */
    public static function version(): int
    {
        return (int) Cache::get('badges.version', 1);
    }

    /**
     * Invalidate every user's cached badge map instantly by bumping the
     * global version. Called whenever badge-affecting data changes.
     */
    public static function touch(): void
    {
        if (Cache::has('badges.version')) {
            Cache::increment('badges.version');
        } else {
            Cache::forever('badges.version', 2); // 1 was the implicit default
        }
    }

    /** @return array<string, array{count: int, severity: string, label: string, description: string}> */
    private function compute(User $user): array
    {
        // Read the whole dashboard settings group once per compute so the
        // per-badge severity overrides below cost nothing extra (one query
        // total, and the result is cached per-user for the TTL anyway).
        $settings = $this->settings->getGroup('dashboard');
        $globalDanger  = $this->intSetting($settings, 'dashboard.badges.danger_threshold', 20);
        $globalWarning = $this->intSetting($settings, 'dashboard.badges.warning_threshold', 0);

        $out = [];
        foreach ($this->definitions($user) as $key => $def) {
            $feature = self::FEATURE_BY_BADGE[$key] ?? null;
            if ($feature !== null && $this->settings->get("modules.{$feature}", true) !== true) {
                continue;
            }
            if (! $this->userHasAny($user, $def['permissions'])
                || ! $this->userHasAll($user, $def['required_permissions'] ?? [])) {
                continue;
            }
            try {
                $count = (int) ($def['counter'])();
            } catch (Throwable $e) {
                Log::debug("BadgeService.{$key} skipped: {$e->getMessage()}");
                continue;
            }
            $out[$key] = [
                'count'       => $count,
                // Per-badge override wins; missing override falls back to the
                // global thresholds (e.g. overdue_bills.danger=3 so a handful
                // of overdue payables is red before the global 20).
                'severity'    => $this->severity(
                    $count,
                    $this->intSetting($settings, "dashboard.badges.overrides.{$key}.danger", $globalDanger),
                    $this->intSetting($settings, "dashboard.badges.overrides.{$key}.warning", $globalWarning),
                ),
                'label'       => $def['label'],
                'description' => $def['description'],
            ];
        }
        return $out;
    }

    /**
     * @return array<string, array{
     *   permissions: array<int, string>,
     *   required_permissions?: array<int, string>,
     *   label: string,
     *   description: string,
     *   counter: Closure
     * }>
     */
    private function definitions(User $user): array
    {
        $billSignalCounts = null;
        $countBillSignals = function () use (&$billSignalCounts): object {
            return $billSignalCounts ??= DB::query()
                ->selectSub(Bill::query()->overdue()->selectRaw('COUNT(*)'), 'overdue')
                ->selectSub(Bill::query()->unpaid()->selectRaw('COUNT(*)'), 'unpaid')
                ->selectSub(Bill::query()->dueSoon()->selectRaw('COUNT(*)'), 'due_soon')
                ->first() ?? (object) ['overdue' => 0, 'unpaid' => 0, 'due_soon' => 0];
        };

        return [
            // Reuse the board's filtered queue so sidebar count matches the cards shown there.
            'approvals' => [
                'permissions' => ['approvals.board.view'],
                'label'       => 'Approvals',
                'description' => 'Approvals requiring your action',
                'counter'     => fn (): int => (int) app(ApprovalBoardService::class)
                    ->board($user)['summary']['my_action'],
            ],

            'action_center' => [
                // Same slug that gates the action-center routes — a role that
                // cannot open the queue must not carry its badge either.
                'permissions' => ['dashboard.action_center.view'],
                'label'       => 'Action Center',
                'description' => 'Open items needing attention',
                'counter' => fn (): int => (int) app(ActionCenterService::class)->for($user)['summary']['total'],
            ],

            // Procurement > Purchase requests — pending PRs routed to this role.
            'purchase_requests' => [
                'permissions' => ['purchasing.pr.approve'],
                'label'       => 'Purchase Requests',
                'description' => 'Purchase requests awaiting approval',
                'counter'     => fn (): int => app(PurchaseRequestAccessPolicy::class)
                    ->awaitingMyApprovalQuery($user)
                    ->count(),
            ],

            // HR > Leave management — pending requests visible to this approver tier.
            'leaves' => [
                'permissions' => ['leave.approve_dept', 'leave.approve_hr'],
                'label'       => 'Leaves',
                'description' => 'Leave requests awaiting your approval',
                'counter'     => function () use ($user): int {
                    $statuses = [];
                    if ($user->hasPermission('leave.approve_dept')) {
                        $statuses[] = LeaveRequestStatus::PendingDept->value;
                    }
                    if ($user->hasPermission('leave.approve_hr')) {
                        $statuses[] = LeaveRequestStatus::PendingHr->value;
                    }
                    if ($statuses === []) {
                        return 0;
                    }
                    $query = LeaveRequest::query()->whereIn('status', $statuses);
                    DepartmentScope::apply(
                        $query,
                        $user,
                        viewAllPermission: 'leave.approve_hr',
                        departmentPermission: 'leave.approve_dept',
                        deptColumn: 'department_id',
                        selfColumn: 'employee_id',
                        selfId: $user->employee_id ? (int) $user->employee_id : null,
                        deptRelation: 'employee',
                    );

                    return $query->count();
                },
            ],

            // Payroll > Overtime — pending OT requests.
            'overtime' => [
                'permissions' => ['attendance.ot.approve'],
                'label'       => 'Overtime',
                'description' => 'Overtime requests awaiting approval',
                'counter'     => function () use ($user): int {
                    $query = OvertimeRequest::query()->where('status', 'pending');
                    DepartmentScope::apply(
                        $query,
                        $user,
                        viewAllPermission: 'hr.employees.view_sensitive',
                        departmentPermission: 'attendance.ot.approve',
                        deptColumn: 'department_id',
                        selfColumn: 'employee_id',
                        selfId: $user->employee_id ? (int) $user->employee_id : null,
                        deptRelation: 'employee',
                    );

                    return $query->count();
                },
            ],

            // Maintenance > Maintenance WOs — open + in-progress work orders.
            'maintenance_wo' => [
                'permissions' => ['maintenance.view'],
                'label'       => 'Maintenance Work Orders',
                'description' => 'Open maintenance work orders',
                'counter'     => fn (): int => MaintenanceWorkOrder::query()->open()->count(),
            ],
            'maintenance_wo_unassigned' => [
                'permissions' => ['maintenance.wo.assign'],
                'required_permissions' => ['maintenance.view'],
                'label'       => 'Unassigned',
                'description' => 'Open maintenance work orders without an assignee',
                'counter'     => fn (): int => MaintenanceWorkOrder::query()->unassigned()->count(),
            ],

            // Warehouse > Stock levels — items at/below reorder point.
            // Outer `reorder_point > 0` filter is intentional: items with no
            // configured reorder threshold (0) should never appear as low-stock.
            'low_stock' => [
                'permissions' => ['inventory.view'],
                'label'       => 'Low Stock',
                'description' => 'Items at or below reorder point',
                'counter'     => fn (): int => Item::query()
                    ->where('reorder_point', '>', 0)
                    ->whereRaw('(SELECT COALESCE(SUM(quantity - reserved_quantity), 0) FROM stock_levels sl WHERE sl.item_id = items.id) <= items.reorder_point')
                    ->count(),
            ],

            // Quality > NCRs — open / not-yet-closed reports.
            'ncrs' => [
                'permissions' => ['quality.ncr.view'],
                'label'       => 'NCRs',
                'description' => 'Open non-conformance reports',
                'counter'     => fn (): int => NonConformanceReport::query()
                    ->whereNotIn('status', ['closed', 'cancelled'])
                    ->count(),
            ],

            // HR > Profile change requests — pending review queue.
            'profile_requests' => [
                'permissions' => ['hr.employees.view', 'hr.profile_updates.finance_review'],
                'label'       => 'Awaiting my action',
                'description' => 'Profile changes you can review',
                'counter'     => fn (): int => app(ProfileUpdateRequestService::class)
                    ->awaitingMyActionQuery($user)
                    ->count(),
            ],

            // Payroll > Periods awaiting HR/Finance action (draft or processing).
            // PayrollPeriodStatus has: draft, processing, approved, finalized, disbursed.
            // "Awaiting action" = draft (not yet submitted) + processing (computing / under review).
            'payroll' => [
                'permissions' => ['payroll.periods.view'],
                'label'       => 'Payroll',
                'description' => 'Payroll periods awaiting action',
                'counter'     => fn (): int => PayrollPeriod::query()
                    ->whereIn('status', ['draft', 'processing'])
                    ->count(),
            ],

            // HR > Training expiry — employee trainings that have expired.
            'training_expiry' => [
                'permissions' => ['hr.trainings.view'],
                'label'       => 'Training Expiry',
                'description' => 'Expired employee trainings',
                'counter'     => fn (): int => EmployeeTraining::query()
                    ->where('status', EmployeeTrainingStatus::Expired->value)
                    ->count(),
            ],

            // HR > Trainings — sessions coming up or certifications expiring soon.
            // (Complements training_expiry, which counts already-expired rows.)
            'training_upcoming' => [
                'permissions' => ['hr.trainings.view'],
                'label'       => 'Trainings',
                'description' => 'Trainings upcoming or expiring soon',
                'counter'     => function (): int {
                    return EmployeeTraining::query()
                        ->whereNotIn('status', [
                            EmployeeTrainingStatus::Cancelled->value,
                            EmployeeTrainingStatus::Expired->value,
                        ])
                        ->where(fn ($q) => $q
                            // Scheduled sessions in the next 14 days.
                            ->whereBetween('scheduled_for', [today(), today()->addDays(14)])
                            // Completed certifications expiring in the next 30 days.
                            ->orWhereBetween('expires_at', [today(), today()->addDays(30)]))
                        ->count();
                },
            ],

            // HR > Recruitment — postings currently accepting applications.
            'open_postings' => [
                'permissions' => ['hr.recruitment.view'],
                'label'       => 'Recruitment',
                'description' => 'Open job postings',
                'counter'     => fn (): int => JobPosting::query()
                    ->where('status', JobPostingStatus::Open->value)
                    ->count(),
            ],

            // Production > Work orders — active work orders past their due time.
            'work_orders_awaiting_confirmation' => [
                'permissions' => ['production.work_orders.view'],
                'required_permissions' => ['production.view', 'production.wo.confirm'],
                'label'       => 'Awaiting confirmation',
                'description' => 'Planned production work orders awaiting confirmation',
                'counter'     => fn (): int => WorkOrder::query()->awaitingConfirmation()->count(),
            ],
            'work_orders' => [
                'permissions' => ['production.work_orders.view'],
                'required_permissions' => ['production.view'],
                'label'       => 'Overdue',
                'description' => 'Overdue production work orders',
                'counter'     => fn (): int => WorkOrder::query()->overdue()->count(),
            ],
            'work_orders_due_soon' => [
                'permissions' => ['production.work_orders.view'],
                'required_permissions' => ['production.view'],
                'label'       => 'Due soon',
                'description' => 'Production work orders due within the configured window',
                'counter'     => fn (): int => WorkOrder::query()->dueSoon(
                    (int) $this->settings->get('production.work_orders.due_soon_days', 3),
                )->count(),
            ],
            'work_orders_paused' => [
                'permissions' => ['production.work_orders.view'],
                'required_permissions' => ['production.view', 'production.work_orders.lifecycle'],
                'label'       => 'Paused',
                'description' => 'Production work orders awaiting resume or cancellation',
                'counter'     => fn (): int => WorkOrder::query()->paused()->count(),
            ],

            // Supply chain > Deliveries in transit needing an update.
            'deliveries' => [
                'permissions' => ['supply_chain.view', 'supply_chain.deliveries.view'],
                'label'       => 'Deliveries',
                'description' => 'Deliveries in transit',
                'counter'     => fn (): int => Delivery::query()
                    ->whereIn('status', ['loading', 'in_transit'])
                    ->count(),
            ],

            // Overview > Notifications — unread notification count.
            'unread' => [
                'permissions' => [],
                'label'       => 'Notifications',
                'description' => 'Unread notifications',
                'counter'     => fn (): int => $user->unreadNotifications()->count(),
            ],

            // Orders awaiting confirmation or a response; exclude unsent drafts.
            'pending_so' => [
                'permissions' => ['crm.sales_orders.view'],
                'label'       => 'Confirmation queue',
                'description' => 'Sales orders awaiting confirmation or response',
                'counter'     => fn (): int => SalesOrder::query()
                    ->awaitingConfirmation()
                    ->count(),
            ],

            // CRM > Inquiries — public contact-form submissions not yet triaged.
            'inquiries' => [
                'permissions' => ['crm.inquiries.manage'],
                'label'       => 'New inquiries',
                'description' => 'New contact-form inquiries awaiting triage',
                'counter'     => fn (): int => $user->hasPermission('crm.inquiries.view')
                    ? ContactInquiry::query()->awaitingTriage()->count()
                    : 0,
            ],

            // CRM > Complaints — open / investigating customer complaints.
            'open_complaints' => [
                'permissions' => ['crm.complaints.view'],
                'label'       => 'Complaints',
                'description' => 'Open complaints assigned to you',
                'counter'     => fn (): int => $user->hasPermission('crm.complaints.manage')
                    ? CustomerComplaint::query()->openAssignedTo((int) $user->id)->count()
                    : 0,
            ],

            // Quality > Inspections — drafts awaiting readings + active ones.
            'pending_inspections' => [
                'permissions' => ['quality.inspections.view'],
                'label'       => 'Inspections',
                'description' => 'Inspections awaiting completion',
                'counter'     => fn (): int => Inspection::query()
                    ->toInspect()
                    ->count(),
            ],

            // Warehouse > Receiving — goods receipts waiting on QC acceptance.
            'pending_grn' => [
                'permissions' => ['inventory.view'],
                'label'       => 'Receiving (GRN)',
                'description' => 'Goods receipts awaiting QC',
                'counter'     => fn (): int => GoodsReceiptNote::query()
                    ->pendingQc()
                    ->count(),
            ],

            // Warehouse > MRB / Quarantine — lots physically on hold.
            'mrb_holds' => [
                'permissions' => ['inventory.mrb.view'],
                'label'       => 'MRB / Quarantine',
                'description' => 'Lots on hold in quarantine',
                'counter'     => fn (): int => MaterialReviewRecord::query()
                    ->held()
                    ->count(),
            ],

            // Supply chain > Shipments — mid-pipe inbound shipments.
            'shipments' => [
                'permissions' => ['supply_chain.view', 'supply_chain.shipments.view'],
                'label'       => 'Shipments',
                'description' => 'Inbound shipments in transit, customs, or cleared',
                'counter'     => fn (): int => Shipment::query()
                    ->midPipe()
                    ->count(),
            ],

            // MRP > Plans — active plans still reporting material shortages.
            'mrp_plans' => [
                'permissions' => ['mrp.plans.view'],
                'label'       => 'MRP Plans',
                'description' => 'Active MRP plans with shortages',
                'counter'     => fn (): int => MrpPlan::query()
                    ->where('status', MrpPlanStatus::Active->value)
                    ->where('shortages_found', '>', 0)
                    ->count(),
            ],

            // Returns (RMA) — any non-terminal request in the workflow.
            'pending_returns' => [
                'permissions' => ['return_management.view'],
                'label'       => 'Returns (RMA)',
                'description' => 'Returns awaiting processing',
                'counter'     => fn (): int => ReturnRequest::query()
                    ->when(
                        ReturnManagementAccess::customerReturnsOnly($user),
                        fn ($query) => $query->where('type', ReturnRequestType::CustomerReturn->value),
                    )
                    ->whereNotIn('status', [
                        ReturnRequestStatus::Completed->value,
                        ReturnRequestStatus::Rejected->value,
                        ReturnRequestStatus::Cancelled->value,
                    ])
                    ->count(),
            ],

            // Finance > Invoices (AR) — drafts awaiting finalization/posting.
            'draft_invoices' => [
                'permissions' => ['accounting.invoices.view'],
                'label'       => 'Invoices (AR)',
                'description' => 'Invoices awaiting posting',
                'counter'     => fn (): int => Invoice::query()
                    ->where('status', InvoiceStatus::Draft->value)
                    ->count(),
            ],

            // Finance > Bills (AP) — payable bills past their due date.
            'overdue_bills' => [
                'permissions' => ['accounting.bills.view'],
                'label'       => 'Overdue',
                'description' => 'Overdue bills payable',
                'counter'     => fn (): int => (int) $countBillSignals()->overdue,
            ],

            'unpaid_bills' => [
                'permissions' => ['accounting.bills.view'],
                'label'       => 'Unpaid',
                'description' => 'Open bills with an unpaid balance',
                'counter'     => fn (): int => (int) $countBillSignals()->unpaid,
            ],

            'due_soon_bills' => [
                'permissions' => ['accounting.bills.view'],
                'label'       => 'Due soon',
                'description' => 'Open bills due within 3 days',
                'counter'     => fn (): int => (int) $countBillSignals()->due_soon,
            ],

            // Finance > Budgeting — submitted budgets awaiting a checker.
            'pending_budgets' => [
                'permissions' => ['budgeting.view'],
                'label'       => 'Budgets',
                'description' => 'Budgets awaiting your approval',
                'counter'     => fn (): int => Budget::query()->awaitingApprovalFor($user)->count(),
            ],

            // Finance > Budgeting — transfer requests awaiting a checker.
            'pending_transfers' => [
                'permissions' => ['budgeting.approve', 'budgeting.view'],
                'label'       => 'Budget transfers',
                'description' => 'Budget transfers awaiting approval',
                'counter'     => fn (): int => BudgetTransfer::query()->awaitingApprovalFor($user)->count(),
            ],
        ];
    }

    /** @param  array<int, string>  $permissions */
    private function userHasAny(User $user, array $permissions): bool
    {
        if ($permissions === []) {
            return true;
        }
        foreach ($permissions as $slug) {
            if ($user->hasPermission($slug)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<int, string> $permissions */
    private function userHasAll(User $user, array $permissions): bool
    {
        foreach ($permissions as $slug) {
            if (! $user->hasPermission($slug)) {
                return false;
            }
        }
        return true;
    }

    private function severity(int $count, int $danger, int $warning): string
    {
        if ($count > $danger)  return 'danger';
        if ($count > $warning) return 'warning';
        return 'neutral';
    }

    /** Safe TTL read — a missing settings row must never 500 the endpoint. */
    private function ttlSeconds(): int
    {
        try {
            return $this->settings->requiredInt('dashboard.badges.cache_ttl_seconds', 1, 3600);
        } catch (Throwable) {
            return 30;
        }
    }

    /**
     * Integer from a settings-group map; falls back to $default for missing,
     * non-numeric, or negative values (a negative threshold is meaningless).
     *
     * @param  array<string, mixed>  $settings
     */
    private function intSetting(array $settings, string $key, int $default): int
    {
        $value = $settings[$key] ?? null;
        if (! is_numeric($value) || (int) $value < 0) {
            return $default;
        }
        return (int) $value;
    }
}
