<?php

declare(strict_types=1);

use App\Common\Models\ApprovalDelegation;
use App\Common\Models\ScheduledExport;
use App\Common\Services\SettingsService;
use App\Common\Support\ApprovalSourceScope;
use App\Common\Support\ChainDefinitions;
use App\Common\Support\DepartmentScope;
use App\Common\Support\HashIdFilter;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Bill;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\BudgetTransfer;
use App\Modules\Accounting\Models\CreditNote;
use App\Modules\Accounting\Models\Customer;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Vendor;
use App\Modules\Accounting\Services\BudgetService;
use App\Modules\Accounting\Services\BudgetTransferService;
use App\Modules\Assets\Models\Asset;
use App\Modules\Attendance\Models\Attendance;
use App\Modules\Attendance\Models\EmployeeShiftAssignment;
use App\Modules\Attendance\Models\OvertimeRequest;
use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Services\OvertimeDecisionPolicy;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\User;
use App\Modules\B2B\Models\CustomerPortalUser;
use App\Modules\B2B\Models\DeliverySchedule;
use App\Modules\B2B\Models\SupplierPortalUser;
use App\Modules\CRM\Models\CustomerComplaint;
use App\Modules\CRM\Models\PriceAgreement;
use App\Modules\CRM\Models\PriceRequest;
use App\Modules\CRM\Models\Product;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\Dashboard\Services\DashboardLayoutService;
use App\Modules\Forecasting\Models\DemandForecast;
use App\Modules\HR\Models\Clearance;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeTraining;
use App\Modules\HR\Models\JobApplication;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Models\ProfileUpdateRequest;
use App\Modules\HR\Models\SalaryAdjustment;
use App\Modules\HR\Models\Skill;
use App\Modules\HR\Models\Training;
use App\Modules\HR\Services\SeparationService;
use App\Modules\HR\Support\EmployeeCompetenceScope;
use App\Modules\Inventory\Models\GoodsReceiptNote;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\MaterialIssueSlip;
use App\Modules\Inventory\Models\MaterialReviewRecord;
use App\Modules\Inventory\Models\StockCountSession;
use App\Modules\Inventory\Models\TransferOrder;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Loans\Models\EmployeeLoan;
use App\Modules\Loans\Policies\LoanAccessPolicy;
use App\Modules\Maintenance\Models\MaintenanceSchedule;
use App\Modules\Maintenance\Models\MaintenanceWorkOrder;
use App\Modules\Maintenance\Support\MaintenanceWorkAccess;
use App\Modules\MRP\Models\Bom;
use App\Modules\MRP\Models\Machine;
use App\Modules\MRP\Models\Mold;
use App\Modules\MRP\Models\MrpPlan;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Production\Models\ProductionSchedule;
use App\Modules\Production\Models\ProductRouting;
use App\Modules\Production\Models\WorkOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequest;
use App\Modules\Purchasing\Models\RequestForQuote;
use App\Modules\Purchasing\Policies\PurchaseOrderAccessPolicy;
use App\Modules\Purchasing\Policies\PurchaseRequestAccessPolicy;
use App\Modules\Purchasing\Policies\RequestForQuoteAccessPolicy;
use App\Modules\Quality\Models\CalibrationRecord;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionSpec;
use App\Modules\Quality\Models\NcrTemplate;
use App\Modules\Quality\Models\NonConformanceReport;
use App\Modules\Quality\Models\PpapSubmission;
use App\Modules\ReturnManagement\Models\ReturnCase;
use App\Modules\ReturnManagement\Models\ReturnRequest;
use App\Modules\ReturnManagement\Support\ReturnManagementAccess;
use App\Modules\SupplyChain\Models\Delivery;
use App\Modules\SupplyChain\Models\Shipment;
use App\Modules\SupplyChain\Models\ShipmentLot;
use App\Modules\SupplyChain\Models\Vehicle;
use Illuminate\Support\Facades\Broadcast;

/**
 * Reverb / private channel routes.
 *
 * The SPA subscribes via Laravel Echo with cookie-based auth (Sanctum
 * stateful — withCredentials: true). The /broadcasting/auth endpoint is
 * already wired by Laravel; the closures below decide who can subscribe
 * to which private channel.
 *
 * Sprint 6 channels (production), Sprint 8 channels (payroll, inventory,
 * maintenance, user notifications).
 */
$canViewSubject = static function (User $user, string $hashId, string $permission, string $model): bool {
    if (! $user->hasPermission($permission)) {
        return false;
    }

    $id = HashIdFilter::decode($hashId, $model);
    if ($id === null) {
        return false;
    }

    return ApprovalSourceScope::hasScopeFor($model, $user)
        ? ApprovalSourceScope::visibleIds($model, [$id], $user) !== []
        : $model::query()->whereKey($id)->exists();
};

// Scheduled exports are visible only to their owner or audit-log administrators,
// matching ScheduledExportController::authorizeRow() and index() scope.
Broadcast::channel('scheduled-export.{hashId}', function (User $user, string $hashId): bool {
    $id = ScheduledExport::tryDecodeHash($hashId);
    $row = $id === null ? null : ScheduledExport::withTrashed()->find($id);

    return $row !== null
        && ($user->can('admin.audit_logs.view') || (int) $row->owner_id === (int) $user->id);
});

Broadcast::channel('entity.scheduled_export.user.{userHashId}', fn (User $user, string $userHashId): bool => hash_equals((string) $user->hash_id, $userHashId));

Broadcast::channel('entity.scheduled_export.admin', fn (User $user): bool => $user->can('admin.audit_logs.view'));

// Import batch history and rollback are both restricted to import managers.
Broadcast::channel('entity.admin_import_batch.visible', fn (User $user): bool => $user->is_active && $user->hasPermission('admin.import.manage'));

// Invite refreshes are restricted to the same permission as the list API.
Broadcast::channel('entity.user_invite.visible', fn (User $user): bool => $user->is_active && $user->hasPermission('auth.users.invite'));

// Notification settings are private to the matching active principal.
Broadcast::channel('user.notification-preferences.{userHashId}', fn (User $user, string $userHashId): bool => $user->is_active
        && $user->hasPermission('notifications.preferences.manage')
        && hash_equals((string) $user->hash_id, $userHashId));

// Display preferences are private to the active authenticated user.
Broadcast::channel('user.preferences.{userHashId}', fn (User $user, string $userHashId): bool => $user->is_active && hash_equals((string) $user->hash_id, $userHashId));

// Inquiry messages are available only to CRM inbox readers; event data is a refetch signal.
Broadcast::channel('entity.contact_inquiry.visible', fn (User $user): bool => $user->is_active
        && app(SettingsService::class)->requiredBool('modules.crm')
        && $user->hasPermission('crm.inquiries.view'));

// Asset pings contain no record data. Detail subscriptions use the same
// `assets.view` permission and active-record scope as GET /assets/{asset}.
Broadcast::channel('asset.{hashId}', function (User $user, string $hashId): bool {
    $id = HashIdFilter::decode($hashId, Asset::class);

    return app(SettingsService::class)->requiredBool('modules.assets')
        && $user->hasPermission('assets.view')
        && $id !== null
        && Asset::query()->whereKey($id)->exists();
});

Broadcast::channel('entity.asset.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.assets')
    && $user->hasPermission('assets.view'));

// Leave refresh signals carry no record metadata. Detail auth mirrors the
// leave API's employee/department/HR row scope; list reads remain scoped by API.
Broadcast::channel('leave-request.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.leave') || ! $user->hasPermission('leave.view')) {
        return false;
    }

    $id = HashIdFilter::decode($hashId, LeaveRequest::class);

    return $id !== null && ApprovalSourceScope::visibleIds(LeaveRequest::class, [$id], $user) !== [];
});

Broadcast::channel('entity.leave_request.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.leave')
    && $user->hasPermission('leave.view'));

Broadcast::channel('entity.leave_type.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.leave')
    && $user->hasPermission('leave.view'));

// Holiday refresh signals carry no date/name/type. Subscribers must be able
// to read an affected attendance, calendar, or HR dashboard response.
Broadcast::channel('entity.holiday.visible', fn (User $user): bool => (app(SettingsService::class)->requiredBool('modules.attendance')
        && ($user->hasPermission('attendance.view')
            || $user->hasPermission('attendance.edit')
            || $user->hasPermission('attendance.holidays.manage')))
    || $user->hasPermission('calendar.view')
    || $user->hasPermission('dashboard.hr.view')
    // The authenticated employee dashboard includes next_holiday and has no
    // extra permission gate; match that API scope with a refresh-only ping.
    || $user->employee_id !== null);

// Shift payloads are refresh-only. Linked employees can receive the broad
// shift signal and refetch only their own self-service shift API; HR readers
// must have the same Attendance grant as the shift list endpoint.
Broadcast::channel('entity.shift.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.attendance')
    && ($user->hasPermission('attendance.edit')
        || $user->hasPermission('attendance.shifts.manage')));

Broadcast::channel('entity.shift.employee', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.attendance')
    && $user->employee_id !== null
    && Employee::query()->whereKey($user->employee_id)->exists());

Broadcast::channel('shift.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.attendance')
        || (! $user->hasPermission('attendance.edit') && ! $user->hasPermission('attendance.shifts.manage'))) {
        return false;
    }

    $id = HashIdFilter::decode($hashId, Shift::class);

    return $id !== null && Shift::query()->whereKey($id)->exists();
});

Broadcast::channel('entity.employee_shift_assignment.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.attendance')
    && $user->hasPermission('attendance.shifts.manage'));

