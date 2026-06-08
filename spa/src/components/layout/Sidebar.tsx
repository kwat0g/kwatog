import { Link, useLocation } from 'react-router-dom';
import {
  LayoutDashboard,
  Users,
  Clock4,
  CalendarDays,
  Wallet,
  BookOpen,
  Boxes,
  ShoppingCart,
  Truck,
  Layers,
  Briefcase,
  ShieldCheck,
  Wrench,
  Settings as SettingsIcon,
  CalendarClock,
  FileEdit,
  Receipt,
  Users2,
  FileText,
  Package,
  Bell,
  Inbox,
  AlertTriangle,
  Calendar,
  ArrowLeftRight,
  TrendingUp,
  RotateCcw,
  Building2,
  BarChart2,
  PieChart,
  Landmark,
  Store,
  type LucideIcon,
} from 'lucide-react';
import { cn } from '@/lib/cn';
import { useSidebarStore } from '@/stores/sidebarStore';
import { Tooltip } from '@/components/ui/Tooltip';
import { Badge } from '@/components/ui/Badge';
import { useBadges } from '@/hooks/useBadges';
import type { BadgeSeverity } from '@/api/badges';

interface NavItem {
  to: string;
  label: string;
  icon: LucideIcon;
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
  /** Optional feature flag (e.g. 'hr', 'inventory'). */
  feature?: string;
}

interface NavSection {
  label: string;
  items: NavItem[];
}

/**
 * Sidebar — workflow pages only. Sub-features accessible via buttons
 * on their parent page (no hub pages).
 */
