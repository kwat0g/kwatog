import { IconType } from '@/lib/icons';
/* eslint-disable react-refresh/only-export-components -- navigation metadata is intentionally shared with command/search surfaces. */
import { Link, useLocation } from 'react-router-dom';
import {
  LuLayoutDashboard,
  LuClock4,
  LuCalendarDays,
  LuWallet,
  LuBookOpen,
  LuTruck,
  LuLayers,
  LuBriefcase,
  LuShieldCheck,
  LuWrench,
  LuSettings as SettingsIcon,
  LuCalendarClock,
  LuUsers,
  LuFileText,
  LuPackage,
  LuBell,
  LuInbox,
  LuMailOpen,
  LuTriangleAlert,
  LuCalendar,
  LuArrowLeftRight,
  LuRotateCcw,
  LuSettings2,
  LuSlidersHorizontal,
  LuBuilding2,
  LuChartColumn,
  LuChartColumnIncreasing,
  LuChartPie,
  LuLandmark,
  LuStore,
  LuTarget,
  LuTag,
  LuClipboardList,
  LuGitFork,
  LuListTree,
  LuCpu,
  LuBadgeCheck,
  LuRoute,
  LuX,
  LuShieldAlert,
  LuMonitor,
  LuDatabase,
  LuListChecks,
  LuScanBarcode,
  LuGraduationCap,

} from '@/lib/icons';
import {
  WorkflowIcon,
  OrderIcon,
  RequestIcon,
  InvoiceIcon,
  BillIcon,
  DeliveryIcon,
  ScheduleIcon,
  WorkOrderIcon,
  ReceivingIcon,
  IssuanceIcon,
  InventoryIcon,
  PickingIcon,
  WarehouseMapIcon,
  InspectionIcon,
  ComplaintIcon,
  ReturnIcon,
  PriceAgreementIcon,
  FleetIcon,
  CreditNoteIcon,
} from '@/lib/icons';

import { memo, useCallback, useEffect, useMemo, useRef } from 'react';
import { cn } from '@/lib/cn';
import { useSidebarStore } from '@/stores/sidebarStore';
import { Tooltip } from '@/components/ui/Tooltip';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { useBadges } from '@/hooks/useBadges';
import type { BadgeSeverity } from '@/api/badges';

export interface NavItem {
  to: string;
  label: string;
  icon: IconType;
  /** Optional unread/pending count badge (static). */
  badge?: number;
  /**
   * Polish Task S2 — dynamic badge key. The `useBadges()` hook returns a map
   * keyed by these slugs; if a key is present and count > 0 we render its
   * count + severity-coloured Badge.
   */
  badgeKey?: string;
  /** Optional permission gate slug; sidebar hides items the user can't access. */
  permission?: string;
  /** Show when the user has at least one permission in this list. */
  anyPermissions?: string[];
  /** Optional feature flag (e.g. 'hr', 'inventory'). */
  feature?: string;
}

export interface NavSection {
  label: string;
  items: NavItem[];
}

/**
 * Sidebar — workflow pages only.
 *
 * Visibility is decided by permission + feature flag, never by role name:
 * `isNavItemVisible` reads `roleSlug` only for the system_admin bypass that
 * mirrors User::hasPermission. A per-item role allowlist used to exist here and
 * was never set by a single item — dead code that invited role-name coupling
 * back into navigation. Standalone "dashboard" sub-pages (Production Dashboard,
 * Quality Dashboard) are removed — users reach their role dashboard via the
 * top-level /dashboard redirect.
 */
