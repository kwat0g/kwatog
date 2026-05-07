import { NavLink } from 'react-router-dom';
import { Home, Calendar, Plane, Wallet, User } from 'lucide-react';
import { cn } from '@/lib/cn';

const ITEMS = [
  { to: '/self-service',          label: 'Home',    icon: Home },
  { to: '/self-service/dtr',      label: 'DTR',     icon: Calendar },
  { to: '/self-service/leaves',   label: 'Leave',   icon: Plane },
  { to: '/self-service/payslips', label: 'Payslip', icon: Wallet },
  { to: '/self-service/profile',  label: 'Me',      icon: User },
];

/**
 * U3 — Self-service bottom nav. Mounted inside SelfServiceLayout.
 * 44px tall (per spec), grayscale background, indigo dot above active item.
 */
export function BottomNav() {
  return (
    <nav
      className="fixed bottom-0 inset-x-0 h-[44px] bg-canvas border-t border-default z-30"
      role="navigation"
      aria-label="Self-service"
    >
      <ul className="grid grid-cols-5 h-full">
        {ITEMS.map((item) => {
          const Icon = item.icon;
          return (
            <li key={item.to} className="flex">
              <NavLink
                to={item.to}
                end={item.to === '/self-service'}
                className={({ isActive }) =>
                  cn(
                    'flex flex-col items-center justify-center w-full text-[10px] gap-0.5 relative',
                    isActive ? 'text-primary font-medium' : 'text-muted',
                  )
                }
              >
                {({ isActive }) => (
                  <>
                    {isActive && (
                      <span
                        aria-hidden
                        className="absolute top-1 w-1 h-1 rounded-full bg-accent"
                      />
                    )}
                    <Icon size={16} strokeWidth={1.75} />
                    <span>{item.label}</span>
                  </>
                )}
              </NavLink>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}
