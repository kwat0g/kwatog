import { NotificationBell } from '@/components/layout/NotificationBell';
import { portalNotificationsApi } from '@/api/b2b/notifications';
import { Link, useLocation } from 'react-router-dom';
import { createPortal } from 'react-dom';
import type { ReactNode } from 'react';
import { Suspense, useCallback, useEffect, useId, useMemo, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import axios from 'axios';
import { focusRingInset } from '@/lib/focus';
import { cn } from '@/lib/cn';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { customerPortalApi } from '@/api/b2b/customer';
import { setFunctionalCurrency } from '@/lib/runtimeCurrency';
import { OfflineBanner } from '@/components/ui/OfflineBanner';
import { PortalWalkthrough } from '@/components/portal/PortalWalkthrough';
import { Avatar } from '@/components/ui/Avatar';
import { BrandLogo } from '@/components/brand/BrandLogo';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { landingApi } from '@/api/landing';
import { ErrorBoundary } from '@/components/guards/ErrorBoundary';
import { useRealtimeEvent } from '@/hooks/useRealtimeEvent';
import { useRealtimeSignal } from '@/hooks/useRealtimeSignal';
import { useRealtimeStatus } from '@/lib/realtime/context';
import { publishAuthSessionSignal, clearAuthSessionDrafts } from '@/lib/authSessionSync';
import { clearPwaCaches } from '@/sw-register';
import {
  DashboardIcon,
  OrderIcon,
  InvoiceIcon,
  DeliveryIcon,
  LuBell,
  LuLogOut,
  LuMenu,
  LuX,
  LuPackageCheck,
  LuFileText,
  LuMessageSquare,
  LuPlus,
  type IconType,
} from '@/lib/icons';

type PortalType = 'supplier' | 'customer';

interface PortalLayoutProps {
  type: PortalType;
  user: { id: string; name: string; email: string; customer_id?: string; vendor?: { id: string } | null } | null;
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

interface PortalBadgeSignal {
  key: 'overdue' | 'unpaid';
  label: string;
  count: number;
  to: string;
  variant: 'danger' | 'warning';
}

interface PortalNavSection {
  label: string;
  items: PortalNavItem[];
}

const SUPPLIER_NAV: PortalNavSection[] = [
  {
    label: 'Overview',
    items: [
      { to: '/portal/supplier', label: 'Dashboard', icon: DashboardIcon },
      { to: '/portal/supplier/notifications', label: 'Notifications', icon: LuBell },
    ],
  },
  {
    label: 'Orders',
    items: [
      { to: '/portal/supplier/purchase-orders', label: 'Purchase Orders', icon: OrderIcon },
      { to: '/portal/supplier/rfqs', label: 'Supplier RFQs', icon: LuFileText },
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
      { to: '/portal/supplier/problems', label: 'Problem reports', icon: LuMessageSquare },
    ],
  },
];

const CUSTOMER_NAV: PortalNavSection[] = [
  {
    label: 'Overview',
    items: [
      { to: '/portal/customer', label: 'Dashboard', icon: DashboardIcon },
      { to: '/portal/customer/notifications', label: 'Notifications', icon: LuBell },
    ],
  },
  {
    label: 'Orders',
    items: [
      { to: '/portal/customer/orders', label: 'Orders', icon: OrderIcon },
      { to: '/portal/customer/orders/new', label: 'Place Order', icon: LuPlus },
      { to: '/portal/customer/pricing', label: 'Pricing agreements', icon: InvoiceIcon },
    ],
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
    ],
  },
  {
    label: 'Quality',
    items: [
      { to: '/portal/customer/returns', label: 'Returns', icon: LuPackageCheck },
      { to: '/portal/customer/problems', label: 'Problem reports', icon: LuMessageSquare },
    ],
  },
];

function PortalNavLink({
  item,
  active,
  onNavigate,
  badgeSignals = [],
}: {
  item: PortalNavItem;
  active: boolean;
  onNavigate: () => void;
  badgeSignals?: PortalBadgeSignal[];
}) {
  const Icon = item.icon;
  const visibleSignals = badgeSignals.filter((signal) => signal.count > 0);
  const primary = visibleSignals[0];
  const signalDetailsId = useId();
  const [signalsOpen, setSignalsOpen] = useState(false);
  const [signalMenuPosition, setSignalMenuPosition] = useState<{ top: number; left: number } | null>(null);
  const signalButtonRef = useRef<HTMLButtonElement>(null);
  const signalMenuRef = useRef<HTMLDivElement>(null);
  const signalCloseTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const ignoreNextSignalFocus = useRef(false);
  const focusFirstSignal = useRef(false);
  const countLabel = (count: number) => count > 99 ? '99+' : count;
  const signalSummary = visibleSignals
    .map((signal) => `${signal.count} ${signal.label.toLowerCase()}`)
    .join(', ');

  const cancelSignalClose = () => {
    if (signalCloseTimer.current !== null) clearTimeout(signalCloseTimer.current);
  };
  const scheduleSignalClose = () => {
    cancelSignalClose();
    signalCloseTimer.current = setTimeout(() => setSignalsOpen(false), 150);
  };

  useEffect(() => () => {
    if (signalCloseTimer.current !== null) clearTimeout(signalCloseTimer.current);
  }, []);

  useEffect(() => {
    if (!signalsOpen || !primary) return;

    const updatePosition = () => {
      const trigger = signalButtonRef.current?.getBoundingClientRect();
      const menu = signalMenuRef.current?.getBoundingClientRect();
      if (!trigger || !menu) return;

      const width = menu.width || 256;
      const height = menu.height || 160;
      const left = Math.max(8, trigger.right - width);
      const top = Math.max(8, Math.min(trigger.bottom + 4, window.innerHeight - height - 8));
      setSignalMenuPosition({ top, left });
    };

    const closeOnOutsideClick = (event: PointerEvent) => {
      const target = event.target;
      if (!(target instanceof Node)
        || (!signalButtonRef.current?.contains(target) && !signalMenuRef.current?.contains(target))) {
        setSignalsOpen(false);
      }
    };
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return;
      setSignalsOpen(false);
      if (document.activeElement !== signalButtonRef.current) {
        ignoreNextSignalFocus.current = true;
        signalButtonRef.current?.focus();
      }
    };

    updatePosition();
    if (focusFirstSignal.current) {
      signalMenuRef.current?.querySelector<HTMLAnchorElement>('a')?.focus();
      focusFirstSignal.current = false;
    }
    window.addEventListener('resize', updatePosition);
    window.addEventListener('scroll', updatePosition, true);
    document.addEventListener('pointerdown', closeOnOutsideClick);
    document.addEventListener('keydown', closeOnEscape);

    return () => {
      window.removeEventListener('resize', updatePosition);
      window.removeEventListener('scroll', updatePosition, true);
      document.removeEventListener('pointerdown', closeOnOutsideClick);
      document.removeEventListener('keydown', closeOnEscape);
    };
  }, [primary, signalsOpen]);

  return (
    <div className="mx-2 mb-0.5">
      <div className="flex items-center gap-1">
        <Link
          to={primary?.to ?? item.to}
          onClick={onNavigate}
          aria-current={active ? 'page' : undefined}
          aria-label={visibleSignals.length ? `${item.label}: ${signalSummary}` : item.label}
          className={cn(
            'relative flex min-w-0 flex-1 items-center gap-3 rounded-md px-3 py-2.5 text-sm transition-colors duration-fast',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-inset',
            active
              ? 'text-accent font-medium bg-accent/15'
              : 'text-secondary hover:bg-surface hover:text-primary',
          )}
        >
          {active && (
            <span
              className="absolute left-0 top-2 bottom-2 w-[3px] rounded-r-md bg-accent"
              aria-hidden
            />
          )}
          <Icon size={16} className={cn('shrink-0', active ? 'text-accent' : 'text-muted')} />
          <span className="truncate flex-1">{item.label}</span>
        </Link>
        {primary && (
          <button
            ref={signalButtonRef}
            type="button"
            aria-label={`View all ${item.label} signals: ${signalSummary}`}
            aria-expanded={signalsOpen}
            aria-controls={signalDetailsId}
            aria-haspopup="dialog"
            className="z-10 flex shrink-0 cursor-pointer items-center justify-center rounded p-1 transition-colors hover:bg-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
            onMouseEnter={() => {
              cancelSignalClose();
              focusFirstSignal.current = false;
              setSignalsOpen(true);
            }}
            onMouseLeave={scheduleSignalClose}
            onFocus={() => {
              if (ignoreNextSignalFocus.current) {
                ignoreNextSignalFocus.current = false;
                return;
              }
              focusFirstSignal.current = true;
              setSignalsOpen(true);
            }}
            onClick={() => {
              focusFirstSignal.current = true;
              setSignalsOpen(true);
            }}
          >
            <Badge variant={primary.variant} className="tabular-nums">
              {countLabel(primary.count)}
              {visibleSignals.length > 1 && (
                <span aria-hidden className="ml-0.5 text-[9px] opacity-80">
                  +{visibleSignals.length - 1}
                </span>
              )}
            </Badge>
          </button>
        )}
      </div>
      {visibleSignals.length > 0 && signalsOpen && (
        createPortal(
          <div
            ref={signalMenuRef}
            id={signalDetailsId}
            role="dialog"
            aria-label={`${item.label} signals`}
            aria-modal="false"
            className="fixed z-[100] max-h-[min(70vh,28rem)] w-64 overflow-auto rounded-md border border-default bg-canvas p-2 shadow-lg"
            style={signalMenuPosition ?? { top: -9999, left: -9999 }}
            onMouseEnter={cancelSignalClose}
            onMouseLeave={scheduleSignalClose}
          >
            <p className="px-2 pb-1 text-xs font-semibold text-secondary">{item.label}</p>
            {visibleSignals.map((signal) => (
              <Link
                key={signal.key}
                to={signal.to}
                aria-label={`${item.label}: ${signal.count} ${signal.label.toLowerCase()}`}
                className="flex min-h-9 items-center justify-between gap-3 rounded px-2 text-sm text-secondary hover:bg-elevated hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                onClick={() => {
                  setSignalsOpen(false);
                  onNavigate();
                }}
              >
                <span className="truncate">{signal.label}</span>
                <Badge variant={signal.variant}>{countLabel(signal.count)}</Badge>
              </Link>
            ))}
          </div>,
          document.body,
        )
      )}
    </div>
  );
}