Broadcast::channel('shift-assignment.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.attendance')
        || ! $user->hasPermission('attendance.shifts.manage')) {
        return false;
    }

    $id = HashIdFilter::decode($hashId, EmployeeShiftAssignment::class);

    return $id !== null && EmployeeShiftAssignment::query()->whereKey($id)->exists();
});

// Internal shift managers/editors can watch an employee shift the API allows
// them to read. An employee account is limited to its own linked employee.
Broadcast::channel('attendance.employee-shift.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.attendance')) {
        return false;
    }

    $employeeId = HashIdFilter::decode($hashId, Employee::class);
    if ($employeeId === null) {
        return false;
    }

    if (! Employee::query()->whereKey($employeeId)->exists()) {
        return false;
    }

    return (int) $user->employee_id === $employeeId
        || $user->hasPermission('attendance.edit')
        || $user->hasPermission('attendance.shifts.manage');
});

// Holiday changes affect the crew-availability calculation in readiness.
// The signal is content-free and the channel matches the readiness API grant.
Broadcast::channel('production.readiness', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.production')
    && $user->hasPermission('production.work_orders.lifecycle'));

// Balance refreshes contain no employee/type identifiers; every refetch is
// authorized by the matching balance API route and its own employee scope.
Broadcast::channel('entity.leave_balance.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.leave')
    && ($user->hasPermission('leave.view')
        || $user->hasPermission('leave.approve_dept')
        || $user->hasPermission('leave.approve_hr')));

// Attendance record pings are content-free. The channel mirrors the API's
// attendance.view grant and row-scope filters, including archived-row deletes.
Broadcast::channel('attendance.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.attendance')
        || ! $user->hasPermission('attendance.view')) {
        return false;
    }

    $id = HashIdFilter::decode($hashId, Attendance::class);

    return $id !== null
        && Attendance::withTrashed()->whereKey($id)->exists()
        && app(AttendanceService::class)->listQuery([], $user)->whereKey($id)->exists();
});

// Overtime pings carry no request fields. Detail visibility reuses the same
// employee/department/HR row scope as the list and detail API; the list
// channel requires the attendance list grant and each refetch remains scoped.
Broadcast::channel('overtime-request.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.attendance')
        || ! $user->hasPermission('attendance.view')) {
        return false;
    }

    $id = HashIdFilter::decode($hashId, OvertimeRequest::class);

    return $id !== null
        && app(OvertimeDecisionPolicy::class)
            ->visibleTo(OvertimeRequest::query(), $user)
            ->whereKey($id)
            ->exists();
});

Broadcast::channel('entity.overtime_request.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.attendance')
    && ($user->hasPermission('attendance.view') || $user->hasPermission('attendance.ot.approve')));

// Self-service Overtime refreshes use a dedicated one-user channel so the
// personal API does not require access to the company Attendance list.
Broadcast::channel('overtime.user.{userHashId}', fn (User $user, string $userHashId): bool => app(SettingsService::class)->requiredBool('modules.hr')
    && hash_equals((string) $user->hash_id, $userHashId));

// Loan payloads are refresh-only. Record subscriptions use the same row policy
// as the detail API; list pings expose no row metadata and refetch through the
// caller's existing scoped loan API.
Broadcast::channel('employee-loan.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.loans') || ! $user->hasPermission('loans.view')) {
        return false;
    }

    $id = HashIdFilter::decode($hashId, EmployeeLoan::class);
    $loan = $id === null ? null : EmployeeLoan::query()->find($id);

    return $loan !== null && app(LoanAccessPolicy::class)->canView($user, $loan);
});

Broadcast::channel('entity.employee_loan.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.loans')
    && $user->hasPermission('loans.view'));

// Self-service Loan reads are scoped to the authenticated employee. This
// channel is exact-user only and sends no personal loan information.
Broadcast::channel('loan.user.{userHashId}', fn (User $user, string $userHashId): bool => app(SettingsService::class)->requiredBool('modules.hr')
    && $user->employee_id !== null
    && hash_equals((string) $user->hash_id, $userHashId));

// Government table pings contain no bracket or agency values. The channel
// uses the exact permission protecting the table list and management APIs.
Broadcast::channel('entity.government_table.visible', fn (User $user): bool => $user->hasPermission('admin.gov_tables.manage'));

// Department pings contain no department fields; visibility matches the index
// endpoint, which allows either HR department or directory readers.
Broadcast::channel('entity.department.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.hr')
    && ($user->hasPermission('hr.departments.view') || $user->hasPermission('hr.directory.view')));

// Position pings contain no title or compensation details and match the index API gate.
Broadcast::channel('entity.position.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.hr')
    && $user->hasPermission('hr.positions.view'));

// A Position record signal also refreshes linked Employee details. The
// employee-only grant is authorized only when DepartmentScope permits at
// least one Employee currently linked to this exact Position.
Broadcast::channel('position.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.hr') || ! $user->is_active) {
        return false;
    }

    $positionId = HashIdFilter::decode($hashId, Position::class);
    if ($positionId === null || ! Position::query()->whereKey($positionId)->exists()) {
        return false;
    }

    if ($user->hasPermission('hr.positions.view')) {
        return true;
    }

    return $user->hasPermission('hr.employees.view')
        && DepartmentScope::apply(
            Employee::query()->where('position_id', $positionId),
            $user,
            viewAllPermission: 'hr.employees.view_sensitive',
            departmentPermission: 'hr.employees.view',
            deptColumn: 'department_id',
            selfColumn: 'id',
            selfId: $user->employee_id,
        )->exists();
});

// Employee refresh signals contain no employee fields. Company-wide list
// viewers use the broad channel; department-scoped viewers subscribe only to
// their own department, while a linked employee has an exact private channel.
Broadcast::channel('entity.employee.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.hr')
    && $user->hasPermission('hr.employees.view')
    && $user->hasPermission('hr.employees.view_sensitive'));

Broadcast::channel('entity.employee.department.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.hr')) {
        return false;
    }

    $departmentId = HashIdFilter::decode($hashId, Department::class);
    if ($departmentId === null) {
        return false;
    }

    return $user->hasPermission('hr.employees.view')
        && ($user->hasPermission('hr.employees.view_sensitive')
            || DepartmentScope::departmentIdFor($user) === $departmentId);
});

Broadcast::channel('employee.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.hr')
        || ! $user->hasPermission('hr.employees.view')) {
        return false;
    }

    $employeeId = HashIdFilter::decode($hashId, Employee::class);
    if ($employeeId === null) {
        return false;
    }

    return DepartmentScope::apply(
        Employee::query()->whereKey($employeeId),
        $user,
        viewAllPermission: 'hr.employees.view_sensitive',
        departmentPermission: 'hr.employees.view',
        deptColumn: 'department_id',
        selfColumn: 'id',
        selfId: $user->employee_id,
    )->exists();
});

// Employee document pings contain no filenames or document fields. The
// channel mirrors both employee document-list APIs' permission and row scope.
Broadcast::channel('employee.documents.{hashId}', function (User $user, string $hashId): bool {
    if (! $user->is_active || ! $user->hasPermission('hr.employees.documents.view')) {
        return false;
    }

    $employeeId = HashIdFilter::decode($hashId, Employee::class);
    if ($employeeId === null) {
        return false;
    }

    return DepartmentScope::apply(
        Employee::query()->whereKey($employeeId),
        $user,
        viewAllPermission: 'hr.employees.view_sensitive',
        departmentPermission: 'hr.employees.view',
        deptColumn: 'department_id',
        selfColumn: 'id',
        selfId: $user->employee_id,
    )->exists();
});

Broadcast::channel('employee.user.{userHashId}', function (User $user, string $userHashId): bool {
    $channelUserId = User::tryDecodeHash($userHashId);

    return app(SettingsService::class)->requiredBool('modules.hr')
        && $channelUserId !== null
        && (int) $user->getKey() === $channelUserId;
});

// Salary-adjustment signals contain no pay values. Their private channels use
// the same feature and view grant as the maker-checker list/show APIs.
Broadcast::channel('entity.salary_adjustment.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.hr')
    && $user->hasPermission('hr.salary_adjustments.view'));

Broadcast::channel('hr.salary-adjustment.{hashId}', function (User $user, string $hashId): bool {
    $id = HashIdFilter::decode($hashId, SalaryAdjustment::class);

    return app(SettingsService::class)->requiredBool('modules.hr')
        && $user->hasPermission('hr.salary_adjustments.view')
        && $id !== null
        && SalaryAdjustment::query()->whereKey($id)->exists();
});

// Training list signals contain no record or actor identifiers. The detail
// channel applies the same catalog permission and active-record scope as show.
Broadcast::channel('entity.training.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.hr')
    && $user->hasPermission('hr.trainings.view'));

Broadcast::channel('training.{hashId}', function (User $user, string $hashId): bool {
    $id = HashIdFilter::decode($hashId, Training::class);

    return app(SettingsService::class)->requiredBool('modules.hr')
        && $user->hasPermission('hr.trainings.view')
        && $id !== null
        && Training::query()->whereKey($id)->exists();
});

// Skill catalog refreshes carry metadata only; catalog readers refetch through
// the same HR feature and permission boundary as the index/show API.
Broadcast::channel('entity.skill.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.hr')
    && $user->is_active
    && $user->hasPermission('hr.trainings.view'));

