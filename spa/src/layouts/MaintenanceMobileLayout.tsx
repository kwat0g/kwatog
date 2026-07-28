import { Outlet, useNavigate, useLocation, Link } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import { Wrench, ClipboardList, Thermometer } from 'lucide-react';

/**
 * Mobile-first shell for the Maintenance Tech PWA.
 * Sticky top bar with tech name + logout, bottom navigation with 3 tabs,
 * full-bleed content area between them.
 * Pattern: mirrors FactoryFloorLayout.
 */
export default function MaintenanceMobileLayout() {
  const user = useAuthStore(s => s.user);
  const logout = useAuthStore(s => s.logout);
  const navigate = useNavigate();
  const location = useLocation();

  const tabs = [
    { to: '/maintenance/mobile', label: 'Work Orders', icon: ClipboardList, exact: true },
    { to: '/maintenance/mobile/condition-reading', label: 'Readings', icon: Thermometer, exact: true },
  ] as const;

  function isActive(to: string, exact: boolean) {
    if (exact) return location.pathname === to;
    return location.pathname.startsWith(to);
  }

  return (
    <div className="min-h-screen flex flex-col bg-surface text-primary">
      {/* Sticky header */}
      <header className="sticky top-0 z-10 border-b border-default bg-canvas">
        <div className="flex items-center justify-between px-4 py-3">
          <div>
            <div className="text-xs uppercase tracking-wider text-muted flex items-center gap-1.5">
              <Wrench className="w-3.5 h-3.5" />
              Maintenance
            </div>
            <div className="font-medium leading-tight">{user?.name ?? 'Technician'}</div>
          </div>
          <button
            type="button"
            onClick={async () => {
              await logout();
              navigate('/login');
            }}
            className="text-sm text-secondary hover:text-primary px-3 py-2 min-h-[44px] rounded focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
          >
            Logout
          </button>
        </div>
      </header>

      {/* Main content */}
      <main className="flex-1 max-w-2xl w-full mx-auto px-4 py-4 pb-20">
        <Outlet />
      </main>

      {/* Bottom navigation */}
      <nav className="fixed bottom-0 inset-x-0 z-10 border-t border-default bg-canvas safe-area-pb">
        <div className="flex items-stretch max-w-2xl mx-auto">
          {tabs.map(tab => {
            const active = isActive(tab.to, tab.exact);
            const Icon = tab.icon;
            return (
              <Link
                key={tab.to}
                to={tab.to}
                className={`flex-1 flex flex-col items-center justify-center py-2 min-h-[56px] transition-colors focus:outline-none focus:ring-2 focus:ring-inset focus:ring-accent ${
                  active
                    ? 'text-accent'
                    : 'text-muted hover:text-secondary'
                }`}
              >
                <Icon className="w-5 h-5" />
                <span className="text-[11px] mt-0.5 font-medium">{tab.label}</span>
              </Link>
            );
          })}
        </div>
      </nav>
    </div>
  );
}
