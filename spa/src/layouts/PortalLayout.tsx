import { Link, useLocation } from 'react-router-dom';
import type { ReactNode } from 'react';
import {
  LayoutDashboard,
  FileText,
  Receipt,
  Truck,
  MessageSquare,
  ReceiptText,
  ClipboardList,
  LogOut,
  ChevronRight,
  Package,
  Building2,
} from 'lucide-react';

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
  { to: '/portal/supplier', label: 'Dashboard', icon: LayoutDashboard, exact: true },
  { to: '/portal/supplier/purchase-orders', label: 'Purchase Orders', icon: FileText },
  { to: '/portal/supplier/invoices', label: 'Invoices', icon: Receipt },
  { to: '/portal/supplier/deliveries', label: 'Deliveries', icon: Truck },
  { to: '/portal/supplier/statement-of-account', label: 'Statement', icon: ReceiptText },
  { to: '/portal/supplier/delivery-schedules', label: 'Schedules', icon: ClipboardList },
];

const CUSTOMER_NAV = [
  { to: '/portal/customer', label: 'Dashboard', icon: LayoutDashboard, exact: true },
  { to: '/portal/customer/orders', label: 'My Orders', icon: Package },
  { to: '/portal/customer/invoices', label: 'Invoices', icon: Receipt },
  { to: '/portal/customer/deliveries', label: 'Deliveries', icon: Truck },
  { to: '/portal/customer/complaints', label: 'Complaints', icon: MessageSquare },
  { to: '/portal/customer/statement-of-account', label: 'Statement', icon: ReceiptText },
  { to: '/portal/customer/delivery-schedules', label: 'Schedules', icon: ClipboardList },
];

function PortalSidebar({ type, nav, pathname, onLogout }: {
  type: PortalType;
  nav: typeof SUPPLIER_NAV;
  pathname: string;
  onLogout: () => void;
}) {
  const isSupplier = type === 'supplier';
  const brand = isSupplier ? 'Supplier Portal' : 'Customer Portal';
  const BrandIcon = isSupplier ? Building2 : Package;

  return (
    <aside className="w-56 shrink-0 border-r border-border bg-elevated flex flex-col h-screen sticky top-0">
      {/* Brand */}
      <div className="flex items-center gap-2 px-4 h-14 border-b border-border">
        <span className="h-7 w-7 rounded-md bg-accent text-canvas inline-flex items-center justify-center font-medium text-xs">
          <BrandIcon size={14} />
        </span>
        <span className="text-sm font-semibold truncate">{brand}</span>
      </div>

      {/* Navigation */}
      <nav className="flex-1 py-3 px-2 space-y-0.5 overflow-y-auto">
        {nav.map((item) => {
          const active = item.exact ? pathname === item.to : pathname.startsWith(item.to);
          const Icon = item.icon;
          return (
            <Link
              key={item.to}
              to={item.to}
              className={`flex items-center gap-2.5 px-3 py-2 rounded-md text-xs font-medium transition-colors ${
                active
                  ? 'bg-accent/10 text-accent'
                  : 'text-muted hover:text-primary hover:bg-subtle'
              }`}
            >
              <Icon size={15} className="shrink-0" />
              <span className="truncate">{item.label}</span>
              {active && <ChevronRight size={12} className="ml-auto shrink-0" />}
            </Link>
          );
        })}
      </nav>

      {/* Logout */}
      <div className="px-2 pb-3 border-t border-border pt-2">
        <button
          onClick={onLogout}
          className="flex items-center gap-2.5 px-3 py-2 rounded-md text-xs font-medium text-muted hover:text-danger hover:bg-danger/5 w-full transition-colors"
        >
          <LogOut size={15} />
          Sign out
        </button>
      </div>
    </aside>
  );
}

export default function PortalLayout({ type, user, onLogout, title, subtitle, children }: PortalLayoutProps) {
  const location = useLocation();
  const nav = type === 'supplier' ? SUPPLIER_NAV : CUSTOMER_NAV;

  return (
    <div className="min-h-screen bg-canvas flex">
      <PortalSidebar type={type} nav={nav} pathname={location.pathname} onLogout={onLogout} />

      <main className="flex-1 flex flex-col overflow-auto">
        {/* Top bar */}
        <header className="h-14 border-b border-border flex items-center justify-between px-5 shrink-0 bg-elevated/50 backdrop-blur-sm">
          <div>
            <h1 className="text-sm font-semibold">{title}</h1>
            <p className="text-2xs text-muted">{subtitle}</p>
          </div>
          <div className="flex items-center gap-3">
            <div className="text-right">
              <p className="text-xs font-medium">{user?.name}</p>
              <p className="text-2xs text-muted">{user?.email}</p>
            </div>
            <div className="h-8 w-8 rounded-full bg-accent/10 text-accent flex items-center justify-center text-xs font-semibold">
              {user?.name?.charAt(0)?.toUpperCase() ?? '?'}
            </div>
          </div>
        </header>

        {/* Content */}
        <div className="flex-1 p-5">
          {children}
        </div>
      </main>
    </div>
  );
}

export { SUPPLIER_NAV, CUSTOMER_NAV };