// Recruitment payloads are opaque refresh signals; these channels match the
// permission required by the internal posting/application read endpoints.
foreach (['job_posting' => JobPosting::class, 'job_application' => JobApplication::class] as $entityType => $modelClass) {
    Broadcast::channel("entity.{$entityType}.visible", fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.hr')
        && app(SettingsService::class)->requiredBool('modules.recruitment')
        && $user->is_active
        && $user->hasPermission('hr.recruitment.view'));

    Broadcast::channel("{$entityType}.{hashId}", function (User $user, string $hashId) use ($modelClass): bool {
        $id = HashIdFilter::decode($hashId, $modelClass);

        return app(SettingsService::class)->requiredBool('modules.hr')
            && app(SettingsService::class)->requiredBool('modules.recruitment')
            && $user->is_active
            && $user->hasPermission('hr.recruitment.view')
            && $id !== null
            && ($modelClass === JobPosting::class
                ? JobPosting::withTrashed()->whereKey($id)->exists()
                : JobApplication::query()->whereKey($id)->exists());
    });
}

Broadcast::channel('skill.{hashId}', function (User $user, string $hashId): bool {
    $id = HashIdFilter::decode($hashId, Skill::class);

    return app(SettingsService::class)->requiredBool('modules.hr')
        && $user->is_active
        && $user->hasPermission('hr.trainings.view')
        && $id !== null
        && Skill::query()->whereKey($id)->exists();
});

// Profile-request pings include opaque row references only. Company-wide HR,
// department HR, Finance bank-review, and requester channels mirror their API
// visibility scopes independently.
Broadcast::channel('profile-update-request.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.hr') || ! $user->is_active) {
        return false;
    }

    $requestId = HashIdFilter::decode($hashId, ProfileUpdateRequest::class);
    $request = $requestId === null ? null : ProfileUpdateRequest::query()->find($requestId);
    if ($request === null) {
        return false;
    }

    if ((int) $request->requested_by === (int) $user->id) {
        return $user->employee_id !== null
            && (int) $request->employee_id === (int) $user->employee_id
            && Employee::query()->whereKey($user->employee_id)->exists();
    }

    if ($user->hasPermission('hr.profile_updates.finance_review')
        && (bool) $request->requires_finance
        && in_array($request->status, ['pending_finance', 'approved', 'rejected'], true)) {
        return true;
    }

    $visible = ProfileUpdateRequest::query()->whereKey($request->getKey());
    DepartmentScope::apply(
        $visible,
        $user,
        viewAllPermission: 'hr.employees.view_sensitive',
        departmentPermission: 'hr.employees.view',
        deptColumn: 'department_id',
        deptRelation: 'employee',
    );

    return $user->hasPermission('hr.employees.view') && $visible->exists();
});

Broadcast::channel('entity.profile_update_request.hr.all', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.hr')
    && $user->is_active
    && $user->hasPermission('hr.employees.view')
    && $user->hasPermission('hr.employees.view_sensitive'));

Broadcast::channel('entity.profile_update_request.hr.department.{departmentHashId}', function (User $user, string $departmentHashId): bool {
    $departmentId = HashIdFilter::decode($departmentHashId, Department::class);

    return app(SettingsService::class)->requiredBool('modules.hr')
        && $user->is_active
        && $user->hasPermission('hr.employees.view')
        && $departmentId !== null
        && ($user->hasPermission('hr.employees.view_sensitive')
            || DepartmentScope::departmentIdFor($user) === $departmentId);
});

Broadcast::channel('entity.profile_update_request.finance', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.hr')
    && $user->is_active
    && $user->hasPermission('hr.profile_updates.finance_review'));

Broadcast::channel('profile-update-request.user.{userHashId}', fn (User $user, string $userHashId): bool => app(SettingsService::class)->requiredBool('modules.hr')
    && $user->is_active
    && hash_equals((string) $user->hash_id, $userHashId));

// The list channel is only a content-free refresh cue. Record subscriptions
// enforce the same employee row scope as HR show/index APIs, or exact
// self-service ownership.
Broadcast::channel('entity.employee_training.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.hr')
    && $user->hasPermission('hr.employees.trainings.view'));

// Onboarding contains only progress timestamps and is available to HR editors.
// The channel matches the read/write permission on the onboarding endpoints.
Broadcast::channel('employee.onboarding.{employeeHashId}', function (User $user, string $employeeHashId): bool {
    $employeeId = HashIdFilter::decode($employeeHashId, Employee::class);

    return app(SettingsService::class)->requiredBool('modules.hr')
        && $user->is_active
        && $user->hasPermission('hr.employees.edit')
        && $employeeId !== null
        && Employee::query()->whereKey($employeeId)->exists();
});

Broadcast::channel('employee_training.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.hr')) {
        return false;
    }

    $id = HashIdFilter::decode($hashId, EmployeeTraining::class);
    $record = $id === null ? null : EmployeeTraining::query()->find($id);
    if ($record === null) {
        return false;
    }

    if ($user->employee_id !== null && (int) $user->employee_id === (int) $record->employee_id) {
        return true;
    }

    return $user->hasPermission('hr.employees.trainings.view')
        && EmployeeCompetenceScope::employees(Employee::query(), $user)
            ->whereKey($record->employee_id)
            ->exists();
});

// Employee skill refreshes carry no employee ID on the permission-wide list
// channel; the exact collection channel applies the EmployeeSkill list scope.
Broadcast::channel('entity.employee_skill.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.hr')
    && $user->is_active
    && ($user->hasPermission('hr.trainings.view')
        || $user->hasPermission('hr.employees.trainings.view')));

Broadcast::channel('employee.skills.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.hr')
        || ! $user->is_active
        || ! $user->hasPermission('hr.employees.trainings.view')) {
        return false;
    }

    $employeeId = HashIdFilter::decode($hashId, Employee::class);
    if ($employeeId === null) {
        return false;
    }

    return EmployeeCompetenceScope::employees(Employee::query(), $user)
        ->whereKey($employeeId)
        ->exists();
});

// De minimis pings carry no employee or compensation data; the list permission
// and Payroll feature flag match the API route that authorizes every refetch.
Broadcast::channel('entity.payroll_de_minimis.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.payroll')
    && $user->hasPermission('payroll.adjustments.create'));

// Anomaly payloads identify only the affected period so a reviewer refetches
// that period's list and state through the same API permission boundary.
Broadcast::channel('entity.payroll_anomaly.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.payroll')
    && $user->hasPermission('payroll.anomalies.review'));

// Account refresh pings disclose no chart fields; access matches the
// accounting.coa.view API permission.
Broadcast::channel('accounting.account.{hashId}', function (User $user, string $hashId): bool {
    $id = HashIdFilter::decode($hashId, Account::class);

    return app(SettingsService::class)->requiredBool('modules.accounting')
        && $user->hasPermission('accounting.coa.view')
        && $id !== null
        && Account::query()->whereKey($id)->exists();
});

Broadcast::channel('entity.account.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.accounting')
    && $user->hasPermission('accounting.coa.view'));

// Vendor refreshes contain only a content-free signal; access matches the
// accounting.vendors.view API permission.
Broadcast::channel('accounting.vendor.{hashId}', function (User $user, string $hashId): bool {
    $id = HashIdFilter::decode($hashId, Vendor::class);

    return app(SettingsService::class)->requiredBool('modules.accounting')
        && $user->hasPermission('accounting.vendors.view')
        && $id !== null
        && Vendor::query()->whereKey($id)->exists();
});

Broadcast::channel('entity.vendor.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.accounting')
    && $user->hasPermission('accounting.vendors.view'));

// Budget-transfer detail access mirrors GET /budget-transfers/{id}: viewers
// may read all rows, while approvers may read only a transfer they can decide.
Broadcast::channel('accounting.budget-transfer.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.budgeting')) {
        return false;
    }

    $id = HashIdFilter::decode($hashId, BudgetTransfer::class);
    $transfer = $id !== null ? BudgetTransfer::query()->with('requester')->find($id) : null;
    if ($transfer === null) {
        return false;
    }

    if ($user->hasPermission('budgeting.view')) {
        return true;
    }

    return ApprovalDelegation::canActForPermission($user, 'budgeting.approve', [(int) $transfer->requested_by])
        && app(BudgetTransferService::class)->canApprove($transfer, $user);
});

// Budget detail authorization mirrors GET /budgets/{budget}: readers see all
// rows; approval-only users see only submitted budgets they may review.
Broadcast::channel('accounting.budget.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.budgeting')) {
        return false;
    }

    $id = HashIdFilter::decode($hashId, Budget::class);
    $budget = $id !== null ? Budget::query()->with('submittedBy')->find($id) : null;
    if ($budget === null) {
        return false;
    }

    return $user->hasPermission('budgeting.view')
        || app(BudgetService::class)->canReview($budget, $user);
});

// Credit-note refresh pings disclose no financial fields; record and list access
// match the accounting.credit_notes.view API permission.
Broadcast::channel('accounting.credit-note.{hashId}', function (User $user, string $hashId): bool {
    $id = HashIdFilter::decode($hashId, CreditNote::class);

    return app(SettingsService::class)->requiredBool('modules.accounting')
        && $user->hasPermission('accounting.credit_notes.view')
        && $id !== null
        && CreditNote::query()->whereKey($id)->exists();
});

// Ledger notices disclose no record fields. Record access matches the withTrashed
// JournalEntry API route; list access matches its accounting.journal.view gate.
Broadcast::channel('accounting.journal-entry.{hashId}', function (User $user, string $hashId): bool {
    $id = HashIdFilter::decode($hashId, JournalEntry::class);

    return app(SettingsService::class)->requiredBool('modules.accounting')
        && $user->hasPermission('accounting.journal.view')
        && $id !== null
        && JournalEntry::withTrashed()->whereKey($id)->exists();
});

