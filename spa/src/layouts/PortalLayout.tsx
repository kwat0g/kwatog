import { Link, useLocation } from 'react-router-dom';
import type { ReactNode } from 'react';
import { useEffect, useMemo, useState } from 'react';
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
  LuMenu,
  LuX,
  LuPackageCheck,
  LuFileText,
  type IconType,
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

interface PortalNavItem {
  to: string;
  label: string;
  icon: IconType;
}

interface PortalNavSection {
  label: string;
  items: PortalNavItem[];
}

const SUPPLIER_NAV: PortalNavSection[] = [
  {
    label: 'Overview',
    items: [{ to: '/portal/supplier', label: 'Dashboard', icon: DashboardIcon }],
  },
  {
    label: 'Orders',
    items: [
      { to: '/portal/supplier/purchase-orders', label: 'Purchase Orders', icon: OrderIcon },
      { to: '/portal/supplier/item-listings', label: 'Item Listings', icon: LuPackageCheck },
    ],
  },
  {
    label: 'Billing',
    items: [
      { to: '/portal/supplier/invoices', label: 'Invoices', icon: InvoiceIcon },
      { to: '/portal/supplier/statement-of-account', label: 'Account Statement', icon: LuFileText },
    ],
  },
  {
    label: 'Logistics',
    items: [
      { to: '/portal/supplier/deliveries', label: 'Deliveries', icon: DeliveryIcon },
      { to: '/portal/supplier/delivery-schedules', label: 'Delivery Schedules', icon: ScheduleIcon },
    ],
  },
];

const CUSTOMER_NAV: PortalNavSection[] = [
  {
    label: 'Overview',
    items: [{ to: '/portal/customer', label: 'Dashboard', icon: DashboardIcon }],
  },
  {
    label: 'Orders',
    items: [{ to: '/portal/customer/orders', label: 'Orders', icon: OrderIcon }],
  },
  {
    label: 'Billing',
    items: [
      { to: '/portal/customer/invoices', label: 'Invoices', icon: InvoiceIcon },
      { to: '/portal/customer/statement-of-account', label: 'Account Statement', icon: LuFileText },
    ],
  },
  {
    label: 'Logistics',
    items: [
      { to: '/portal/customer/deliveries', label: 'Deliveries', icon: DeliveryIcon },
      { to: '/portal/customer/delivery-schedules', label: 'Delivery Schedules', icon: ScheduleIcon },
    ],
  },
  {
    label: 'Quality',
    items: [{ to: '/portal/customer/complaints', label: 'Quality Complaints', icon: ComplaintIcon }],
  },
];

function PortalNavLink({
  item,
  active,
  onNavigate,
}: {
  item: PortalNavItem;
  active: boolean;
  onNavigate: () => void;
}) {
  const Icon = item.icon;
  return (
    <Link
      to={item.to}
      onClick={onNavigate}
      aria-current={active ? 'page' : undefined}
      className={cn(
        'relative flex items-center gap-3 px-3 py-2.5 mx-2 mb-0.5 rounded-md text-sm transition-colors duration-fast',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-inset',
        active
          ? 'text-accent font-medium bg-accent/15'
          : 'text-secondary hover:bg-surface hover:text-primary',
      )}
    >
      {active && (
        <span className="absolute left-0 top-2 bottom-2 w-[3px] rounded-r-md bg-accent" aria-hidden />
      )}
      <Icon size={16} className={cn('shrink-0', active ? 'text-accent' : 'text-muted')} />
      <span className="truncate flex-1">{item.label}</span>
    </Link>
  );
}

function PortalSidebar({
  type,
  sections,
  pathname,
  onLogout,
  mobileOpen,
  onNavigate,
}: {
  type: PortalType;
  sections: PortalNavSection[];
  pathname: string;
  onLogout: () => void;
  mobileOpen: boolean;
  onNavigate: () => void;
}) {
  const brand = type === 'supplier' ? 'Supplier Portal' : 'Customer Portal';

  // Longest-prefix match so a parent like /portal/supplier does not stay lit
  // when /portal/supplier/purchase-orders is the active route.
  const matched = useMemo(
    () =>
      sections
        .flatMap((s) => s.items)
        .filter((item) => pathname === item.to || pathname.startsWith(item.to + '/'))
        .sort((a, b) => b.to.length - a.to.length)[0],
    [sections, pathname],
  );
  const isActive = (to: string) => matched?.to === to;
  const activeSectionLabel = useMemo(
    () =>
      matched
        ? sections.find((s) => s.items.some((it) => it.to === matched.to))?.label
        : undefined,
    [matched, sections],
  );

  return (
    <aside
      id="portal-navigation"
      className={cn(
        'relative fixed inset-y-0 left-0 z-50 flex w-60 shrink-0 flex-col overflow-y-auto border-r border-default bg-canvas md:sticky md:top-0 md:z-auto',
        mobileOpen ? 'flex' : 'hidden md:flex',
      )}
    >
      {/* Blueprint grid texture — decorative, matches the ERP sidebar surface. */}
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

      {/* Brand */}
      <div className="relative z-10 flex h-12 items-center justify-between border-b border-default px-4">
        <Link
          to={`/portal/${type}`}
          onClick={onNavigate}
          className="flex min-w-0 items-center gap-2 rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-inset"
        >
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

      {/* Navigation */}
      <nav aria-label={`${brand} navigation`} className="relative z-10 flex-1 py-3">
        {sections.map((section, idx) => {
          const isActiveSection = activeSectionLabel === section.label;
          return (
            <div key={section.label} className="mb-3">
              {idx > 0 && <div className="mx-4 mb-2 border-t border-default" aria-hidden />}
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
              <ul className="flex flex-col">
                {section.items.map((item) => (
                  <li key={item.to}>
                    <PortalNavLink item={item} active={isActive(item.to)} onNavigate={onNavigate} />
                  </li>
                ))}
              </ul>
            </div>
          );
        })}
      </nav>

      {/* Footer */}
      <div className="relative z-10 border-t border-default px-2 pb-3 pt-2">
        <button
          onClick={onLogout}
          className={cn(
            'flex w-full cursor-pointer items-center gap-3 rounded-md px-3 py-2.5 text-sm text-secondary transition-colors duration-fast hover:bg-danger-bg/10 hover:text-danger-fg',
            focusRingInset,
          )}
        >
          <LuLogOut size={16} />
          Sign out
        </button>
      </div>
    </aside>
  );
}

export default function PortalLayout({ type, user, onLogout, title, subtitle, children }: PortalLayoutProps) {
  const location = useLocation();
  const [mobileOpen, setMobileOpen] = useState(false);
  const sections = type === 'supplier' ? SUPPLIER_NAV : CUSTOMER_NAV;
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
  sections={sections}
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