export const SECTIONS: NavSection[] = [
  {
    label: 'Overview',
    items: [
      { to: '/dashboard', label: 'Dashboard', icon: LuLayoutDashboard },
      { to: '/action-center', label: 'Action Center', icon: LuListChecks, badgeKey: 'action_center' },
      // /exceptions removed 2026-08-08 (scope cut — folded into Action Center as
      // the 'Exceptions' scope toggle, which filters out approvals exactly like the
      // old ActionCenterService::exceptions endpoint. Page file kept.)
      { to: '/dashboard/scorecard', label: 'KPI Scorecard', icon: LuChartColumnIncreasing },
      {
        to: '/chains',
        label: 'Chain Tracker',
        icon: WorkflowIcon,
        permission: 'crm.sales_orders.view',
      },
      {
        to: '/chains/recovery',
        label: 'Automation Recovery',
        icon: LuRotateCcw,
        permission: 'dashboard.chain_recovery.view',
      },
      {
        to: '/approvals',
        label: 'Approvals',
        icon: LuInbox,
        permission: 'approvals.board.view',
        badgeKey: 'approvals',
      },
      { to: '/notifications', label: 'Notifications', icon: LuBell, badgeKey: 'unread' },
    ],
  },
  {
    label: 'Sales & CRM',
    items: [
      {
        to: '/crm/sales-orders',
        label: 'Sales Orders',
        icon: OrderIcon,
        feature: 'crm',
        permission: 'crm.sales_orders.view',
        badgeKey: 'pending_so',
      },
      {
        to: '/crm/inquiries',
        label: 'Inquiries',
        icon: LuMailOpen,
        feature: 'crm',
        permission: 'crm.inquiries.view',
        badgeKey: 'inquiries',
      },
      // Customers deduped 2026-08-08: /accounting/customers and /crm/customers are
      // the SAME table + controller (Accounting CustomerController). One entry kept
      // under Sales & CRM, gated on accounting.customers.view (the shared backend
      // permission) so finance_officer still sees it — crm.sales_orders.view is only
      // held by system_admin. The /accounting/customers URL still works for links.
      {
        to: '/crm/customers',
        label: 'Customers',
        icon: LuUsers,
        feature: 'crm',
        permission: 'accounting.customers.view',
      },
      {
        to: '/crm/products',
        label: 'Products',
        icon: LuTag,
        feature: 'crm',
        permission: 'crm.products.view',
      },
      {
        to: '/crm/price-agreements',
        label: 'Price Agreements',
        icon: PriceAgreementIcon,
        feature: 'crm',
        permission: 'crm.price_agreements.view',
      },
      {
        to: '/crm/complaints',
        label: 'Complaints',
        icon: ComplaintIcon,
        feature: 'crm',
        permission: 'crm.complaints.manage',
        badgeKey: 'open_complaints',
      },
      {
        to: '/return-management',
        label: 'Returns (RMA)',
        icon: ReturnIcon,
        feature: 'return_management',
        permission: 'return_management.view',
        badgeKey: 'pending_returns',
      },
    ],
  },
  {
    label: 'Production',
    items: [
      {
        to: '/production/work-orders',
        label: 'Work Orders',
        icon: WorkOrderIcon,
        feature: 'production',
        permission: 'production.work_orders.view',
        badgeKey: 'work_orders',
      },
      {
        to: '/production/schedule',
        label: 'Schedule (Gantt)',
        icon: LuCalendarClock,
        feature: 'production',
        permission: 'production.schedule.view',
      },
      {
        to: '/production/routings',
        label: 'Routings',
        icon: LuRoute,
        feature: 'production',
        permission: 'production.routings.view',
      },
    ],
  },
  {
    label: 'Production Planning (MRP)',
    items: [
      {
        to: '/mrp/plans',
        label: 'MRP Plans',
        icon: LuLayers,
        feature: 'mrp',
        permission: 'mrp.plans.view',
        badgeKey: 'mrp_plans',
      },
      {
        to: '/mrp/boms',
        label: 'Bill of Materials',
        icon: LuListTree,
        feature: 'mrp',
        permission: 'mrp.boms.view',
      },
      {
        to: '/mrp/machines',
        label: 'Machines',
        icon: LuCpu,
        feature: 'mrp',
        permission: 'mrp.machines.view',
      },
      {
        to: '/mrp/molds',
        label: 'Molds',
        icon: LuPackage,
        feature: 'mrp',
        permission: 'mrp.molds.view',
      },
    ],
  },
  {
    label: 'Procurement',
    items: [
      {
        to: '/purchasing/chain',
        label: 'Procurement Chain',
        icon: WorkflowIcon,
        feature: 'purchasing',
        permission: 'purchasing.view',
      },
      {
        to: '/purchasing/purchase-orders',
        label: 'Purchase Orders',
        icon: OrderIcon,
        feature: 'purchasing',
        permission: 'purchasing.view',
      },
      {
        to: '/purchasing/purchase-requests',
        label: 'Purchase Requests',
        icon: RequestIcon,
        feature: 'purchasing',
        permission: 'purchasing.view',
        badgeKey: 'purchase_requests',
      },
      {
        to: '/purchasing/approved-suppliers',
        label: 'Approved Suppliers',
        icon: LuBadgeCheck,
        feature: 'purchasing',
        permission: 'purchasing.view',
      },
    ],
  },
  {
    label: 'Warehouse',
    items: [
      {
        to: '/inventory/items',
        label: 'Items',
        icon: InventoryIcon,
        feature: 'inventory',
        permission: 'inventory.view',
        badgeKey: 'low_stock',
      },
      {
        to: '/inventory/grn',
        label: 'Receiving (GRN)',
        icon: ReceivingIcon,
        feature: 'inventory',
        permission: 'inventory.view',
        badgeKey: 'pending_grn',
      },
      {
        to: '/inventory/material-issues',
        label: 'Issuance',
        icon: IssuanceIcon,
        feature: 'inventory',
        permission: 'inventory.view',
      },
      {
        to: '/inventory/mrb',
        label: 'MRB / Quarantine',
        icon: LuTriangleAlert,
        feature: 'inventory',
        permission: 'inventory.mrb.view',
        badgeKey: 'mrb_holds',
      },
      {
        to: '/inventory/stock-levels',
        label: 'Stock Levels',
        icon: LuChartColumnIncreasing,
        feature: 'inventory',
        permission: 'inventory.view',
      },
      // /inventory/movements removed 2026-08-08 (scope cut — now a view toggle on Stock Levels)
      {
        to: '/inventory/stock-adjustments',
        label: 'Stock Adjustments',
        icon: LuSlidersHorizontal,
        feature: 'inventory',
        permission: 'inventory.view',
      },
      {
        to: '/inventory/scanner',
        label: 'Warehouse Scanner',
        icon: LuScanBarcode,
        feature: 'inventory',
        permission: 'inventory.view',
      },
      // Stock Count merged into Warehouse Map (2026-08-08) — use its Map | Stock
      // Count toggle. The /inventory/stock-count path still works (scanner links).
      {
        to: '/inventory/warehouse-map',
        label: 'Warehouse Map',
        icon: WarehouseMapIcon,
        feature: 'inventory',
        permission: 'inventory.view',
      },
      {
        to: '/inventory/transfer-orders',
        label: 'Transfer Orders',
        icon: LuArrowLeftRight,
        feature: 'inventory',
        permission: 'inventory.view',
      },
      {
        to: '/inventory/picking',
        label: 'Picking',
        icon: PickingIcon,
        feature: 'inventory',
        permission: 'inventory.view',
      },
    ],
  },
  {
    label: 'Supply Chain',
    items: [
      {
        to: '/supply-chain/deliveries',
        label: 'Deliveries',
        icon: LuTruck,
        feature: 'supply_chain',
        // Either read reaches the page (see supplyChainRoutes). Warehouse staff
        // hold only the narrow one, and an item they can open must be an item
        // they can see.
        anyPermissions: ['supply_chain.view', 'supply_chain.deliveries.view'],
        badgeKey: 'deliveries',
      },
      {
        to: '/supply-chain/shipments',
        label: 'Shipments',
        icon: DeliveryIcon,
        feature: 'supply_chain',
        permission: 'supply_chain.view',
        badgeKey: 'shipments',
      },
      {
        to: '/supply-chain/fleet',
        label: 'Fleet',
        icon: FleetIcon,
        feature: 'supply_chain',
        permission: 'supply_chain.view',
      },
    ],
  },
  {
    label: 'Quality',
    items: [
      {
        to: '/quality/inspection-specs',
        label: 'Inspection Specs',
        icon: LuClipboardList,
        feature: 'quality',
        permission: 'quality.specs.view',
      },
      {
        to: '/quality/inspections',
        label: 'Inspections',
        icon: InspectionIcon,
        feature: 'quality',
        permission: 'quality.inspections.view',
        badgeKey: 'pending_inspections',
      },
      {
        to: '/quality/ncrs',
        label: 'NCRs',
        icon: LuTriangleAlert,
        feature: 'quality',
        permission: 'quality.ncr.view',
        badgeKey: 'ncrs',
      },
      {
        to: '/quality/traceability',
        label: 'Traceability',
        icon: LuGitFork,
        feature: 'quality',
        permission: 'quality.inspections.view',
      },
    ],
  },
  {
    label: 'Finance',
    items: [
      {
        to: '/accounting/coa',
        label: 'Chart of Accounts',
        icon: LuLandmark,
        feature: 'accounting',
        permission: 'accounting.coa.view',
      },
      {
        to: '/accounting/journal-entries',
        label: 'Journal Entries',
        icon: LuBookOpen,
        feature: 'accounting',
        permission: 'accounting.journal.view',
      },
      {
        to: '/accounting/invoices',
        label: 'Invoices (AR)',
        icon: InvoiceIcon,
        feature: 'accounting',
        permission: 'accounting.invoices.view',
        badgeKey: 'draft_invoices',
      },
      {
        to: '/accounting/bills',
        label: 'Bills (AP)',
        icon: BillIcon,
        feature: 'accounting',
        permission: 'accounting.bills.view',
        badgeKey: 'overdue_bills',
      },
      {
        to: '/accounting/credit-notes',
        label: 'Credit Notes',
        icon: CreditNoteIcon,
        feature: 'accounting',
        permission: 'accounting.credit_notes.view',
      },
      {
        to: '/accounting/vendors',
        label: 'Vendors',
        icon: LuStore,
        feature: 'accounting',
        permission: 'accounting.vendors.view',
      },
      {
        to: '/accounting/periods',
        label: 'Accounting Periods',
        icon: ScheduleIcon,
        feature: 'accounting',
        permission: 'accounting.periods.view',
      },
      {
        to: '/budgeting',
        label: 'Budgets',
        icon: LuChartPie,
        feature: 'budgeting',
        permission: 'budgeting.view',
      },
      {
        to: '/budgeting/budget-vs-actual',
        label: 'Budget vs Actual',
        icon: LuTarget,
        feature: 'budgeting',
        permission: 'budgeting.view',
      },
    ],
  },
  {
    label: 'Human Resources',
    items: [
      {
        to: '/hr/employees',
        label: 'Employees',
        icon: LuUsers,
        feature: 'hr',
        anyPermissions: ['hr.employees.view', 'hr.salary_adjustments.view'],
        badgeKey: 'profile_requests',
      },
      {
        to: '/hr/departments',
        label: 'Departments',
        icon: LuBuilding2,
        feature: 'hr',
        permission: 'hr.departments.view',
      },
      {
        to: '/hr/attendance',
        label: 'Attendance',
        icon: LuClock4,
        feature: 'attendance',
        anyPermissions: ['attendance.edit', 'attendance.import', 'attendance.ot.approve'],
      },
      {
        to: '/hr/attendance/overtime',
        label: 'Overtime',
        icon: LuCalendarClock,
        feature: 'attendance',
        permission: 'attendance.ot.approve',
        badgeKey: 'overtime',
      },
      // 2026-08-08: the `leaves` badge (pending leave approvals) moved here from
      // Attendance — the Leave page is where those approvals are actioned.
      {
        to: '/hr/leaves',
        label: 'Leave',
        icon: LuCalendarDays,
        feature: 'leave',
        anyPermissions: ['leave.approve_dept', 'leave.approve_hr', 'leave.types.manage'],
        badgeKey: 'leaves',
      },
      {
        to: '/payroll/periods',
        label: 'Payroll',
        icon: LuWallet,
        feature: 'payroll',
        permission: 'payroll.periods.view',
        badgeKey: 'payroll',
      },
      {
        to: '/payroll/adjustments',
        label: 'Adjustments',
        icon: LuSettings2,
        feature: 'payroll',
        permission: 'payroll.adjustments.create',
      },
      {
        to: '/payroll/statutory',
        label: 'Statutory Exports',
        icon: LuFileText,
        feature: 'payroll',
        permission: 'payroll.statutory.export',
      },
      {
        to: '/hr/recruitment',
        label: 'Recruitment',
        icon: LuBriefcase,
        feature: 'recruitment',
        permission: 'hr.recruitment.view',
        badgeKey: 'open_postings',
      },
      {
        to: '/hr/training/matrix',
        label: 'Training Matrix',
        icon: LuClipboardList,
        feature: 'hr',
        permission: 'hr.trainings.view',
        badgeKey: 'training_expiry',
      },
      {
        to: '/hr/trainings',
        label: 'Trainings',
        icon: LuGraduationCap,
        feature: 'hr',
        permission: 'hr.trainings.view',
        badgeKey: 'training_upcoming',
      },
    ],
  },
  {
    label: 'Maintenance',
    items: [
      {
        to: '/maintenance/work-orders',
        label: 'Work Orders',
        icon: LuWrench,
        feature: 'maintenance',
        permission: 'maintenance.view',
        badgeKey: 'maintenance_wo',
      },
      {
        to: '/maintenance/schedules',
        label: 'Schedules',
        icon: LuCalendar,
        feature: 'maintenance',
        permission: 'maintenance.view',
      },
      {
        to: '/maintenance/downtime',
        label: 'Downtime',
        icon: LuChartColumn,
        feature: 'maintenance',
        permission: 'maintenance.view',
      },
    ],
  },
  {
    label: 'Assets',
    items: [
      {
        to: '/assets',
        label: 'Fixed Assets',
        icon: LuBuilding2,
        feature: 'assets',
        permission: 'assets.view',
      },
    ],
  },
  {
    label: 'Administration',
    items: [
      { to: '/admin/users', label: 'Users', icon: LuUsers, permission: 'admin.users.manage' },
      { to: '/admin/roles', label: 'Roles', icon: LuShieldCheck, permission: 'admin.roles.manage' },
      {
        to: '/admin/audit-logs',
        label: 'Audit Logs',
        icon: LuFileText,
        permission: 'admin.audit_logs.view',
      },
      {
        to: '/admin/sod',
        label: 'Segregation of Duties',
        icon: LuShieldAlert,
        permission: 'admin.sod.view',
      },
      {
        to: '/admin/settings',
        label: 'LuSettings',
        icon: SettingsIcon,
        permission: 'admin.settings.manage',
      },
      {
        to: '/admin/sessions',
        label: 'Sessions',
        icon: LuMonitor,
        permission: 'admin.settings.manage',
      },
      {
        to: '/admin/backups',
        label: 'Backup & Restore',
        icon: LuDatabase,
        permission: 'admin.backups.view',
      },
      {
        to: '/admin/gov-tables',
        label: 'Gov Contribution Tables',
        icon: LuLandmark,
        permission: 'admin.gov_tables.manage',
      },
      // /admin/depreciation removed 2026-08-08 (scope cut — monthly runner moved
      // to the Fixed Assets page as a header button/modal, same permission gate).
    ],
  },
];

