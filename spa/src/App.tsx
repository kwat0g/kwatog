import { lazy, Suspense } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import { AuthGuard } from '@/components/guards/AuthGuard';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';
import { AppLayout } from '@/layouts/AppLayout';
import { AuthLayout } from '@/layouts/AuthLayout';
import { FullPageLoader } from '@/components/ui/Spinner';

// Auth flow
const LoginPage = lazy(() => import('@/pages/auth/login'));
const ChangePasswordPage = lazy(() => import('@/pages/auth/change-password'));

// App
const DashboardPage = lazy(() => import('@/pages/dashboard'));

// Admin (Sprint 1)
const RolesIndexPage = lazy(() => import('@/pages/admin/roles'));
const RolePermissionsPage = lazy(() => import('@/pages/admin/roles/permissions'));
const SettingsPage = lazy(() => import('@/pages/admin/settings'));
const AuditLogsPage = lazy(() => import('@/pages/admin/audit-logs'));

// HR (Sprint 2 — Tasks 13/14/15)
const DepartmentsPage = lazy(() => import('@/pages/hr/departments'));
const PositionsPage = lazy(() => import('@/pages/hr/positions'));
const EmployeesListPage = lazy(() => import('@/pages/hr/employees'));
const CreateEmployeePage = lazy(() => import('@/pages/hr/employees/create'));
const EmployeeDetailPage = lazy(() => import('@/pages/hr/employees/detail'));
const EditEmployeePage = lazy(() => import('@/pages/hr/employees/edit'));

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

// Errors
const NotFoundPage = lazy(() => import('@/pages/error/NotFound'));

export default function App() {
  return (
    <Suspense fallback={<FullPageLoader />}>
      <Routes>
        {/* Auth (no AuthGuard) */}
        <Route element={<AuthLayout />}>
          <Route path="/login" element={<LoginPage />} />
        </Route>

        <Route
          path="/change-password"
          element={
            <AuthGuard>
              <AuthLayout />
            </AuthGuard>
          }
        >
          <Route index element={<ChangePasswordPage />} />
        </Route>

        {/* Authenticated app shell */}
        <Route
          element={
            <AuthGuard>
              <AppLayout />
            </AuthGuard>
          }
        >
          <Route path="/" element={<Navigate to="/dashboard" replace />} />
          <Route path="/dashboard" element={<DashboardPage />} />

          {/* HR module */}
          <Route element={<ModuleGuard module="hr" />}>
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
          </Route>

          {/* Attendance module */}
          <Route element={<ModuleGuard module="attendance" />}>
            <Route
              path="/attendance"
              element={<PermissionGuard permission="attendance.view"><AttendancePage /></PermissionGuard>}
            />
            <Route
              path="/attendance/import"
              element={<PermissionGuard permission="attendance.import"><AttendanceImportPage /></PermissionGuard>}
            />
            <Route
              path="/attendance/shifts"
              element={<PermissionGuard permission="attendance.view"><ShiftsPage /></PermissionGuard>}
            />
            <Route
              path="/attendance/shifts/assign"
              element={<PermissionGuard permission="attendance.shifts.manage"><BulkAssignShiftPage /></PermissionGuard>}
            />
            <Route
              path="/attendance/holidays"
              element={<PermissionGuard permission="attendance.view"><HolidaysPage /></PermissionGuard>}
            />
            <Route
              path="/attendance/overtime"
              element={<PermissionGuard permission="attendance.view"><OvertimeListPage /></PermissionGuard>}
            />
            <Route
              path="/attendance/overtime/create"
              element={<OvertimeCreatePage />}
            />
          </Route>

          {/* Leave module */}
          <Route element={<ModuleGuard module="leave" />}>
            <Route
              path="/leaves"
              element={<PermissionGuard permission="leave.view"><LeavesPage /></PermissionGuard>}
            />
            <Route
              path="/leaves/create"
              element={<PermissionGuard permission="leave.create"><CreateLeavePage /></PermissionGuard>}
            />
            <Route
              path="/leaves/:id"
              element={<PermissionGuard permission="leave.view"><LeaveDetailPage /></PermissionGuard>}
            />
          </Route>

          {/* Loans module */}
          <Route element={<ModuleGuard module="loans" />}>
            <Route
              path="/loans"
              element={<PermissionGuard permission="loans.view"><LoansPage /></PermissionGuard>}
            />
            <Route
              path="/loans/create"
              element={<PermissionGuard permission="loans.create"><CreateLoanPage /></PermissionGuard>}
            />
            <Route
              path="/loans/:id"
              element={<PermissionGuard permission="loans.view"><LoanDetailPage /></PermissionGuard>}
            />
          </Route>

          {/* Admin */}
          <Route
            path="/admin/roles"
            element={
              <PermissionGuard permission="admin.roles.manage">
                <RolesIndexPage />
              </PermissionGuard>
            }
          />
          <Route
            path="/admin/roles/:id/permissions"
            element={
              <PermissionGuard permission="admin.roles.manage">
                <RolePermissionsPage />
              </PermissionGuard>
            }
          />
          <Route
            path="/admin/settings"
            element={
              <PermissionGuard permission="admin.settings.manage">
                <SettingsPage />
              </PermissionGuard>
            }
          />
          <Route
            path="/admin/audit-logs"
            element={
              <PermissionGuard permission="admin.audit_logs.view">
                <AuditLogsPage />
              </PermissionGuard>
            }
          />
        </Route>

        {/* 404 */}
        <Route path="*" element={<NotFoundPage />} />
      </Routes>
    </Suspense>
  );
}
