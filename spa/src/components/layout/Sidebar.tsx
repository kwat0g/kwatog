/* eslint-disable react-refresh/only-export-components -- navigation metadata is intentionally shared with command/search surfaces. */
import { Link, useLocation } from 'react-router-dom';
import {
 LayoutDashboard,
 Workflow,
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
 RotateCcw,
 Settings2,
 Building2,
 BarChart2,
 BarChart3,
 PieChart,
 Landmark,
 Store,
 Target,
 Tag,
 MessageSquare,
 ClipboardList,
 GitFork,
 ListTree,
 Cpu,
 Activity,
 BadgeCheck,
 Navigation,
 Route,
 X,
 UserPlus,
 Star,
 Coins,
 ShieldAlert,
 Monitor,
 Upload,
 ListChecks,
 ScanBarcode,
 GraduationCap,
 Filter,
 GitBranch,
 type LucideIcon,
} from 'lucide-react';
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
 /** Show when the user has at least one permission in this list. */
 anyPermissions?: string[];
 /** Optional feature flag (e.g. 'hr', 'inventory'). */
 feature?: string;
 /**
 * Optional role allowlist. When set, only users whose role.slug is in this
 * array see the item regardless of permission. Used to hide high-volume
 * operational pages from roles that never need them directly. RESERVED:
 * enforced in isVisible() but no item currently sets it (system_admin bypasses).
 */
 roles?: string[];
}

export interface NavSection {
 label: string;
 items: NavItem[];
}

/**
 * Sidebar — workflow pages only.
 *
 * Items with `roles` are only shown to those roles; items without `roles` are
 * visible to any role that passes the permission/feature gates. Standalone
 * "dashboard" sub-pages (Production Dashboard, Quality Dashboard) are removed
 * — users reach their role dashboard via the top-level /dashboard redirect.
 */
