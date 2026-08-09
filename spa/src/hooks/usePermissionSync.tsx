import { useEffect } from 'react';
import { Key } from 'lucide-react';
import { useAuthStore } from '@/stores/authStore';
import { useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { getEcho } from '@/lib/echo';

/**
 * Listens for real-time permission and module toggle changes via WebSocket.
 * Mount once in AppLayout.
 */
export function usePermissionSync() {
  const user = useAuthStore((s) => s.user);
  const refresh = useAuthStore((s) => s.refresh);
  const queryClient = useQueryClient();

  useEffect(() => {
    if (!user) return;

    // Echo loads on demand (see `@/lib/echo`); subscribe once it resolves and
    // skip entirely if we unmounted while the import was in flight.
    let disposed = false;
    let teardown: (() => void) | undefined;

    void getEcho().then((echo) => {
      if (disposed) return;

      // Listen for permission changes on user's private channel
      const userChannel = echo.private(`user.${user.id}`);
      userChannel.listen('.PermissionsChanged', () => {
        toast('Your permissions have been updated.', {
          icon: <Key size={16} className="text-muted" />,
        });
        refresh();
      });

      // Listen for module toggle changes on public settings channel
      const settingsChannel = echo.channel('settings');
      settingsChannel.listen('.ModuleToggled', () => {
        queryClient.invalidateQueries({ queryKey: ['settings'] });
        refresh();
      });

      teardown = () => {
        userChannel.stopListening('.PermissionsChanged');
        settingsChannel.stopListening('.ModuleToggled');
        echo.leave(`user.${user.id}`);
        echo.leave('settings');
      };
    });

    return () => {
      disposed = true;
      teardown?.();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps -- only re-subscribe when user identity changes; refresh/queryClient are stable refs
  }, [user?.id]);
}