export interface NavVisibilityContext {
  permissions?: Set<string>;
  features?: Set<string>;
  roleSlug?: string;
}

/**
 * Shared nav-item gate — used by the Sidebar and the ⌘K palette's "Go to"
 * group so both surfaces always show exactly the same destinations.
 *
 * system_admin bypasses the permission gate, mirroring usePermission().can()'s
 * isAdmin short-circuit — otherwise an admin with an empty permission set would
 * see a thinner menu than the pages they can actually open.
 */
export function isNavItemVisible(
  item: NavItem,
  { permissions, features, roleSlug }: NavVisibilityContext,
): boolean {
  const isAdmin = roleSlug === 'system_admin';
  if (item.feature && features && !features.has(item.feature)) return false;
  if (!isAdmin && item.permission && permissions && !permissions.has(item.permission)) return false;
  if (
    !isAdmin &&
    item.anyPermissions &&
    permissions &&
    !item.anyPermissions.some((permission) => permissions.has(permission))
  )
    return false;
  return true;
}

interface SidebarProps {
  permissions?: Set<string>;
  features?: Set<string>;
  roleSlug?: string;
}

export const Sidebar = memo(function Sidebar({ permissions, features, roleSlug }: SidebarProps) {
  const collapsed = useSidebarStore((s) => s.collapsed);
  const mobileOpen = useSidebarStore((s) => s.mobileOpen);
  const setMobileOpen = useSidebarStore((s) => s.setMobileOpen);
  const { pathname } = useLocation();

  // Close mobile drawer on route change.
  useEffect(() => {
    setMobileOpen(false);
  }, [pathname, setMobileOpen]);

  // Lock body scroll while mobile drawer is open.
  useEffect(() => {
    if (!mobileOpen) return;
    const original = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.body.style.overflow = original;
    };
  }, [mobileOpen]);

  // Polish Task S2 — dynamic badge counts for every gated nav item.
  const { getBadge } = useBadges();

  // system_admin bypass is handled inside isNavItemVisible.
  const visibility = useMemo(
    () => ({ permissions, features, roleSlug }),
    [permissions, features, roleSlug],
  );

  const isVisible = useCallback(
    (item: NavItem) => isNavItemVisible(item, visibility),
    [visibility],
  );

  // Filter sections to only those with visible items so the active-section
  // detection and collapsed-rail dividers don't reference hidden groups.
  const visibleSections = useMemo(
    () =>
      SECTIONS.map((section) => ({
        ...section,
        visibleItems: section.items.filter(isVisible),
      })).filter((s) => s.visibleItems.length > 0),
    [isVisible],
  );

  // Pick the most-specific item that matches the current path so a parent
  // like `/hr/attendance` doesn't stay lit when `/hr/attendance/overtime`
  // is active.
  const matched = useMemo(
    () =>
      visibleSections
        .flatMap((s) => s.visibleItems)
        .filter((item) => pathname === item.to || pathname.startsWith(item.to + '/'))
        .sort((a, b) => b.to.length - a.to.length)[0],
    [visibleSections, pathname],
  );

  const isActive = useCallback((to: string) => matched?.to === to, [matched]);

  const activeSectionLabel = useMemo(
    () =>
      matched
        ? visibleSections.find((s) => s.visibleItems.some((it) => it.to === matched.to))?.label
        : undefined,
    [matched, visibleSections],
  );

  const sidebarContent = (
    <nav className="py-3">
      {visibleSections.map((section, idx) => {
        const isActiveSection = activeSectionLabel === section.label;
        return (
          <div key={section.label} className="mb-3">
            {/* Collapsed rail: thin divider between icon groups */}
            {collapsed && !mobileOpen && idx > 0 && (
              <div className="mx-3 mb-2 border-t border-default" aria-hidden />
            )}

            {/* Expanded: section header with accent dot */}
            {(!collapsed || mobileOpen) && (
              <div
                className={cn(
                  'px-4 mb-1 flex items-center gap-1.5 text-2xs uppercase tracking-widest font-medium',
                  isActiveSection ? 'text-primary' : 'text-muted',
                )}
              >
                <span
                  className={cn(
                    'inline-block h-1.5 w-1.5 rounded-full',
                    isActiveSection ? 'bg-accent' : 'bg-subtle',
                  )}
                  aria-hidden
                />
                {section.label}
              </div>
            )}

            <ul className="flex flex-col">
              {section.visibleItems.map((item) => {
                const entry = getBadge(item.badgeKey);
                return (
                  <li key={item.to}>
                    {' '}
                    {collapsed && !mobileOpen ? (
                      <Tooltip
                        content={`${entry?.description ?? item.label}${entry?.count ? ` · ${entry.count}` : ''}`}
                        side="right"
                      >
                        <NavLink
                          item={item}
                          active={isActive(item.to)}
                          collapsed
                          badgeOverride={entry?.count}
                          badgeVariant={entry?.severity}
                          badgeDescription={entry?.description}
                        />
                      </Tooltip>
                    ) : (
                      <NavLink
                        item={item}
                        active={isActive(item.to)}
                        badgeOverride={entry?.count}
                        badgeVariant={entry?.severity}
                        badgeDescription={entry?.description}
                      />
                    )}
                  </li>
                );
              })}
            </ul>
          </div>
        );
      })}
    </nav>
  );

  return (
    <>
      {/* Desktop sidebar */}
      <aside
        className={cn(
          'shrink-0 sticky top-12 h-[calc(100vh-3rem)] border-r border-default bg-canvas overflow-y-auto transition-[width] duration-fast',
          'relative',
          'hidden md:block',
          collapsed ? 'w-14' : 'w-60',
        )}
      >
        {/* Blueprint grid texture — decorative, aria-hidden, behind nav content */}
        <div
          aria-hidden
          className="pointer-events-none absolute inset-0 z-0 opacity-[0.35]"
          style={{
            backgroundImage:
              'linear-gradient(var(--border-default) 1px, transparent 1px), linear-gradient(90deg, var(--border-default) 1px, transparent 1px)',
            backgroundSize: '32px 32px',
            maskImage:
              'linear-gradient(to bottom, transparent 0%, black 20%, black 70%, transparent 100%)',
            WebkitMaskImage:
              'linear-gradient(to bottom, transparent 0%, black 20%, black 70%, transparent 100%)',
          }}
        />
        {sidebarContent}
      </aside>

      {/* Mobile drawer */}
      {mobileOpen && (
        <div className="fixed inset-0 z-50 md:hidden">
          <div
            className="absolute inset-0 bg-black/40 animate-fade-in"
            onClick={() => setMobileOpen(false)}
          />
          <aside className="absolute inset-y-0 left-0 w-72 bg-canvas border-r border-default overflow-y-auto animate-slide-right">
            <div className="h-12 flex items-center justify-between px-3 border-b border-default">
              <span className="text-sm font-medium text-primary">Menu</span>
              <Button
                variant="ghost"
                size="sm"
                iconOnly
                icon={<LuX size={14} />}
                aria-label="Close menu"
                onClick={() => setMobileOpen(false)}
                className="text-muted hover:text-primary"
              />
            </div>
            {sidebarContent}
          </aside>
        </div>
      )}
    </>
  );
});

