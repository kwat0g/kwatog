import { IconType } from '@/lib/icons';
/* eslint-disable react-refresh/only-export-components -- navigation metadata is intentionally shared with command/search surfaces. */
import { Link, useLocation } from 'react-router-dom';
import {
  LuLayoutDashboard,
  LuClock4,
  LuCalendarDays,
  LuWallet,
  LuTruck,
  LuLayers,
  LuShieldCheck,
  LuWrench,
  LuSettings as SettingsIcon,
  LuCalendarClock,
  LuUsers,
  LuFileText,
  LuPackage,
  LuBell,
  LuInbox,
  LuTriangleAlert,
  LuCalendar,
  LuArrowLeftRight,
  LuRotateCcw,
  LuSettings2,
  LuSlidersHorizontal,
  LuBuilding2,
  LuChartColumnIncreasing,
  LuLandmark,
  LuTag,
  LuClipboardList,
  LuGitFork,
  LuListTree,
  LuCpu,
  LuBadgeCheck,
  LuRoute,
  LuX,
  LuMonitor,
  LuKeyRound,
  LuDatabase,
  LuListChecks,
  LuTarget,
  LuRuler,
  LuStore,
  LuChevronDown,
  LuChevronRight,
} from '@/lib/icons';
import {
  WorkflowIcon,
  OrderIcon,
  RequestIcon,
  InvoiceIcon,
  BillIcon,
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
} from '@/lib/icons';

import { memo, useCallback, useEffect, useMemo, useRef, useState, useId } from 'react';
import { cn } from '@/lib/cn';
import { focusRingInset } from '@/lib/focus';
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
  /** Keep the most urgent cross-module destinations visible above module taxonomy. */
  work?: boolean;
  /** Plain-language explanation shown on hover/focus for dense internal terms. */
  description?: string;
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
  description?: string;
  items: NavItem[];
}

/**
 * Sidebar — workflow pages only.
 *
 * The sitemap intentionally keeps primary chain/workflow destinations here.
 * Supporting pages can remain routable and linked from dashboards, detail
 * views, notifications, or direct URLs without taking a top-level slot.
 *
 * Visibility is decided by permission + feature flag, never by role name:
 * `isNavItemVisible` reads `roleSlug` only for the system_admin bypass that
 * mirrors User::hasPermission. A per-item role allowlist used to exist here and
 * was never set by a single item — dead code that invited role-name coupling
 * back into navigation. Standalone "dashboard" sub-pages (Production Dashboard,
 * Quality Dashboard) are removed — users reach their role dashboard via the
 * top-level /dashboard redirect.
 */
const SECTION_PRIORITY: Record<string, number> = {
  Overview: 0,
  'Sales & CRM': 10,
  'Production Planning (MRP)': 20,
  Procurement: 30,
  Production: 40,
  Quality: 50,
  Warehouse: 60,
  'Supply Chain': 70,
  'Human Resources': 80,
  Finance: 90,
  Maintenance: 100,
  Assets: 110,
  Administration: 120,
};

/** Role context changes reading order only; permission gates remain authoritative. */
const ROLE_SECTION_ORDER: Record<string, string[]> = {
  system_admin: ['Administration'],
  impex_officer: ['Supply Chain', 'Procurement'],
  vice_president: ['Finance', 'Sales & CRM', 'Production', 'Procurement'],
  finance_officer: ['Finance', 'Procurement', 'Payroll', 'Assets'],
  purchasing_officer: ['Procurement', 'Warehouse', 'Finance'],
  ppc_head: ['Production Planning (MRP)', 'Production', 'Procurement', 'Warehouse'],
  production_manager: ['Production', 'Production Planning (MRP)', 'Quality', 'Warehouse'],
  warehouse_staff: ['Warehouse', 'Supply Chain', 'Quality'],
  qc_inspector: ['Quality', 'Warehouse', 'Production'],
  maintenance_tech: ['Maintenance', 'Assets', 'Production'],
  maintenance_supervisor: ['Maintenance', 'Assets', 'Production Planning (MRP)'],
  hr_officer: ['Human Resources', 'Finance'],
  department_head: ['Human Resources', 'Finance', 'Production'],
  sales_officer: ['Sales & CRM', 'Supply Chain'],
  customer_service_officer: ['Sales & CRM', 'Supply Chain'],
  driver: ['Supply Chain'],
  employee: ['Human Resources'],
};

