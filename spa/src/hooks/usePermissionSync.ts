import { useEffect } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { echo } from '@/lib/echo';

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

    // Listen for permission changes on user's private channel
    const userChannel = echo.private(`user.${user.id}`);
    userChannel.listen('.PermissionsChanged', () => {
      toast('Your permissions have been updated.', { icon: '🔑' });
      refresh();
    });

    // Listen for module toggle changes on public settings channel
    const settingsChannel = echo.channel('settings');
    settingsChannel.listen('.ModuleToggled', () => {
      queryClient.invalidateQueries({ queryKey: ['settings'] });
      refresh();
    });

    return () => {
      userChannel.stopListening('.PermissionsChanged');
      settingsChannel.stopListening('.ModuleToggled');
      echo.leave(`user.${user.id}`);
      echo.leave('settings');
    };
  }, [user?.id, refresh, queryClient]);
}
