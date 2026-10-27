<?php

declare(strict_types=1);

namespace App\Common\Services;

use App\Common\Enums\PermissionOverrideType;
use App\Common\Exceptions\BusinessRuleException;
use App\Common\Models\ApprovalDelegation;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetTransfer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Admin\Models\UserPermissionOverride;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Services\OvertimeDecisionPolicy;
use App\Modules\Auth\Models\User;
use App\Modules\Inventory\Models\ItemCostReview;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockCountItem;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Policies\LoanAccessPolicy;
use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Purchasing\Models\SupplierItemListing;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Notify-only SLA sweep for custom approval state machines without ledger rows. */
class CustomApprovalEscalationService
{
    private int $failed = 0;

    private int $unstaffed = 0;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
    ) {}

    public function runReminders(): int
    {
        return $this->run('reminder', $this->positiveIntSetting('approvals.reminder_hours'));
    }

    public function runEscalations(): int
    {
        return $this->run('escalation', $this->positiveIntSetting('approvals.escalation_hours'));
    }

    public function failedCount(): int
    {
        return $this->failed;
    }

    public function unstaffedCount(): int
    {
        return $this->unstaffed;
    }

    private function run(string $stage, int $hours): int
    {
        $this->failed = 0;
        $this->unstaffed = 0;
        $count = 0;
        foreach ($this->candidateQueries(now()->subHours($hours)) as $kind => $query) {
            $chunkColumn = match ($kind) {
                'overtime' => 'overtime_requests.id',
                'stock_count_variance' => 'stock_count_items.id',
                'budget' => 'budgets.id',
                'budget_transfer' => 'budget_transfers.id',
                default => 'id',
            };
            $query->chunkById(100, function ($rows) use ($stage, $kind, &$count): void {
                try {
                    $subjects = $this->subjectsFor($kind, $rows);
                } catch (\Throwable $exception) {
                    $this->failed += $rows->count();
                    Log::warning('Custom approval SLA subject batch load failed.', [
                        'kind' => $kind,
                        'stage' => $stage,
                        'error' => $exception->getMessage(),
                    ]);

                    return;
                }

                foreach ($rows as $row) {
                    try {
                        $candidate = $this->candidate($kind, $row);
                        $subject = $subjects->get((int) $row->id);
                        if (! $subject instanceof Model) {
                            throw new \RuntimeException('Approval subject is no longer available.');
                        }

                        $recipients = $this->eligibleApprovers($candidate, $subject);
                        $recipients = $recipients->unique('id')->values();
                        if ($recipients->isEmpty()) {
                            $this->unstaffed++;

                            continue;
                        }

                        $ageHours = (int) Carbon::parse($candidate['since'])->diffInHours(now(), true);
                        $type = $stage === 'reminder' ? 'approval_reminder' : 'approval_escalation';
                        $label = $stage === 'reminder' ? 'Approval reminder' : 'Approval escalation';
                        $dedupeKey = sprintf('approval:custom:%s:%s:%d:%s', $stage, $kind, $candidate['id'], sha1($candidate['epoch']));
                        if (DB::table('notifications')->where('dedupe_key', $dedupeKey)->exists()) {
                            continue;
                        }

                        $this->notifications->sendForSubject($subject, $recipients, $type, [
                            'title' => $label,
                            'message' => "{$candidate['label']} has waited {$ageHours}h for approval.",
                            'link_to' => $candidate['link'],
                            'entity_type' => $kind,
                            'entity_id' => app('hashids')->encode($candidate['id']),
                        ], $dedupeKey);
                        $count++;
                    } catch (\Throwable $exception) {
                        $this->failed++;
                        Log::warning('Custom approval SLA notification failed.', [
                            'kind' => $kind,
                            'row_id' => $row->id,
                            'stage' => $stage,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            }, $chunkColumn, str_contains($chunkColumn, '.') ? 'id' : null);
        }

        return $count;
    }

    /** @return Collection<int, Model> */
    private function subjectsFor(string $kind, Collection $rows): Collection
    {
        $query = match ($kind) {
            'overtime' => OvertimeRequest::query()->with('employee.user'),
            'stock_adjustment' => StockAdjustment::query(),
            'item_cost_review' => ItemCostReview::query(),
            'stock_count_variance' => StockCountItem::query(),
            'payroll_adjustment' => PayrollAdjustment::query(),
            'budget' => Budget::query(),
            'budget_transfer' => BudgetTransfer::query(),
            'invoice_prebill' => Invoice::query(),
            'supplier_listing' => SupplierItemListing::query(),
            'loan_write_off' => EmployeeLoan::query(),
            'payroll' => PayrollPeriod::query(),
            default => throw new \InvalidArgumentException("Unknown custom approval subject kind: {$kind}"),
        };

        return $query->whereKey($rows->pluck('id'))->get()->keyBy(static fn (Model $subject): int => (int) $subject->getKey());
    }

    /** @return array<string, Builder> */
    private function candidateQueries(CarbonInterface $cutoff): array
    {
        $tolerance = $this->settings->requiredFloat('inventory.stock_count.variance_tolerance_pct', 0);

        return [
            'overtime' => DB::table('overtime_requests')->leftJoin('users as makers', 'makers.employee_id', '=', 'overtime_requests.employee_id')
                ->select('overtime_requests.id', 'overtime_requests.created_at as since', 'makers.id as maker_id')
                ->where('overtime_requests.status', 'pending')->where('overtime_requests.created_at', '<', $cutoff),
            'stock_adjustment' => DB::table('stock_adjustments')->select('id', 'created_at as since', 'requested_by as maker_id')
                ->where('status', 'pending')->whereNull('stock_movement_id')->where('created_at', '<', $cutoff),
            'item_cost_review' => DB::table('item_cost_reviews')->select('id', 'updated_at as since', 'updated_at as epoch')
                ->where('status', 'pending')->where('updated_at', '<', $cutoff),
            'stock_count_variance' => DB::table('stock_count_items')
                ->join('stock_count_sessions', 'stock_count_sessions.id', '=', 'stock_count_items.session_id')
                ->select('stock_count_items.id as id', 'stock_count_items.updated_at as since', 'stock_count_items.updated_at as epoch', 'stock_count_items.counted_by as maker_id',
                    'stock_count_sessions.created_by as session_maker_id', 'stock_count_sessions.id as session_id')
                ->where('stock_count_sessions.status', 'in_progress')->where('stock_count_items.status', 'counted')
                ->whereRaw('ABS(stock_count_items.variance_percent) > ?', [$tolerance])
                ->where('stock_count_items.updated_at', '<', $cutoff),
            'payroll_adjustment' => DB::table('payroll_adjustments')->select('id', 'created_at as since', 'created_by as maker_id')
                ->where('status', 'pending')->where('created_at', '<', $cutoff),
            'budget' => DB::table('budgets')->leftJoin('users as submitters', 'submitters.id', '=', 'budgets.submitted_by')
                ->select('budgets.id', 'budgets.submitted_at as since', 'budgets.submitted_by as maker_id', 'submitters.role_id as maker_role_id')
                ->where('budgets.status', 'submitted')->where('budgets.submitted_at', '<', $cutoff),
            'budget_transfer' => DB::table('budget_transfers')->leftJoin('users as requesters', 'requesters.id', '=', 'budget_transfers.requested_by')
                ->select('budget_transfers.id', 'budget_transfers.created_at as since', 'budget_transfers.requested_by as maker_id', 'requesters.role_id as maker_role_id')
                ->where('budget_transfers.status', 'pending')->where('budget_transfers.created_at', '<', $cutoff),
            'invoice_prebill' => DB::table('invoices')->select('id', 'created_at as since', 'updated_at as epoch', 'created_by as maker_id')
                ->where('status', 'draft')->where('lifecycle_type', 'prebill')->whereNotNull('prebill_reason')
                ->whereRaw("TRIM(prebill_reason) <> ''")->whereNull('prebill_approved_by')->whereNull('prebill_approved_at')
                ->where('created_at', '<', $cutoff),
            'supplier_listing' => DB::table('supplier_item_listings')->select('id', 'supplier_item_code', 'submitted_at as since', 'updated_at as epoch')
                ->where('status', 'pending')->where('submitted_at', '<', $cutoff),
            'loan_write_off' => DB::table('employee_loans')->select('id', 'write_off_requested_at as since', 'write_off_requested_by as maker_id')
                ->where('status', 'write_off_pending')->whereNotNull('write_off_reason')->whereRaw("TRIM(write_off_reason) <> ''")
                ->whereNotNull('write_off_evidence')->whereRaw("TRIM(write_off_evidence) <> ''")
                ->where('write_off_requested_at', '<', $cutoff),
            'payroll' => DB::table('payroll_periods')->select('id', 'updated_at as since', 'created_by as maker_id', 'computed_by as second_maker_id')
                ->where('status', PayrollPeriodStatus::Computed->value)->where('updated_at', '<', $cutoff),
        ];
    }

    /** @return array{id:int,kind:string,label:string,permissions:list<string>,read_permissions:list<string>,since:mixed,epoch:string,link:string,maker_ids:list<int>,maker_role_id:?int,session_id:?int} */
    private function candidate(string $kind, object $row): array
    {
        $id = (int) $row->id;
        $hashId = app('hashids')->encode($id);
        $makerIds = array_values(array_filter(array_map('intval', [
            $row->maker_id ?? null,
            $row->session_maker_id ?? null,
            $row->second_maker_id ?? null,
        ])));
        $routes = [
            'overtime' => ['Overtime request', 'attendance.ot.approve', "/hr/attendance/overtime/{$hashId}", ['attendance.view']],
            'stock_adjustment' => ['Stock adjustment', 'inventory.adjust.approve', '/inventory/stock-adjustments?status=pending', ['inventory.adjust', 'inventory.adjust.approve']],
            'item_cost_review' => ['Supplier cost review', 'inventory.costs.approve', "/crm/price-agreements/cost-reviews?review={$hashId}", ['inventory.costs.review']],
            'stock_count_variance' => ['Stock-count variance', 'inventory.stock_count.manage', '/inventory/stock-count?session_id='.app('hashids')->encode((int) ($row->session_id ?? 0)).'&count_item_id='.$hashId, ['inventory.stock_count.view']],
            'payroll_adjustment' => ['Payroll adjustment', ['payroll.adjustments.approve', 'payroll.adjustments.reject'], "/payroll/adjustments/{$hashId}", ['payroll.adjustments.view']],
            'budget' => ['Budget', 'budgeting.approve', "/budgeting/{$hashId}", ['budgeting.view']],
            'budget_transfer' => ['Budget transfer', 'budgeting.approve', "/budgeting/transfers/{$hashId}", ['budgeting.view']],
            'invoice_prebill' => ['Prebill invoice', 'accounting.invoices.prebill_approve', "/accounting/invoices/{$hashId}", ['accounting.invoices.view']],
            'supplier_listing' => ['Supplier listing', 'purchasing.supplier_listings.review', '/purchasing/supplier-listings?status=pending'.(! empty($row->supplier_item_code) ? '&search='.rawurlencode((string) $row->supplier_item_code) : ''), ['purchasing.supplier_listings.view']],
            'loan_write_off' => ['Loan write-off', 'loans.write_off.approve', "/hr/loans/{$hashId}", ['loans.view']],
            'payroll' => ['Payroll period', 'payroll.periods.approve', "/payroll/periods/{$hashId}", ['payroll.periods.view']],
        ];
        [$label, $permissions, $link, $readPermissions] = $routes[$kind];
        $permissions = (array) $permissions;

        return [
            'id' => $id,
            'kind' => $kind,
            'label' => $label,
            'permissions' => $permissions,
            'read_permissions' => $readPermissions,
            'since' => $row->since,
            'epoch' => (string) ($row->epoch ?? $row->since),
            'link' => $link,
            'maker_ids' => $makerIds,
            'maker_role_id' => isset($row->maker_role_id) ? (int) $row->maker_role_id : null,
            'session_id' => isset($row->session_id) ? (int) $row->session_id : null,
        ];
    }

    /** @param array{id:int,kind:string,label:string,permissions:list<string>,read_permissions:list<string>,since:mixed,epoch:string,link:string,maker_ids:list<int>,maker_role_id:?int,session_id:?int} $candidate */
    private function eligibleApprovers(array $candidate, Model $sourceRow): Collection
    {
        $audience = collect($candidate['permissions'])
            ->flatMap(static fn (string $permission) => ApprovalDelegation::approvalAudience($permission))
            ->unique('id')
            ->values();
        $primaryReadPermission = $candidate['read_permissions'][0] ?? null;
        $revokedReadIds = count($candidate['read_permissions']) > 1 && $primaryReadPermission !== null
            ? UserPermissionOverride::query()
                ->whereIn('user_id', $audience->pluck('id'))
                ->whereHas('permission', static fn ($query) => $query->where('slug', $primaryReadPermission))
                ->where('type', PermissionOverrideType::Revoke->value)
                ->where(static fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->pluck('user_id')->map(static fn ($id): int => (int) $id)->all()
            : [];
        $invoiceEditors = [];
        if ($candidate['kind'] === 'invoice_prebill') {
            $updates = DB::table('audit_logs')
                ->where('model_type', (new Invoice)->getMorphClass())
                ->where('model_id', $candidate['id'])
                ->where('action', 'updated')
                ->orderBy('id')
                ->get(['id', 'user_id', 'new_values']);
            $lastApprovalId = 0;
            foreach ($updates as $update) {
                $changes = is_array($update->new_values) ? $update->new_values : (json_decode((string) $update->new_values, true) ?: []);
                if (($changes['prebill_approved_by'] ?? null) !== null) {
                    $lastApprovalId = (int) $update->id;
                }
            }
            $invoiceEditors = $updates->filter(static fn ($update): bool => (int) $update->id > $lastApprovalId)
                ->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();
        }

        return $audience->filter(function (User $user) use ($candidate, $sourceRow, $invoiceEditors, $revokedReadIds): bool {
            if (in_array((int) $user->id, $revokedReadIds, true)) {
                return false;
            }
            $mayApproveOwnPayroll = $candidate['kind'] === 'payroll' && $user->hasPermission('payroll.periods.self_approve_override');
            if ((! $mayApproveOwnPayroll && in_array((int) $user->id, $candidate['maker_ids'], true))
                || ! collect($candidate['permissions'])->contains(
                    static fn (string $permission): bool => ApprovalDelegation::canActForPermission($user, $permission, $candidate['maker_ids']),
                )
                || ! collect($candidate['read_permissions'])->contains(
                    static fn (string $permission): bool => $user->hasPermission($permission),
                )) {
                return false;
            }
            if ($candidate['kind'] === 'invoice_prebill') {
                $principals = $user->hasPermission('accounting.invoices.prebill_approve')
                    ? [(int) $user->id]
                    : [(int) $user->id, ...ApprovalDelegation::delegatorsForPermission($user, 'accounting.invoices.prebill_approve')->pluck('id')->map(static fn ($id): int => (int) $id)->all()];
                if (array_intersect($principals, $invoiceEditors) !== []) {
                    return false;
                }
            }
            if ($candidate['kind'] === 'overtime'
                && ! app(OvertimeDecisionPolicy::class)->canApproveWithinScope($sourceRow, $user)) {
                return false;
            }
            if ($candidate['kind'] === 'loan_write_off'
                && ! app(LoanAccessPolicy::class)->canDecide($user, $sourceRow)) {
                return false;
            }
            if (in_array($candidate['kind'], ['budget', 'budget_transfer'], true)
                && ! ApprovalDelegation::hasDifferentPermissionRole($user, 'budgeting.approve', $candidate['maker_role_id'], $candidate['maker_ids'])) {
                return false;
            }

            return true;
        })->values();
    }

    private function positiveIntSetting(string $key): int
    {
        $value = $this->settings->get($key);
        if (! is_numeric($value) || (int) $value <= 0) {
            throw new BusinessRuleException("Required business setting {$key} is missing or invalid.");
        }

        return (int) $value;
    }
}
