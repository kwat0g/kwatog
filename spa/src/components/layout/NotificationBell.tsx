/**
 * Sprint P4 — full-fledged notification bell.
 *
 * Replaces the placeholder shell. Shows:
 *   - Unread count badge (capped at 99+)
 *   - Click → dropdown panel with the last 8 notifications
 *   - Each row: type icon, title/message, "time ago", indigo left border
 *     when unread
 *   - "View all" footer link to /notifications
 *
 * Polls every 30s while mounted so the count stays roughly fresh without
 * needing a WebSocket. Real-time push (Reverb) is a separate follow-up
 * once the broadcast event ships from the backend.
 */
import { useEffect, useRef, useState } from 'react';
import { Bell } from 'lucide-react';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Tooltip } from '@/components/ui/Tooltip';
import { cn } from '@/lib/cn';
import { notificationsApi, type NotificationRow } from '@/api/notifications';
import { notificationMeta, timeAgo } from '@/lib/notificationMeta';
import { useNotificationRealtime } from '@/hooks/useNotificationRealtime';

const POLL_MS = 30_000;
const PEEK_COUNT = 8;

export function NotificationBell() {
  useNotificationRealtime();
  const [open, setOpen] = useState(false);
  const navigate = useNavigate();
  const containerRef = useRef<HTMLDivElement | null>(null);
  const qc = useQueryClient();

  const { data } = useQuery({
    queryKey: ['notifications', 'peek'],
    queryFn: () => notificationsApi.list({ per_page: PEEK_COUNT }),
    refetchInterval: POLL_MS,
    refetchIntervalInBackground: false,
  });

  const markRead = useMutation({
    mutationFn: (id: string) => notificationsApi.markRead(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['notifications'] });
    },
  });

  const markAllMutation = useMutation({
    mutationFn: () => notificationsApi.markAllRead(),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['notifications'] });
    },
  });

  const unread = data?.meta.unread_count ?? 0;
  const items = data?.data ?? [];

  // Close on outside click.
  useEffect(() => {
    if (!open) return;
    const onClick = (e: MouseEvent) => {
      if (!containerRef.current?.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, [open]);

  // Close on Esc.
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open]);

  const handleClick = (n: NotificationRow) => {
    setOpen(false);
    if (!n.read_at) {
      markRead.mutate(n.id);
    }
    const link = (n.data?.link_to as string | undefined) ?? null;
    if (link) navigate(link);
  };

  return (
    <div className="relative" ref={containerRef}>
      <Tooltip content="Notifications">
        <button
          type="button"
          aria-label={`Notifications${unread > 0 ? `, ${unread} unread` : ''}`}
          aria-expanded={open}
          onClick={() => setOpen((v) => !v)}
          className="h-7 w-7 inline-flex items-center justify-center rounded-md text-muted hover:bg-elevated hover:text-primary relative"
        >
          <Bell size={14} />
          {unread > 0 && (
            <span
              className="absolute -top-1 -right-1 inline-flex items-center justify-center min-w-[16px] h-4 px-1 rounded-full bg-accent text-accent-fg text-[10px] font-medium font-mono tabular-nums leading-none"
              aria-hidden
            >
              {unread > 99 ? '99+' : unread}
            </span>
          )}
        </button>
      </Tooltip>

      {open && (
        <div
          className="absolute right-0 top-9 w-80 bg-canvas border border-default rounded-md shadow-menu z-50 animate-fade-in overflow-hidden"
          role="menu"
        >
          <div className="px-3 py-2 border-b border-default flex items-center justify-between">
            <span className="text-sm font-medium">Notifications</span>
            <div className="flex items-center gap-2">
              <span className="text-xs text-muted font-mono tabular-nums">{unread} unread</span>
              {unread > 0 && (
                <button
                  type="button"
                  onClick={() => { markAllMutation.mutate(); }}
                  className="text-2xs text-accent hover:underline"
                >
                  Mark all read
                </button>
              )}
            </div>
          </div>

          {items.length === 0 ? (
            <div className="px-3 py-6 text-center text-sm text-muted">No notifications yet.</div>
          ) : (
            <ul className="max-h-96 overflow-y-auto divide-y divide-subtle">
              {items.map((n) => {
                const meta = notificationMeta(n.type);
                const Icon = meta.icon;
                const title = (n.data?.title as string | undefined) ?? meta.label;
                const message = (n.data?.message as string | undefined) ?? '';
                const isUnread = !n.read_at;
                return (
                  <li key={n.id}>
                    <button
                      type="button"
                      onClick={() => handleClick(n)}
                      className={cn(
                        'w-full text-left px-3 py-2.5 flex items-start gap-2.5 hover:bg-elevated transition-colors duration-fast',
                        isUnread && 'border-l-2 border-accent',
                      )}
                    >
                      <span
                        className={cn(
                          'shrink-0 inline-flex items-center justify-center w-6 h-6 rounded-md',
                          isUnread ? 'bg-accent text-accent-fg' : 'bg-elevated text-muted',
                        )}
                      >
                        <Icon size={12} />
                      </span>
                      <span className="min-w-0 flex-1">
                        <span className="block text-sm truncate">{title}</span>
                        {message && (
                          <span className="block text-xs text-muted truncate">{message}</span>
                        )}
                        <span className="block text-2xs text-muted font-mono tabular-nums mt-0.5">
                          {timeAgo(n.created_at)}
                        </span>
                      </span>
                    </button>
                  </li>
                );
              })}
            </ul>
          )}

          <div className="px-3 py-2 border-t border-default text-center">
            <Link
              to="/notifications"
              onClick={() => setOpen(false)}
              className="text-xs text-accent hover:underline"
            >
              View all notifications
            </Link>
          </div>
        </div>
      )}
    </div>
  );
}