const NavLink = memo(function NavLink({
  item,
  active,
  collapsed,
  badgeOverride,
  badgeVariant,
  badgeDescription,
}: {
  item: NavItem;
  active: boolean;
  collapsed?: boolean;
  badgeOverride?: number;
  badgeVariant?: BadgeSeverity | 'accent';
  /** What the badge count represents (server-provided, e.g. "Overdue bills payable"). */
  badgeDescription?: string;
}) {
  const Icon = item.icon;
  const badgeValue = badgeOverride ?? item.badge;
  const linkRef = useRef<HTMLAnchorElement>(null);

  useEffect(() => {
    if (active && linkRef.current) {
      linkRef.current.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
  }, [active]);

  return (
    <Link
      ref={linkRef}
      to={item.to}
      aria-label={collapsed ? item.label : undefined}
      className={cn(
        'relative flex items-center gap-3 px-3 py-2.5 mx-2 mb-0.5 rounded-md text-sm transition-colors duration-fast',
        active
          ? 'text-accent font-medium bg-accent/15'
          : 'text-secondary hover:bg-surface hover:text-primary',
        collapsed && 'justify-center mx-1 py-2',
      )}
    >
      {active && (
        <span
          className="absolute left-0 top-2 bottom-2 w-[3px] rounded-r-md bg-accent"
          aria-hidden
        />
      )}
      <Icon
        size={16}
        className={cn(collapsed ? '' : 'shrink-0', active ? 'text-accent' : 'text-muted')}
      />
      {collapsed && badgeValue != null && badgeValue > 0 && (
        <span
          className={cn(
            'absolute top-1 right-2 h-2 w-2 rounded-full',
            badgeVariant === 'danger'
              ? 'bg-danger-bg'
              : badgeVariant === 'warning'
                ? 'bg-warning-bg'
                : 'bg-accent',
          )}
          aria-hidden
        />
      )}{' '}
      {!collapsed && (
        <>
          <span className="truncate flex-1">{item.label}</span>
          {badgeValue != null && badgeValue > 0 ? (
            <Tooltip content={`${badgeDescription ?? item.label} · ${badgeValue}`} side="top">
              <Badge
                variant={badgeVariant ?? 'accent'}
                aria-label={`${badgeDescription ?? item.label} — ${badgeValue}`}
              >
                {badgeValue > 99 ? '99+' : badgeValue}
              </Badge>
            </Tooltip>
          ) : null}
        </>
      )}
    </Link>
  );
});
