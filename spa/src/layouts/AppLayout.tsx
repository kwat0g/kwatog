import { Outlet, useNavigate } from 'react-router-dom';
import { CircleHelp } from 'lucide-react';
import toast from 'react-hot-toast';
import { Topbar } from '@/components/layout/Topbar';
import { Sidebar } from '@/components/layout/Sidebar';
import { DevErrorPanel } from '@/components/dev/DevErrorPanel';
import { useAuthStore } from '@/stores/authStore';
import { PageActionsProvider } from '@/contexts/PageActionsContext';
import { useKeyboardShortcuts } from '@/hooks/useKeyboardShortcuts';
import { KeyboardShortcutHelp } from '@/components/ui/KeyboardShortcutHelp';
import { RouteTransition } from '@/components/ui/RouteTransition';
import { Tooltip } from '@/components/ui/Tooltip';

// Inner component so the keyboard-shortcut hook lives inside the
// PageActionsProvider (otherwise the dispatcher context is null).
function AppLayoutInner() {
  const navigate = useNavigate();
  const user = useAuthStore((s) => s.user);
  const permissions = useAuthStore((s) => s.permissions);
  const features = useAuthStore((s) => s.features);
  const logout = useAuthStore((s) => s.logout);
  const { helpOpen, setHelpOpen } = useKeyboardShortcuts();

  const onLogout = async () => {
    await logout();
    toast.success('Signed out.');
    navigate('/login', { replace: true });
  };

  return (
    <div className="min-h-screen bg-canvas text-primary flex flex-col">
      <Topbar
        user={user ? { name: user.name, email: user.email } : null}
        onLogout={onLogout}
        rightExtras={
          <Tooltip content="Keyboard shortcuts (?)">
            <button
              type="button"
              onClick={() => setHelpOpen(true)}
              aria-label="Keyboard shortcuts"
              className="h-7 w-7 inline-flex items-center justify-center rounded-md text-muted hover:bg-elevated hover:text-primary"
            >
              <CircleHelp size={14} />
            </button>
          </Tooltip>
        }
      />
      <div className="flex flex-1">
        <Sidebar permissions={permissions} features={features} />
        <main className="flex-1 min-w-0">
          {/* Series X / Task X5 — 150 ms fade between routed page content. */}
          <RouteTransition>
            <Outlet />
          </RouteTransition>
        </main>
      </div>
      <DevErrorPanel />
      <KeyboardShortcutHelp open={helpOpen} onClose={() => setHelpOpen(false)} />
    </div>
  );
}

export function AppLayout() {
  return (
    <PageActionsProvider>
      <AppLayoutInner />
    </PageActionsProvider>
  );
}

export default AppLayout;
