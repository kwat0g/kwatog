import { lazy, Suspense, useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Outlet, useNavigate } from 'react-router-dom';
import { LuCircleHelp } from '@/lib/icons';
import toast from 'react-hot-toast';
import { Topbar } from '@/components/layout/Topbar';
import { Sidebar } from '@/components/layout/Sidebar';
import { Spinner } from '@/components/ui/Spinner';
import { DevErrorPanel } from '@/components/dev/DevErrorPanel';
import { OfflineBanner } from '@/components/ui/OfflineBanner';
import { useAuthStore } from '@/stores/authStore';
import { PageActionsProvider } from '@/contexts/PageActionsContext';
import { useKeyboardShortcuts } from '@/hooks/useKeyboardShortcuts';
import { usePermissionSync } from '@/hooks/usePermissionSync';
import { usePageFocus } from '@/hooks/usePageFocus';
import { useRecentPageTracker } from '@/hooks/useRecentPageTracker';

const KeyboardShortcutHelp = lazy(() =>
 import('@/components/ui/KeyboardShortcutHelp').then((m) => ({ default: m.KeyboardShortcutHelp })),
);
import { RouteTransition } from '@/components/ui/RouteTransition';
import { Tooltip } from '@/components/ui/Tooltip';
import { Button } from '@/components/ui/Button';
import { businessPoliciesApi } from '@/api/businessPolicies';
import { setFunctionalCurrency } from '@/lib/runtimeCurrency';
import { RealtimeStatusIndicator } from '@/components/layout/RealtimeStatusIndicator';
import { useEmployeeListRealtime, useRealtimeList } from '@/hooks/useRealtimeRecord';
import { useCacheGroupRealtime, useRealtimeSignal } from '@/hooks/useRealtimeSignal';
import { useRealtimeEvent } from '@/hooks/useRealtimeEvent';

import { ErrorBoundary } from '@/components/guards/ErrorBoundary';

