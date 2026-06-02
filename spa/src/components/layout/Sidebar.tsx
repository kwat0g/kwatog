import { Link, useLocation } from 'react-router-dom';
import {
  AlertTriangle,
  LayoutDashboard,
  Users,
  Clock4,
  CalendarDays,
  Wallet,
  HandCoins,
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
  DollarSign,
  Inbox,
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
 * S1 — Consolidated sidebar navigation.
 *
 * Reduced from ~57 items to ~22 primary entry points. Sub-features are
 * accessed via tabs/sections within parent "hub" pages (PayrollHub,
 * AttendanceHub, AdminUsersRolesHub) or via deep links from the parent
 * page. Only PRIMARY module entry points appear here.
 */
const SECTIONS: NavSection[] = [
  {
    label: 'Overview',
    items: [
      { to: '/dashboard',  label: 'Dashboard',  icon: LayoutDashboard },
      { to: '/approvals',  label: 'Approvals',  icon: Inbox,         permission: 'approvals.board.view', badgeKey: 'approvals' },
      { to: '/calendar',   label: 'Calendar',   icon: CalendarDays,  permission: 'calendar.view' },
      { to: '/alerts',     label: 'Alerts',     icon: AlertTriangle, permission: 'alerts.view' },
    ],
  },
  {
    label: 'Sales & CRM',
    items: [
      { to: '/crm/sales-orders',     label: 'Sales orders', icon: Briefcase,     feature: 'crm', permission: 'crm.sales_orders.view' },
      { to: '/accounting/customers', label: 'Customers',    icon: Users2,        feature: 'accounting', permission: 'accounting.customers.view' },
    ],
  },
  {
    label: 'Production',
    items: [
      { to: '/production/work-orders', label: 'Work orders',      icon: FileText,      feature: 'production', permission: 'production.work_orders.view', badgeKey: 'work_orders' },
      { to: '/production/schedule',    label: 'Schedule (Gantt)', icon: CalendarClock, feature: 'production', permission: 'production.schedule.view' },
      { to: '/mrp/plans',              label: 'MRP plans',        icon: Layers,        feature: 'mrp', permission: 'mrp.plans.view' },
    ],
  },
  {
    label: 'Procurement',
    items: [
      { to: '/purchasing/purchase-requests', label: 'Purchase requests', icon: FileText,     feature: 'purchasing', permission: 'purchasing.view', badgeKey: 'purchase_requests' },
      { to: '/purchasing/purchase-orders',   label: 'Purchase orders',   icon: ShoppingCart, feature: 'purchasing', permission: 'purchasing.view' },
    ],
  },
  {
    label: 'Warehouse',
    items: [
      { to: '/inventory/items',           label: 'Items',          icon: Boxes,    feature: 'inventory', permission: 'inventory.view' },
      { to: '/inventory/grn',             label: 'Receiving (GRN)', icon: Package, feature: 'inventory', permission: 'inventory.view' },
      { to: '/inventory/material-issues', label: 'Issuance',       icon: FileEdit, feature: 'inventory', permission: 'inventory.view' },
    ],
  },
  {
    label: 'Supply Chain',
    items: [
      { to: '/supply-chain/deliveries', label: 'Deliveries', icon: Truck, feature: 'supply_chain', permission: 'supply_chain.view', badgeKey: 'deliveries' },
      { to: '/supply-chain/shipments',  label: 'Shipments',  icon: Truck, feature: 'supply_chain', permission: 'supply_chain.view' },
    ],
  },
  {
    label: 'Quality Control',
    items: [
      { to: '/quality/dashboard', label: 'Quality', icon: ShieldCheck, feature: 'quality', permission: 'quality.view', badgeKey: 'ncrs' },
    ],
  },
  {
    label: 'Finance & Accounting',
    items: [
      { to: '/accounting/journal-entries', label: 'Journal entries', icon: BookOpen,   feature: 'accounting', permission: 'accounting.journal.view' },
      { to: '/accounting/invoices',        label: 'Invoices (AR)',   icon: FileText,   feature: 'accounting', permission: 'accounting.invoices.view' },
      { to: '/accounting/bills',           label: 'Bills (AP)',      icon: Receipt,    feature: 'accounting', permission: 'accounting.bills.view' },
      { to: '/budgeting',                  label: 'Budgets',         icon: DollarSign, permission: 'budgeting.view' },
    ],
  },
  {
    label: 'Human Resources',
    items: [
      { to: '/hr/employees',        label: 'Employees',         icon: Users,     feature: 'hr', permission: 'hr.employees.view', badgeKey: 'profile_requests' },
      { to: '/hr/attendance/hub',    label: 'Attendance & Leave', icon: Clock4,   feature: 'attendance', permission: 'attendance.view', badgeKey: 'leaves' },
      { to: '/payroll/hub',          label: 'Payroll',           icon: Wallet,    feature: 'payroll', permission: 'payroll.view', badgeKey: 'payroll' },
      { to: '/hr/loans',             label: 'Loans',             icon: HandCoins, feature: 'loans', permission: 'loans.view' },
    ],
  },
  {
    label: 'Maintenance',
    items: [
      { to: '/maintenance/work-orders', label: 'Maintenance',  icon: Wrench,   feature: 'maintenance', permission: 'maintenance.view', badgeKey: 'maintenance_wo' },
      { to: '/assets',                  label: 'Assets',       icon: Package,  feature: 'assets', permission: 'assets.view' },
    ],
  },
  {
    label: 'Administration',
    items: [
      { to: '/admin/users-roles', label: 'Users & Roles',   icon: Users2,       permission: 'admin.users.manage' },
      { to: '/admin/settings',    label: 'System settings', icon: SettingsIcon, permission: 'admin.settings.manage' },
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
                        <Tooltip content={item.label} side="right">
                          <NavLink item={item} active={isActive(item.to)} collapsed />
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
