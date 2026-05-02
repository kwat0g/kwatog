import { lazy, Suspense } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import { AuthGuard } from '@/components/guards/AuthGuard';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';
import { AppLayout } from '@/layouts/AppLayout';
import { AuthLayout } from '@/layouts/AuthLayout';
import { SelfServiceLayout } from '@/layouts/SelfServiceLayout';
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

// Payroll (Sprint 3 — Tasks 23-30)
const PayrollPeriodsPage         = lazy(() => import('@/pages/payroll/periods'));
const CreatePayrollPeriodPage    = lazy(() => import('@/pages/payroll/periods/create'));
const PayrollPeriodDetailPage    = lazy(() => import('@/pages/payroll/periods/detail'));
const PayrollEmployeeDetailPage  = lazy(() => import('@/pages/payroll/periods/employee-detail'));
const PayrollAdjustmentsPage     = lazy(() => import('@/pages/payroll/adjustments'));
const CreatePayrollAdjustmentPage = lazy(() => import('@/pages/payroll/adjustments/create'));
const SelfServicePayslipsPage    = lazy(() => import('@/pages/self-service/payslips'));
const AdminGovTablesPage         = lazy(() => import('@/pages/admin/gov-tables'));

// Inventory (Sprint 5 — Tasks 39-41, 46)
const InventoryDashboardPage = lazy(() => import('@/pages/inventory/dashboard'));
const ItemsListPage          = lazy(() => import('@/pages/inventory/items'));
const CreateItemPage         = lazy(() => import('@/pages/inventory/items/create'));
const EditItemPage           = lazy(() => import('@/pages/inventory/items/edit'));
const ItemDetailPage         = lazy(() => import('@/pages/inventory/items/detail'));
const ItemCategoriesPage     = lazy(() => import('@/pages/inventory/categories'));
const WarehousePage          = lazy(() => import('@/pages/inventory/warehouse'));
const StockLevelsPage        = lazy(() => import('@/pages/inventory/stock-levels'));
const StockMovementsPage     = lazy(() => import('@/pages/inventory/movements'));
const CreateStockAdjustmentPage = lazy(() => import('@/pages/inventory/stock-adjustments/create'));
const CreateStockTransferPage   = lazy(() => import('@/pages/inventory/stock-transfers/create'));
const GrnListPage            = lazy(() => import('@/pages/inventory/grn'));
const CreateGrnPage          = lazy(() => import('@/pages/inventory/grn/create'));
const GrnDetailPage          = lazy(() => import('@/pages/inventory/grn/detail'));
const MaterialIssuesListPage = lazy(() => import('@/pages/inventory/material-issues'));
const CreateMaterialIssuePage = lazy(() => import('@/pages/inventory/material-issues/create'));

// Purchasing (Sprint 5 — Tasks 42-44)
const PurchaseRequestsListPage  = lazy(() => import('@/pages/purchasing/purchase-requests'));
const CreatePurchaseRequestPage = lazy(() => import('@/pages/purchasing/purchase-requests/create'));
const PurchaseRequestDetailPage = lazy(() => import('@/pages/purchasing/purchase-requests/detail'));
const PurchaseOrdersListPage    = lazy(() => import('@/pages/purchasing/purchase-orders'));
const CreatePurchaseOrderPage   = lazy(() => import('@/pages/purchasing/purchase-orders/create'));
const PurchaseOrderDetailPage   = lazy(() => import('@/pages/purchasing/purchase-orders/detail'));
const ApprovedSuppliersPage     = lazy(() => import('@/pages/purchasing/approved-suppliers'));

