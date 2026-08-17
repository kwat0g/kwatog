import { Link, useLocation } from 'react-router-dom';
import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { focusRingInset } from '@/lib/focus';
import { cn } from '@/lib/cn';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { customerPortalApi } from '@/api/b2b/customer';
import { setFunctionalCurrency } from '@/lib/runtimeCurrency';
import { OfflineBanner } from '@/components/ui/OfflineBanner';
import { Avatar } from '@/components/ui/Avatar';
import { BrandLogo } from '@/components/brand/BrandLogo';
import { Button } from '@/components/ui/Button';
import { landingApi } from '@/api/landing';
import { ErrorBoundary } from '@/components/guards/ErrorBoundary';
import {
 DashboardIcon,
 OrderIcon,
 InvoiceIcon,
 DeliveryIcon,
 ScheduleIcon,
 ComplaintIcon,
 LuLogOut,
 LuChevronRight,
 LuMenu,
 LuX,
 LuPackage,
 LuBuilding2,
} from '@/lib/icons';

type PortalType = 'supplier' | 'customer';

interface PortalLayoutProps {
 type: PortalType;
 user: { name: string; email: string } | null;
 onLogout: () => void;
 title: string;
 subtitle: string;
 children: ReactNode;
}

const SUPPLIER_NAV = [
 { to: '/portal/supplier', label: 'Dashboard', icon: DashboardIcon, exact: true },
 { to: '/portal/supplier/purchase-orders', label: 'Purchase orders', icon: OrderIcon },
 { to: '/portal/supplier/invoices', label: 'Invoices', icon: InvoiceIcon },
 { to: '/portal/supplier/deliveries', label: 'Deliveries', icon: DeliveryIcon },
 { to: '/portal/supplier/statement-of-account', label: 'Account statement', icon: InvoiceIcon },
 { to: '/portal/supplier/delivery-schedules', label: 'Delivery schedules', icon: ScheduleIcon },
];

const CUSTOMER_NAV = [
 { to: '/portal/customer', label: 'Dashboard', icon: DashboardIcon, exact: true },
 { to: '/portal/customer/orders', label: 'Orders', icon: OrderIcon },
 { to: '/portal/customer/invoices', label: 'Invoices', icon: InvoiceIcon },
 { to: '/portal/customer/deliveries', label: 'Deliveries', icon: DeliveryIcon },
 { to: '/portal/customer/complaints', label: 'Quality complaints', icon: ComplaintIcon },
 { to: '/portal/customer/statement-of-account', label: 'Account statement', icon: InvoiceIcon },
 { to: '/portal/customer/delivery-schedules', label: 'Delivery schedules', icon: ScheduleIcon },
];

function PortalSidebar({ type, nav, pathname, onLogout, mobileOpen, onNavigate }: {
 type: PortalType;
 nav: typeof SUPPLIER_NAV;
 pathname: string;
 onLogout: () => void;
 mobileOpen: boolean;
 onNavigate: () => void;
}) {
 const isSupplier = type === 'supplier';
 const brand = isSupplier ? 'Supplier Portal' : 'Customer Portal';
 const BrandIcon = isSupplier ? LuBuilding2 : LuPackage;

 return (
 <aside
 id="portal-navigation"
 className={cn(
 'fixed inset-y-0 left-0 z-50 flex w-60 shrink-0 flex-col border-r border-default bg-canvas md:sticky md:top-0 md:z-auto md:flex',
 mobileOpen ? 'flex' : 'hidden md:flex',
 )}
 >
 {/* Brand */}
 <div className="flex h-12 items-center justify-between border-b border-default px-4">
 <Link to={`/portal/${type}`} onClick={onNavigate} className="flex min-w-0 items-center gap-2 rounded-md">
 <BrandLogo alt="Ogami ERP" className="h-7 shrink-0" />
 <span className="truncate text-sm font-medium text-primary">{brand}</span>
 </Link>
 <button
 type="button"
 onClick={onNavigate}
 aria-label="Close portal navigation"
 className="min-h-hit min-w-hit rounded-md text-muted hover:bg-elevated hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent md:hidden"
 >
 <LuX size={15} />
 </button>
 </div>

 <div className="border-b border-default px-4 py-3">
 <p className="flex items-center gap-1.5 font-mono text-[10px] uppercase tracking-[0.16em] text-text-subtle">
 <BrandIcon size={11} />
 External workspace
 </p>
 </div>

 {/* Navigation */}
 <nav aria-label={`${brand} navigation`} className="flex-1 space-y-0.5 overflow-y-auto px-2 py-3">
 <p className="px-3 pb-2 pt-1 font-mono text-2xs uppercase tracking-[0.16em] text-text-subtle">Your workspace</p>
 {nav.map((item) => {
 const active = item.exact ? pathname === item.to : pathname.startsWith(item.to);
 const Icon = item.icon;
 return (
 <Link
 key={item.to}
 to={item.to}
 onClick={onNavigate}
 aria-current={active ? 'page' : undefined}
 className={`flex min-h-row items-center gap-2.5 border-l-2 px-3 text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-inset ${
 active
 ? 'border-accent bg-elevated text-primary font-medium'
 : 'border-transparent text-secondary hover:bg-elevated hover:text-primary'
 }`}
 >
 <Icon size={15} className="shrink-0" />
 <span className="truncate">{item.label}</span>
 {active && <LuChevronRight size={12} className="ml-auto shrink-0" />}
 </Link>
 );
 })}
 </nav>

 {/* Logout */}
 <div className="border-t border-default px-2 pb-3 pt-2">
 <Link
 to="/"
 onClick={onNavigate}
 className="mb-1 flex min-h-row items-center gap-2.5 rounded-md px-3 text-sm text-secondary transition-colors hover:bg-elevated hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-inset"
 >
 <span className="h-1.5 w-1.5 rounded-full bg-accent" aria-hidden="true" />
 Back to website
 </Link>
 <button
 onClick={onLogout}
 className={cn('flex min-h-row w-full cursor-pointer items-center gap-2.5 rounded-md px-3 text-sm text-secondary transition-colors hover:bg-danger-bg/10 hover:text-danger-fg', focusRingInset)}
 >
 <LuLogOut size={15} />
 Sign out
 </button>
 </div>
 </aside>
 );
}