// Inner component so the keyboard-shortcut hook lives inside the
// PageActionsProvider (otherwise the dispatcher context is null).
function AppLayoutInner() {
 const navigate = useNavigate();
 const user = useAuthStore((s) => s.user);
 const permissions = useAuthStore((s) => s.permissions);
 const features = useAuthStore((s) => s.features);
 const isSystemAdmin = user?.role?.slug === 'system_admin';
 const logout = useAuthStore((s) => s.logout);
 useRealtimeEvent('system.refresh', '.database.restore.completed', () => window.location.reload());
 const { helpOpen, setHelpOpen } = useKeyboardShortcuts();
 const {
  data: businessPolicies,
  isFetched: policiesFetched,
  isError: policiesError,
  isFetching: policiesFetching,
  refetch: refetchPolicies,
 } = useQuery({ queryKey: ['business-policies'], queryFn: businessPoliciesApi.get });
 // formatPeso reads the currency from a module variable, so a page that
 // rendered before this policy arrived kept bare numbers ("58,800.00", no ₱)
 // until something else re-rendered it. Hold route content until it is set.
 const [currencyApplied, setCurrencyApplied] = useState(false);
 const routeLoading = (
 <div className="flex min-h-[320px] items-center justify-center">
 <Spinner size="lg" />
 </div>
 );

 const functionalCurrencyCode = businessPolicies?.functional_currency_code;
 const currencyUnavailable = policiesError || (policiesFetched && !functionalCurrencyCode?.trim());

 useEffect(() => {
 if (currencyUnavailable) {
 setFunctionalCurrency(undefined);
 setCurrencyApplied(false);
 return;
 }
 if (functionalCurrencyCode?.trim()) {
 setFunctionalCurrency(functionalCurrencyCode);
 setCurrencyApplied(true);
 }
 }, [currencyUnavailable, functionalCurrencyCode]);

 // Listen for real-time permission and module toggle changes
 usePermissionSync();
 // Delegations change the effective approval roles returned by chain bottlenecks.
 // Keep every authenticated screen on the viewer's exact-user refresh channel.
 useRealtimeSignal(
  'approval_delegations_changed',
  user?.id ? `approval-delegation.user.${user.id}` : '',
  '.approval.delegation.changed',
 );
 // Personal dashboard layout changes are private to this account and refresh sibling tabs.
 useRealtimeSignal(
  'dashboard_layout_changed',
  user?.id ? `user.preferences.${user.id}` : '',
  '.dashboard.layout.changed',
 );
 // Employee changes feed HR and self-service queries outside the employee
 // list route, so keep one permission-scoped listener at the shell boundary.
 useEmployeeListRealtime();
 // An employee's attendance changes use the exact-owner private channel and
 // invalidate their self-service and dashboard queries through the shared map.
 useRealtimeSignal(
  'attendance_changed',
  features.has('hr') && user?.employee?.id && user.id ? `employee.user.${user.id}` : '',
  '.attendance.changed',
 );
 // Settings are content-free refresh signals; each affected page refetches
 // through its own permission-checked API endpoint.
 useRealtimeSignal('settings_changed', user?.id ? 'settings.refresh' : '', '.settings.changed');
 const canReadWarehouseLayout = (
  features.has('inventory')
  && (isSystemAdmin || permissions.has('inventory.view') || permissions.has('inventory.warehouse.manage'))
 ) || (
  features.has('return_management')
  && (permissions.has('return_management.receive') || permissions.has('return_management.dispose') || permissions.has('return_management.complete'))
 ) || permissions.has('dashboard.warehouse.view');
 useRealtimeSignal(
  'warehouse_layout_changed',
  canReadWarehouseLayout ? 'inventory.warehouse-layout' : '',
  '.inventory.warehouse-layout.changed',
 );
 useRealtimeSignal(
  'warehouse_stock_changed',
  features.has('inventory') && permissions.has('inventory.warehouse.manage')
   ? 'entity.warehouse_map.visible'
   : '',
  '.InventoryStockChanged',
 );
 // Widget source writes can affect viewers who are not authorized for the
 // source record. This channel carries only a refresh ping; API reads apply
 // each viewer's current widget and data permissions again.
 useRealtimeSignal(
  'dashboard_widget_data_changed',
  user?.id ? 'dashboard.widgets' : '',
  '.dashboard.widgets.changed',
 );
 useRealtimeSignal(
  'warehouse_dashboard_changed',
  (isSystemAdmin || permissions.has('dashboard.warehouse.view')) ? 'dashboard.warehouse' : '',
  '.dashboard.warehouse.changed',
 );
 useRealtimeSignal(
  'hr_dashboard_changed',
  permissions.has('dashboard.hr.view') ? 'dashboard.hr' : '',
  '.hr.dashboard.changed',
 );
 // Personal dashboard aggregates can change through HR/payroll writes whose
 // record channels are intentionally unavailable to ordinary employees.
 const hasLinkedEmployee = Boolean(user?.employee?.id);
 useRealtimeSignal(
  'employee_dashboard_changed',
  hasLinkedEmployee ? 'dashboard.employee' : '',
  '.employee.dashboard.changed',
 );
 useRealtimeSignal(
  'employee_dashboard_changed',
  hasLinkedEmployee && user?.id ? `dashboard.employee.user.${user.id}` : '',
  '.employee.dashboard.changed',
 );

 // Leave catalog and balance changes are shared reference/aggregate signals.
 // Mount one permission-scoped subscription for the shell so any active leave,
 // HR, self-service or dashboard query is invalidated through the central map.
 const leaveEnabled = features.has('leave');
 useCacheGroupRealtime('dashboards', Boolean(user?.id));
 useCacheGroupRealtime(
  'financial-statements',
  features.has('accounting') && (isSystemAdmin || permissions.has('accounting.statements.view') || permissions.has('dashboard.accounting.view')),
 );
 useCacheGroupRealtime(
  'procurement-chain',
  (features.has('purchasing') && (isSystemAdmin || permissions.has('purchasing.view') || permissions.has('purchasing.chain.view') || permissions.has('dashboard.purchasing.view') || permissions.has('dashboard.view_bottlenecks')))
  || (features.has('inventory') && (isSystemAdmin || permissions.has('inventory.view')))
  || (features.has('accounting') && (isSystemAdmin || permissions.has('accounting.bills.view') || permissions.has('dashboard.accounting.view'))),
 );
 const canReadLeaveTypes = leaveEnabled && (isSystemAdmin || permissions.has('leave.view'));
 const canReadLeaveBalances = leaveEnabled && (
  isSystemAdmin
  || permissions.has('leave.view')
  || permissions.has('leave.approve_dept')
  || permissions.has('leave.approve_hr')
 );
 const canReadLeaveRequests = leaveEnabled && (isSystemAdmin || permissions.has('leave.view'));
 useRealtimeList('leave_request', canReadLeaveRequests ? 'visible' : undefined);
 const canReadAttendance = features.has('attendance') && (isSystemAdmin || permissions.has('attendance.view'));
 useRealtimeList('attendance', canReadAttendance ? 'visible' : undefined);
 useRealtimeList('leave_type', canReadLeaveTypes ? 'visible' : undefined);
 useRealtimeList('leave_balance', canReadLeaveBalances ? 'visible' : undefined);
 const canReadHolidayUpdates = (features.has('attendance') && (
  isSystemAdmin
  || permissions.has('attendance.view')
  || permissions.has('attendance.edit')
  || permissions.has('attendance.holidays.manage')
 )) || permissions.has('calendar.view') || permissions.has('dashboard.hr.view') || Boolean(user?.employee?.id);
 useRealtimeList('holiday', canReadHolidayUpdates ? 'visible' : undefined);
 const canReadDepartments = features.has('hr') && (
  isSystemAdmin || permissions.has('hr.departments.view') || permissions.has('hr.directory.view')
 );
 useRealtimeList('department', canReadDepartments || permissions.has('admin.users.manage') ? 'visible' : undefined);
 const canReadPositions = features.has('hr') && (isSystemAdmin || permissions.has('hr.positions.view'));
 useRealtimeList('position', canReadPositions ? 'visible' : undefined);
 const canReadChartOfAccounts = features.has('accounting') && (
  isSystemAdmin || permissions.has('accounting.coa.view')
 );
 useRealtimeList('account', canReadChartOfAccounts ? 'visible' : undefined);
 useRealtimeList(
  'transfer_order',
  features.has('inventory') && (isSystemAdmin || permissions.has('inventory.adjust')) ? 'visible' : undefined,
 );
 const canReadVendors = features.has('accounting') && (
  isSystemAdmin || permissions.has('accounting.vendors.view')
 );
 useRealtimeList('vendor', canReadVendors ? 'visible' : undefined);
 const canReadCustomerRefresh = (features.has('accounting') || features.has('crm'))
  && (isSystemAdmin || permissions.has('accounting.customers.view'));
 const canReadForecastCustomerLabels = features.has('forecasting')
  && permissions.has('forecasting.view');
 useRealtimeList('customer', canReadCustomerRefresh || canReadForecastCustomerLabels ? 'visible' : undefined);
 const canReadSupplierPerformance = features.has('purchasing') && (
  isSystemAdmin || permissions.has('purchasing.suppliers.performance.view')
 );
 useRealtimeList('supplier_performance', canReadSupplierPerformance ? 'visible' : undefined);
 const canReadCreditNotes = features.has('accounting') && (
  isSystemAdmin || permissions.has('accounting.credit_notes.view')
 );
 useRealtimeList('credit_note', canReadCreditNotes ? 'visible' : undefined);
 const canReadBudgetTransfers = features.has('budgeting') && (
  isSystemAdmin || permissions.has('budgeting.view') || permissions.has('budgeting.approve')
 );
 useRealtimeList('budget_transfer', canReadBudgetTransfers ? 'visible' : undefined);
 const canReadBudgets = features.has('budgeting') && (
  isSystemAdmin || permissions.has('budgeting.view') || permissions.has('budgeting.approve')
 );
 useRealtimeList('budget', canReadBudgets ? 'visible' : undefined);
 const canReviewPayrollAnomalies = features.has('payroll') && permissions.has('payroll.anomalies.review');
 useRealtimeList('payroll_anomaly', canReviewPayrollAnomalies ? 'visible' : undefined);
 const canManageUsers = permissions.has('admin.users.manage');
 useRealtimeList('admin_user', canManageUsers ? 'visible' : undefined);
 const canManageRoles = permissions.has('admin.roles.manage');
 useRealtimeList('role', canManageRoles ? 'visible' : undefined);
 const canReadComplaints = features.has('crm') && permissions.has('crm.complaints.view');
 useRealtimeList('customer_complaint', canReadComplaints ? 'visible' : undefined);
 const canReadProducts = features.has('crm') && (isSystemAdmin || [
  'crm.products.view',
  'production.molds.manage',
  'quality.inspections.view',
  'quality.inspections.manage',
  'quality.ncr.manage',
  'quality.specs.view',
  'forecasting.view',
 ].some((permission) => permissions.has(permission)));
 useRealtimeList('product', canReadProducts ? 'visible' : undefined);
 const canReadPriceAgreements = features.has('crm') && (
  isSystemAdmin || permissions.has('crm.price_agreements.view')
 );
 useRealtimeList('price_agreement', canReadPriceAgreements ? 'visible' : undefined);
 const canReadPriceRequests = features.has('crm') && (
  isSystemAdmin || ['crm.price_agreements.view', 'crm.price_agreements.approve', 'crm.price_agreements.propose']
   .some((permission) => permissions.has(permission))
 );
 useRealtimeList('price_request', canReadPriceRequests ? 'visible' : undefined);

 // Move focus to new page content after route transitions (a11y)
 usePageFocus();

 // Feed page visits into the ⌘K palette's Recent group
 useRecentPageTracker();

 const onLogout = async () => {
 await logout();
 toast.success('Signed out.');
  navigate('/sign-in', { replace: true });
 };

 return (
 <div className="app-shell min-h-screen bg-canvas text-primary flex flex-col">
 <a
 href="#main-content"
 className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-[100] focus:px-3 focus:py-1.5 focus:rounded-md focus:bg-accent focus:text-accent-fg focus:text-sm"
 >
 Skip to content
 </a>
 <OfflineBanner />
 <Topbar
 user={user ? { name: user.name, email: user.email, avatarUrl: user.employee?.photo_url } : null}
 canViewNotifications={permissions.has('notifications.view')}
 onLogout={onLogout}
  onOpenShortcuts={() => setHelpOpen(true)}
  rightExtras={
 <div className="flex items-center gap-1">
  <RealtimeStatusIndicator />
 <span className="hidden lg:inline-flex">
  <Tooltip content="Keyboard shortcuts (?)">
   <Button
   variant="ghost"
   size="sm"
   iconOnly
   icon={<LuCircleHelp size={14} />}
   aria-label="Keyboard shortcuts"
   onClick={() => setHelpOpen(true)}
   className="min-h-[44px] min-w-[44px] text-muted hover:text-primary sm:min-h-0 sm:min-w-0"
   />
  </Tooltip>
 </span>
 </div>
 }
 />
 <div className="app-content flex flex-1">
 <Sidebar permissions={permissions} delegatedApprovals={user?.delegated_approvals} features={features} roleSlug={user?.role?.slug} />
 <main id="main-content" tabIndex={-1} className="app-main flex-1 min-w-0">
 {/* Route content stays immediate; RouteTransition is a semantic wrapper only. */}
 <RouteTransition>
 {/*
 * The app's only ErrorBoundary was in App.tsx, wrapping this layout —
 * so a throw in any page unmounted the topbar, the sidebar and the
 * breadcrumbs with it, leaving "Something went wrong" and a Reload
 * button as the only way out of a working session. A page crash costs
 * the user that page now, not their navigation.
 */}
 <ErrorBoundary>
 <Suspense fallback={routeLoading}>
 {currencyUnavailable ? (
 <div role="alert" className="px-5 py-4 text-sm text-danger-fg">
 <p>Business currency settings are unavailable. Financial values are hidden until settings load.</p>
 <Button
  type="button"
  variant="secondary"
  size="sm"
  loading={policiesFetching}
  onClick={() => void refetchPolicies()}
  className="mt-3"
 >
  Retry business settings
 </Button>
 </div>
 ) : currencyApplied ? <Outlet /> : routeLoading}
 </Suspense>
 </ErrorBoundary>
 </RouteTransition>
 </main>
 </div>
 <DevErrorPanel />
 <Suspense fallback={null}>
 <KeyboardShortcutHelp open={helpOpen} onClose={() => setHelpOpen(false)} />
 </Suspense>
 </div>
 );
}

export function AppLayout() {
 return (
 <PageActionsProvider>
 <AppLayoutInner />
 </PageActionsProvider>
 );
}

export default AppLayout;
