<?php

declare(strict_types=1);

namespace App\Common\Support;

use App\Common\Models\ApprovalDelegation;
use App\Common\Services\SettingsService;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Services\OvertimeDecisionPolicy;
use App\Modules\Accounting\Enums\BudgetStatus;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetTransfer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Services\BudgetService;
use App\Modules\Accounting\Services\BudgetTransferService;
use App\Modules\Auth\Models\User;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeTraining;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Services\SeparationService;
use App\Modules\HR\Support\EmployeeCompetenceScope;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemCostReview;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Maintenance\Models\MaintenancePartRequest;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Support\MaintenanceWorkAccess;
use App\Modules\Payroll\Models\Payroll;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Production\Services\WorkOrderAssignmentService;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\SupplierItemListing;
use App\Modules\Purchasing\Policies\PurchaseOrderAccessPolicy;
use App\Modules\Purchasing\Policies\PurchaseRequestAccessPolicy;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\ReturnManagement\Enums\ReturnRequestType;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Support\ReturnManagementAccess;
use App\Modules\SupplyChain\Models\Delivery;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class NotificationSubjectAccessRegistry
{
    public static function supports(string $class): bool
    {
        return in_array($class, [Invoice::class, Bill::class, Budget::class, BudgetTransfer::class, Clearance::class, Delivery::class, Employee::class, EmployeeTraining::class, GoodsReceiptNote::class, Inspection::class, Item::class, ItemCostReview::class, JobApplication::class, JobPosting::class, MaintenancePartRequest::class, MaintenanceWorkOrder::class, NonConformanceReport::class, Attendance::class, OvertimeRequest::class, Payroll::class, PayrollAdjustment::class, PayrollPeriod::class, ReturnRequest::class, SalesOrder::class, StockAdjustment::class, SupplierItemListing::class, WorkOrder::class], true)
            || ApprovalTypeRegistry::forClass($class) !== null;
    }

    public static function userCanViewRecord(User $user, Model $subject, ?string $linkTo = null): bool
    {
        $class = $subject->getMorphClass();

        return match ($class) {
            // The route group rejects invoice access while Accounting is disabled.
            Invoice::class => app(SettingsService::class)->get('modules.accounting', false) === true
                && $user->hasPermission('accounting.invoices.view'),
            Bill::class => app(SettingsService::class)->get('modules.accounting', false) === true
                && $user->hasPermission('accounting.bills.view'),
            StockAdjustment::class => app(SettingsService::class)->get('modules.inventory', false) === true
                && ($user->hasPermission('inventory.adjust') || $user->hasPermission('inventory.adjust.approve')),
            GoodsReceiptNote::class, Item::class => app(SettingsService::class)->get('modules.inventory', false) === true
                && $user->hasPermission('inventory.view')
                && ($subject instanceof GoodsReceiptNote || $subject instanceof Item),
            Inspection::class => app(SettingsService::class)->get('modules.quality', false) === true
                && $user->is_active
                && $user->hasPermission('quality.inspections.view')
                && $subject instanceof Inspection,
            NonConformanceReport::class => app(SettingsService::class)->get('modules.quality', false) === true
                && $user->is_active
                && $user->hasPermission('quality.ncr.view')
                && $subject instanceof NonConformanceReport,
            ReturnRequest::class => app(SettingsService::class)->get('modules.return_management', false) === true
                && $user->is_active
                && $user->hasPermission('return_management.view')
                && $subject instanceof ReturnRequest
                && ! (ReturnManagementAccess::customerReturnsOnly($user) && $subject->type === ReturnRequestType::SupplierReturn),
            EmployeeTraining::class => self::userCanViewEmployeeTraining($user, $subject),
            Employee::class => self::userCanViewEmployee($user, $subject),
            Clearance::class => app(SettingsService::class)->get('modules.hr', false) === true
                && $user->is_active
                && $user->hasPermission('hr.separation.view')
                && $subject instanceof Clearance
                && app(SeparationService::class)->canView($subject, $user),
            JobApplication::class => app(SettingsService::class)->get('modules.hr', false) === true
                && app(SettingsService::class)->get('modules.recruitment', false) === true
                && $user->is_active
                && $user->hasPermission('hr.recruitment.view')
                && $subject instanceof JobApplication,
            JobPosting::class => app(SettingsService::class)->get('modules.hr', false) === true
                && app(SettingsService::class)->get('modules.recruitment', false) === true
                && $user->is_active
                && $user->hasPermission('hr.recruitment.view')
                && $subject instanceof JobPosting,
            LeaveRequest::class => app(SettingsService::class)->get('modules.leave', false) === true
                && $user->is_active
                && $subject instanceof LeaveRequest
                && ApprovalTypeRegistry::userCanViewRecord($user, LeaveRequest::class, (int) $subject->getKey()),
            Attendance::class => self::userCanViewAttendance($user, $subject, $linkTo),
            OvertimeRequest::class => self::userCanViewOvertime($user, $subject, $linkTo),
            PayrollPeriod::class => app(SettingsService::class)->get('modules.payroll', false) === true
                && $user->is_active
                && $user->hasPermission('payroll.periods.view')
                && $subject instanceof PayrollPeriod,
            PayrollAdjustment::class => app(SettingsService::class)->get('modules.payroll', false) === true
                && $user->is_active
                && $user->hasPermission('payroll.adjustments.view')
                && $subject instanceof PayrollAdjustment,
            Payroll::class => self::userCanViewPayslip($user, $subject),
            ItemCostReview::class => app(SettingsService::class)->get('modules.inventory', false) === true
                && $user->hasPermission('inventory.costs.review')
                && $subject instanceof ItemCostReview,
            MaintenancePartRequest::class => app(SettingsService::class)->get('modules.inventory', false) === true
                && $user->is_active
                && $user->hasPermission('inventory.picking.view')
                && $subject instanceof MaintenancePartRequest,
            MaintenanceWorkOrder::class => self::userCanViewMaintenanceWorkOrder($user, $subject),
            SupplierItemListing::class => app(SettingsService::class)->get('modules.purchasing', false) === true
                && $user->hasPermission('purchasing.supplier_listings.view'),
            WorkOrder::class => self::userCanViewWorkOrder($user, $subject),
            EmployeeLoan::class => self::userCanViewLoan($user, $subject),
            Budget::class => self::budgetingEnabled() && self::userCanViewBudget($user, $subject),
            BudgetTransfer::class => self::budgetingEnabled() && (
                $user->hasPermission('budgeting.view')
                || app(BudgetTransferService::class)->canApprove($subject, $user)
            ),
            SalesOrder::class => app(SettingsService::class)->get('modules.crm', false) === true
                && $user->hasPermission('crm.sales_orders.view'),
            PurchaseRequest::class => app(SettingsService::class)->get('modules.purchasing', false) === true
                && $user->hasPermission('purchasing.view')
                && $subject instanceof PurchaseRequest
                && app(PurchaseRequestAccessPolicy::class)->canView($user, $subject),
            Delivery::class => app(SettingsService::class)->get('modules.supply_chain', false) === true
                && ($user->hasPermission('supply_chain.view') || $user->hasPermission('supply_chain.deliveries.view'))
                && $subject instanceof Delivery,
            PurchaseOrder::class => app(SettingsService::class)->get('modules.purchasing', false) === true
                && ($user->hasPermission('purchasing.view') || $user->hasPermission('purchasing.po.approve'))
                && $subject instanceof PurchaseOrder
                && app(PurchaseOrderAccessPolicy::class)->canView($user, $subject),
            default => ApprovalTypeRegistry::forClass($class) !== null
                && ApprovalTypeRegistry::userCanViewRecord($user, $class, (int) $subject->getKey()),
        };
    }

    private static function userCanViewWorkOrder(User $user, Model $subject): bool
    {
        if (app(SettingsService::class)->get('modules.production', false) !== true) {
            return false;
        }

        if ($user->hasPermission('production.view') && $user->hasPermission('production.work_orders.view')) {
            return true;
        }

        try {
            return app(WorkOrderAssignmentService::class)->historicalCrewQuery($user)
                ->where('work_order_id', $subject->getKey())
                ->exists();
        } catch (HttpException) {
            return false;
        }
    }

    private static function userCanViewMaintenanceWorkOrder(User $user, Model $subject): bool
    {
        return app(SettingsService::class)->get('modules.maintenance', false) === true
            && $user->is_active
            && $subject instanceof MaintenanceWorkOrder
            && ($user->hasPermission('maintenance.view') || MaintenanceWorkAccess::canRead($subject, $user));
    }

    private static function userCanViewLoan(User $user, Model $subject): bool
    {
        $settings = app(SettingsService::class);

        // Employees read their own loans through HR's owner-scoped self-service route.
        return ($settings->get('modules.hr', false) === true
                && (int) $user->employee_id === (int) $subject->employee_id)
            || ($settings->get('modules.loans', false) === true
                && ApprovalTypeRegistry::userCanViewRecord($user, EmployeeLoan::class, (int) $subject->getKey()));
    }

    private static function userCanViewPayslip(User $user, Model $subject): bool
    {
        return app(SettingsService::class)->get('modules.payroll', false) === true
            && $user->is_active
            && $user->hasPermission('payroll.view')
            && $subject instanceof Payroll
            && (int) $user->employee_id === (int) $subject->employee_id;
    }

    private static function userCanViewEmployeeTraining(User $user, Model $subject): bool
    {
        if (app(SettingsService::class)->get('modules.hr', false) !== true
            || ! $user->is_active
            || ! $subject instanceof EmployeeTraining) {
            return false;
        }

        if ((int) $user->employee_id === (int) $subject->employee_id
            && Employee::query()->whereKey($subject->employee_id)->exists()) {
            return true;
        }

        return $user->hasPermission('hr.employees.trainings.view')
            && EmployeeCompetenceScope::employees(Employee::query(), $user)
                ->whereKey($subject->employee_id)
                ->exists();
    }

    private static function userCanViewEmployee(User $user, Model $subject): bool
    {
        if (app(SettingsService::class)->get('modules.hr', false) !== true
            || ! $user->is_active
            || ! $user->hasPermission('hr.employees.view')
            || ! $subject instanceof Employee) {
            return false;
        }

        return DepartmentScope::apply(
            Employee::query()->whereKey($subject->getKey()),
            $user,
            viewAllPermission: 'hr.employees.view_sensitive',
            departmentPermission: 'hr.employees.view',
            deptColumn: 'department_id',
            selfColumn: 'id',
            selfId: $user->employee_id,
        )->exists();
    }

    private static function userCanViewOvertime(User $user, Model $subject, ?string $linkTo): bool
    {
        if (! $user->is_active || ! $subject instanceof OvertimeRequest || $linkTo === null) {
            return false;
        }

        if (str_starts_with($linkTo, '/self-service/overtime/')) {
            return app(SettingsService::class)->get('modules.attendance', false) === true
                && app(SettingsService::class)->get('modules.hr', false) === true
                && (int) $user->employee_id === (int) $subject->employee_id
                && Employee::query()->whereKey($subject->employee_id)->exists();
        }

        if (! str_starts_with($linkTo, '/hr/attendance/overtime/')
            || app(SettingsService::class)->get('modules.attendance', false) !== true
            || ! $user->hasPermission('attendance.view')) {
            return false;
        }

        if (app(OvertimeDecisionPolicy::class)->isAllRecordActor($user)
            || (int) $user->employee_id === (int) $subject->employee_id) {
            return true;
        }

        if (! ApprovalDelegation::canActForPermission($user, 'attendance.ot.approve') || ! $user->employee_id) {
            return false;
        }

        $departmentId = Employee::query()->whereKey($user->employee_id)->value('department_id');

        return $departmentId !== null
            && (int) $departmentId === (int) Employee::query()->whereKey($subject->employee_id)->value('department_id');
    }

    private static function userCanViewAttendance(User $user, Model $subject, ?string $linkTo): bool
    {
        if (! $user->is_active
            || ! $subject instanceof Attendance
            || ! is_string($linkTo)
            || ! str_starts_with($linkTo, '/hr/attendance?missing_time_out=1')
            || app(SettingsService::class)->get('modules.attendance', false) !== true
            || ! $user->hasPermission('attendance.view')) {
            return false;
        }

        if ($user->role?->slug === 'system_admin'
            || $user->hasPermission('attendance.edit')
            || $user->hasPermission('attendance.import')
            || (int) $user->employee_id === (int) $subject->employee_id) {
            return true;
        }

        if (! $user->hasPermission('attendance.ot.approve') || ! $user->employee_id) {
            return false;
        }

        $departmentId = Employee::query()->whereKey($user->employee_id)->value('department_id');

        return $departmentId !== null
            && (int) $departmentId === (int) Employee::query()->whereKey($subject->employee_id)->value('department_id');
    }

    private static function budgetingEnabled(): bool
    {
        $settings = app(SettingsService::class);

        return $settings->get('modules.accounting', false) === true
            && $settings->get('modules.budgeting', false) === true;
    }

    private static function userCanViewBudget(User $user, Model $subject): bool
    {
        if ($user->hasPermission('budgeting.view')) {
            return true;
        }

        return $subject->status === BudgetStatus::Submitted->value
            && (int) $subject->submitted_by !== (int) $user->getKey()
            && ApprovalDelegation::canActForPermission($user, 'budgeting.approve', [(int) $subject->submitted_by])
            && app(BudgetService::class)->hasDifferentRoleFromSubmitter($subject, $user);
    }
}