Broadcast::channel('production.dashboard', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.production')
    && (($user->hasPermission('production.view') && $user->hasPermission('production.dashboard.view'))
        || $user->hasPermission('dashboard.plant_manager.view')
        || $user->hasPermission('dashboard.ppc.view')
        || $user->hasPermission('dashboard.company.view'))
);

Broadcast::channel('quality.dashboard', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.production')
    && $user->hasPermission('dashboard.quality.view')
);

Broadcast::channel('dashboard.plant_manager', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.production')
    && $user->hasPermission('dashboard.plant_manager.view')
);

// Mirrors GET /dashboards/ppc: the signal is content-free and viewers refetch
// the dashboard through the permission-gated API.
Broadcast::channel('dashboard.ppc', fn (User $user): bool => $user->hasPermission('dashboard.ppc.view'));

Broadcast::channel('production.wo.{hashId}', function (User $user, string $hashId) use ($canViewSubject): bool {
    return app(SettingsService::class)->requiredBool('modules.production')
        && $user->hasPermission('production.view')
        && $canViewSubject($user, $hashId, 'production.work_orders.view', WorkOrder::class);
});

// Content-free work-order refresh for one shop-floor account. The client
// refetches only the authenticated employee's existing self-service API scope.
Broadcast::channel('production.operator.{userHashId}', function (User $user, string $userHashId): bool {
    $channelUserId = User::tryDecodeHash($userHashId);

    return app(SettingsService::class)->requiredBool('modules.production')
        && $user->is_active
        && $channelUserId !== null
        && (int) $user->getKey() === $channelUserId
        && $user->employee_id !== null
        && Employee::query()->whereKey($user->employee_id)->where('status', 'active')
            ->whereHas('department', fn ($query) => $query->where('code', 'PROD')->where('is_active', true))
            ->exists();
});

Broadcast::channel('production.machine.{hashId}', fn (User $user, string $hashId): bool => app(SettingsService::class)->requiredBool('modules.mrp')
    && $canViewSubject($user, $hashId, 'mrp.machines.view', Machine::class)
);

Broadcast::channel('production.mold.{hashId}', fn (User $user, string $hashId): bool => app(SettingsService::class)->requiredBool('modules.mrp')
    && $canViewSubject($user, $hashId, 'mrp.molds.view', Mold::class)
);

Broadcast::channel('production.bom.{hashId}', fn (User $user, string $hashId): bool => app(SettingsService::class)->requiredBool('modules.mrp')
    && $canViewSubject($user, $hashId, 'mrp.boms.view', Bom::class)
);

Broadcast::channel('mrp.plan.{hashId}', fn (User $user, string $hashId): bool => app(SettingsService::class)->requiredBool('modules.mrp')
    && $canViewSubject($user, $hashId, 'mrp.plans.view', MrpPlan::class)
);

Broadcast::channel('entity.bom.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.mrp')
    && $user->hasPermission('mrp.boms.view'));

// Product BOM panels are rendered only to BOM viewers; keep the signal within
// the same module permission used by GET /mrp/products/{product}/bom.
Broadcast::channel('mrp.product-bom.{hashId}', function (User $user, string $hashId): bool {
    $id = HashIdFilter::decode($hashId, Product::class);

    return app(SettingsService::class)->requiredBool('modules.mrp')
        && $user->hasPermission('mrp.boms.view')
        && $id !== null
        && Product::query()->whereKey($id)->exists();
});

Broadcast::channel('entity.mold.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.mrp')
    && $user->hasPermission('mrp.molds.view'));

Broadcast::channel('production_schedule.{hashId}', fn (User $user, string $hashId): bool => app(SettingsService::class)->requiredBool('modules.mrp')
    && $canViewSubject($user, $hashId, 'production.schedule.view', ProductionSchedule::class));

/* Sprint 8 — Task 78. New channels. */

// Payroll period progress contains company-wide run metadata.
Broadcast::channel('payroll.period.{hashId}', fn (User $user, string $hashId): bool => app(SettingsService::class)->requiredBool('modules.payroll')
    && $canViewSubject($user, $hashId, 'payroll.periods.view', PayrollPeriod::class)
);

Broadcast::channel('payroll.adjustment.{hashId}', fn (User $user, string $hashId): bool => app(SettingsService::class)->requiredBool('modules.payroll')
    && $canViewSubject($user, $hashId, 'payroll.adjustments.view', PayrollAdjustment::class)
);

// Clearance refreshes carry no checklist or final-pay values. The record
// channel uses the same department scope as SeparationService::show().
Broadcast::channel('clearance.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.hr') || ! $user->hasPermission('hr.separation.view')) {
        return false;
    }

    $id = HashIdFilter::decode($hashId, Clearance::class);
    $clearance = $id === null ? null : Clearance::query()->find($id);

    return $clearance !== null && app(SeparationService::class)->canView($clearance, $user);
});

// List refreshes are delivered only to active users authorized to read this
// clearance under the same department scope; this user channel carries a ping.
Broadcast::channel('hr.clearance.user.{userId}', function (User $user, string $userId): bool {
    $decodedUserId = User::tryDecodeHash($userId);

    return app(SettingsService::class)->requiredBool('modules.hr')
        && $user->is_active
        && $user->hasPermission('hr.separation.view')
        && $decodedUserId !== null
        && (int) $user->getKey() === $decodedUserId;
});

// Dashboard readers may see role-scoped widgets without being able to open
// every maintenance record. This channel carries only a refresh signal.
Broadcast::channel('maintenance.dashboard', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.maintenance')
    && ($user->hasPermission('maintenance.view') || $user->hasPermission('dashboard.maintenance.view'))
);

Broadcast::channel('maintenance_work_order.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.maintenance')) {
        return false;
    }

    $id = HashIdFilter::decode($hashId, MaintenanceWorkOrder::class);
    $order = $id === null ? null : MaintenanceWorkOrder::query()->find($id);

    return $order !== null
        && ($user->can('maintenance.view') || MaintenanceWorkAccess::canRead($order, $user));
});

Broadcast::channel('maintenance_schedule.{hashId}', fn (User $user, string $hashId): bool => app(SettingsService::class)->requiredBool('modules.maintenance')
    && $canViewSubject($user, $hashId, 'maintenance.view', MaintenanceSchedule::class));

Broadcast::channel('entity.maintenance_schedule.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.maintenance')
    && $user->hasPermission('maintenance.view'));

Broadcast::channel('maintenance.user.{userHashId}', function (User $user, string $userHashId): bool {
    $userId = User::tryDecodeHash($userHashId);

    return app(SettingsService::class)->requiredBool('modules.maintenance')
        && $user->is_active
        && $user->hasPermission('maintenance.work.assigned')
        && $userId !== null
        && (int) $user->getKey() === $userId;
});

// Stock events carry only a refresh signal; clients refetch through APIs
// protected by the same inventory permission and module switch.
Broadcast::channel('dashboard.warehouse', fn (User $user): bool => $user->hasPermission('dashboard.warehouse.view'));

Broadcast::channel('inventory.dashboard', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.inventory')
    && $user->hasPermission('inventory.view')
);

// Warehouse layout events carry refresh metadata only. Authorization mirrors
// one of the protected tree, map or warehouse-dashboard read scopes; refetches
// are independently checked by those APIs.
Broadcast::channel('inventory.warehouse-layout', function (User $user): bool {
    $canReadInventoryTree = app(SettingsService::class)->requiredBool('modules.inventory')
        && ($user->hasPermission('inventory.view') || $user->hasPermission('inventory.warehouse.manage'));
    $canReadReturnLocations = app(SettingsService::class)->requiredBool('modules.return_management')
        && ($user->hasPermission('return_management.receive')
            || $user->hasPermission('return_management.dispose')
            || $user->hasPermission('return_management.complete'));

    return $canReadInventoryTree || $canReadReturnLocations || $user->hasPermission('dashboard.warehouse.view');
});

// Transfer-order pings contain no stock or order fields. Detail and list
// subscriptions match the API's inventory.adjust read gate.
Broadcast::channel('inventory.transfer-order.{hashId}', function (User $user, string $hashId): bool {
    $id = HashIdFilter::decode($hashId, TransferOrder::class);

    return app(SettingsService::class)->requiredBool('modules.inventory')
        && $user->hasPermission('inventory.adjust')
        && $id !== null
        && TransferOrder::query()->whereKey($id)->exists();
});

Broadcast::channel('entity.transfer_order.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.inventory')
    && $user->hasPermission('inventory.adjust'));

// Adjustment list pings match the list endpoint's permission_any gate and
// carry only opaque refresh metadata; every viewer refetches its own API rows.
Broadcast::channel('entity.stock_adjustment.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.inventory')
    && $user->is_active
    && ($user->hasPermission('inventory.adjust') || $user->hasPermission('inventory.adjust.approve')));

// Item change signals contain no item fields. List subscribers match one of
// the API permissions used by the item index/catalog endpoints; record access
// follows the item detail endpoint's stricter inventory.view gate.
Broadcast::channel('entity.item_cost_review.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.inventory')
    && $user->is_active
    && $user->hasPermission('inventory.costs.review'));

// MRB refreshes carry no disposition or inventory values. The list channel
// matches the API's inventory.mrb.view gate; detail authorization also checks
// that the opaque record hash resolves to a current MRB row.
Broadcast::channel('entity.material_review_record.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.inventory')
    && $user->is_active
    && $user->hasPermission('inventory.mrb.view'));