function PortalSidebar({
  type,
  sections,
  pathname,
  onLogout,
  mobileOpen,
  onNavigate,
  invoiceSignals,
}: {
  type: PortalType;
  sections: PortalNavSection[];
  pathname: string;
  onLogout: () => void;
  mobileOpen: boolean;
  onNavigate: () => void;
  invoiceSignals?: { unpaid: number; overdue: number };
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
      matched ? sections.find((s) => s.items.some((it) => it.to === matched.to))?.label : undefined,
    [matched, sections],
  );

  return (
    <aside
      id="portal-navigation"
      className={cn(
        'relative fixed inset-y-0 left-0 z-50 flex w-60 shrink-0 flex-col overflow-hidden border-r border-default bg-canvas md:sticky md:inset-y-auto md:top-0 md:h-dvh md:z-auto',
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
      <nav data-portal-guide-target="navigation" aria-label={`${brand} navigation`} className="relative z-10 flex min-h-0 flex-1 flex-col overflow-y-auto overscroll-contain py-3">
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
                {section.items.map((item) => {
                  const badgeSignals: PortalBadgeSignal[] = type === 'customer'
                    && item.to === '/portal/customer/invoices'
                    && invoiceSignals
                    ? [
                        { key: 'overdue', label: 'Overdue', count: invoiceSignals.overdue, to: `${item.to}?signal=overdue`, variant: 'danger' },
                        { key: 'unpaid', label: 'Unpaid', count: invoiceSignals.unpaid, to: `${item.to}?signal=unpaid`, variant: 'warning' },
                      ]
                    : [];
                  return (
                    <li key={item.to}>
                      <PortalNavLink item={item} active={isActive(item.to)} onNavigate={onNavigate} badgeSignals={badgeSignals} />
                    </li>
                  );
                })}
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

export default function PortalLayout({
  type,
  user,
  onLogout,
  title,
  subtitle,
  children,
}: PortalLayoutProps) {
  const location = useLocation();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { state: realtimeState } = useRealtimeStatus();
  const [mobileOpen, setMobileOpen] = useState(false);
  const revocationHandled = useRef(false);
  const sections = type === 'supplier' ? SUPPLIER_NAV : CUSTOMER_NAV;
  const brand = type === 'supplier' ? 'Supplier Portal' : 'Customer Portal';
  const { data: contact } = useQuery({
    queryKey: ['landing', 'contact'],
    queryFn: landingApi.contact,
    staleTime: 300_000,
  });
  const { data: invoiceSignals } = useQuery({
    queryKey: ['portal', 'customer', user?.id, 'invoice-signals'],
    queryFn: customerPortalApi.invoiceSignals,
    enabled: type === 'customer' && Boolean(user?.id),
    staleTime: 0,
  });
  const leaveRevokedPortalSession = useCallback(() => {
    if (revocationHandled.current) return;
    revocationHandled.current = true;
    queryClient.clear();
    clearAuthSessionDrafts();
    void clearPwaCaches();
    publishAuthSessionSignal(type, 'changed');
    navigate('/sign-in', {
      state: { realm: type, from: location.pathname },
      replace: true,
    });
  }, [location.pathname, navigate, queryClient, type]);
  const verifyPortalSession = useCallback(async () => {
    try {
      if (type === 'customer') await customerPortalApi.me();
      else await supplierPortalApi.me();
    } catch (error) {
      if (axios.isAxiosError(error) && error.response?.status === 401) {
        leaveRevokedPortalSession();
      }
    }
  }, [leaveRevokedPortalSession, type]);
  useRealtimeEvent(
    user?.id ? `portal.${type}.user.${user.id}` : '',
    '.b2b.portal-access.revoked',
    leaveRevokedPortalSession,
    () => { void verifyPortalSession(); },
    () => { void verifyPortalSession(); },
  );
  useEffect(() => {
    if (!user?.id || realtimeState === 'live') return;

    let disposed = false;
    let inFlight = false;
    let delay = 5_000;
    let timer: number | undefined;

    const schedule = () => {
      if (disposed) return;
      timer = window.setTimeout(async () => {
        if (!disposed && document.visibilityState === 'visible' && !inFlight) {
          inFlight = true;
          await verifyPortalSession();
          inFlight = false;
          delay = Math.min(delay * 2, 60_000);
        }
        schedule();
      }, delay);
    };
    const onVisible = () => {
      if (document.visibilityState !== 'visible') return;
      if (timer !== undefined) window.clearTimeout(timer);
      delay = 5_000;
      void verifyPortalSession();
      schedule();
    };

    schedule();
    document.addEventListener('visibilitychange', onVisible);
    window.addEventListener('focus', onVisible);

    return () => {
      disposed = true;
      if (timer !== undefined) window.clearTimeout(timer);
      document.removeEventListener('visibilitychange', onVisible);
      window.removeEventListener('focus', onVisible);
    };
  }, [realtimeState, user?.id, verifyPortalSession]);
  const refreshCustomerAccount = () => {
    void queryClient.invalidateQueries({ queryKey: ['portal', 'customer'], refetchType: 'active' });
  };
  useRealtimeEvent(
    type === 'customer' && user?.customer_id ? `customer.profile.${user.customer_id}` : '',
    '.accounting.customer.changed',
    refreshCustomerAccount,
    refreshCustomerAccount,
    refreshCustomerAccount,
  );
  useRealtimeSignal(
    'customer_returns_changed',
    type === 'customer' && user?.customer_id ? `customer.account.${user.customer_id}` : '',
    '.return.request.refresh',
  );
  useRealtimeSignal(
    'customer_delivery_schedules_changed',
    type === 'customer' && user?.customer_id ? `customer.delivery-schedule.${user.customer_id}` : '',
    '.b2b.delivery-schedule.changed',
  );
  useRealtimeSignal(
    'supplier_delivery_schedules_changed',
    type === 'supplier' && user?.vendor?.id ? `supplier.vendor.${user.vendor.id}` : '',
    '.b2b.delivery-schedule.changed',
  );
  useRealtimeSignal(
    'supplier_rfqs_changed',
    type === 'supplier' && user?.vendor?.id ? `supplier.vendor.${user.vendor.id}` : '',
    '.supplier.rfq.changed',
  );
  useRealtimeEvent(
    user?.id ? `portal.${type}.system` : '',
    '.database.restore.completed',
    () => window.location.reload(),
  );
  const {
    data: businessPolicies,
    isFetched: policiesFetched,
    isError: policiesError,
    isFetching: policiesFetching,
    refetch: refetchPolicies,
  } = useQuery({
    queryKey: ['portal', type, 'business-policies'],
    queryFn: () =>
      type === 'supplier'
        ? supplierPortalApi.businessPolicies()
        : customerPortalApi.businessPolicies(),
  });
  const functionalCurrencyCode = businessPolicies?.functional_currency_code;
  const policiesLoaded = policiesFetched && businessPolicies !== undefined;
  // Same first-paint race as AppLayout: formatPeso reads a module variable.
  const [currencyApplied, setCurrencyApplied] = useState(false);
  const loadingPortalPage = <div role="status" className="px-5 py-4 text-sm text-muted">Loading portal page…</div>;

  useEffect(() => {
    if (policiesError) {
      setCurrencyApplied(false);
      return;
    }
    setFunctionalCurrency(functionalCurrencyCode);
    if (policiesLoaded) setCurrencyApplied(true);
  }, [functionalCurrencyCode, policiesLoaded, policiesError]);

  useEffect(() => {
    setMobileOpen(false);
  }, [location.pathname]);

  return (
    <div className="flex h-dvh min-h-0 overflow-hidden bg-canvas text-primary">
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
        invoiceSignals={type === 'customer' ? invoiceSignals : undefined}
      />

      <main
        id="portal-main-content"
        tabIndex={-1}
        className="flex min-h-0 min-w-0 flex-1 flex-col overflow-y-auto overscroll-contain focus:outline-none"
      >
        {/* Top bar */}
        <header
          aria-label={`${brand} header`}
          className="sticky top-0 z-30 flex h-12 shrink-0 items-center gap-3 border-b border-default bg-canvas px-4"
        >
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
            {user && (
              <NotificationBell
                api={portalNotificationsApi[type]}
                queryKey={['portal', type, user.id, 'notifications']}
                inboxPath={`/portal/${type}/notifications`}
                realtimeChannel={`portal.${type}.user.${user.id}`}
                linkPrefix={`/portal/${type}`}
              />
            )}
            {user && <PortalWalkthrough realm={type} userId={user.id} sections={sections} />}
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
            <Suspense fallback={loadingPortalPage}>
              {policiesError ? (
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
              ) : currencyApplied ? children : loadingPortalPage}
            </Suspense>
          </ErrorBoundary>
        </div>
      </main>
    </div>
  );
}

export { SUPPLIER_NAV, CUSTOMER_NAV };
