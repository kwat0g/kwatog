import { Outlet, useNavigate } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';

/**
 * T2.5 — Mobile-first shell for the Driver PWA.
 * No sidebar, no app chrome — just a compact top bar with the driver's
 * name and a logout button. Pages render full-bleed below.
 */
export default function DriverLayout() {
  const user = useAuthStore(s => s.user);
  const logout = useAuthStore(s => s.logout);
  const navigate = useNavigate();

  return (
    <div className="min-h-screen bg-surface text-primary">
      <header className="sticky top-0 z-10 border-b border-default bg-canvas">
        <div className="flex items-center justify-between px-4 py-3">
          <div>
            <div className="text-xs uppercase tracking-wider text-muted">Driver</div>
            <div className="font-medium leading-tight">{user?.name ?? 'Driver'}</div>
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
      <main className="max-w-2xl mx-auto px-4 py-4">
        <Outlet />
      </main>
    </div>
  );
}