Broadcast::channel('inventory.mrb.{hashId}', function (User $user, string $hashId): bool {
    $id = HashIdFilter::decode($hashId, MaterialReviewRecord::class);

    return app(SettingsService::class)->requiredBool('modules.inventory')
        && $user->is_active
        && $user->hasPermission('inventory.mrb.view')
        && $id !== null
        && MaterialReviewRecord::query()->whereKey($id)->exists();
});

// Material-issue pings disclose no slip or stock fields. Reader scope matches
// GET /inventory/material-issues; record subscriptions also require a live row.
Broadcast::channel('entity.material_issue_slip.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.inventory')
    && $user->is_active
    && $user->hasPermission('inventory.issue.create'));

Broadcast::channel('inventory.material-issue.{hashId}', function (User $user, string $hashId): bool {
    $id = HashIdFilter::decode($hashId, MaterialIssueSlip::class);

    return app(SettingsService::class)->requiredBool('modules.inventory')
        && $user->is_active
        && $user->hasPermission('inventory.issue.create')
        && $id !== null
        && MaterialIssueSlip::query()->whereKey($id)->exists();
});

Broadcast::channel('entity.item.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.inventory')
    && ($user->hasPermission('inventory.view')
        || $user->hasPermission('purchasing.pr.create')
        || $user->hasPermission('mrp.boms.manage')));

// Warehouse map/bin responses require this same Inventory permission. The
// event body is refresh-only and stock details are refetched through the API.
Broadcast::channel('entity.warehouse_map.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.inventory')
    && $user->hasPermission('inventory.warehouse.manage'));

Broadcast::channel('inventory.item.{hashId}', function (User $user, string $hashId): bool {
    $id = HashIdFilter::decode($hashId, Item::class);

    return app(SettingsService::class)->requiredBool('modules.inventory')
        && $user->hasPermission('inventory.view')
        && $id !== null
        && Item::query()->whereKey($id)->exists();
});

// RFQ detail refresh payloads are empty signals; the API resource applies the
// same creator/requester/permission scope and controls sealed quotation data.
Broadcast::channel('rfq.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.purchasing')) {
        return false;
    }

    $id = RequestForQuote::tryDecodeHash($hashId);
    $rfq = $id === null ? null : RequestForQuote::query()->find($id);

    return $rfq !== null && app(RequestForQuoteAccessPolicy::class)->canSee($user, $rfq);
});

// Per-user notifications channel — backs the SPA bell + toast updates.
// Laravel's default convention is `App.Models.User.{id}` for notifiable
// listeners; we use the explicit `user.{id}` channel for clarity.
Broadcast::channel('user.{userId}', fn (User $user, string $userId): bool => $user->hasPermission('notifications.view')
    && $user->id === User::tryDecodeHash($userId)
);

// Routing payloads contain no process definition; readers refetch through the
// same permission-gated API used by both the list and routing editor.
Broadcast::channel('entity.production_routing.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.production')
    && $user->is_active
    && $user->hasPermission('production.view')
    && $user->hasPermission('production.routings.view'));

Broadcast::channel('production.routing.{hashId}', function (User $user, string $hashId): bool {
    $id = HashIdFilter::decode($hashId, ProductRouting::class);

    return app(SettingsService::class)->requiredBool('modules.production')
        && $user->is_active
        && $user->hasPermission('production.view')
        && $user->hasPermission('production.routings.view')
        && $id !== null
        && ProductRouting::query()->whereKey($id)->exists();
});

// Driver delivery refreshes use the same feature and permission as the
// assigned-run API, independent of the unrelated notifications permission.
Broadcast::channel('driver.{userHashId}', function (User $user, string $userHashId): bool {
    return app(SettingsService::class)->requiredBool('modules.supply_chain')
        && $user->is_active
        && $user->hasPermission('supply_chain.driver.access')
        && hash_equals((string) $user->hash_id, $userHashId);
});

// Role access changes contain no role/user data. Only current members of the
// hashed role channel may receive the ping and refetch their own auth identity.
Broadcast::channel('role.{roleHashId}', function (User $user, string $roleHashId): bool {
    $roleId = HashIdFilter::decode($roleHashId, Role::class);

    return $roleId !== null
        && (int) $user->role_id === $roleId
        && Role::query()->whereKey($roleId)->exists();
});

// Admin User record pings contain only a refresh envelope. The same
// `admin.users.manage` gate authorizes the detail and list API endpoints.
Broadcast::channel('admin.user.{userHashId}', function (User $user, string $userHashId): bool {
    $targetId = User::tryDecodeHash($userHashId);

    return $user->is_active
        && $user->hasPermission('admin.users.manage')
        && $targetId !== null
        && User::query()->whereKey($targetId)->exists();
});

// Role directory pings are content-free and restricted to role managers.
Broadcast::channel('admin.role.{roleHashId}', function (User $user, string $roleHashId): bool {
    $roleId = Role::tryDecodeHash($roleHashId);

    return $user->is_active
        && $user->hasPermission('admin.roles.manage')
        && $roleId !== null
        && Role::withTrashed()->whereKey($roleId)->exists();
});

// Access-scope and session-revocation refreshes are private to the affected
// user and require no notification permission; payloads contain only a refetch signal.
Broadcast::channel('user.access.{userHashId}', fn (User $user, string $userHashId): bool => $user->id === User::tryDecodeHash($userHashId)
);

// Badge/approval refreshes are targeted to the same internal user whose
// permission-scoped badge API response will be refetched. Use a distinct
// prefix so this cannot be captured by the broader user.{userId} pattern.
Broadcast::channel('badge-user.{userHashId}', fn (User $user, string $userHashId): bool => $user->is_active
    && $user->id === User::tryDecodeHash($userHashId));

// Backup list refreshes expose no operation data; reads remain permission-scoped.
Broadcast::channel('admin.backups', fn (User $user): bool => $user->is_active && $user->hasPermission('admin.backups.view'));

// Session refreshes contain no session identifiers; the channel matches the
// permission required by the Active Sessions list and termination endpoints.
Broadcast::channel('admin.sessions', fn (User $user): bool => $user->is_active && $user->hasPermission('admin.settings.manage'));

// KPI reads are available to authenticated users; the payload only asks each
// viewer to refetch its own permission-filtered scorecard.
Broadcast::channel('entity.kpi_snapshot.visible', fn (User $user): bool => $user->is_active);

// Automation recovery refreshes contain no listener errors or operator notes.
Broadcast::channel('chain.listener-runs', fn (User $user): bool => $user->is_active && $user->hasPermission('dashboard.chain_recovery.view'));

// Cache-group events carry only an allowlisted group name and a refresh flag.
// Each follow-up request still uses the viewer's own API permission/scope.
Broadcast::channel('cache-group.{group}', function (User $user, string $group): bool {
    if (! $user->is_active) {
        return false;
    }

    return match ($group) {
        // Every active user has a permission-filtered dashboard-layout API.
        'dashboards' => true,
        'financial-statements' => app(SettingsService::class)->requiredBool('modules.accounting')
            && ($user->hasPermission('accounting.statements.view')
                || $user->hasPermission('dashboard.accounting.view')),
        'procurement-chain' => (
            app(SettingsService::class)->requiredBool('modules.purchasing')
            && ($user->hasPermission('purchasing.view')
                || $user->hasPermission('purchasing.chain.view')
                || $user->hasPermission('dashboard.purchasing.view')
                || $user->hasPermission('dashboard.view_bottlenecks'))
        ) || (
            app(SettingsService::class)->requiredBool('modules.inventory')
            && $user->hasPermission('inventory.view')
        ) || (
            app(SettingsService::class)->requiredBool('modules.accounting')
            && ($user->hasPermission('accounting.bills.view')
                || $user->hasPermission('dashboard.accounting.view'))
        ),
        default => false,
    };
});

// System dashboard subscribers receive only a refetch ping. Every dashboard
// read continues through its own panel permissions and viewer-scoped API cache.
Broadcast::channel('dashboard.widgets', fn (User $user): bool => $user->is_active
    && app(DashboardLayoutService::class)->listAvailableWidgets($user) !== []);

Broadcast::channel('dashboard.admin', fn (User $user): bool => $user->is_active
    && $user->hasPermission('dashboard.admin.view'));

// HR dashboard signals contain no employee or payroll fields. The dashboard
// API applies its per-panel grants again on every refresh.
Broadcast::channel('dashboard.hr', fn (User $user): bool => $user->is_active
    && $user->hasPermission('dashboard.hr.view'));

// Employee dashboard events carry no personal fields. The shared channel
// matches the authenticated self-service endpoint; the scoped variant is
// private to one linked employee for leave, balance, and payslip changes.
Broadcast::channel('dashboard.employee', fn (User $user): bool => $user->is_active
    && $user->employee_id !== null);
Broadcast::channel('dashboard.employee.user.{userHashId}', fn (User $user, string $userHashId): bool => $user->is_active
    && $user->employee_id !== null
    && $user->id === User::tryDecodeHash($userHashId));

// GRN status changes can alter Finance's missing-bill bottleneck widget. The
// channel carries only a refetch ping; dashboard panels remain API-gated.
Broadcast::channel('dashboard.finance', fn (User $user): bool => $user->is_active
    && app(SettingsService::class)->requiredBool('modules.accounting')
    && $user->hasPermission('dashboard.accounting.view'));

