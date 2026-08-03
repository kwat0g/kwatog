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
 * Delivery is BOTH: polls every 30s while mounted AND subscribes to Reverb via
 * useNotificationRealtime() (private user.{id} channel, notification.created) —
 * the websocket pops a toast + invalidates the query; the poll is the fallback.
 */
import { useEffect, useRef, useState } from 'react';
import { Bell } from 'lucide-react';
import { EmptyState } from '@/components/ui/EmptyState';
import { Link, useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Tooltip } from '@/components/ui/Tooltip';
import { LinkButton } from '@/components/ui/LinkButton';
import { Button } from '@/components/ui/Button';
import { cn } from '@/lib/cn';
import { notificationsApi, type NotificationRow } from '@/api/notifications';
import { notificationMeta, timeAgo } from '@/lib/notificationMeta';
import { focusRingInset } from '@/lib/focus';
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
        <Button
          variant="ghost"
          size="sm"
          iconOnly
          aria-label={`Notifications${unread > 0 ? `, ${unread} unread` : ''}`}
          aria-expanded={open}
          onClick={() => setOpen((v) => !v)}
          className="relative text-muted hover:text-primary"
        >
          <Bell size={14} />
          {unread > 0 && (
            <span
              className="absolute -top-1 -right-1 inline-flex items-center justify-center min-w-[16px] h-4 px-1 rounded-full bg-accent text-accent-fg text-2xs font-medium font-mono tabular-nums leading-none"
              aria-hidden
            >
              {unread > 99 ? '99+' : unread}
            </span>
          )}
        </Button>
      </Tooltip>

      {open && (
        <div
          className="absolute right-0 top-9 w-80 bg-canvas border border-default rounded-md z-50 animate-fade-in overflow-hidden"
          role="menu"
        >
          <div className="px-3 py-2 border-b border-default flex items-center justify-between">
            <span className="text-sm font-medium">Notifications</span>
            <div className="flex items-center gap-2">
              <span className="text-xs text-muted font-mono tabular-nums">{unread} unread</span>
              {unread > 0 && (
                <LinkButton onClick={() => { markAllMutation.mutate(); }} className="text-2xs">
                  Mark all read
                </LinkButton>
              )}
            </div>
          </div>

          {items.length === 0 ? (
            <EmptyState size="compact" icon="bell-off" title="No notifications yet" />
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
                        'w-full text-left px-3 py-2.5 flex items-start gap-2.5 hover:bg-elevated transition-colors duration-fast cursor-pointer',
                        focusRingInset,
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
