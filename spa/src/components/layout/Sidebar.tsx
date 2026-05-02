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
      { to: '/accounting', label: 'Accounting', icon: BookOpen, feature: 'accounting', permission: 'accounting.view' },
    ],
  },
  {
    label: 'Operations',
    items: [
      { to: '/inventory', label: 'Inventory', icon: Boxes, feature: 'inventory', permission: 'inventory.view' },
      { to: '/purchasing/purchase-orders', label: 'Purchasing', icon: ShoppingCart, feature: 'purchasing', permission: 'purchasing.view' },
      { to: '/supply-chain/deliveries', label: 'Supply Chain', icon: Truck, feature: 'supply_chain', permission: 'supply_chain.view' },
      { to: '/production/work-orders', label: 'Production', icon: Factory, feature: 'production', permission: 'production.view' },
      { to: '/mrp/plans', label: 'MRP', icon: Layers, feature: 'mrp', permission: 'mrp.view' },
      { to: '/crm/sales-orders', label: 'CRM', icon: Briefcase, feature: 'crm', permission: 'crm.view' },
      { to: '/quality/inspections', label: 'Quality', icon: ShieldCheck, feature: 'quality', permission: 'quality.view' },
      { to: '/maintenance', label: 'Maintenance', icon: Wrench, feature: 'maintenance', permission: 'maintenance.view' },
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
