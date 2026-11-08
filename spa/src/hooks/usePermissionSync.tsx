import { useEffect } from 'react';
import { LuKey } from '@/lib/icons';
import { useAuthStore } from '@/stores/authStore';
import toast from 'react-hot-toast';
import { useRealtimeEvent } from './useRealtimeEvent';

/** Listens for this user's permission revision signal and reloads server auth state. */
export function usePermissionSync() {
  const user = useAuthStore((state) => state.user);
  const refresh = useAuthStore((state) => state.refresh);
  const refreshSafely = () => {
    void refresh().catch(() => console.warn('Could not refresh the current access scope.'));
  };
  const refreshAccess = () => {
    toast('Your access has been updated.', {
      icon: <LuKey size={16} className="text-muted" />,
    });
    refreshSafely();
  };

  useEffect(() => {
    let refreshing = false;
    const refreshOnReturn = () => {
      if (refreshing || document.visibilityState !== 'visible') return;
      refreshing = true;
      void refresh()
        .catch(() => console.warn('Could not refresh the current access scope after returning to the app.'))
        .finally(() => { refreshing = false; });
    };

    window.addEventListener('focus', refreshOnReturn);
    document.addEventListener('visibilitychange', refreshOnReturn);
    return () => {
      window.removeEventListener('focus', refreshOnReturn);
      document.removeEventListener('visibilitychange', refreshOnReturn);
    };
  }, [refresh]);

  useEffect(() => {
    const boundary = user?.access_scope_refresh_at;
    if (!boundary) return;

    const deadline = Date.parse(boundary);
    if (!Number.isFinite(deadline)) return;

    const timer = window.setTimeout(() => {
      // Background timers can be throttled; the focus/visibility listener
      // above catches up as soon as the user returns to the active screen.
      if (document.visibilityState !== 'visible') return;
      void refresh().catch(() => console.warn('Could not refresh access scope at its next effective boundary.'));
    }, Math.max(0, deadline - Date.now()));

    return () => window.clearTimeout(timer);
  }, [refresh, user?.access_scope_refresh_at]);

  useRealtimeEvent(user?.id ? `user.access.${user.id}` : '', '.permission.override.changed', () => {
    toast('Your permissions have been updated.', {
      icon: <LuKey size={16} className="text-muted" />,
    });
    refreshSafely();
  }, () => {
    // A permission event may have been published while this access channel was
    // still authorizing or reconnecting. Re-read the server identity on ack.
    refreshSafely();
  });

  useRealtimeEvent(
    user?.role?.id ? `role.${user.role.id}` : '',
    '.role.permissions.changed',
    refreshAccess,
    refreshSafely,
    refreshSafely,
  );

  useRealtimeEvent(
    user?.id ? `user.access.${user.id}` : '',
    '.user.access.changed',
    refreshAccess,
    undefined,
    refreshSafely,
  );

  // Display/table preferences affect this same identity in sibling tabs.
  // Re-fetch /auth/user and apply server state without a permission-change toast.
  useRealtimeEvent(
    user?.id ? `user.preferences.${user.id}` : '',
    '.user.preferences.changed',
    refreshSafely,
    refreshSafely,
    refreshSafely,
  );
}