export default function PortalLayout({ type, user, onLogout, title, subtitle, children }: PortalLayoutProps) {
 const location = useLocation();
 const [mobileOpen, setMobileOpen] = useState(false);
 const nav = type === 'supplier' ? SUPPLIER_NAV : CUSTOMER_NAV;
 const brand = type === 'supplier' ? 'Supplier Portal' : 'Customer Portal';
 const { data: contact } = useQuery({
 queryKey: ['landing', 'contact'],
 queryFn: landingApi.contact,
 staleTime: 300_000,
 });
 const { data: businessPolicies } = useQuery({
 queryKey: ['portal', type, 'business-policies'],
 queryFn: () => type === 'supplier' ? supplierPortalApi.businessPolicies() : customerPortalApi.businessPolicies(),
 });

 useEffect(() => {
 setFunctionalCurrency(businessPolicies?.functional_currency_code);
 }, [businessPolicies?.functional_currency_code]);

 useEffect(() => {
 setMobileOpen(false);
 }, [location.pathname]);

 return (
 <div className="flex min-h-screen bg-canvas text-primary">
 <a
 href="#portal-main-content"
 className="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-[100] focus:rounded-md focus:bg-accent focus:px-3 focus:py-1.5 focus:text-sm focus:text-accent-fg"
 >
 Skip to portal content
 </a>
 {mobileOpen && (
 <button
 type="button"
 aria-label="Close portal navigation"
 onClick={() => setMobileOpen(false)}
 className="fixed inset-0 z-40 bg-primary/20 md:hidden"
 />
 )}
 <PortalSidebar
 type={type}
 nav={nav}
 pathname={location.pathname}
 onLogout={onLogout}
 mobileOpen={mobileOpen}
 onNavigate={() => setMobileOpen(false)}
 />

 <main id="portal-main-content" tabIndex={-1} className="flex min-w-0 flex-1 flex-col overflow-auto focus:outline-none">
 {/* Top bar */}
 <header aria-label={`${brand} header`} className="sticky top-0 z-30 flex h-12 shrink-0 items-center gap-3 border-b border-default bg-canvas px-4">
 <Button
 variant="ghost"
 size="sm"
 iconOnly
 icon={<LuMenu size={14} />}
 aria-label="Open portal navigation"
 aria-expanded={mobileOpen}
 aria-controls="portal-navigation"
 onClick={() => setMobileOpen(true)}
 className="text-muted hover:text-primary md:hidden"
 />
 <Link to={`/portal/${type}`} className="flex shrink-0 items-center gap-2 md:hidden">
 <BrandLogo alt="Ogami ERP" className="h-7" />
 </Link>
 <div className="hidden min-w-0 items-center gap-3 md:flex">
 <span className="truncate text-sm font-medium">{contact?.legal_name ?? '—'}</span>
 <span className="border-l border-default pl-3 text-sm text-muted">{brand}</span>
 </div>
 <div className="min-w-0 flex-1 md:hidden">
 <h1 className="truncate text-sm font-medium">{title}</h1>
 <p className="truncate text-2xs text-muted">{subtitle}</p>
 </div>
 <div className="ml-auto flex items-center gap-2">
 <div className="hidden text-right sm:block">
 <p className="text-xs font-medium">{user?.name}</p>
 <p className="text-2xs text-muted">{user?.email}</p>
 </div>
 <Avatar size="md" name={user?.name} />
 </div>
 </header>
 <OfflineBanner placement="in-header" />

 {/* Content — no padding here. Portal pages own the same anatomy as app
 pages: a full-bleed <PageHeader /> followed by a `px-5 py-4` body. */}
 <div className="flex-1 min-w-0">
 {/* A supplier or customer who crashes a portal page has no sidebar to fall
 back on; keep the failure inside the content column so the portal nav
 and sign-out survive. */}
 <ErrorBoundary>
 {children}
 </ErrorBoundary>
 </div>
 </main>
 </div>
 );
}

export { SUPPLIER_NAV, CUSTOMER_NAV };
