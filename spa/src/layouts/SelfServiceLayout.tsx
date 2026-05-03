import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { Home, Calendar, FileText, Receipt, User } from 'lucide-react';

/**
 * Sprint 8 — Task 74. Mobile-first shell for the self-service portal.
 *
 * Layout:
 *   ┌────────────────────────────┐
 *   │ 48px topbar — logo + name  │
 *   ├────────────────────────────┤
 *   │ scrollable page content    │
 *   │                            │
 *   ├────────────────────────────┤
 *   │ 56px bottom nav (5 tabs)   │
 *   └────────────────────────────┘
 *
 * Backend scopes data to auth.user.employee_id; the frontend never sends
 * an employee ID. Bottom nav has 5 large tap targets at ≥ 44px (Apple HIG).
 */

const TABS = [
  { to: '/self-service',                 label: 'Home',    Icon: Home,     end: true },
  { to: '/self-service/dtr',             label: 'DTR',     Icon: Calendar, end: false },
  { to: '/self-service/leave',           label: 'Leave',   Icon: FileText, end: false },
  { to: '/self-service/payslips',        label: 'Payslip', Icon: Receipt,  end: false },
  { to: '/self-service/me',              label: 'Me',      Icon: User,     end: false },
] as const;

export function SelfServiceLayout() {
  const location = useLocation();
  return (
    <div className="min-h-screen bg-canvas flex flex-col">
      {/* Topbar */}
      <header className="h-12 border-b border-default px-4 flex items-center justify-between bg-canvas sticky top-0 z-10">
        <div className="flex items-center gap-2">
          <div className="w-6 h-6 bg-primary text-canvas flex items-center justify-center text-sm font-medium rounded">
            O
          </div>
          <span className="text-sm font-medium">Ogami Self-Service</span>
        </div>
      </header>

      {/* Page content */}
      <main className="flex-1 overflow-y-auto pb-16">
        <Outlet key={location.pathname} />
      </main>

      {/* Bottom nav */}
      <nav className="fixed bottom-0 left-0 right-0 h-14 border-t border-default bg-canvas grid grid-cols-5 z-10">
        {TABS.map(({ to, label, Icon, end }) => (
          <NavLink
            key={to}
            to={to}
            end={end}
            className={({ isActive }) =>
              `flex flex-col items-center justify-center gap-0.5 text-[10px] tracking-wide ` +
              (isActive
                ? 'text-accent font-medium'
                : 'text-muted hover:text-primary')
            }
          >
            <Icon size={18} aria-hidden="true" />
            <span>{label}</span>
          </NavLink>
        ))}
      </nav>
    </div>
  );
}

export default SelfServiceLayout;