export const SECTIONS: NavSection[] = [
 {
 label: 'Overview',
 items: [
 { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
 { to: '/action-center', label: 'Action Center', icon: ListChecks, badgeKey: 'action_center' },
 { to: '/exceptions', label: 'Exceptions', icon: ShieldAlert },
 { to: '/dashboard/scorecard', label: 'KPI Scorecard', icon: BarChart3 },
 { to: '/chains', label: 'Chain Tracker', icon: Workflow, permission: 'crm.sales_orders.view' },
 { to: '/approvals', label: 'Approvals', icon: Inbox, permission: 'approvals.board.view', badgeKey: 'approvals' },
 { to: '/notifications', label: 'Notifications', icon: Bell, badgeKey: 'unread' },
 ],
 },
 {
 label: 'Sales & CRM',
 items: [
 { to: '/crm/sales-orders', label: 'Sales Orders', icon: Briefcase, feature: 'crm', permission: 'crm.sales_orders.view', badgeKey: 'pending_so' },
 { to: '/crm/leads', label: 'Leads', icon: Filter, feature: 'crm', permission: 'crm.leads.view' },
 { to: '/crm/opportunities', label: 'Opportunities', icon: GitBranch, feature: 'crm', permission: 'crm.opportunities.view' },
 { to: '/crm/customers', label: 'CRM Customers', icon: Users2, feature: 'crm', permission: 'crm.sales_orders.view' },
 { to: '/crm/products', label: 'Products', icon: Tag, feature: 'crm', permission: 'crm.products.view' },
 { to: '/crm/price-agreements', label: 'Price Agreements', icon: FileText, feature: 'crm', permission: 'crm.price_agreements.view' },
 { to: '/crm/complaints', label: 'Complaints', icon: MessageSquare, feature: 'crm', permission: 'crm.complaints.manage' },
 { to: '/crm/commissions', label: 'Commissions', icon: Coins, feature: 'crm', permission: 'crm.commissions.view' },
 { to: '/accounting/customers', label: 'AR Customers', icon: Users, feature: 'accounting', permission: 'accounting.customers.view' },
 { to: '/return-management', label: 'Returns (RMA)', icon: RotateCcw, feature: 'return_management', permission: 'return_management.view' },
 ],
 },
 {
 label: 'Production',
 items: [
 { to: '/production/work-orders', label: 'Work Orders', icon: FileText, feature: 'production', permission: 'production.work_orders.view', badgeKey: 'work_orders' },
 { to: '/production/schedule', label: 'Schedule (Gantt)', icon: CalendarClock, feature: 'production', permission: 'production.schedule.view' },
 { to: '/production/routings', label: 'Routings', icon: Route, feature: 'production', permission: 'production.routings.view' },
 { to: '/production/oee', label: 'OEE Report', icon: Activity, feature: 'production', permission: 'production.dashboard.view' },
 ],
 },
 {
 label: 'Production Planning (MRP)',
 items: [
 { to: '/mrp/plans', label: 'MRP Plans', icon: Layers, feature: 'mrp', permission: 'mrp.plans.view' },
 { to: '/mrp/boms', label: 'Bill of Materials', icon: ListTree, feature: 'mrp', permission: 'mrp.boms.view' },
 { to: '/mrp/machines', label: 'Machines', icon: Cpu, feature: 'mrp', permission: 'mrp.machines.view' },
 { to: '/mrp/molds', label: 'Molds', icon: Package, feature: 'mrp', permission: 'mrp.molds.view' },
 ],
 },
 {
 label: 'Procurement',
 items: [
 { to: '/purchasing/chain', label: 'Procurement Chain', icon: Workflow, feature: 'purchasing', permission: 'purchasing.view' },
 { to: '/purchasing/purchase-orders', label: 'Purchase Orders', icon: ShoppingCart, feature: 'purchasing', permission: 'purchasing.view', badgeKey: 'purchase_requests' },
 { to: '/purchasing/purchase-requests', label: 'Purchase Requests', icon: FileText, feature: 'purchasing', permission: 'purchasing.view' },
 { to: '/purchasing/approved-suppliers', label: 'Approved Suppliers', icon: BadgeCheck, feature: 'purchasing', permission: 'purchasing.view' },
 ],
 },
 {
 label: 'Warehouse',
 items: [
 { to: '/inventory/items', label: 'Items', icon: Boxes, feature: 'inventory', permission: 'inventory.view', badgeKey: 'low_stock' },
 { to: '/inventory/grn', label: 'Receiving (GRN)', icon: Package, feature: 'inventory', permission: 'inventory.view' },
 { to: '/inventory/material-issues', label: 'Issuance', icon: FileEdit, feature: 'inventory', permission: 'inventory.view' },
 { to: '/inventory/mrb', label: 'MRB / Quarantine',icon: AlertTriangle, feature: 'inventory', permission: 'inventory.mrb.view' },
 { to: '/inventory/stock-levels', label: 'Stock Levels', icon: BarChart2, feature: 'inventory', permission: 'inventory.view' },
 { to: '/inventory/movements', label: 'Movements', icon: ArrowLeftRight, feature: 'inventory', permission: 'inventory.view' },
 { to: '/inventory/stock-adjustments', label: 'Stock Adjustments', icon: Settings2, feature: 'inventory', permission: 'inventory.view' },
 { to: '/inventory/warehouse-map', label: 'Warehouse Map', icon: Boxes, feature: 'inventory', permission: 'inventory.view' },
 { to: '/inventory/stock-count', label: 'Stock Count', icon: ClipboardList, feature: 'inventory', permission: 'inventory.stock_count.view' },
 { to: '/inventory/transfer-orders', label: 'Transfer Orders', icon: ArrowLeftRight, feature: 'inventory', permission: 'inventory.view' },
 { to: '/inventory/picking', label: 'Picking', icon: Package, feature: 'inventory', permission: 'inventory.view' },
 { to: '/inventory/scanner', label: 'Warehouse Scanner', icon: ScanBarcode, feature: 'inventory', permission: 'inventory.view' },
 ],
 },
 {
 label: 'Supply Chain',
 items: [
 { to: '/supply-chain/deliveries', label: 'Deliveries', icon: Truck, feature: 'supply_chain', permission: 'supply_chain.view', badgeKey: 'deliveries' },
 { to: '/supply-chain/shipments', label: 'Shipments', icon: Package, feature: 'supply_chain', permission: 'supply_chain.view' },
 { to: '/supply-chain/fleet', label: 'Fleet', icon: Navigation, feature: 'supply_chain', permission: 'supply_chain.view' },
 ],
 },
 {
 label: 'Quality',
 items: [
 { to: '/quality/inspection-specs', label: 'Inspection Specs', icon: ClipboardList, feature: 'quality', permission: 'quality.specs.view' },
 { to: '/quality/inspections', label: 'Inspections', icon: ShieldCheck, feature: 'quality', permission: 'quality.inspections.view' },
 { to: '/quality/ncrs', label: 'NCRs', icon: AlertTriangle, feature: 'quality', permission: 'quality.ncr.view', badgeKey: 'ncrs' },
 { to: '/quality/ncr-templates', label: 'NCR Templates', icon: FileText, feature: 'quality', permission: 'quality.ncr.manage' },
 { to: '/quality/traceability', label: 'Traceability', icon: GitFork, feature: 'quality', permission: 'quality.inspections.view' },
 { to: '/quality/spc', label: 'SPC', icon: Activity, feature: 'quality', permission: 'quality.spc.view' },
 { to: '/quality/copq', label: 'COPQ', icon: Coins, feature: 'quality', permission: 'quality.copq.view' },
 { to: '/quality/documents', label: 'Documents', icon: FileText, feature: 'quality', permission: 'quality.documents.manage' },
 ],
 },
 {
 label: 'Finance',
 items: [
 { to: '/accounting/coa', label: 'Chart of Accounts', icon: Landmark, feature: 'accounting', permission: 'accounting.coa.view' },
 { to: '/accounting/journal-entries', label: 'Journal Entries', icon: BookOpen, feature: 'accounting', permission: 'accounting.journal.view' },
 { to: '/accounting/invoices', label: 'Invoices (AR)', icon: FileText, feature: 'accounting', permission: 'accounting.invoices.view' },
 { to: '/accounting/bills', label: 'Bills (AP)', icon: Receipt, feature: 'accounting', permission: 'accounting.bills.view' },
 { to: '/accounting/credit-notes', label: 'Credit Notes', icon: Receipt, feature: 'accounting', permission: 'accounting.credit_notes.view' },
 { to: '/accounting/vendors', label: 'Vendors', icon: Store, feature: 'accounting', permission: 'accounting.vendors.view' },
 { to: '/accounting/opening-balances', label: 'Opening Balances', icon: Landmark, feature: 'accounting', permission: 'accounting.opening_balance.manage' },
 { to: '/accounting/periods', label: 'Periods', icon: CalendarClock, feature: 'accounting', permission: 'accounting.periods.view' },
 { to: '/accounting/fx-rates', label: 'FX Rates', icon: Coins, feature: 'accounting', permission: 'accounting.currency.view' },
 { to: '/accounting/parent-pack', label: 'JP Parent Pack', icon: Building2, feature: 'accounting', permission: 'accounting.currency.view' },
 { to: '/budgeting', label: 'Budgets', icon: PieChart, feature: 'budgeting', permission: 'budgeting.view' },
 { to: '/budgeting/budget-vs-actual', label: 'Budget vs Actual', icon: Target, feature: 'budgeting', permission: 'budgeting.view' },
 { to: '/budgeting/transfers', label: 'Budget Transfers', icon: ArrowLeftRight, feature: 'budgeting', permission: 'budgeting.view' },
 ],
 },
 {
 label: 'Human Resources',
 items: [
 { to: '/hr/employees', label: 'Employees', icon: Users, feature: 'hr', permission: 'hr.employees.view', badgeKey: 'profile_requests' },
 { to: '/hr/departments', label: 'Departments', icon: Building2, feature: 'hr', permission: 'hr.departments.view' },
 { to: '/hr/attendance', label: 'Attendance', icon: Clock4, feature: 'attendance', anyPermissions: ['attendance.edit', 'attendance.import', 'attendance.ot.approve'], badgeKey: 'leaves' },
 { to: '/hr/attendance/overtime', label: 'Overtime', icon: CalendarClock, feature: 'attendance', permission: 'attendance.ot.approve', badgeKey: 'overtime' },
 { to: '/hr/leaves', label: 'Leave', icon: CalendarDays, feature: 'leave', anyPermissions: ['leave.approve_dept', 'leave.approve_hr', 'leave.types.manage'] },
{ to: '/hr/leaves/types', label: 'Leave Types', icon: ListChecks,   feature: 'leave',     permission: 'leave.types.manage' },
      { to: '/hr/leaves/year-end', label: 'Year-End Leave', icon: RotateCcw,    feature: 'leave',     permission: 'leave.types.manage' },
 { to: '/hr/salary-adjustments', label: 'Salary Adjustments', icon: Coins, feature: 'hr', permission: 'hr.salary_adjustments.view' },
 { to: '/payroll/periods', label: 'Payroll', icon: Wallet, feature: 'payroll', permission: 'payroll.periods.view', badgeKey: 'payroll' },
 { to: '/payroll/statutory', label: 'Statutory Exports', icon: FileText, feature: 'payroll', permission: 'payroll.statutory.export' },
      { to: '/payroll/de-minimis', label: 'De Minimis', icon: Coins, feature: 'payroll', permission: 'payroll.adjustments.create' },
 { to: '/hr/succession-plans', label: 'Succession', icon: UserPlus, feature: 'hr', permission: 'hr.succession.manage' },
 { to: '/hr/performance-reviews', label: 'Performance Reviews', icon: Star, feature: 'hr', permission: 'hr.performance.view' },
 { to: '/hr/recruitment', label: 'Recruitment', icon: Briefcase, feature: 'recruitment', permission: 'hr.recruitment.view' },
 { to: '/hr/training/matrix', label: 'Training Matrix', icon: ClipboardList, feature: 'hr', permission: 'hr.trainings.view', badgeKey: 'training_expiry' },
 { to: '/hr/trainings', label: 'Trainings', icon: GraduationCap, feature: 'hr', permission: 'hr.trainings.view' },
 { to: '/hr/skills', label: 'Skills', icon: ListChecks, feature: 'hr', permission: 'hr.trainings.view' },
 ],
 },
 {
 label: 'Maintenance',
 items: [
 { to: '/maintenance/work-orders', label: 'Work Orders', icon: Wrench, feature: 'maintenance', permission: 'maintenance.view', badgeKey: 'maintenance_wo' },
 { to: '/maintenance/schedules', label: 'Schedules', icon: Calendar, feature: 'maintenance', permission: 'maintenance.view' },
 { to: '/maintenance/machine-health', label: 'Machine Health', icon: Activity, feature: 'maintenance', permission: 'maintenance.view' },
 { to: '/maintenance/downtime', label: 'Downtime', icon: BarChart2, feature: 'maintenance', permission: 'maintenance.view' },
 ],
 },
 {
 label: 'Assets',
 items: [
 { to: '/assets', label: 'Fixed Assets', icon: Building2, feature: 'assets', permission: 'assets.view' },
 { to: '/assets/transfers', label: 'Asset Transfers', icon: ArrowLeftRight, feature: 'assets', permission: 'assets.transfer' },
 ],
 },
 {
 label: 'Administration',
 items: [
 { to: '/admin/users', label: 'Users', icon: Users2, permission: 'admin.users.manage' },
 { to: '/admin/roles', label: 'Roles', icon: ShieldCheck, permission: 'admin.roles.manage' },
 { to: '/admin/audit-logs', label: 'Audit Logs', icon: FileText, permission: 'admin.audit_logs.view' },
 { to: '/admin/sod', label: 'Segregation of Duties', icon: ShieldAlert, permission: 'admin.sod.view' },
 { to: '/admin/settings', label: 'Settings', icon: SettingsIcon, permission: 'admin.settings.manage' },
 { to: '/admin/imports', label: 'Import Data', icon: Upload, permission: 'admin.import.manage' },
 { to: '/admin/sessions', label: 'Sessions', icon: Monitor, permission: 'admin.settings.manage' },
 { to: '/admin/depreciation', label: 'Depreciation', icon: BarChart2, permission: 'assets.depreciation.view' },
 { to: '/admin/operations-health', label: 'Operations Health', icon: Activity, permission: 'dashboard.admin.view' },
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
export function isNavItemVisible(item: NavItem, { permissions, features, roleSlug }: NavVisibilityContext): boolean {
 const isAdmin = roleSlug === 'system_admin';
 if (item.feature && features && !features.has(item.feature)) return false;
 if (!isAdmin && item.permission && permissions && !permissions.has(item.permission)) return false;
 if (!isAdmin && item.anyPermissions && permissions && !item.anyPermissions.some((permission) => permissions.has(permission))) return false;
 if (!isAdmin && item.roles && roleSlug && !item.roles.includes(roleSlug)) return false;
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
 return () => { document.body.style.overflow = original; };
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
 () => SECTIONS
 .map((section) => ({ ...section, visibleItems: section.items.filter(isVisible) }))
 .filter((s) => s.visibleItems.length > 0),
 [isVisible],
 );

 // Pick the most-specific item that matches the current path so a parent
 // like `/hr/attendance` doesn't stay lit when `/hr/attendance/overtime`
 // is active.
 const matched = useMemo(
 () => visibleSections
 .flatMap((s) => s.visibleItems)
 .filter((item) => pathname === item.to || pathname.startsWith(item.to + '/'))
 .sort((a, b) => b.to.length - a.to.length)[0],
 [visibleSections, pathname],
 );

 const isActive = useCallback((to: string) => matched?.to === to, [matched]);

 const activeSectionLabel = useMemo(
 () => matched
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
 isActiveSection ? 'text-primary' : 'text-subtle',
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
 {collapsed && !mobileOpen ? (
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
 );

 return (
 <>
 {/* Desktop sidebar */}
 <aside
 className={cn(
 'shrink-0 sticky top-12 h-[calc(100vh-3rem)] border-r border-default bg-canvas overflow-y-auto transition-[width] duration-fast',
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
 maskImage: 'linear-gradient(to bottom, transparent 0%, black 20%, black 70%, transparent 100%)',
 WebkitMaskImage: 'linear-gradient(to bottom, transparent 0%, black 20%, black 70%, transparent 100%)',
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
 icon={<X size={14} />}
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
}: {
 item: NavItem;
 active: boolean;
 collapsed?: boolean;
 badgeOverride?: number;
 badgeVariant?: BadgeSeverity | 'accent';
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
 'relative flex items-center gap-3 px-3 py-2.5 mx-2 mb-0.5 rounded-md text-sm transition-all duration-fast',
 active
 ? 'text-accent font-medium bg-accent/15'
 : 'text-secondary hover:bg-surface hover:text-primary',
 collapsed && 'justify-center mx-1 py-2',
 )}
 >
 {active && <span className="absolute left-0 top-2 bottom-2 w-[3px] rounded-r-md bg-accent" aria-hidden />}
 <Icon size={16} className={cn(collapsed ? '' : 'shrink-0', active ? 'text-accent' : 'text-muted')} />
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
});
