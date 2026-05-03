import { Link, useLocation } from 'react-router-dom';
import {
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
  Factory,
  Layers,
  Briefcase,
  ShieldCheck,
  Wrench,
  Settings as SettingsIcon,
  Lock,
  Building2,
  TimerReset,
  CalendarClock,
  PartyPopper,
  FileEdit,
  Landmark,
  Receipt,
  BookText,
  Users2,
  FileText,
  Banknote,
  Scale,
  TrendingUp,
  Package,
  DollarSign,
  type LucideIcon,
} from 'lucide-react';
import { cn } from '@/lib/cn';
import { useSidebarStore } from '@/stores/sidebarStore';
import { Tooltip } from '@/components/ui/Tooltip';
import { Badge } from '@/components/ui/Badge';

interface NavItem {
  to: string;
  label: string;
  icon: LucideIcon;
  /** Optional unread/pending count badge. */
  badge?: number;
  /** Optional permission gate slug; sidebar hides items the user can't access. */
  permission?: string;
  /** Optional feature flag (e.g. 'hr', 'inventory'). */
  feature?: string;
}

interface NavSection {
  label: string;
  items: NavItem[];
}

const SECTIONS: NavSection[] = [
  {
    label: 'Overview',
    items: [
      { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
    ],
  },
  {
    label: 'People',
    items: [
      { to: '/hr/employees', label: 'Employees', icon: Users, feature: 'hr', permission: 'hr.employees.view' },
      { to: '/hr/departments', label: 'Departments', icon: Building2, feature: 'hr', permission: 'hr.departments.view' },
      { to: '/hr/positions', label: 'Positions', icon: Briefcase, feature: 'hr', permission: 'hr.positions.view' },
      { to: '/hr/attendance', label: 'Attendance', icon: Clock4, feature: 'attendance', permission: 'attendance.view' },
      { to: '/hr/attendance/overtime', label: 'Overtime', icon: TimerReset, feature: 'attendance', permission: 'attendance.view' },
      { to: '/hr/attendance/shifts', label: 'Shifts', icon: CalendarClock, feature: 'attendance', permission: 'attendance.view' },
      { to: '/hr/attendance/holidays', label: 'Holidays', icon: PartyPopper, feature: 'attendance', permission: 'attendance.view' },
      { to: '/hr/leaves', label: 'Leaves', icon: CalendarDays, feature: 'leave', permission: 'leave.view' },
      { to: '/payroll/periods', label: 'Payroll', icon: Wallet, feature: 'payroll', permission: 'payroll.view' },
      { to: '/payroll/adjustments', label: 'Adjustments', icon: FileEdit, feature: 'payroll', permission: 'payroll.view' },
      { to: '/hr/loans', label: 'Loans', icon: HandCoins, feature: 'loans', permission: 'loans.view' },
      { to: '/self-service/payslips', label: 'My Payslips', icon: Receipt, permission: 'payroll.view' },
    ],
  },
  {
    label: 'Finance',
    items: [
      { to: '/accounting/coa',              label: 'Chart of Accounts', icon: BookText,   feature: 'accounting', permission: 'accounting.coa.view' },
      { to: '/accounting/journal-entries',  label: 'Journal Entries',   icon: BookOpen,   feature: 'accounting', permission: 'accounting.journal.view' },
      { to: '/accounting/vendors',          label: 'Vendors',           icon: Users2,     feature: 'accounting', permission: 'accounting.vendors.view' },
      { to: '/accounting/bills',            label: 'Bills (AP)',        icon: FileText,   feature: 'accounting', permission: 'accounting.bills.view' },
      { to: '/accounting/customers',        label: 'Customers',         icon: Users2,     feature: 'accounting', permission: 'accounting.customers.view' },
      { to: '/accounting/invoices',         label: 'Invoices (AR)',     icon: FileText,   feature: 'accounting', permission: 'accounting.invoices.view' },
      { to: '/accounting/trial-balance',    label: 'Trial Balance',     icon: Scale,      feature: 'accounting', permission: 'accounting.statements.view' },
      { to: '/accounting/income-statement', label: 'Income Statement',  icon: TrendingUp, feature: 'accounting', permission: 'accounting.statements.view' },
      { to: '/accounting/balance-sheet',    label: 'Balance Sheet',     icon: Banknote,   feature: 'accounting', permission: 'accounting.statements.view' },
    ],
  },
  {
    label: 'Inventory',
    items: [
      { to: '/inventory/dashboard',          label: 'Dashboard',          icon: LayoutDashboard, feature: 'inventory', permission: 'inventory.view' },
      { to: '/inventory/items',              label: 'Items',              icon: Boxes,           feature: 'inventory', permission: 'inventory.view' },
      { to: '/inventory/categories',         label: 'Categories',         icon: Layers,          feature: 'inventory', permission: 'inventory.view' },
      { to: '/inventory/warehouse',          label: 'Warehouse',          icon: Building2,       feature: 'inventory', permission: 'inventory.view' },
      { to: '/inventory/stock-levels',       label: 'Stock levels',       icon: Boxes,           feature: 'inventory', permission: 'inventory.view' },
      { to: '/inventory/movements',          label: 'Movements',          icon: TimerReset,      feature: 'inventory', permission: 'inventory.view' },
      { to: '/inventory/grn',                label: 'GRN',                icon: FileText,        feature: 'inventory', permission: 'inventory.view' },
      { to: '/inventory/material-issues',    label: 'Material issues',    icon: FileEdit,        feature: 'inventory', permission: 'inventory.view' },
    ],
  },
  {
    label: 'Purchasing',
    items: [
      { to: '/purchasing/purchase-requests', label: 'Purchase requests', icon: FileText,     feature: 'purchasing', permission: 'purchasing.view' },
      { to: '/purchasing/purchase-orders',   label: 'Purchase orders',   icon: ShoppingCart, feature: 'purchasing', permission: 'purchasing.view' },
      { to: '/purchasing/approved-suppliers',label: 'Approved suppliers', icon: Users2,      feature: 'purchasing', permission: 'purchasing.view' },
    ],
  },
  {
    label: 'Operations',
    items: [
      { to: '/supply-chain/deliveries', label: 'Supply Chain', icon: Truck, feature: 'supply_chain', permission: 'supply_chain.view' },

      { to: '/production/dashboard',    label: 'Production',         icon: Factory,     feature: 'production', permission: 'production.dashboard.view' },
      { to: '/production/schedule',     label: 'Schedule (Gantt)',   icon: CalendarClock, feature: 'production', permission: 'production.schedule.view' },
      { to: '/quality/inspection-specs',label: 'Inspection specs',   icon: ShieldCheck, feature: 'quality',    permission: 'quality.specs.view' },
      { to: '/production/work-orders',  label: 'Work orders',        icon: FileText,    feature: 'production', permission: 'production.work_orders.view' },

      { to: '/mrp/plans',               label: 'MRP plans',          icon: Layers,      feature: 'mrp', permission: 'mrp.plans.view' },
      { to: '/mrp/boms',                label: 'BOMs',               icon: BookOpen,    feature: 'mrp', permission: 'mrp.boms.view' },
      { to: '/mrp/machines',            label: 'Machines',           icon: Factory,     feature: 'mrp', permission: 'mrp.machines.view' },
      { to: '/mrp/molds',               label: 'Molds',              icon: Layers,      feature: 'mrp', permission: 'mrp.molds.view' },

      { to: '/crm/products',            label: 'Products',           icon: Package,     feature: 'crm', permission: 'crm.products.view' },
      { to: '/crm/price-agreements',    label: 'Price agreements',   icon: DollarSign,  feature: 'crm', permission: 'crm.price_agreements.view' },
      { to: '/crm/sales-orders',        label: 'Sales orders',       icon: Briefcase,   feature: 'crm', permission: 'crm.sales_orders.view' },

      { to: '/quality/inspections',     label: 'Inspections',        icon: ShieldCheck, feature: 'quality', permission: 'quality.inspections.view' },
      { to: '/maintenance',             label: 'Maintenance',        icon: Wrench,      feature: 'maintenance', permission: 'maintenance.view' },
    ],
  },
  {
    label: 'Admin',
    items: [
      { to: '/admin/settings', label: 'Settings', icon: SettingsIcon, permission: 'admin.settings.manage' },
      { to: '/admin/roles', label: 'Roles', icon: Lock, permission: 'admin.roles.manage' },
      { to: '/admin/gov-tables', label: 'Gov Tables', icon: Landmark, permission: 'admin.gov_tables.manage' },
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

  const isVisible = (item: NavItem) => {
    if (item.feature && features && !features.has(item.feature)) return false;
    if (item.permission && permissions && !permissions.has(item.permission)) return false;
    return true;
  };

  // Determine the single most-specific item that matches the current path so a
  // parent like `/hr/attendance` doesn't stay lit when `/hr/attendance/overtime`
  // is active. We pick the visible item with the longest `to` that is either
  // an exact match or a path-segment prefix of `pathname`.
  const visibleItems = SECTIONS.flatMap((s) => s.items).filter(isVisible);
  const matched = visibleItems
    .filter((item) => pathname === item.to || pathname.startsWith(item.to + '/'))
    .sort((a, b) => b.to.length - a.to.length)[0];
  const isActive = (to: string) => matched?.to === to;

  return (
    <aside
      className={cn(
        'shrink-0 sticky top-12 h-[calc(100vh-3rem)] border-r border-default bg-canvas overflow-y-auto transition-[width] duration-fast',
        collapsed ? 'w-14' : 'w-60',
      )}
    >
      <nav className="py-3">
        {SECTIONS.map((section) => {
          const items = section.items.filter(isVisible);
          if (items.length === 0) return null;
          return (
            <div key={section.label} className="mb-4">
              {!collapsed && (
                <div className="px-4 mb-1 text-2xs uppercase tracking-wider text-text-subtle font-medium">
                  {section.label}
                </div>
              )}
              <ul className="flex flex-col">
                {items.map((item) => (
                  <li key={item.to}>
                    {collapsed ? (
                      <Tooltip content={item.label} side="right">
                        <NavLink item={item} active={isActive(item.to)} collapsed />
                      </Tooltip>
                    ) : (
                      <NavLink item={item} active={isActive(item.to)} />
                    )}
                  </li>
                ))}
              </ul>
            </div>
          );
        })}
      </nav>
    </aside>
  );
}

function NavLink({ item, active, collapsed }: { item: NavItem; active: boolean; collapsed?: boolean }) {
  const Icon = item.icon;
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
          {item.badge ? <Badge>{item.badge}</Badge> : null}
        </>
      )}
    </Link>
  );
}
