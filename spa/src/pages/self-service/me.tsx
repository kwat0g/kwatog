/** Sprint 8 — Task 74. Self-service: profile, preferences, logout. */
import { Link } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import { useThemeStore } from '@/stores/themeStore';
import { Button } from '@/components/ui/Button';

export default function SelfServiceMePage() {
  const user = useAuthStore((s) => s.user);
  const logout = useAuthStore((s) => s.logout);
  const { mode, setMode } = useThemeStore();

  return (
    <div className="px-4 py-4 space-y-4">
      <section className="rounded-md border border-default p-4 bg-surface">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-full bg-elevated flex items-center justify-center text-sm font-medium">
            {(user?.name ?? '?').slice(0, 2).toUpperCase()}
          </div>
          <div className="flex-1 min-w-0">
            <div className="text-sm font-medium truncate">{user?.name ?? 'Unknown user'}</div>
            <div className="text-xs text-muted truncate">{user?.email ?? '—'}</div>
          </div>
        </div>
      </section>

      <section className="rounded-md border border-default bg-canvas">
        <div className="px-3 py-2 border-b border-subtle text-xs uppercase tracking-wider text-muted font-medium">Theme</div>
        <div className="grid grid-cols-3 gap-1 p-2">
          {(['light', 'dark', 'system'] as const).map((m) => (
            <button key={m}
              onClick={() => setMode(m)}
              className={`h-8 px-3 rounded-md text-sm capitalize ${mode === m ? 'bg-elevated text-primary font-medium' : 'text-muted'}`}>
              {m}
            </button>
          ))}
        </div>
      </section>

      <section className="rounded-md border border-default bg-canvas overflow-hidden">
        <Link to="/self-service/notification-preferences" className="block px-3 py-3 hover:bg-elevated text-sm">
          Notification preferences
        </Link>
        <Link to="/change-password" className="block px-3 py-3 hover:bg-elevated text-sm border-t border-subtle">
          Change password
        </Link>
      </section>

      <Button variant="danger" className="w-full" onClick={() => logout()}>Sign out</Button>
    </div>
  );
}