export const SECTIONS: NavSection[] = [
  {
    label: 'Overview',
    items: [
      { to: '/dashboard', label: 'Dashboard', icon: LuLayoutDashboard, work: true },
      {
        to: '/action-center',
        label: 'Action Center',
        icon: LuListChecks,
        work: true,
        permission: 'dashboard.action_center.view',
        badgeKey: 'action_center',
      },
      {
        to: '/decision-center',
        label: 'Decision Center',
        icon: LuTarget,
        work: true,
        permission: 'dss.view',
      },
      {
        to: '/chains',
        label: 'Business Chain Tracker',
        icon: WorkflowIcon,
        anyPermissions: ['crm.sales_orders.view', 'dashboard.view_bottlenecks'],
      },
      {
        to: '/chains/recovery',
        label: 'Automation Recovery',
        icon: LuRotateCcw,
        permission: 'dashboard.chain_recovery.view',
      },
      {
        to: '/approvals',
        label: 'Approval Queue',
        icon: LuInbox,
        work: true,
        permission: 'approvals.board.view',
        badgeKey: 'approvals',
      },
      {
        to: '/notifications',
        label: 'Notifications',
        icon: LuBell,
        work: true,
        permission: 'notifications.view',
        badgeKey: 'unread',
      },
    ],
  },
  {
    label: 'Sales & CRM',
    description: 'Sales and customer relationship management',
    items: [
      {
        to: '/crm/inquiries',
        label: 'Customer Inquiries',
        icon: LuInbox,
        feature: 'crm',
        permission: 'crm.inquiries.view',
      },
      {
        to: '/crm/sales-orders',
        label: 'Sales Orders',
        icon: OrderIcon,
        feature: 'crm',
        permission: 'crm.sales_orders.view',
        badgeKey: 'pending_so',
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
        label: 'Customer Complaints',
        icon: ComplaintIcon,
        feature: 'crm',
        permission: 'crm.complaints.view',
        badgeKey: 'open_complaints',
      },
      {
        to: '/return-management',
        label: 'Returns (RMA)',
        description: 'Product return cases',
        icon: ReturnIcon,
        feature: 'return_management',
        permission: 'return_management.view',
        badgeKey: 'pending_returns',
      },
      {
        to: '/return-management/cases',
        label: 'Problem Reports',
        icon: ReturnIcon,
        feature: 'return_management',
        permission: 'return_management.view',
      },
    ],
  },
  {
    label: 'Production',
    items: [
      {
        to: '/production/work-orders',
        label: 'Production Work Orders',
        icon: WorkOrderIcon,
        feature: 'production',
        permission: 'production.work_orders.view',
        badgeKey: 'work_orders',
      },
      {
        to: '/production/schedule',
        label: 'Production Schedule (Gantt)',
        icon: LuCalendarClock,
        feature: 'production',
        permission: 'production.schedule.view',
      },
      {
        to: '/production/routings',
        label: 'Production Routings',
        icon: LuRoute,
        feature: 'production',
        permission: 'production.routings.view',
      },
    ],
  },
  {
    label: 'Production Planning (MRP)',
    description: 'Material requirements planning',
    items: [
      {
        to: '/mrp/plans',
        label: 'MRP Plans',
        description: 'Material requirements planning runs',
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
        label: 'Production Machines',
        icon: LuCpu,
        feature: 'mrp',
        permission: 'mrp.machines.view',
      },
      {
        to: '/mrp/molds',
        label: 'Production Molds',
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
        permission: 'purchasing.chain.view',
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
        to: '/purchasing/rfqs',
        label: 'Supplier RFQs',
        description: 'Supplier quote requests',
        icon: LuFileText,
        feature: 'purchasing',
        permission: 'purchasing.rfq.view',
      },
      {
        to: '/purchasing/approved-suppliers',
        label: 'Approved Suppliers',
        icon: LuBadgeCheck,
        feature: 'purchasing',
        permission: 'purchasing.po.create',
      },
      {
        to: '/purchasing/supplier-listings',
        label: 'Supplier Listings',
        icon: LuClipboardList,
        feature: 'purchasing',
        permission: 'purchasing.supplier_listings.review',
      },
    ],
  },
  {
    label: 'Warehouse',
    items: [
      {
        to: '/inventory/items',
        label: 'Inventory Items',
        icon: InventoryIcon,
        feature: 'inventory',
        permission: 'inventory.view',
        badgeKey: 'low_stock',
      },
      {
        to: '/inventory/uoms',
        label: 'Units of Measure',
        icon: LuRuler,
        feature: 'inventory',
        permission: 'inventory.items.manage',
      },
      {
        to: '/inventory/grn',
        label: 'Goods Receipts (GRN)',
        description: 'Inbound goods receipts',
        icon: ReceivingIcon,
        feature: 'inventory',
        permission: 'inventory.view',
        badgeKey: 'pending_grn',
      },
      {
        to: '/inventory/material-issues',
        label: 'Material Issues',
        icon: IssuanceIcon,
        feature: 'inventory',
        permission: 'inventory.issue.create',
      },
      {
        to: '/inventory/stock-levels',
        label: 'Inventory Stock Levels',
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
        anyPermissions: ['inventory.adjust', 'inventory.adjust.approve'],
      },
      // Stock Count merged into Warehouse Map (2026-08-08) — use its Map | Stock
      // Count toggle. The /inventory/stock-count path still works (scanner links).
      {
        to: '/inventory/warehouse-map',
        label: 'Warehouse Map',
        icon: WarehouseMapIcon,
        feature: 'inventory',
        permission: 'inventory.warehouse.manage',
      },
      {
        to: '/inventory/transfer-orders',
        label: 'Inventory Transfers',
        icon: LuArrowLeftRight,
        feature: 'inventory',
        permission: 'inventory.adjust',
      },
      {
        to: '/inventory/picking',
        label: 'Material Handover',
        icon: PickingIcon,
        feature: 'inventory',
        permission: 'inventory.picking.view',
      },
    ],
  },
  {
    label: 'Supply Chain',
    items: [
      {
        to: '/supply-chain/deliveries',
        label: 'Outbound Deliveries',
        icon: LuTruck,
        feature: 'supply_chain',
        // Either read reaches the page (see supplyChainRoutes). Warehouse staff
        // hold only the narrow one, and an item they can open must be an item
        // they can see.
        anyPermissions: ['supply_chain.view', 'supply_chain.deliveries.view'],
        badgeKey: 'deliveries',
      },
      {
        // The driver's own run sheet (status + proof photo). Drivers hold no
        // other module read, so without this they landed on an empty
        // dashboard with no way to reach the page that unblocks the
        // customer's confirmation.
        to: '/driver',
        label: 'My Deliveries',
        icon: LuTruck,
        feature: 'supply_chain',
        permission: 'supply_chain.driver.access',
      },
      {
        to: '/supply-chain/fleet',
        label: 'Delivery Fleet',
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
        label: 'Inspection Specifications',
        icon: LuClipboardList,
        feature: 'quality',
        permission: 'quality.specs.view',
      },
      {
        to: '/quality/inspections',
        label: 'Quality Inspections',
        icon: InspectionIcon,
        feature: 'quality',
        permission: 'quality.inspections.view',
        badgeKey: 'pending_inspections',
      },
      {
        to: '/quality/ncrs',
        label: 'Nonconformance Reports (NCRs)',
        description: 'Quality nonconformance reports',
        icon: LuTriangleAlert,
        feature: 'quality',
        permission: 'quality.ncr.view',
        badgeKey: 'ncrs',
      },
      {
        to: '/quality/traceability',
        label: 'Lot Traceability',
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
        to: '/budgeting',
        label: 'Budgeting',
        icon: LuWallet,
        feature: 'budgeting',
        permission: 'budgeting.view',
        badgeKey: 'pending_budgets',
      },
      {
        to: '/budgeting/transfers',
        label: 'Budget Transfers',
        icon: LuArrowLeftRight,
        feature: 'budgeting',
        anyPermissions: ['budgeting.view', 'budgeting.approve'],
        badgeKey: 'pending_transfers',
      },
      {
        to: '/accounting/invoices',
        label: 'Accounts Receivable Invoices',
        icon: InvoiceIcon,
        feature: 'accounting',
        permission: 'accounting.invoices.view',
        badgeKey: 'draft_invoices',
      },
      {
        to: '/accounting/bills',
        label: 'Accounts Payable Bills',
        icon: BillIcon,
        feature: 'accounting',
        permission: 'accounting.bills.view',
        badgeKey: 'overdue_bills',
      },
      {
        to: '/accounting/vendors',
        label: 'Vendors',
        icon: LuStore,
        feature: 'accounting',
        permission: 'accounting.vendors.view',
      },
      {
        to: '/accounting/income-statement',
        label: 'Income Statement',
        icon: LuChartColumnIncreasing,
        feature: 'accounting',
        permission: 'accounting.statements.view',
      },
      {
        to: '/accounting/balance-sheet',
        label: 'Balance Sheet',
        icon: LuLandmark,
        feature: 'accounting',
        permission: 'accounting.statements.view',
      },
    ],
  },
  {
    label: 'Human Resources',
    items: [
      {
        to: '/hr/recruitment',
        label: 'Recruitment',
        icon: LuUsers,
        feature: 'recruitment',
        permission: 'hr.recruitment.view',
      },
      {
        to: '/hr/trainings',
        label: 'Training Programs',
        icon: LuBadgeCheck,
        feature: 'hr',
        permission: 'hr.trainings.view',
      },
      {
        to: '/hr/loans',
        label: 'Employee Loans',
        icon: LuWallet,
        feature: 'loans',
        permission: 'loans.view',
      },
      {
        to: '/hr/separations',
        label: 'Separation Clearance',
        icon: LuListChecks,
        feature: 'hr',
        permission: 'hr.separation.view',
      },
      {
        to: '/hr/profile-update-requests',
        label: 'Bank Account Changes',
        icon: LuLandmark,
        feature: 'hr',
        anyPermissions: ['hr.employees.view', 'hr.profile_updates.finance_review'],
      },
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
        label: 'Attendance & DTR',
        description: 'Attendance and daily time records',
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
        label: 'Leave Management',
        icon: LuCalendarDays,
        feature: 'leave',
        anyPermissions: ['leave.approve_dept', 'leave.approve_hr', 'leave.types.manage'],
        badgeKey: 'leaves',
      },
      {
        to: '/payroll/periods',
        label: 'Payroll Processing',
        icon: LuWallet,
        feature: 'payroll',
        permission: 'payroll.periods.view',
        badgeKey: 'payroll',
      },
      {
        to: '/payroll/adjustments',
        label: 'Payroll Adjustments',
        icon: LuSettings2,
        feature: 'payroll',
        permission: 'payroll.adjustments.view',
      },
      {
        to: '/payroll/statutory',
        label: 'Payroll Statutory Exports',
        icon: LuFileText,
        feature: 'payroll',
        permission: 'payroll.statutory.export',
      },
    ],
  },
  {
    label: 'Maintenance',
    items: [
      {
        to: '/maintenance/work-orders',
        label: 'Maintenance Work Orders',
        icon: LuWrench,
        feature: 'maintenance',
        permission: 'maintenance.view',
        badgeKey: 'maintenance_wo',
      },
      {
        to: '/maintenance/schedules',
        label: 'Maintenance Schedules',
        icon: LuCalendar,
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
      {
        to: '/admin/users',
        label: 'User Accounts',
        icon: LuUsers,
        permission: 'admin.users.manage',
      },
      {
        to: '/admin/roles',
        label: 'Roles & Permissions',
        icon: LuShieldCheck,
        permission: 'admin.roles.manage',
      },
      {
        to: '/admin/audit-logs',
        label: 'Audit Logs',
        icon: LuFileText,
        permission: 'admin.audit_logs.view',
      },
      {
        to: '/admin/settings',
        label: 'Settings',
        icon: SettingsIcon,
        permission: 'admin.settings.manage',
      },
      {
        to: '/admin/sessions',
        label: 'Active Sessions',
        icon: LuMonitor,
        permission: 'admin.settings.manage',
      },
      // W1.2 — the shared-component catalogue. Not a business destination, but a
      // reviewer's page nobody would find if it were URL-only.
      {
        to: '/admin/component-gallery',
        label: 'Component Gallery',
        icon: LuLayers,
        permission: 'admin.settings.manage',
      },
      {
        to: '/accounting/portal-access',
        label: 'Portal Access',
        icon: LuKeyRound,
        permission: 'b2b.portal_access.view',
      },
      {
        to: '/admin/backups',
        label: 'Backup & Restore',
        icon: LuDatabase,
        permission: 'admin.backups.view',
      },
      {
        to: '/admin/gov-tables',
        label: 'Government Contribution Tables',
        icon: LuLandmark,
        permission: 'admin.gov_tables.manage',
      },
      // /admin/depreciation removed 2026-08-08 (scope cut — monthly runner moved
      // to the Fixed Assets page as a header button/modal, same permission gate).
    ],
  },
].sort(
  (left, right) =>
    (SECTION_PRIORITY[left.label] ?? Number.MAX_SAFE_INTEGER) -
    (SECTION_PRIORITY[right.label] ?? Number.MAX_SAFE_INTEGER),
);

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
/** Shared labels keep restricted queues clear in both sidebar and page search. */
export function navItemForPermissions(item: NavItem, permissions?: ReadonlySet<string>): NavItem {
  if (item.to === '/hr/employees' && permissions && !permissions.has('hr.employees.view')) {
    return { ...item, label: 'Salary Adjustments' };
  }
  if (item.to === '/hr/profile-update-requests' && permissions?.has('hr.employees.view')) {
    return { ...item, label: 'Profile Change Requests' };
  }
  return item;
}

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
  const peek = useSidebarStore((s) => s.peek);
  const [hovered, setHovered] = useState(false);
  const [focused, setFocused] = useState(false);
  const hoverTimer = useRef<ReturnType<typeof setTimeout>>();
  const peekTimers = useRef(new Map<string, ReturnType<typeof setTimeout>>());
  const visited = useRef(new Set<string>());
  const navId = useId();
  const moduleContentId = (label: string, mobile: boolean) =>
    `${navId}-${mobile ? 'mobile' : 'desktop'}-${label.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`;
  const rail = collapsed && !(peek && hovered) && !focused;

  useEffect(() => {
    clearTimeout(hoverTimer.current);
    setHovered(false);
  }, [collapsed, peek]);

  const clearHover = () => {
    clearTimeout(hoverTimer.current);
    setHovered(false);
  };

  useEffect(
    () => () => {
      clearTimeout(hoverTimer.current);
      peekTimers.current.forEach(clearTimeout);
    },
    [],
  );
  const setMobileOpen = useSidebarStore((s) => s.setMobileOpen);
  const toggleSidebar = useSidebarStore((s) => s.toggle);
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

  const mobileDrawerRef = useRef<HTMLElement>(null);

  // Keep focus inside the mobile drawer and return it to its opener.
  useEffect(() => {
    if (!mobileOpen) return;
    const previous = document.activeElement;
    const drawer = mobileDrawerRef.current;
    drawer?.focus();
    function onKeyDown(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        e.preventDefault();
        setMobileOpen(false);
      }
      if (e.key === 'Tab' && drawer) {
        const controls = Array.from(
          drawer.querySelectorAll<HTMLElement>(
            'button:not([disabled]), a[href], input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
          ),
        ).filter(
          (control) =>
            control.tabIndex >= 0 &&
            !control.matches(':disabled') &&
            !control.closest('[hidden], [aria-hidden="true"]'),
        );
        const first = controls[0];
        const last = controls[controls.length - 1];
        const current = document.activeElement;
        if (!first || !last) {
          e.preventDefault();
          drawer.focus();
        } else if (!drawer.contains(current)) {
          e.preventDefault();
          (e.shiftKey ? last : first).focus();
        } else if (e.shiftKey && (current === first || current === drawer)) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && (current === last || current === drawer)) {
          e.preventDefault();
          first.focus();
        }
      }
    }
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      if (previous instanceof HTMLElement && previous.isConnected) previous.focus();
    };
  }, [mobileOpen, setMobileOpen]);

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

  // Finance sees its stock-checker work beside accounting approvals.
  const visibleSections = useMemo(() => {
    const roleOrder = ROLE_SECTION_ORDER[roleSlug ?? ''] ?? [];
    const roleRank = new Map(roleOrder.map((label, index) => [label, index]));
    const financeApprovalItems =
      roleSlug === 'finance_officer'
        ? (SECTIONS.find((section) => section.label === 'Warehouse')?.items.filter(
            (item) => item.to === '/inventory/stock-adjustments',
          ) ?? [])
        : [];
    const financeReturns =
      roleSlug === 'finance_officer'
        ? (SECTIONS.find((section) => section.label === 'Sales & CRM')?.items.filter(
            (item) => item.to === '/return-management',
          ) ?? [])
        : [];
    const sections = SECTIONS.map((section) => ({
      ...section,
      label:
        roleSlug === 'finance_officer' && section.label === 'Human Resources'
          ? 'Payroll'
          : section.label,
      visibleItems: [
        ...section.items.filter(
          (item) =>
            isVisible(item) &&
            (roleSlug !== 'finance_officer' ||
              item.work ||
              ['Finance', 'Procurement', 'Assets'].includes(section.label) ||
              item.to.startsWith('/payroll/') ||
              ['/hr/profile-update-requests', '/hr/loans', '/hr/separations'].includes(item.to)),
        ),
        ...(section.label === 'Finance'
          ? [...financeApprovalItems, ...financeReturns].filter(isVisible)
          : []),
      ].map((item) => navItemForPermissions(item, permissions)),
    })).filter((section) => section.visibleItems.length > 0);

    return sections.sort((left, right) => {
      const leftRank =
        left.label === 'Overview'
          ? -1
          : (roleRank.get(left.label) ??
            roleOrder.length + (SECTION_PRIORITY[left.label] ?? Number.MAX_SAFE_INTEGER));
      const rightRank =
        right.label === 'Overview'
          ? -1
          : (roleRank.get(right.label) ??
            roleOrder.length + (SECTION_PRIORITY[right.label] ?? Number.MAX_SAFE_INTEGER));
      return leftRank - rightRank;
    });
  }, [isVisible, roleSlug, permissions]);

  const workItems = useMemo(
    () => visibleSections.flatMap((section) => section.visibleItems.filter((item) => item.work)),
    [visibleSections],
  );
  const moduleSections = useMemo(
    () =>
      visibleSections
        .map((section) => ({
          ...section,
          visibleItems: section.visibleItems.filter((item) => !item.work),
        }))
        .filter((section) => section.visibleItems.length > 0),
    [visibleSections],
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
  const activeModuleSectionLabel = matched?.work ? undefined : activeSectionLabel;

  const [modules, setModules] = useState({
    pathname,
    activeSectionLabel,
    expanded: activeModuleSectionLabel ? [activeModuleSectionLabel] : [],
  });
  if (modules.pathname !== pathname || modules.activeSectionLabel !== activeSectionLabel) {
    const expandedAll =
      moduleSections.length > 0 &&
      moduleSections.every((section) => modules.expanded.includes(section.label));
    const expanded = expandedAll
      ? modules.expanded
      : activeModuleSectionLabel
        ? [activeModuleSectionLabel]
        : [];
    setModules({ pathname, activeSectionLabel, expanded });
  }

  useEffect(() => {
    if (activeSectionLabel) visited.current.add(activeSectionLabel);
    peekTimers.current.forEach(clearTimeout);
    peekTimers.current.clear();
  }, [pathname, activeSectionLabel]);

  const allExpanded =
    moduleSections.length > 0 &&
    moduleSections.every((section) => modules.expanded.includes(section.label));
  const toggleAll = () => {
    peekTimers.current.forEach(clearTimeout);
    peekTimers.current.clear();
    setModules({
      pathname,
      activeSectionLabel,
      expanded: allExpanded
        ? activeModuleSectionLabel
          ? [activeModuleSectionLabel]
          : []
        : moduleSections.map((section) => section.label),
    });
  };

  const toggleModule = (label: string) => {
    clearTimeout(peekTimers.current.get(label));
    peekTimers.current.delete(label);
    const open = !modules.expanded.includes(label);
    setModules({
      pathname,
      activeSectionLabel,
      expanded: open
        ? [...modules.expanded, label]
        : modules.expanded.filter((entry) => entry !== label),
    });
    if (open && !visited.current.has(label)) {
      const expire = () => {
        // Keep the temporary expansion while pointer hovers or keyboard enters its links.
        const inUse = [false, true].some((mobile) => {
          const group = document.getElementById(moduleContentId(label, mobile));
          return group?.contains(document.activeElement) || group?.matches(':hover');
        });
        if (inUse) {
          peekTimers.current.set(label, setTimeout(expire, 500));
          return;
        }
        setModules((current) => ({
          ...current,
          expanded: current.expanded.filter((entry) => entry !== label),
        }));
        peekTimers.current.delete(label);
      };
      peekTimers.current.set(label, setTimeout(expire, 5000));
    }
  };

  const sidebarContent = (mobile = false) => {
    const compact = !mobile && rail;
    const hoverExpanded = !mobile && collapsed && peek && hovered;
    const renderNavItem = (item: NavItem) => {
      const entry = getBadge(item.badgeKey);
      let target = item.to;
      if ((entry?.count ?? 0) > 0) {
        if (item.badgeKey === 'purchase_requests') target = `${item.to}?signal=awaiting_my_approval`;
        else if (item.badgeKey === 'pending_transfers') target = `${item.to}?status=pending&actionable=1`;
        else if (item.badgeKey === 'pending_grn') target = `${item.to}?status=pending_qc`;
        else if (item.badgeKey === 'pending_inspections') target = `${item.to}?work_queue=to_inspect`;
        else if (item.badgeKey === 'open_complaints') target = `${item.to}?signal=open`;
        else if (item.badgeKey === 'overdue_bills') target = `${item.to}?overdue=1`;
      }
      return (
        <li key={item.to}>
          <NavLink
            item={target === item.to ? item : { ...item, to: target }}
            active={isActive(item.to)}
            collapsed={compact}
            badgeOverride={entry?.count}
            badgeVariant={entry?.severity}
            badgeDescription={entry?.description}
          />
        </li>
      );
    };
    const renderModuleSection = (section: (typeof moduleSections)[number]) => {
      const active = activeModuleSectionLabel === section.label;
      const expanded = modules.expanded.includes(section.label);
      const open = hoverExpanded || active || expanded;
      const sectionBadges = section.visibleItems.flatMap((item) => {
        const entry = getBadge(item.badgeKey);
        const count = entry?.count ?? item.badge ?? 0;
        return count > 0
          ? [
              {
                count,
                severity: entry?.severity ?? 'accent',
                description: entry?.description ?? item.label,
              },
            ]
          : [];
      });
      const sectionBadgeCount = sectionBadges.reduce((total, badge) => total + badge.count, 0);
      const sectionBadgeVariant: BadgeSeverity | 'accent' = sectionBadges.some(
        (badge) => badge.severity === 'danger',
      )
        ? 'danger'
        : sectionBadges.some((badge) => badge.severity === 'warning')
          ? 'warning'
          : sectionBadges.some((badge) => badge.severity === 'accent')
            ? 'accent'
            : 'neutral';
      const sectionBadgeSummary = sectionBadges
        .map((badge) => `${badge.description}: ${badge.count}`)
        .join(' · ');
      const id = moduleContentId(section.label, mobile);
      return (
        <div key={section.label} data-module={section.label} className="mb-2">
          {!compact && (
            <button
              type="button"
              aria-expanded={open}
              aria-controls={id}
              aria-label={
                !open && sectionBadgeCount > 0
                  ? `${section.label}: ${sectionBadgeSummary}`
                  : undefined
              }
              disabled={hoverExpanded || active}
              onClick={() => toggleModule(section.label)}
              className={cn(
                'flex min-h-11 w-full items-center gap-2 rounded-md px-4 py-1.5 text-left text-sm font-normal transition-colors duration-fast md:min-h-9',
                focusRingInset,
                active
                  ? 'font-medium text-primary'
                  : 'text-secondary hover:bg-elevated hover:text-primary',
              )}
            >
              <span
                title={section.description ?? section.label}
                className="min-w-0 flex-1 truncate"
              >
                {section.label}
              </span>
              {!open && sectionBadgeCount > 0 && (
                <span aria-hidden title={sectionBadgeSummary} className="shrink-0">
                  <Badge variant={sectionBadgeVariant}>
                    {sectionBadgeCount > 99 ? '99+' : sectionBadgeCount}
                  </Badge>
                </span>
              )}
              <LuChevronDown
                size={14}
                aria-hidden
                className={cn(
                  'shrink-0 text-muted transition-transform duration-fast',
                  !open && '-rotate-90',
                )}
              />
            </button>
          )}
          <ul
            id={id}
            hidden={!compact && !open}
            className={cn((compact || open) && 'flex flex-col')}
          >
            {section.visibleItems.map(renderNavItem)}
          </ul>
        </div>
      );
    };
    return (
      <>
        <nav aria-label="Modules" className="min-h-0 flex-1 overflow-y-auto py-2">
          {workItems.length > 0 && (
            <div role="group" aria-label="Work" className="mb-3">
              {!compact && (
                <div className="px-4 pb-1 text-2xs font-medium uppercase tracking-wide text-muted">
                  Work
                </div>
              )}
              <ul className="flex flex-col">{workItems.map(renderNavItem)}</ul>
            </div>
          )}

          {moduleSections.map(renderModuleSection)}
        </nav>
        {!compact && !hoverExpanded && moduleSections.length > 1 && (
          <div
            role="group"
            aria-label="Module controls"
            className="flex shrink-0 gap-1 border-t border-default bg-canvas p-2"
          >
            <button
              type="button"
              onClick={toggleAll}
              className={cn(
                'flex min-h-11 flex-1 items-center justify-center gap-1.5 rounded-md px-2 py-2 text-sm text-muted hover:bg-surface hover:text-primary md:min-h-hit',
                focusRingInset,
              )}
            >
              <LuChevronDown size={14} className={cn(allExpanded && 'rotate-180')} aria-hidden />
              {allExpanded ? 'Collapse all' : 'Expand all'}
            </button>
          </div>
        )}
        {compact && (
          <div className="shrink-0 border-t border-default bg-canvas p-2">
            <Tooltip content="Expand navigation" side="right">
              <Button
                variant="ghost"
                size="sm"
                iconOnly
                icon={<LuChevronRight size={18} />}
                aria-label="Expand navigation"
                onClick={toggleSidebar}
                className="min-h-11 min-w-11 text-muted hover:text-primary md:min-h-hit md:min-w-hit"
              />
            </Tooltip>
          </div>
        )}
      </>
    );
  };

  return (
    <>
      {/* Desktop sidebar */}
      <aside
        aria-label="Sidebar"
        onMouseEnter={() => {
          if (collapsed && peek) hoverTimer.current = setTimeout(() => setHovered(true), 150);
        }}
        onMouseLeave={clearHover}
        onFocusCapture={(e) => {
          setFocused(true);
          const label = e.target.closest<HTMLElement>('[data-module]')?.dataset.module;
          if (rail && label && !modules.expanded.includes(label)) {
            setModules((current) => ({ ...current, expanded: [...current.expanded, label] }));
          }
        }}
        onBlurCapture={(e) => {
          if (!e.currentTarget.contains(e.relatedTarget)) setFocused(false);
        }}
        className={cn(
          'shrink-0 sticky top-12 h-[calc(100vh-3rem)] border-r border-default bg-canvas overflow-hidden flex-col transition-[width] duration-fast motion-reduce:transition-none',
          'hidden md:flex',
          rail ? 'w-14' : 'w-60',
        )}
      >
        {sidebarContent()}
      </aside>

      {/* Mobile drawer */}
      {mobileOpen && (
        <div className="fixed inset-0 z-50 md:hidden">
          <div
            className="absolute inset-0 bg-black/40 animate-fade-in"
            aria-hidden
            onClick={() => setMobileOpen(false)}
          />
          <aside
            ref={mobileDrawerRef}
            tabIndex={-1}
            role="dialog"
            aria-modal="true"
            aria-label="Menu"
            className="absolute inset-y-0 left-0 w-72 flex flex-col bg-canvas border-r border-default overflow-hidden animate-slide-right focus:outline-none"
          >
            <div className="h-12 shrink-0 flex items-center justify-between px-3 border-b border-default">
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
            {sidebarContent(true)}
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
  const hasBadge = badgeValue != null && badgeValue > 0;
  const collapsedLabel = hasBadge
    ? `${item.label}, ${badgeDescription ?? 'badge count'}: ${badgeValue}`
    : item.label;
  const linkRef = useRef<HTMLAnchorElement>(null);

  useEffect(() => {
    if (active && linkRef.current) {
      linkRef.current.scrollIntoView({
        block: 'nearest',
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
      });
    }
  }, [active]);

  const link = (
    <Link
      ref={linkRef}
      to={item.to}
      aria-label={collapsed ? collapsedLabel : undefined}
      title={collapsed ? collapsedLabel : item.description}
      aria-current={active ? 'page' : undefined}
      className={cn(
        focusRingInset,
        'relative flex min-h-11 items-center gap-3 py-1.5 mx-2 mb-0.5 rounded-md text-base transition-colors duration-fast md:min-h-9',
        active
          ? 'text-primary font-medium bg-accent/10'
          : 'text-secondary hover:bg-elevated hover:text-primary',
        collapsed ? 'justify-center mx-1 px-0 py-2' : 'px-3',
      )}
    >
      {active && (
        <span className="absolute left-1 top-1 bottom-1 w-0.5 rounded-r-md bg-accent" aria-hidden />
      )}
      <Icon
        size={collapsed ? 20 : 16}
        className={cn('shrink-0', active ? 'text-accent' : 'text-muted')}
      />
      {collapsed && hasBadge && (
        <span aria-hidden className="absolute -top-1 right-0 z-10">
          <Badge variant={badgeVariant ?? 'accent'} className="ring-2 ring-canvas">
            {badgeValue > 99 ? '99+' : badgeValue}
          </Badge>
        </span>
      )}
      {!collapsed && (
        <>
          <span title={item.label} className="min-w-0 flex-1 truncate">
            {item.label}
          </span>
          {badgeValue != null && badgeValue > 0 ? (
            <span className="ml-auto shrink-0">
              <Tooltip content={`${badgeDescription ?? item.label} · ${badgeValue}`} side="top">
                <Badge
                  variant={badgeVariant ?? 'accent'}
                  aria-label={`${badgeDescription ?? item.label} — ${badgeValue}`}
                >
                  {badgeValue > 99 ? '99+' : badgeValue}
                </Badge>
              </Tooltip>
            </span>
          ) : null}
        </>
      )}
    </Link>
  );

  return collapsed ? (
    <Tooltip content={item.description ?? collapsedLabel} side="right">
      {link}
    </Tooltip>
  ) : (
    link
  );
});
