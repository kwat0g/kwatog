import { lazy } from 'react';
import { Route, Navigate } from 'react-router-dom';
import { ModuleGuard } from '@/components/guards/ModuleGuard';
import { PermissionGuard } from '@/components/guards/PermissionGuard';

// Accounting (Sprint 4 — Tasks 31-37)
const ChartOfAccountsPage    = lazy(() => import('@/pages/accounting/coa'));
const CreateAccountPage      = lazy(() => import('@/pages/accounting/coa/create'));
const EditAccountPage        = lazy(() => import('@/pages/accounting/coa/edit'));
const JournalEntriesPage     = lazy(() => import('@/pages/accounting/journal-entries'));
const CreateJournalEntryPage = lazy(() => import('@/pages/accounting/journal-entries/create'));
const JournalEntryDetailPage = lazy(() => import('@/pages/accounting/journal-entries/detail'));
const VendorsPage            = lazy(() => import('@/pages/accounting/vendors'));
const CreateVendorPage       = lazy(() => import('@/pages/accounting/vendors/create'));
const EditVendorPage         = lazy(() => import('@/pages/accounting/vendors/edit'));
const VendorDetailPage       = lazy(() => import('@/pages/accounting/vendors/detail'));
const BillsPage              = lazy(() => import('@/pages/accounting/bills'));
const CreateBillPage         = lazy(() => import('@/pages/accounting/bills/create'));
const BillDetailPage         = lazy(() => import('@/pages/accounting/bills/detail'));
const CustomersPage          = lazy(() => import('@/pages/accounting/customers'));
const CreateCustomerPage     = lazy(() => import('@/pages/accounting/customers/create'));
const EditCustomerPage       = lazy(() => import('@/pages/accounting/customers/edit'));
const CustomerDetailPage     = lazy(() => import('@/pages/accounting/customers/detail'));
const InvoicesPage           = lazy(() => import('@/pages/accounting/invoices'));
const CreateInvoicePage      = lazy(() => import('@/pages/accounting/invoices/create'));
const InvoiceDetailPage      = lazy(() => import('@/pages/accounting/invoices/detail'));
const TrialBalancePage       = lazy(() => import('@/pages/accounting/trial-balance'));
const IncomeStatementPage    = lazy(() => import('@/pages/accounting/income-statement'));
const BalanceSheetPage       = lazy(() => import('@/pages/accounting/balance-sheet'));

export const accountingRoutes = (
  <>
    {/* Accounting module (Sprint 4) */}
    <Route element={<ModuleGuard module="accounting" />}>
      <Route path="/accounting" element={<Navigate to="/accounting/journal-entries" replace />} />

      <Route path="/accounting/coa"
        element={<PermissionGuard permission="accounting.coa.view"><ChartOfAccountsPage /></PermissionGuard>} />
      <Route path="/accounting/coa/create"
        element={<PermissionGuard permission="accounting.coa.manage"><CreateAccountPage /></PermissionGuard>} />
      <Route path="/accounting/coa/:id/edit"
        element={<PermissionGuard permission="accounting.coa.manage"><EditAccountPage /></PermissionGuard>} />

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
  </>
);
