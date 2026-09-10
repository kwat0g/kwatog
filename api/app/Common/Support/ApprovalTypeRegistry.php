<?php

declare(strict_types=1);

namespace App\Common\Support;

use App\Modules\Auth\Models\User;

/**
 * Single source of truth for approval-board type metadata.
 *
 * The board, escalation links, and visibility policy must agree on the
 * polymorphic class, source table, URL, and module permissions. Keeping that
 * contract here prevents a newly-supported approvable from silently falling
 * back to an audit-log URL or leaking through the broad board permission.
 */
final class ApprovalTypeRegistry
{
    /** @var array<string, array{kind:string,label:string,table:string,number:string|null,link:string,permissions:array<int,string>}> */
    private const TYPES = [
        'App\\Modules\\Leave\\Models\\LeaveRequest' => [
            'kind' => 'leave',
            'label' => 'Leave',
            'table' => 'leave_requests',
            'number' => 'leave_request_no',
            'link' => '/hr/leaves/',
            'permissions' => ['leave.view', 'leave.approve_dept', 'leave.approve_hr'],
        ],
        'App\\Modules\\Purchasing\\Models\\PurchaseRequest' => [
            'kind' => 'pr',
            'label' => 'Purchase requests',
            'table' => 'purchase_requests',
            'number' => 'pr_number',
            'link' => '/purchasing/purchase-requests/',
            'permissions' => ['purchasing.view', 'purchasing.pr.approve'],
        ],
        'App\\Modules\\Purchasing\\Models\\PurchaseOrder' => [
            'kind' => 'po',
            'label' => 'Purchase orders',
            'table' => 'purchase_orders',
            'number' => 'po_number',
            'link' => '/purchasing/purchase-orders/',
            'permissions' => ['purchasing.view', 'purchasing.po.approve'],
        ],
        'App\\Modules\\Loans\\Models\\EmployeeLoan' => [
            'kind' => 'loan',
            'label' => 'Loans',
            'table' => 'employee_loans',
            'number' => 'loan_no',
            'link' => '/hr/loans/',
            'permissions' => ['loans.view', 'loans.approve'],
        ],
        'App\\Modules\\Payroll\\Models\\PayrollPeriod' => [
            'kind' => 'payroll',
            'label' => 'Payroll',
            'table' => 'payroll_periods',
            'number' => null,
            'link' => '/payroll/periods/',
            'permissions' => ['payroll.periods.view', 'payroll.periods.approve'],
        ],
        // Return Management has NO row scope, same as payroll: every holder of
        // `return_management.view` sees every RMA in the module's own list
        // endpoint, so the permission gate below is already equivalent and
        // ApprovalSourceScope::hasScope() reports false. Deliberate, not an
        // oversight — if a department scope is ever added to the module, wire
        // it into ApprovalSourceScope instead of re-deriving one here.
        'App\\Modules\\ReturnManagement\\Models\\ReturnRequest' => [
            'kind' => 'return_request',
            'label' => 'Return requests',
            'table' => 'return_requests',
            'number' => 'rma_number',
            'link' => '/return-management/',
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

    /** @return array<int, array{value:string,label:string}> */
    public static function kindOptions(): array
    {
        $options = [];
        foreach (self::TYPES as $meta) {
            $options[$meta['kind']] = [
                'value' => $meta['kind'],
                'label' => $meta['label'],
            ];
        }

        return array_values($options);
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

    public static function linkFor(string $class, string $hashId): string
    {
        $meta = self::forClass($class);

        return $meta === null ? '/approvals' : $meta['link'].$hashId;
    }
}