// Accounting (Sprint 4 — Tasks 31-37)
const ChartOfAccountsPage        = lazy(() => import('@/pages/accounting/coa'));
const JournalEntriesPage         = lazy(() => import('@/pages/accounting/journal-entries'));
const CreateJournalEntryPage     = lazy(() => import('@/pages/accounting/journal-entries/create'));
const JournalEntryDetailPage     = lazy(() => import('@/pages/accounting/journal-entries/detail'));
const VendorsPage                = lazy(() => import('@/pages/accounting/vendors'));
const CreateVendorPage           = lazy(() => import('@/pages/accounting/vendors/create'));
const EditVendorPage             = lazy(() => import('@/pages/accounting/vendors/edit'));
const VendorDetailPage           = lazy(() => import('@/pages/accounting/vendors/detail'));
const BillsPage                  = lazy(() => import('@/pages/accounting/bills'));
const CreateBillPage             = lazy(() => import('@/pages/accounting/bills/create'));
const BillDetailPage             = lazy(() => import('@/pages/accounting/bills/detail'));
const CustomersPage              = lazy(() => import('@/pages/accounting/customers'));
const CreateCustomerPage         = lazy(() => import('@/pages/accounting/customers/create'));
const EditCustomerPage           = lazy(() => import('@/pages/accounting/customers/edit'));
const CustomerDetailPage         = lazy(() => import('@/pages/accounting/customers/detail'));
const InvoicesPage               = lazy(() => import('@/pages/accounting/invoices'));
const CreateInvoicePage          = lazy(() => import('@/pages/accounting/invoices/create'));
const InvoiceDetailPage          = lazy(() => import('@/pages/accounting/invoices/detail'));
const TrialBalancePage           = lazy(() => import('@/pages/accounting/trial-balance'));
const IncomeStatementPage        = lazy(() => import('@/pages/accounting/income-statement'));
const BalanceSheetPage           = lazy(() => import('@/pages/accounting/balance-sheet'));

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

          {/* Payroll module */}
          <Route element={<ModuleGuard module="payroll" />}>
            <Route
              path="/payroll/periods"
              element={<PermissionGuard permission="payroll.view"><PayrollPeriodsPage /></PermissionGuard>}
            />
            <Route
              path="/payroll/periods/create"
              element={<PermissionGuard permission="payroll.periods.create"><CreatePayrollPeriodPage /></PermissionGuard>}
            />
            <Route
              path="/payroll/periods/:id"
              element={<PermissionGuard permission="payroll.view"><PayrollPeriodDetailPage /></PermissionGuard>}
            />
            <Route
              path="/payroll/periods/:id/employee/:eid"
              element={<PermissionGuard permission="payroll.view"><PayrollEmployeeDetailPage /></PermissionGuard>}
            />
            <Route
              path="/payroll/adjustments"
              element={<PermissionGuard permission="payroll.view"><PayrollAdjustmentsPage /></PermissionGuard>}
            />
            <Route
              path="/payroll/adjustments/create"
              element={<PermissionGuard permission="payroll.adjustments.create"><CreatePayrollAdjustmentPage /></PermissionGuard>}
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
          <Route
            path="/admin/gov-tables"
            element={
              <PermissionGuard permission="admin.gov_tables.manage">
                <AdminGovTablesPage />
              </PermissionGuard>
            }
          />

          {/* Accounting module (Sprint 4) */}
          <Route element={<ModuleGuard module="accounting" />}>
            <Route path="/accounting" element={<Navigate to="/accounting/coa" replace />} />

            <Route path="/accounting/coa"
              element={<PermissionGuard permission="accounting.coa.view"><ChartOfAccountsPage /></PermissionGuard>} />

            <Route path="/accounting/journal-entries"
              element={<PermissionGuard permission="accounting.journal.view"><JournalEntriesPage /></PermissionGuard>} />
            <Route path="/accounting/journal-entries/create"
              element={<PermissionGuard permission="accounting.journal.create"><CreateJournalEntryPage /></PermissionGuard>} />
            <Route path="/accounting/journal-entries/:id"
              element={<PermissionGuard permission="accounting.journal.view"><JournalEntryDetailPage /></PermissionGuard>} />

            <Route path="/accounting/vendors"
              element={<PermissionGuard permission="accounting.vendors.view"><VendorsPage /></PermissionGuard>} />
            <Route path="/accounting/vendors/create"
              element={<PermissionGuard permission="accounting.vendors.manage"><CreateVendorPage /></PermissionGuard>} />
            <Route path="/accounting/vendors/:id"
              element={<PermissionGuard permission="accounting.vendors.view"><VendorDetailPage /></PermissionGuard>} />
            <Route path="/accounting/vendors/:id/edit"
              element={<PermissionGuard permission="accounting.vendors.manage"><EditVendorPage /></PermissionGuard>} />

            <Route path="/accounting/bills"
              element={<PermissionGuard permission="accounting.bills.view"><BillsPage /></PermissionGuard>} />
            <Route path="/accounting/bills/create"
              element={<PermissionGuard permission="accounting.bills.create"><CreateBillPage /></PermissionGuard>} />
            <Route path="/accounting/bills/:id"
              element={<PermissionGuard permission="accounting.bills.view"><BillDetailPage /></PermissionGuard>} />

            <Route path="/accounting/customers"
              element={<PermissionGuard permission="accounting.customers.view"><CustomersPage /></PermissionGuard>} />
            <Route path="/accounting/customers/create"
              element={<PermissionGuard permission="accounting.customers.manage"><CreateCustomerPage /></PermissionGuard>} />
            <Route path="/accounting/customers/:id"
              element={<PermissionGuard permission="accounting.customers.view"><CustomerDetailPage /></PermissionGuard>} />
            <Route path="/accounting/customers/:id/edit"
              element={<PermissionGuard permission="accounting.customers.manage"><EditCustomerPage /></PermissionGuard>} />

            <Route path="/accounting/invoices"
              element={<PermissionGuard permission="accounting.invoices.view"><InvoicesPage /></PermissionGuard>} />
            <Route path="/accounting/invoices/create"
              element={<PermissionGuard permission="accounting.invoices.create"><CreateInvoicePage /></PermissionGuard>} />
            <Route path="/accounting/invoices/:id"
              element={<PermissionGuard permission="accounting.invoices.view"><InvoiceDetailPage /></PermissionGuard>} />

            <Route path="/accounting/trial-balance"
              element={<PermissionGuard permission="accounting.statements.view"><TrialBalancePage /></PermissionGuard>} />
            <Route path="/accounting/income-statement"
              element={<PermissionGuard permission="accounting.statements.view"><IncomeStatementPage /></PermissionGuard>} />
            <Route path="/accounting/balance-sheet"
              element={<PermissionGuard permission="accounting.statements.view"><BalanceSheetPage /></PermissionGuard>} />
          </Route>

          {/* Inventory module (Sprint 5) */}
          <Route element={<ModuleGuard module="inventory" />}>
            <Route path="/inventory" element={<Navigate to="/inventory/dashboard" replace />} />
            <Route path="/inventory/dashboard"
              element={<PermissionGuard permission="inventory.view"><InventoryDashboardPage /></PermissionGuard>} />

            <Route path="/inventory/items"
              element={<PermissionGuard permission="inventory.view"><ItemsListPage /></PermissionGuard>} />
            <Route path="/inventory/items/create"
              element={<PermissionGuard permission="inventory.items.manage"><CreateItemPage /></PermissionGuard>} />
            <Route path="/inventory/items/:id"
              element={<PermissionGuard permission="inventory.view"><ItemDetailPage /></PermissionGuard>} />
            <Route path="/inventory/items/:id/edit"
              element={<PermissionGuard permission="inventory.items.manage"><EditItemPage /></PermissionGuard>} />

            <Route path="/inventory/categories"
              element={<PermissionGuard permission="inventory.view"><ItemCategoriesPage /></PermissionGuard>} />
            <Route path="/inventory/warehouse"
              element={<PermissionGuard permission="inventory.view"><WarehousePage /></PermissionGuard>} />

            <Route path="/inventory/stock-levels"
              element={<PermissionGuard permission="inventory.view"><StockLevelsPage /></PermissionGuard>} />
            <Route path="/inventory/movements"
              element={<PermissionGuard permission="inventory.view"><StockMovementsPage /></PermissionGuard>} />
            <Route path="/inventory/stock-adjustments/create"
              element={<PermissionGuard permission="inventory.adjust"><CreateStockAdjustmentPage /></PermissionGuard>} />
            <Route path="/inventory/stock-transfers/create"
              element={<PermissionGuard permission="inventory.adjust"><CreateStockTransferPage /></PermissionGuard>} />

            <Route path="/inventory/grn"
              element={<PermissionGuard permission="inventory.view"><GrnListPage /></PermissionGuard>} />
            <Route path="/inventory/grn/create"
              element={<PermissionGuard permission="inventory.grn.create"><CreateGrnPage /></PermissionGuard>} />
            <Route path="/inventory/grn/:id"
              element={<PermissionGuard permission="inventory.view"><GrnDetailPage /></PermissionGuard>} />

            <Route path="/inventory/material-issues"
              element={<PermissionGuard permission="inventory.view"><MaterialIssuesListPage /></PermissionGuard>} />
            <Route path="/inventory/material-issues/create"
              element={<PermissionGuard permission="inventory.issue.create"><CreateMaterialIssuePage /></PermissionGuard>} />
          </Route>

          {/* Purchasing module (Sprint 5) */}
          <Route element={<ModuleGuard module="purchasing" />}>
            <Route path="/purchasing" element={<Navigate to="/purchasing/purchase-orders" replace />} />

            <Route path="/purchasing/purchase-requests"
              element={<PermissionGuard permission="purchasing.view"><PurchaseRequestsListPage /></PermissionGuard>} />
            <Route path="/purchasing/purchase-requests/create"
              element={<PermissionGuard permission="purchasing.pr.create"><CreatePurchaseRequestPage /></PermissionGuard>} />
            <Route path="/purchasing/purchase-requests/:id"
              element={<PermissionGuard permission="purchasing.view"><PurchaseRequestDetailPage /></PermissionGuard>} />

            <Route path="/purchasing/purchase-orders"
              element={<PermissionGuard permission="purchasing.view"><PurchaseOrdersListPage /></PermissionGuard>} />
            <Route path="/purchasing/purchase-orders/create"
              element={<PermissionGuard permission="purchasing.po.create"><CreatePurchaseOrderPage /></PermissionGuard>} />
            <Route path="/purchasing/purchase-orders/:id"
              element={<PermissionGuard permission="purchasing.view"><PurchaseOrderDetailPage /></PermissionGuard>} />

            <Route path="/purchasing/approved-suppliers"
              element={<PermissionGuard permission="purchasing.view"><ApprovedSuppliersPage /></PermissionGuard>} />
          </Route>
        </Route>

        {/* Self-service portal — separate mobile-friendly layout (SelfServiceLayout) */}
        <Route
          element={
            <AuthGuard>
              <SelfServiceLayout />
            </AuthGuard>
          }
        >
          <Route
            path="/self-service/payslips"
            element={<PermissionGuard permission="payroll.view"><SelfServicePayslipsPage /></PermissionGuard>}
          />
        </Route>

        {/* 404 */}
        <Route path="*" element={<NotFoundPage />} />
      </Routes>
    </Suspense>
  );
}