// Bottleneck signals carry only a refetch request. Match the API's exact
// permission so a broader dashboard grant cannot expose unauthorized refreshes.
Broadcast::channel('dashboard.chain-bottlenecks', fn (User $user): bool => $user->is_active
    && $user->hasPermission('dashboard.view_bottlenecks'));

// Supplier-risk and purchasing summaries are refresh-only; panel and row
// permissions are re-applied by the Purchasing dashboard API on every read.
Broadcast::channel('forecast.demand-forecast.{hashId}', function (User $user, string $hashId): bool {
    if (! $user->is_active
        || ! app(SettingsService::class)->requiredBool('modules.forecasting')
        || ! $user->hasPermission('forecasting.view')) {
        return false;
    }

    $id = DemandForecast::tryDecodeHash($hashId);

    return $id !== null && DemandForecast::query()->whereKey($id)->exists();
});

Broadcast::channel('dashboard.purchasing', fn (User $user): bool => $user->is_active
    && $user->hasPermission('dashboard.purchasing.view'));

// Activity-feed subscribers receive only a content-free refresh signal;
// feed contents remain protected by the same permission on the API routes.
Broadcast::channel('admin.activity', fn (User $user): bool => $user->is_active
    && $user->hasPermission('admin.activity.view'));

// Audit-log readers receive a content-free refetch ping. The audit-log API
// applies this same permission to every request that returns log contents.
Broadcast::channel('admin.audit-logs', fn (User $user): bool => $user->is_active
    && $user->hasPermission('admin.audit_logs.view'));

// System-setting updates carry only a generic refetch signal; every follow-up
// read remains authenticated and permission-scoped at its API endpoint.
Broadcast::channel('settings.refresh', fn (User $user): bool => (bool) $user->is_active);

// DSS subscribers receive only a refetch ping; their own dss.view/manage/admin
// permission and module feature gate continue to authorize every API response.
Broadcast::channel('dss.refresh', function (User $user): bool {
    return (bool) $user->is_active
        && app(SettingsService::class)->requiredBool('modules.dss')
        && ($user->hasPermission('dss.view') || $user->hasPermission('dss.manage') || $user->hasPermission('dss.admin'));
});

// The approval board receives only a content-free refresh signal. Its API
// response remains scoped to the same permission and current user's role.
Broadcast::channel('approvals.visible', fn (User $user): bool => $user->hasPermission('approvals.board.view'));

// Supplier portal catalog refreshes are scoped to exactly one active vendor.
// Payloads are content-free and the portal refetch remains guarded by tenancy.
Broadcast::channel('supplier.vendor.{vendorHashId}', function (SupplierPortalUser $user, string $vendorHashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.b2b_portals')
        || ! $user->is_active
        || $user->isLocked()
        || $user->must_change_password
        || $user->deleted_at !== null) {
        return false;
    }

    $vendorId = Vendor::tryDecodeHash($vendorHashId);

    return $vendorId !== null
        && (int) $user->vendor_id === $vendorId
        && Vendor::query()->whereKey($vendorId)->where('is_active', true)->exists();
});

// Portal notification pings are personal and contain no notification data.
// The recipient refetches its own inbox through the portal-guarded API.
Broadcast::channel('portal.supplier.user.{userHashId}', function (SupplierPortalUser $user, string $userHashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.b2b_portals')
        || ! $user->is_active
        || $user->isLocked()
        || $user->must_change_password
        || $user->deleted_at !== null) {
        return false;
    }

    $userId = SupplierPortalUser::tryDecodeHash($userHashId);
    $vendorId = (int) $user->vendor_id;

    return $userId !== null
        && $userId === (int) $user->getKey()
        && Vendor::query()->whereKey($vendorId)->where('is_active', true)->exists();
}, ['guards' => ['supplier_portal']]);

Broadcast::channel('portal.customer.user.{userHashId}', function (CustomerPortalUser $user, string $userHashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.b2b_portals')
        || ! $user->is_active
        || $user->isLocked()
        || $user->must_change_password
        || $user->deleted_at !== null) {
        return false;
    }

    $userId = CustomerPortalUser::tryDecodeHash($userHashId);
    $customerId = (int) $user->customer_id;

    return $userId !== null
        && $userId === (int) $user->getKey()
        && Customer::query()->whereKey($customerId)->where('is_active', true)->exists();
}, ['guards' => ['customer_portal']]);

// Restore notifications contain no restored row data; the clients discard
// their in-memory snapshots and reload through the current portal session.
Broadcast::channel('system.refresh', fn (User $user): bool => $user->is_active);

Broadcast::channel('portal.supplier.system', function (SupplierPortalUser $user): bool {
    return app(SettingsService::class)->requiredBool('modules.b2b_portals')
        && $user->is_active
        && ! $user->isLocked()
        && ! $user->must_change_password
        && $user->deleted_at === null
        && Vendor::query()->whereKey((int) $user->vendor_id)->where('is_active', true)->exists();
}, ['guards' => ['supplier_portal']]);

Broadcast::channel('portal.customer.system', function (CustomerPortalUser $user): bool {
    return app(SettingsService::class)->requiredBool('modules.b2b_portals')
        && $user->is_active
        && ! $user->isLocked()
        && ! $user->must_change_password
        && $user->deleted_at === null
        && Customer::query()->whereKey((int) $user->customer_id)->where('is_active', true)->exists();
}, ['guards' => ['customer_portal']]);

// A delegation list ping contains no rows; its exact-user channel is only for
// the two principals whose self-service lists can include the changed row.
Broadcast::channel('approval-delegation.user.{userHashId}', function (User $user, string $userHashId): bool {
    $targetUserId = User::tryDecodeHash($userHashId);

    return $user->is_active
        && $targetUserId !== null
        && (int) $user->getKey() === $targetUserId;
});

// Chain entity list channels carry only the content-free refresh ping. The
// API remains responsible for row-level scope when each viewer refetches.
// Register these exact channels before the generic entity matcher below so
// their module-specific permissions are not shadowed by its fallback.
Broadcast::channel('entity.delivery_schedule.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.b2b_portals')
    && $user->is_active
    && $user->hasPermission('b2b.portal_access.view'));

Broadcast::channel('entity.b2b_portal_access.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.b2b_portals')
    && $user->is_active
    && $user->hasPermission('b2b.portal_access.view'));

Broadcast::channel('entity.shipment.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.supply_chain')
    && $user->is_active
    && ($user->hasPermission('supply_chain.view')
        || $user->hasPermission('supply_chain.shipments.view')));

Broadcast::channel('entity.vehicle.visible', fn (User $user): bool => app(SettingsService::class)->requiredBool('modules.supply_chain')
    && $user->is_active
    && $user->hasPermission('supply_chain.view'));

