import { lazy } from 'react';
import { Route, Navigate } from 'react-router-dom';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// HR (Sprint 2 — Tasks 13/14/15)
const HrHubPage = lazy(() => import('@/pages/hr/hub'));
const DepartmentsPage = lazy(() => import('@/pages/hr/departments'));
const PositionsPage = lazy(() => import('@/pages/hr/positions'));
const EmployeesListPage = lazy(() => import('@/pages/hr/employees'));
const CreateEmployeePage = lazy(() => import('@/pages/hr/employees/create'));
const EmployeeDetailPage = lazy(() => import('@/pages/hr/employees/detail'));
const EditEmployeePage = lazy(() => import('@/pages/hr/employees/edit'));

// HR > Profile Change Requests (Task U3 — HR review queue)
const ProfileUpdateRequestsPage = lazy(() => import('@/pages/hr/profile-update-requests'));

// Series F / Task F5 — Employee directory + org chart
const EmployeeDirectoryPage = lazy(() => import('@/pages/hr/directory'));

// Attendance (Sprint 2 — Tasks 16/17/18/19)
const ShiftsPage = lazy(() => import('@/pages/attendance/shifts'));
const BulkAssignShiftPage = lazy(() => import('@/pages/attendance/shifts/assign'));
const HolidaysPage = lazy(() => import('@/pages/attendance/holidays'));
const AttendancePage = lazy(() => import('@/pages/attendance'));
const AttendanceImportPage = lazy(() => import('@/pages/attendance/import'));
const OvertimeListPage = lazy(() => import('@/pages/attendance/overtime'));
const OvertimeCreatePage = lazy(() => import('@/pages/attendance/overtime/create'));

// Leaves (Sprint 2 — Tasks 20/21)
const LeavesPage = lazy(() => import('@/pages/leaves'));
const CreateLeavePage = lazy(() => import('@/pages/leaves/create'));
const LeaveDetailPage = lazy(() => import('@/pages/leaves/detail'));

// Loans (Sprint 2 — Task 22)
const LoansPage = lazy(() => import('@/pages/loans'));
const CreateLoanPage = lazy(() => import('@/pages/loans/create'));
const LoanDetailPage = lazy(() => import('@/pages/loans/detail'));

// Separation (Sprint 8 — Task 71)
const SeparationsListPage  = lazy(() => import('@/pages/hr/separations'));
const SeparationDetailPage = lazy(() => import('@/pages/hr/separations/detail'));

export const hrRoutes = (
  <>
    {/* HR module */}
    <Route element={<ModuleGuard module="hr" />}>
      <Route path="/hr" element={<Navigate to="/hr/hub" replace />} />
      <Route
        path="/hr/hub"
        element={<PermissionGuard permission="hr.employees.view"><HrHubPage /></PermissionGuard>}
      />
      <Route
        path="/hr/departments"
        element={<PermissionGuard permission="hr.departments.view"><DepartmentsPage /></PermissionGuard>}
      />
      <Route
        path="/hr/positions"
        element={<PermissionGuard permission="hr.positions.view"><PositionsPage /></PermissionGuard>}
      />
      <Route
        path="/hr/employees"
        element={<PermissionGuard permission="hr.employees.view"><EmployeesListPage /></PermissionGuard>}
      />
      <Route
        path="/hr/employees/create"
        element={<PermissionGuard permission="hr.employees.create"><CreateEmployeePage /></PermissionGuard>}
      />
      <Route
        path="/hr/employees/:id"
        element={<PermissionGuard permission="hr.employees.view"><EmployeeDetailPage /></PermissionGuard>}
      />
      <Route
        path="/hr/employees/:id/edit"
        element={<PermissionGuard permission="hr.employees.edit"><EditEmployeePage /></PermissionGuard>}
      />
      <Route
        path="/hr/profile-update-requests"
        element={<PermissionGuard permission="hr.employees.view"><ProfileUpdateRequestsPage /></PermissionGuard>}
      />
      {/* Series F / Task F5 — Employee directory + org chart */}
      <Route
        path="/hr/directory"
        element={<PermissionGuard permission="hr.directory.view"><EmployeeDirectoryPage /></PermissionGuard>}
      />
    </Route>

    {/* Attendance module */}
    <Route element={<ModuleGuard module="attendance" />}>
      <Route
        path="/hr/attendance"
        element={<PermissionGuard permission="attendance.view"><AttendancePage /></PermissionGuard>}
      />
      <Route
        path="/hr/attendance/import"
        element={<PermissionGuard permission="attendance.import"><AttendanceImportPage /></PermissionGuard>}
      />
      <Route
        path="/hr/attendance/shifts"
        element={<PermissionGuard permission="attendance.view"><ShiftsPage /></PermissionGuard>}
      />
      <Route
        path="/hr/attendance/shifts/assign"
        element={<PermissionGuard permission="attendance.shifts.manage"><BulkAssignShiftPage /></PermissionGuard>}
      />
      <Route
        path="/hr/attendance/holidays"
        element={<PermissionGuard permission="attendance.view"><HolidaysPage /></PermissionGuard>}
      />
      <Route
        path="/hr/attendance/overtime"
        element={<PermissionGuard permission="attendance.view"><OvertimeListPage /></PermissionGuard>}
      />
      <Route
        path="/hr/attendance/overtime/create"
        element={<OvertimeCreatePage />}
      />
    </Route>

    {/* Leave module */}
    <Route element={<ModuleGuard module="leave" />}>
      <Route
        path="/hr/leaves"
        element={<PermissionGuard permission="leave.view"><LeavesPage /></PermissionGuard>}
      />
      <Route
        path="/hr/leaves/create"
        element={<PermissionGuard permission="leave.create"><CreateLeavePage /></PermissionGuard>}
      />
      <Route
        path="/hr/leaves/:id"
        element={<PermissionGuard permission="leave.view"><LeaveDetailPage /></PermissionGuard>}
      />
    </Route>

    {/* Loans module */}
    <Route element={<ModuleGuard module="loans" />}>
      <Route
        path="/hr/loans"
        element={<PermissionGuard permission="loans.view"><LoansPage /></PermissionGuard>}
      />
      <Route
        path="/hr/loans/create"
        element={<PermissionGuard permission="loans.create"><CreateLoanPage /></PermissionGuard>}
      />
      <Route
        path="/hr/loans/:id"
        element={<PermissionGuard permission="loans.view"><LoanDetailPage /></PermissionGuard>}
      />
    </Route>

    {/* HR Separations (Sprint 8 — Task 71) */}
    <Route element={<ModuleGuard module="hr" />}>
      <Route path="/hr/separations"
        element={<PermissionGuard permission="hr.separation.view"><SeparationsListPage /></PermissionGuard>} />
      <Route path="/hr/separations/:id"
        element={<PermissionGuard permission="hr.separation.view"><SeparationDetailPage /></PermissionGuard>} />
    </Route>
  </>
);
