import { Outlet, useNavigate } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import { Button } from '@/components/ui/Button';

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
          <Button
            variant="ghost"
            size="lg"
            className="min-h-[44px] text-secondary"
            onClick={async () => {
              await logout();
              navigate('/login');
            }}
          >
            Log out
          </Button>
        </div>
      </header>
      <main className="max-w-2xl mx-auto px-4 py-4">
        <Outlet />
      </main>
    </div>
  );
}