Broadcast::channel('entity.{entityType}.{scope}', function (User $user, string $entityType, string $scope): bool {
    if ($entityType === 'customer') {
        $canReadDirectory = $user->hasPermission('accounting.customers.view')
            && (app(SettingsService::class)->requiredBool('modules.accounting')
                || app(SettingsService::class)->requiredBool('modules.crm'));
        $canReadForecastCustomerLabels = app(SettingsService::class)->requiredBool('modules.forecasting')
            && $user->hasPermission('forecasting.view');

        return $scope === 'visible'
            && $user->is_active
            && ($canReadDirectory || $canReadForecastCustomerLabels);
    }

    if ($entityType === 'admin_user') {
        return $scope === 'visible'
            && $user->is_active
            && $user->hasPermission('admin.users.manage');
    }

    if ($entityType === 'approval_delegation') {
        return $scope === 'managers'
            && $user->is_active
            && $user->hasPermission('admin.delegations.manage_any');
    }

    if ($entityType === 'role') {
        return $scope === 'visible'
            && $user->is_active
            && $user->hasPermission('admin.roles.manage');
    }

    if ($entityType === 'product') {
        return $scope === 'visible'
            && app(SettingsService::class)->requiredBool('modules.crm')
            && $user->is_active
            && collect([
                'crm.products.view',
                'production.molds.manage',
                'quality.inspections.view',
                'quality.inspections.manage',
                'quality.ncr.manage',
                'quality.specs.view',
                'forecasting.view',
            ])->contains(fn (string $permission): bool => $user->hasPermission($permission));
    }

    if ($entityType === 'price_request') {
        return $scope === 'visible'
            && $user->is_active
            && app(SettingsService::class)->requiredBool('modules.crm')
            && collect([
                'crm.price_agreements.view',
                'crm.price_agreements.approve',
                'crm.price_agreements.propose',
            ])->contains(fn (string $permission): bool => $user->hasPermission($permission));
    }

    if ($entityType === 'demand_forecast') {
        return $scope === 'visible'
            && $user->is_active
            && app(SettingsService::class)->requiredBool('modules.forecasting')
            && $user->hasPermission('forecasting.view');
    }

    if ($entityType === 'department') {
        return $scope === 'visible'
            && $user->is_active
            && ($user->hasPermission('admin.users.manage')
                || (app(SettingsService::class)->requiredBool('modules.hr')
                    && ($user->hasPermission('hr.departments.view')
                        || $user->hasPermission('hr.directory.view'))));
    }

    if ($entityType === 'return_request') {
        if (! app(SettingsService::class)->requiredBool('modules.return_management')
            || ! $user->hasPermission('return_management.view')) {
            return false;
        }

        return match ($scope) {
            'customer' => ReturnManagementAccess::customerReturnsOnly($user),
            'all' => ! ReturnManagementAccess::customerReturnsOnly($user),
            default => false,
        };
    }

    if ($entityType === 'return_case') {
        if (! app(SettingsService::class)->requiredBool('modules.return_management')
            || ! $user->hasPermission('return_management.view')) {
            return false;
        }

        $customerOnly = ReturnManagementAccess::customerReturnsOnly($user);

        return match ($scope) {
            'customer' => $customerOnly,
            'supplier', 'all' => ! $customerOnly,
            default => false,
        };
    }

    if ($scope !== 'visible') {
        return false;
    }

    [$feature, $permissionGroups] = match ($entityType) {
        'sales_order' => ['crm', [['crm.sales_orders.view']]],
        'price_agreement' => ['crm', [['crm.price_agreements.view']]],
        'customer_complaint' => ['crm', [['crm.complaints.view']]],
        'work_order' => ['production', [['production.view', 'production.work_orders.view']]],
        'stock_count_session' => ['inventory', [['inventory.stock_count.view']]],
        'late_production_report' => ['production', [['production.work_orders.lifecycle', 'production.wo.record']]],
        'production_schedule' => ['mrp', [['production.schedule.view']]],
        'mrp_plan' => ['mrp', [['mrp.plans.view']]],
        'mrp_run' => ['mrp', [['mrp.runs.view']]],
        'journal_entry' => ['accounting', [['accounting.journal.view']]],
        'accounting_period' => ['accounting', [['accounting.periods.view']]],
        'account' => ['accounting', [['accounting.coa.view']]],
        'vendor' => ['accounting', [['accounting.vendors.view']]],
        'supplier_performance' => ['purchasing', [['purchasing.suppliers.performance.view']]],
        'credit_note' => ['accounting', [['accounting.credit_notes.view']]],
        'budget_transfer' => ['budgeting', [['budgeting.view'], ['budgeting.approve']]],
        'budget' => ['budgeting', [['budgeting.view'], ['budgeting.approve']]],
        'machine' => ['mrp', [['mrp.machines.view']]],
        'maintenance_work_order' => ['maintenance', [['maintenance.view']]],
        'purchase_request', 'purchase_order' => ['purchasing', [['purchasing.view']]],
        'request_for_quote' => ['purchasing', [['purchasing.rfq.view'], ['purchasing.rfq.manage']]],
        'supplier_listing' => ['purchasing', [['purchasing.supplier_listings.view']]],
        'approved_supplier' => ['purchasing', [['purchasing.po.create']]],
        'delivery' => ['supply_chain', [['supply_chain.view'], ['supply_chain.deliveries.view']]],
        'inspection' => ['quality', [['quality.inspections.view']]],
        'ncr' => ['quality', [['quality.ncr.view']]],
        'calibration' => ['quality', [['quality.calibration.view']]],
        'ppap' => ['quality', [['quality.ppap.view']]],
        'inspection_spec' => ['quality', [['quality.specs.view']]],
        'ncr_template' => ['quality', [['quality.ncr.view']]],
        'quality_plan' => ['inventory', [['inventory.view']]],
        'return_request' => ['return_management', [['return_management.view']]],
        'shipment_lot' => ['quality', [['quality.inspections.view']]],
        'grn' => ['inventory', [['inventory.view']]],
        'bill' => ['accounting', [['accounting.bills.view']]],
        'invoice' => ['accounting', [['accounting.invoices.view']]],
        'payroll_period' => ['payroll', [['payroll.periods.view']]],
        'attendance' => ['attendance', [['attendance.view']]],
        'payroll_adjustment' => ['payroll', [['payroll.adjustments.view']]],
        default => [null, []],
    };

    if ($feature === null || ! app(SettingsService::class)->requiredBool("modules.{$feature}")) {
        return false;
    }

    return collect($permissionGroups)->contains(
        fn (array $group): bool => collect($group)->every(fn (string $permission): bool => $user->hasPermission($permission)),
    );
});

// Customer record refreshes use the same directory read grant as the CRM and
// Accounting show endpoints; the signal itself carries no Customer fields.
Broadcast::channel('accounting.customer.{hashId}', function (User $user, string $hashId): bool {
    if (! $user->is_active
        || ! $user->hasPermission('accounting.customers.view')
        || (! app(SettingsService::class)->requiredBool('modules.accounting')
            && ! app(SettingsService::class)->requiredBool('modules.crm'))) {
        return false;
    }

    $id = Customer::tryDecodeHash($hashId);

    return $id !== null && Customer::query()->whereKey($id)->exists();
});

// Customer portal profile refreshes are restricted to that active customer's
// own account session and carry no profile values.
Broadcast::channel('customer.profile.{customerHashId}', function (CustomerPortalUser $user, string $customerHashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.b2b_portals')
        || ! $user->is_active
        || $user->isLocked()
        || $user->must_change_password
        || $user->deleted_at !== null) {
        return false;
    }

    $customerId = Customer::tryDecodeHash($customerHashId);

    return $customerId !== null
        && (int) $user->customer_id === $customerId
        && Customer::query()->whereKey($customerId)->where('is_active', true)->exists();
}, ['guards' => ['customer_portal']]);

// Product details use the same feature and read grants as ProductController::show.
Broadcast::channel('crm.product.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.crm')
        || ! $user->is_active
        || ! ($user->hasPermission('crm.products.view') || $user->hasPermission('inventory.costs.approve'))) {
        return false;
    }

    $id = Product::tryDecodeHash($hashId);

    return $id !== null && Product::query()->whereKey($id)->exists();
});

// Price Agreement refresh pings use the same CRM read grant as list/detail APIs.
Broadcast::channel('crm.price-agreement.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.crm')
        || ! $user->is_active
        || ! $user->hasPermission('crm.price_agreements.view')) {
        return false;
    }

    $id = PriceAgreement::tryDecodeHash($hashId);

    return $id !== null && PriceAgreement::query()->whereKey($id)->exists();
});

// Pricing-request refresh payloads are shared only with internal pricing readers.
Broadcast::channel('crm.price-request.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.crm')
        || ! $user->is_active
        || ! collect([
            'crm.price_agreements.view',
            'crm.price_agreements.approve',
            'crm.price_agreements.propose',
        ])->contains(fn (string $permission): bool => $user->hasPermission($permission))) {
        return false;
    }

    $id = PriceRequest::tryDecodeHash($hashId);

    return $id !== null && PriceRequest::query()->whereKey($id)->exists();
});

// Customer pricing refreshes stay on the customer's separate session guard and account scope.
Broadcast::channel('customer.price-request.{customerHashId}', function (CustomerPortalUser $user, string $customerHashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.b2b_portals')
        || ! $user->is_active
        || $user->isLocked()
        || $user->must_change_password
        || $user->deleted_at !== null) {
        return false;
    }

    $customerId = Customer::tryDecodeHash($customerHashId);

    return $customerId !== null
        && (int) $user->customer_id === $customerId
        && Customer::query()->whereKey($customerId)->where('is_active', true)->exists();
}, ['guards' => ['customer_portal']]);

// Stock-count detail pings contain no count data; the API applies this same
// module/permission boundary before returning the session representation.
Broadcast::channel('inventory.stock-count-session.{hashId}', function (User $user, string $hashId): bool {
    $id = HashIdFilter::decode($hashId, StockCountSession::class);

    return app(SettingsService::class)->requiredBool('modules.inventory')
        && $user->hasPermission('inventory.stock_count.view')
        && $id !== null
        && StockCountSession::query()->whereKey($id)->exists();
});

// Inspection detail refresh uses the same feature gate and permission as its API route.
Broadcast::channel('inspection.{hashId}', function (User $user, string $hashId) use ($canViewSubject): bool {
    return app(SettingsService::class)->requiredBool('modules.quality')
        && $canViewSubject($user, $hashId, 'quality.inspections.view', Inspection::class);
});

// NCR detail refresh uses the same feature gate and permission as its API route.
Broadcast::channel('ncr.{hashId}', function (User $user, string $hashId) use ($canViewSubject): bool {
    return app(SettingsService::class)->requiredBool('modules.quality')
        && $canViewSubject($user, $hashId, 'quality.ncr.view', NonConformanceReport::class);
});

// Complaint detail refresh is private and uses the exact view permission on
// the CRM API. The signal carries no complaint or customer data.
Broadcast::channel('customer_complaint.{hashId}', function (User $user, string $hashId): bool {
    $id = CustomerComplaint::tryDecodeHash($hashId);

    return app(SettingsService::class)->requiredBool('modules.crm')
        && $user->hasPermission('crm.complaints.view')
        && $id !== null
        && CustomerComplaint::query()->whereKey($id)->exists();
});

Broadcast::channel('calibration.{hashId}', function (User $user, string $hashId) use ($canViewSubject): bool {
    return app(SettingsService::class)->requiredBool('modules.quality')
        && $canViewSubject($user, $hashId, 'quality.calibration.view', CalibrationRecord::class);
});

Broadcast::channel('ppap.{hashId}', function (User $user, string $hashId) use ($canViewSubject): bool {
    return app(SettingsService::class)->requiredBool('modules.quality')
        && $canViewSubject($user, $hashId, 'quality.ppap.view', PpapSubmission::class);
});

Broadcast::channel('inspection_spec.{hashId}', function (User $user, string $hashId): bool {
    $id = HashIdFilter::decode($hashId, InspectionSpec::class);

    return app(SettingsService::class)->requiredBool('modules.quality')
        && $user->hasPermission('quality.specs.view')
        && $id !== null
        && InspectionSpec::withTrashed()->whereKey($id)->exists();
});