const SECTIONS: NavSection[] = [
  {
    label: 'Overview',
    items: [
      { to: '/dashboard',      label: 'Dashboard',      icon: LayoutDashboard },
      { to: '/approvals',      label: 'Approvals',      icon: Inbox,  permission: 'approvals.board.view', badgeKey: 'approvals' },
      { to: '/notifications',  label: 'Notifications',  icon: Bell,   badgeKey: 'unread' },
    ],
  },
  {
    label: 'Sales & CRM',
    items: [
      { to: '/crm/sales-orders',     label: 'Sales Orders',  icon: Briefcase, feature: 'crm', permission: 'crm.sales_orders.view', badgeKey: 'pending_so' },
      { to: '/crm/customers',        label: 'Customers',     icon: Users2,    feature: 'crm', permission: 'crm.sales_orders.view' },
      { to: '/accounting/customers', label: 'AR Customers',  icon: Users,     feature: 'accounting', permission: 'accounting.customers.view' },
    ],
  },
  {
    label: 'Production',
    items: [
      { to: '/production/work-orders', label: 'Work Orders',      icon: FileText,      feature: 'production', permission: 'production.work_orders.view', badgeKey: 'work_orders' },
      { to: '/mrp/plans',              label: 'MRP Plans',        icon: Layers,        feature: 'mrp', permission: 'mrp.plans.view' },
      { to: '/production/schedule',    label: 'Schedule (Gantt)', icon: CalendarClock, feature: 'production', permission: 'production.schedule.view' },
    ],
  },
  {
    label: 'Procurement',
    items: [
      { to: '/purchasing/purchase-orders',   label: 'Purchase Orders',   icon: ShoppingCart, feature: 'purchasing', permission: 'purchasing.view', badgeKey: 'purchase_requests' },
      { to: '/purchasing/purchase-requests', label: 'Purchase Requests', icon: FileText,     feature: 'purchasing', permission: 'purchasing.view' },
    ],
  },
  {
    label: 'Warehouse',
    items: [
      { to: '/inventory/items',            label: 'Items',           icon: Boxes,    feature: 'inventory', permission: 'inventory.view', badgeKey: 'low_stock' },
      { to: '/inventory/grn',             label: 'Receiving (GRN)', icon: Package,  feature: 'inventory', permission: 'inventory.view' },
      { to: '/inventory/material-issues', label: 'Issuance',        icon: FileEdit, feature: 'inventory', permission: 'inventory.view' },
    ],
  },
  {
    label: 'Supply Chain',
    items: [
      { to: '/supply-chain/deliveries', label: 'Deliveries', icon: Truck,   feature: 'supply_chain', permission: 'supply_chain.view', badgeKey: 'deliveries' },
      { to: '/supply-chain/shipments',  label: 'Shipments',  icon: Package, feature: 'supply_chain', permission: 'supply_chain.view' },
    ],
  },
  {
    label: 'Quality',
    items: [
      { to: '/quality/inspections', label: 'Inspections', icon: ShieldCheck, feature: 'quality', permission: 'quality.view' },
      { to: '/quality/ncrs',        label: 'NCRs',        icon: AlertTriangle, feature: 'quality', permission: 'quality.view', badgeKey: 'ncrs' },
    ],
  },
  {
    label: 'Finance',
    items: [
      { to: '/accounting/coa',              label: 'Chart of Accounts', icon: Landmark,       feature: 'accounting', permission: 'accounting.coa.view' },
      { to: '/accounting/journal-entries',  label: 'Journal Entries',   icon: BookOpen,       feature: 'accounting', permission: 'accounting.journal.view' },
      { to: '/accounting/invoices',         label: 'Invoices (AR)',     icon: FileText,       feature: 'accounting', permission: 'accounting.invoices.view' },
      { to: '/accounting/bills',            label: 'Bills (AP)',        icon: Receipt,        feature: 'accounting', permission: 'accounting.bills.view' },
      { to: '/accounting/vendors',          label: 'Vendors',           icon: Store,          feature: 'accounting', permission: 'accounting.vendors.view' },
      { to: '/accounting/trial-balance',    label: 'Trial Balance',     icon: BarChart2,      feature: 'accounting', permission: 'accounting.statements.view' },
      { to: '/accounting/income-statement', label: 'Income Statement',  icon: TrendingUp,     feature: 'accounting', permission: 'accounting.statements.view' },
      { to: '/accounting/balance-sheet',    label: 'Balance Sheet',     icon: BarChart2,      feature: 'accounting', permission: 'accounting.statements.view' },
      { to: '/budgeting',                   label: 'Budgets',           icon: PieChart,       permission: 'budgeting.view' },
      { to: '/budgeting/budget-vs-actual',  label: 'Budget vs Actual',  icon: BarChart2,      permission: 'budgeting.view' },
      { to: '/budgeting/transfers',         label: 'Budget Transfers',  icon: ArrowLeftRight, feature: 'accounting', permission: 'accounting.budget.view' },
    ],
  },
  {
    label: 'Human Resources',
    items: [
      { to: '/hr/employees',      label: 'Employees',     icon: Users,        feature: 'hr', permission: 'hr.employees.view', badgeKey: 'profile_requests' },
      { to: '/hr/attendance',     label: 'Attendance',     icon: Clock4,      feature: 'attendance', permission: 'attendance.view', badgeKey: 'leaves' },
      { to: '/hr/leaves',         label: 'Leave',         icon: CalendarDays, feature: 'leave', permission: 'leave.view' },
      { to: '/payroll/periods',   label: 'Payroll',       icon: Wallet,       feature: 'payroll', permission: 'payroll.view', badgeKey: 'payroll' },
    ],
  },
  {
    label: 'Maintenance',
    items: [
      { to: '/maintenance/work-orders', label: 'Work Orders', icon: Wrench,   feature: 'maintenance', permission: 'maintenance.view', badgeKey: 'maintenance_wo' },
      { to: '/maintenance/schedules',   label: 'Schedules',   icon: Calendar, feature: 'maintenance', permission: 'maintenance.view' },
    ],
  },
  {
    label: 'Assets',
    items: [
      { to: '/assets', label: 'Fixed Assets', icon: Building2, feature: 'assets', permission: 'assets.view' },
    ],
  },
  {
    label: 'Forecasting',
    items: [
      { to: '/forecasting/demand',    label: 'Demand Forecast',      icon: TrendingUp,    feature: 'forecasting', permission: 'forecasting.view' },
      { to: '/forecasting/stock-out', label: 'Stock-Out Projection', icon: AlertTriangle, feature: 'forecasting', permission: 'forecasting.view' },
    ],
  },
  {
    label: 'Return Management',
    items: [
      { to: '/return-management', label: 'Returns (RMA)', icon: RotateCcw, feature: 'return_management', permission: 'return_management.view' },
    ],
  },
  {
    label: 'Administration',
    items: [
      { to: '/admin/users',      label: 'Users',      icon: Users2,       permission: 'admin.users.manage' },
      { to: '/admin/roles',      label: 'Roles',      icon: ShieldCheck,  permission: 'admin.roles.manage' },
      { to: '/admin/audit-logs', label: 'Audit Logs', icon: FileText,     permission: 'admin.audit_logs.view' },
      { to: '/admin/settings',   label: 'Settings',   icon: SettingsIcon, permission: 'admin.settings.manage' },
    ],
  },
];

interface SidebarProps {
  permissions?: Set<string>;
  features?: Set<string>;
}

