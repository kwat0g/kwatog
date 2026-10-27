<?php

declare(strict_types=1);

namespace App\Common\Support;

use App\Common\Models\ApprovalDelegation;
use App\Modules\Auth\Models\User;

/**
 * Source of truth for ledger types and approval-board filter labels.
 *
 * The board, escalation links, and visibility policy must agree on the
 * polymorphic class, source table, URL, and module permissions. Custom queue
 * labels stay separate because those workflows do not write approval_records
 * and are queried directly by ApprovalBoardService.
 */
final class ApprovalTypeRegistry
{
    /** @var array<string, string> */
    private const CUSTOM_KINDS = [
        'overtime' => 'Overtime',
        'stock_adjustment' => 'Stock adjustments',
        'item_cost_review' => 'Item cost reviews',
        'stock_count_variance' => 'Stock-count variances',
        'payroll_adjustment' => 'Payroll adjustments',
        'budget' => 'Budgets',
        'budget_transfer' => 'Budget transfers',
        'invoice_prebill' => 'Invoice prebill',
        'supplier_listing' => 'Supplier listings',
        'loan_write_off' => 'Loan write-offs',
    ];

    /** @var array<string, array<int, string>> */
    private const CUSTOM_KIND_PERMISSIONS = [
        'overtime' => ['attendance.view'],
        'stock_adjustment' => ['inventory.adjust', 'inventory.adjust.approve'],
        'stock_count_variance' => ['inventory.stock_count.view'],
        'payroll_adjustment' => ['payroll.adjustments.view'],
        'budget' => ['budgeting.view', 'budgeting.approve'],
        'budget_transfer' => ['budgeting.view', 'budgeting.approve'],
        'invoice_prebill' => ['accounting.invoices.view'],
        'supplier_listing' => ['purchasing.supplier_listings.view'],
        'loan_write_off' => ['loans.view'],
    ];