Broadcast::channel('ncr_template.{hashId}', function (User $user, string $hashId) use ($canViewSubject): bool {
    return app(SettingsService::class)->requiredBool('modules.quality')
        && $canViewSubject($user, $hashId, 'quality.ncr.view', NcrTemplate::class);
});

// The revision page is scoped to an item and its inventory.view API.
Broadcast::channel('quality_plan.{hashId}', function (User $user, string $hashId) use ($canViewSubject): bool {
    return app(SettingsService::class)->requiredBool('modules.inventory')
        && $canViewSubject($user, $hashId, 'inventory.view', Item::class);
});

Broadcast::channel('return_request.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.return_management')
        || ! $user->hasPermission('return_management.view')) {
        return false;
    }

    $id = ReturnRequest::tryDecodeHash($hashId);
    $request = $id === null ? null : ReturnRequest::query()->find($id);
    if ($request === null) {
        return false;
    }

    return ! (ReturnManagementAccess::customerReturnsOnly($user)
        && $request->type?->value === 'supplier_return');
});

Broadcast::channel('return_case.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.return_management')
        || ! $user->hasPermission('return_management.view')) {
        return false;
    }

    $id = ReturnCase::tryDecodeHash($hashId);
    $case = $id === null ? null : ReturnCase::query()->find($id);

    return $case !== null
        && ! (ReturnManagementAccess::customerReturnsOnly($user) && $case->type?->value === 'supplier');
});

// Customer problem reports use a separate session guard and company-scoped API.
Broadcast::channel('customer.account.{customerHashId}', function (CustomerPortalUser $user, string $customerHashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.b2b_portals')
        || ! app(SettingsService::class)->requiredBool('modules.return_management')
        || ! $user->is_active || $user->isLocked() || $user->must_change_password || $user->deleted_at !== null) {
        return false;
    }

    $customerId = Customer::tryDecodeHash($customerHashId);

    return $customerId !== null
        && (int) $user->customer_id === $customerId
        && Customer::query()->whereKey($customerId)->where('is_active', true)->exists();
});

// Delivery-schedule refreshes are isolated from Return Management, which can
// be disabled while customer B2B schedules remain available.
Broadcast::channel('customer.delivery-schedule.{customerHashId}', function (CustomerPortalUser $user, string $customerHashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.b2b_portals')
        || ! $user->is_active
        || $user->isLocked()
        || $user->must_change_password
        || $user->deleted_at !== null) {
        return false;
    }

    $customerId = Customer::tryDecodeHash($customerHashId);

    return $customerId !== null
        && (int) $user->customer_id === $customerId
        && Customer::query()->whereKey($customerId)->where('is_active', true)->exists();
}, ['guards' => ['customer_portal']]);

Broadcast::channel('delivery-schedule.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.b2b_portals')
        || ! $user->is_active
        || ! $user->hasPermission('b2b.portal_access.view')) {
        return false;
    }

    $scheduleId = HashIdFilter::decode($hashId, DeliverySchedule::class);

    return $scheduleId !== null && DeliverySchedule::query()->whereKey($scheduleId)->exists();
});

// Invoice refreshes carry no accounting values; the portal refetch is scoped
// by the same active customer account as its authenticated invoice API.
Broadcast::channel('customer.invoice.{customerHashId}', function (CustomerPortalUser $user, string $customerHashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.b2b_portals')
        || ! $user->is_active
        || $user->isLocked()
        || $user->must_change_password
        || $user->deleted_at !== null) {
        return false;
    }

    $customerId = Customer::tryDecodeHash($customerHashId);

    return $customerId !== null
        && (int) $user->customer_id === $customerId
        && Customer::query()->whereKey($customerId)->where('is_active', true)->exists();
});

// Complaint status pings are limited to active portal users for the same
// customer account; their follow-up request remains customer-scoped by API.
Broadcast::channel('customer.complaint.{customerHashId}', function (CustomerPortalUser $user, string $customerHashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.b2b_portals')
        || ! $user->is_active
        || $user->isLocked()
        || $user->must_change_password
        || $user->deleted_at !== null) {
        return false;
    }

    $customerId = Customer::tryDecodeHash($customerHashId);

    return $customerId !== null
        && (int) $user->customer_id === $customerId
        && Customer::query()->whereKey($customerId)->where('is_active', true)->exists();
}, ['guards' => ['customer_portal']]);

// Product/catalog updates are account-scoped refresh signals only; the catalog API remains tenant-scoped.
Broadcast::channel('customer.catalog.{customerHashId}', function (CustomerPortalUser $user, string $customerHashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.b2b_portals')
        || ! $user->is_active
        || $user->isLocked()
        || $user->must_change_password
        || $user->deleted_at !== null) {
        return false;
    }

    $customerId = Customer::tryDecodeHash($customerHashId);

    return $customerId !== null
        && (int) $user->customer_id === $customerId
        && Customer::query()->whereKey($customerId)->where('is_active', true)->exists();
}, ['guards' => ['customer_portal']]);

// Order and delivery refresh pings contain no record data; the customer portal
// refetches through its customer-account-scoped API endpoints.
Broadcast::channel('customer.order.{customerHashId}', function (CustomerPortalUser $user, string $customerHashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.b2b_portals')
        || ! $user->is_active
        || $user->isLocked()
        || $user->must_change_password
        || $user->deleted_at !== null) {
        return false;
    }

    $customerId = Customer::tryDecodeHash($customerHashId);

    return $customerId !== null
        && (int) $user->customer_id === $customerId
        && Customer::query()->whereKey($customerId)->where('is_active', true)->exists();
});

Broadcast::channel('shipment_lot.{hashId}', function (User $user, string $hashId) use ($canViewSubject): bool {
    return app(SettingsService::class)->requiredBool('modules.quality')
        && $canViewSubject($user, $hashId, 'quality.inspections.view', ShipmentLot::class);
});

// Shipment refreshes contain metadata only; channel access matches the shipment read routes.
Broadcast::channel('shipment.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.supply_chain')
        || ! $user->is_active
        || (! $user->hasPermission('supply_chain.view')
            && ! $user->hasPermission('supply_chain.shipments.view'))) {
        return false;
    }

    $id = Shipment::tryDecodeHash($hashId);

    return $id !== null && Shipment::query()->whereKey($id)->exists();
});

// Vehicle pings contain refresh metadata only; access matches the fleet API.
Broadcast::channel('vehicle.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.supply_chain')
        || ! $user->is_active
        || ! $user->hasPermission('supply_chain.view')) {
        return false;
    }

    $id = Vehicle::tryDecodeHash($hashId);

    return $id !== null && Vehicle::withTrashed()->whereKey($id)->exists();
});

// Fulfillment previews are also readable by Production confirmers without
// crm.sales_orders.view; this exact-record channel mirrors the preview route.
Broadcast::channel('sales-order-fulfillment.{hashId}', function (User $user, string $hashId): bool {
    if (! app(SettingsService::class)->requiredBool('modules.crm')
        || ! $user->is_active
        || (! $user->hasPermission('crm.sales_orders.view')
            && ! $user->hasPermission('production.wo.confirm')
            && ! $user->hasPermission('production.schedule.confirm'))) {
        return false;
    }

    $id = SalesOrder::tryDecodeHash($hashId);

    return $id !== null && SalesOrder::query()->whereKey($id)->exists();
});

/* Series C — Task C4. Chain progress (sales_order/work_order/purchase_order/
 * delivery/grn). One private channel per entity instance. Permission is
 * derived from the entity-type slug. Hash IDs are passed verbatim — the
 * subscribing component is the only consumer that knows what they map to.
 */
Broadcast::channel(
    'chain.{entityType}.{hashId}',
    function (User $user, string $entityType, string $hashId): bool {
        if (! in_array($entityType, ChainDefinitions::allowedTypes(), true)) {
            return false;
        }

        [$feature, $model, $permissionGroups] = match ($entityType) {
            'sales_order' => ['crm', SalesOrder::class, [['crm.sales_orders.view']]],
            'work_order' => ['production', WorkOrder::class, [['production.view', 'production.work_orders.view']]],
            'purchase_request' => ['purchasing', PurchaseRequest::class, [['purchasing.view']]],
            'purchase_order' => ['purchasing', PurchaseOrder::class, [['purchasing.view'], ['purchasing.po.approve']]],
            'delivery' => ['supply_chain', Delivery::class, [['supply_chain.view'], ['supply_chain.deliveries.view']]],
            'grn' => ['inventory', GoodsReceiptNote::class, [['inventory.view']]],
            'bill' => ['accounting', Bill::class, [['accounting.bills.view']]],
            'invoice' => ['accounting', Invoice::class, [['accounting.invoices.view']]],
            default => [null, null, []],
        };

        if ($feature === null || ! app(SettingsService::class)->requiredBool("modules.{$feature}")) {
            return false;
        }

        $hasRoutePermission = collect($permissionGroups)->contains(
            fn (array $group): bool => collect($group)->every(fn (string $permission): bool => $user->hasPermission($permission)),
        );
        if (! $hasRoutePermission) {
            return false;
        }

        $id = $model::tryDecodeHash($hashId);
        $record = $id === null ? null : $model::query()->find($id);
        if ($record === null) {
            return false;
        }

        return match ($entityType) {
            'purchase_request' => app(PurchaseRequestAccessPolicy::class)->canView($user, $record),
            'purchase_order' => app(PurchaseOrderAccessPolicy::class)->canView($user, $record),
            default => true,
        };
    }
);