export function Sidebar({ permissions, features }: SidebarProps) {
  const collapsed = useSidebarStore((s) => s.collapsed);
  const { pathname } = useLocation();

  // Polish Task S2 — dynamic badge counts for every gated nav item.
  const { getBadge } = useBadges();

  const isVisible = (item: NavItem) => {
    if (item.feature && features && !features.has(item.feature)) return false;
    if (item.permission && permissions && !permissions.has(item.permission)) return false;
    return true;
  };

  // Filter sections to only those with visible items so the active-section
  // detection and collapsed-rail dividers don't reference hidden groups.
  const visibleSections = SECTIONS
    .map((section) => ({ ...section, visibleItems: section.items.filter(isVisible) }))
    .filter((s) => s.visibleItems.length > 0);

  // Pick the most-specific item that matches the current path so a parent
  // like `/hr/attendance` doesn't stay lit when `/hr/attendance/overtime`
  // is active.
  const matched = visibleSections
    .flatMap((s) => s.visibleItems)
    .filter((item) => pathname === item.to || pathname.startsWith(item.to + '/'))
    .sort((a, b) => b.to.length - a.to.length)[0];
  const isActive = (to: string) => matched?.to === to;

  const activeSectionLabel = matched
    ? visibleSections.find((s) => s.visibleItems.some((it) => it.to === matched.to))?.label
    : undefined;

  return (
    <aside
      className={cn(
        'shrink-0 sticky top-12 h-[calc(100vh-3rem)] border-r border-default bg-canvas overflow-y-auto transition-[width] duration-fast',
        collapsed ? 'w-14' : 'w-60',
      )}
    >
      <nav className="py-3">
        {visibleSections.map((section, idx) => {
          const isActiveSection = activeSectionLabel === section.label;
          return (
            <div key={section.label} className="mb-3">
              {/* Collapsed rail: thin divider between icon groups */}
              {collapsed && idx > 0 && (
                <div className="mx-3 mb-2 border-t border-default" aria-hidden />
              )}

              {/* Expanded: section header with accent dot */}
              {!collapsed && (
                <div
                  className={cn(
                    'px-4 mb-1 flex items-center gap-1.5 text-2xs uppercase tracking-widest font-medium',
                    isActiveSection ? 'text-primary' : 'text-text-subtle',
                  )}
                >
                  <span
                    className={cn(
                      'inline-block h-1.5 w-1.5 rounded-full',
                      isActiveSection ? 'bg-accent' : 'bg-text-subtle',
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
                      {collapsed ? (
                        <Tooltip content={`${item.label}${entry?.count ? ` (${entry.count})` : ''}`} side="right">
                          <NavLink item={item} active={isActive(item.to)} collapsed badgeOverride={entry?.count} badgeVariant={entry?.severity} />
                        </Tooltip>
                      ) : (
                        <NavLink
                          item={item}
                          active={isActive(item.to)}
                          badgeOverride={entry?.count}
                          badgeVariant={entry?.severity}
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
    </aside>
  );
}

function NavLink({
  item,
  active,
  collapsed,
  badgeOverride,
  badgeVariant,
}: {
  item: NavItem;
  active: boolean;
  collapsed?: boolean;
  badgeOverride?: number;
  /**
   * Backend-supplied severity OR explicit Badge variant. Since BadgeSeverity
   * ('warning' | 'danger' | 'neutral') is a strict subset of Badge's variant
   * union, we can pass it through directly with no mapping helper.
   */
  badgeVariant?: BadgeSeverity | 'accent';
}) {
  const Icon = item.icon;
  const badgeValue = badgeOverride ?? item.badge;
  return (
    <Link
      to={item.to}
      className={cn(
        'relative flex items-center gap-2.5 px-4 py-1.5 text-sm transition-colors duration-fast',
        active
          ? 'text-primary font-medium bg-elevated'
          : 'text-secondary hover:bg-elevated hover:text-primary',
        collapsed && 'justify-center',
      )}
    >
      {active && <span className="absolute left-0 top-1 bottom-1 w-[2px] bg-accent" aria-hidden />}
      <Icon size={14} className={collapsed ? '' : 'shrink-0'} />
      {collapsed && badgeValue != null && badgeValue > 0 && (
        <span
          className={cn(
            'absolute top-1 right-2 h-2 w-2 rounded-full',
            badgeVariant === 'danger' ? 'bg-danger' : badgeVariant === 'warning' ? 'bg-warning' : 'bg-accent',
          )}
          aria-hidden
        />
      )}
      {!collapsed && (
        <>
          <span className="truncate flex-1">{item.label}</span>
          {badgeValue != null && badgeValue > 0 ? (
            <Badge
              variant={badgeVariant ?? 'accent'}
              aria-label={`${badgeValue} pending`}
            >
              {badgeValue}
            </Badge>
          ) : null}
        </>
      )}
    </Link>
  );
}