    /** @var array<string, array{kind:string,label:string,table:string,number:string|null,link:string,view_permission:string,approval_permission?:string,approval_permission_delegated?:bool,approval_permissions_by_role?:array<string,string>,view_permissions?:array<int,string>,link_record?:bool,permissions:array<int,string>}> */
    private const TYPES = [
        'App\\Modules\\Leave\\Models\\LeaveRequest' => [
            'kind' => 'leave',
            'label' => 'Leave',
            'table' => 'leave_requests',
            'number' => 'leave_request_no',
            'link' => '/hr/leaves/',
            'view_permission' => 'leave.view',
            'approval_permissions_by_role' => [
                'department_head' => 'leave.approve_dept',
                'hr_officer' => 'leave.approve_hr',
            ],
            'permissions' => ['leave.view', 'leave.approve_dept', 'leave.approve_hr'],
        ],
        'App\\Modules\\Purchasing\\Models\\PurchaseRequest' => [
            'kind' => 'pr',
            'label' => 'Purchase requests',
            'table' => 'purchase_requests',
            'number' => 'pr_number',
            'link' => '/purchasing/purchase-requests/',
            'view_permission' => 'purchasing.view',
            'approval_permission' => 'purchasing.pr.approve',
            'permissions' => ['purchasing.view', 'purchasing.pr.approve'],
        ],
        'App\\Modules\\Purchasing\\Models\\PurchaseOrder' => [
            'kind' => 'po',
            'label' => 'Purchase orders',
            'table' => 'purchase_orders',
            'number' => 'po_number',
            'link' => '/purchasing/purchase-orders/',
            'view_permission' => 'purchasing.view',
            'approval_permission' => 'purchasing.po.approve',
            'view_permissions' => ['purchasing.view', 'purchasing.po.approve'],
            'permissions' => ['purchasing.view', 'purchasing.po.approve'],
        ],
        'App\\Modules\\Accounting\\Models\\BillPayment' => [
            'kind' => 'bill_payment',
            'label' => 'Bill payments',
            'table' => 'bill_payments',
            'number' => 'reference_number',
            'link' => '/accounting/bills/',
            'link_parent' => 'bill',
            'view_permission' => 'accounting.bills.view',
            'approval_permission' => 'accounting.bills.payment_approve',
            'permissions' => ['accounting.bills.view', 'accounting.bills.payment_approve'],
        ],
        'App\\Modules\\Loans\\Models\\EmployeeLoan' => [
            'kind' => 'loan',
            'label' => 'Loans',
            'table' => 'employee_loans',
            'number' => 'loan_no',
            'link' => '/hr/loans/',
            'view_permission' => 'loans.view',
            'approval_permission' => 'loans.approve',
            'permissions' => ['loans.view', 'loans.approve'],
        ],
        'App\\Modules\\Payroll\\Models\\PayrollPeriod' => [
            'kind' => 'payroll',
            'label' => 'Payroll',
            'table' => 'payroll_periods',
            'number' => null,
            'link' => '/payroll/periods/',
            'view_permission' => 'payroll.periods.view',
            'approval_permission' => 'payroll.periods.approve',
            'approval_permission_delegated' => true,
            'permissions' => ['payroll.periods.view', 'payroll.periods.approve'],
        ],
        // 2026-09-10 audit — both are ENFORCED chains (services call
        // ApprovalService::submit) but were absent here, so the board dropped
        // their cards silently and their actors (production_manager, VP) never
        // saw work waiting on them.
        'App\\Modules\\HR\\Models\\SalaryAdjustment' => [
            'kind' => 'salary_adjustment',
            'label' => 'Salary adjustments',
            'table' => 'salary_adjustments',
            'number' => null,
            // The queue is a tab on the employees page, not a per-record URL.
            // link_record=false tells linkFor() not to append the row hash.
            'link' => '/hr/employees',
            'link_record' => false,
            'view_permission' => 'hr.salary_adjustments.view',
            'approval_permission' => 'hr.salary_adjustments.act',
            'permissions' => ['hr.salary_adjustments.view', 'hr.salary_adjustments.act'],
        ],
        'App\\Modules\\Assets\\Models\\Asset' => [
            'kind' => 'asset_disposal',
            'label' => 'Asset disposals',
            'table' => 'assets',
            'number' => 'asset_code',
            'link' => '/assets/',
            'view_permission' => 'assets.view',
            'approval_permission' => 'assets.dispose.approve',
            'permissions' => ['assets.view', 'assets.dispose.approve'],
        ],
        // Customer service sees customer returns only; ApprovalSourceScope
        // applies that type scope to approval cards while other roles retain
        // permission-based visibility.
        'App\\Modules\\ReturnManagement\\Models\\ReturnRequest' => [
            'kind' => 'return_request',
            'label' => 'Return requests',
            'table' => 'return_requests',
            'number' => 'rma_number',
            'link' => '/return-management/',
            'view_permission' => 'return_management.view',
            'approval_permission' => 'return_management.approve',
            'permissions' => ['return_management.view', 'return_management.approve'],
        ],
    ];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::TYPES;
    }

    /** @return array<string, mixed>|null */
    public static function forClass(string $class): ?array
    {
        return self::TYPES[$class] ?? null;
    }

    /** @return array<string, mixed>|null */
    public static function forKind(string $kind): ?array
    {
        foreach (self::TYPES as $meta) {
            if ($meta['kind'] === $kind) {
                return $meta;
            }
        }

        return null;
    }

    public static function kindLabel(string $kind): ?string
    {
        return self::forKind($kind)['label'] ?? self::CUSTOM_KINDS[$kind] ?? null;
    }

    /** @return array<int, array{value:string,label:string}> */
    public static function kindOptions(?User $user = null): array
    {
        $options = [];
        foreach (self::TYPES as $meta) {
            $options[$meta['kind']] = [
                'value' => $meta['kind'],
                'label' => $meta['label'],
            ];
        }
        foreach (self::CUSTOM_KINDS as $kind => $label) {
            $options[$kind] = ['value' => $kind, 'label' => $label];
        }

        $options = array_values($options);

        return $user === null
            ? $options
            : array_values(array_filter(
                $options,
                fn (array $option): bool => self::userCanViewKind($user, $option['value']),
            ));
    }

    public static function userCanViewKind(User $user, string $kind): bool
    {
        $meta = self::forKind($kind);
        if ($meta !== null) {
            return self::userCanView($user, $meta);
        }

        if ($kind === 'item_cost_review') {
            return $user->hasPermission('inventory.costs.review')
                && ApprovalDelegation::canActForPermission($user, 'inventory.costs.approve');
        }

        foreach (self::CUSTOM_KIND_PERMISSIONS[$kind] ?? [] as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function userCanView(User $user, array $meta): bool
    {
        foreach ($meta['permissions'] ?? [] as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    public static function userCanViewRecord(User $user, string $class, int $id): bool
    {
        $meta = self::forClass($class);
        if ($meta === null) {
            return false;
        }

        $viewPermissions = $meta['view_permissions'] ?? [$meta['view_permission']];
        $canView = false;
        foreach ($viewPermissions as $permission) {
            if ($user->hasPermission($permission)) {
                $canView = true;
                break;
            }
        }
        if (! $canView) {
            return false;
        }

        return ! ApprovalSourceScope::hasScope($class)
            || isset(ApprovalSourceScope::visibleIds($class, [$id], $user)[$id]);
    }

    public static function approvalPermissionForStep(string $class, string $roleSlug): ?string
    {
        $meta = self::forClass($class);
        if ($meta === null) {
            return null;
        }

        return $meta['approval_permissions_by_role'][$roleSlug] ?? $meta['approval_permission'] ?? null;
    }

    public static function linkFor(string $class, string $hashId, ?string $parentHashId = null): string
    {
        $meta = self::forClass($class);

        if ($meta === null) {
            return '/approvals';
        }

        if (isset($meta['link_parent'])) {
            return $parentHashId === null ? '/approvals' : $meta['link'].$parentHashId;
        }

        return ($meta['link_record'] ?? true) ? $meta['link'].$hashId : (string) $meta['link'];
    }
}
