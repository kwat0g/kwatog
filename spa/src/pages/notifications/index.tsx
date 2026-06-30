/** Sprint 8 — Task 77 + Sprint P4. Notifications page.
 *
 * Polished into a grouped, filterable list:
 *   - Filter chips: All / Unread / Approvals / Alerts / System
 *   - Rows grouped by date bucket: Today / Yesterday / Earlier this
 *     week / Older
 *   - Each row shows a type icon (per `lib/notificationMeta`), title,
 *     optional message, and a relative time. Unread rows have a 2px
 *     indigo left border. Click navigates to `data.link_to` and marks
 *     the row read in the same call.
 */
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Check, Eye } from 'lucide-react';
import { notificationsApi, type NotificationRow } from '@/api/notifications';
import { Button } from '@/components/ui/Button';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { PageHeader } from '@/components/layout/PageHeader';
import { cn } from '@/lib/cn';
import {
  bucketLabel,
  dateBucket,
  notificationMeta,
  timeAgo,
  type NotificationGroup,
} from '@/lib/notificationMeta';

type FilterKey = 'all' | 'unread' | NotificationGroup;

interface FilterDef { key: FilterKey; label: string }

const FILTERS: FilterDef[] = [
  { key: 'all',       label: 'All' },
  { key: 'unread',    label: 'Unread' },
  { key: 'approvals', label: 'Approvals' },
  { key: 'alerts',    label: 'Alerts' },
  { key: 'system',    label: 'System' },
];

export default function NotificationsListPage() {
  const qc = useQueryClient();
  const navigate = useNavigate();
  const [filter, setFilter] = useState<FilterKey>('all');
  const unreadOnly = filter === 'unread';

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['notifications', { filter, unreadOnly }],
    queryFn: () => notificationsApi.list({ per_page: 50, unread_only: unreadOnly }),
    placeholderData: (prev) => prev,
  });

  const markRead = useMutation({
    mutationFn: (id: string) => notificationsApi.markRead(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['notifications'] }),
  });
  const markAll = useMutation({
    mutationFn: () => notificationsApi.markAllRead(),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['notifications'] }),
  });

  // Apply group filter client-side (filter chips other than All / Unread).
  const visibleRows = useMemo(() => {
    if (!data) return [];
    if (filter === 'all' || filter === 'unread') return data.data;
    return data.data.filter((n) => notificationMeta(n.type).group === filter);
  }, [data, filter]);

  // Group rows into Today / Yesterday / Earlier / Older buckets.
  const grouped = useMemo(() => {
    const buckets: Record<ReturnType<typeof dateBucket>, NotificationRow[]> = {
      today: [],
      yesterday: [],
      this_week: [],
      older: [],
    };
    for (const row of visibleRows) {
      buckets[dateBucket(row.created_at)].push(row);
    }
    return buckets;
  }, [visibleRows]);

  const handleClickRow = (n: NotificationRow) => {
    if (!n.read_at) markRead.mutate(n.id);
    const link = (n.data?.link_to as string | undefined) ?? null;
    if (link) navigate(link);
  };

  return (
    <div>
      <PageHeader
        title="Notifications"
        subtitle={
          data?.meta
            ? `${data.meta.unread_count} unread of ${data.meta.total} total`
            : undefined
        }
        actions={
          <Button
            variant="secondary"
            size="sm"
            icon={<Check size={14} />}
            onClick={() => markAll.mutate()}
            loading={markAll.isPending}
            disabled={(data?.meta.unread_count ?? 0) === 0}
          >
            Mark all read
          </Button>
        }
      />

      {/* Filter chips */}
      <div className="px-5 py-3 border-b border-default flex items-center gap-1.5 flex-wrap">
        {FILTERS.map((f) => {
          const unreadCount = data?.meta.unread_count ?? 0;
          const showBadge = f.key === 'unread' && unreadCount > 0;
          return (
            <button
              key={f.key}
              type="button"
              onClick={() => setFilter(f.key)}
              className={cn(
                'h-7 px-3 text-xs rounded-md border transition-colors duration-fast inline-flex items-center gap-1.5',
                filter === f.key
                  ? 'bg-primary text-canvas border-primary'
                  : 'border-default hover:bg-elevated',
              )}
            >
              {f.label}
              {showBadge && (
                <span
                  className={cn(
                    'inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 text-2xs font-mono tabular-nums rounded-full',
                    filter === f.key
                      ? 'bg-canvas/20 text-canvas'
                      : 'bg-accent text-accent-fg',
                  )}
                >
                  {unreadCount}
                </span>
              )}
            </button>
          );
        })}
      </div>

      <div className="px-5 py-4">
        {/* ─── LOADING ─── */}
        {isLoading && !data && (
          <div className="space-y-2">
            {[1, 2, 3, 4].map((i) => (
              <SkeletonBlock key={i} className="h-12 rounded-md" />
            ))}
          </div>
        )}

        {/* ─── ERROR ─── */}
        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load notifications"
            action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
          />
        )}

        {/* ─── EMPTY ─── */}
        {data && visibleRows.length === 0 && (
          <EmptyState
            icon="bell"
            title={
              filter === 'unread'
                ? 'No unread notifications'
                : filter === 'all'
                  ? 'You have no notifications'
                  : `No ${FILTERS.find((f) => f.key === filter)?.label.toLowerCase()} notifications`
            }
          />
        )}

        {/* ─── DATA ─── */}
        {data && visibleRows.length > 0 && (
          <div className="space-y-5">
            {(['today', 'yesterday', 'this_week', 'older'] as const).map((bucket) => {
              const rows = grouped[bucket];
              if (rows.length === 0) return null;
              return (
                <section key={bucket}>
                  <div className="text-2xs uppercase tracking-wider text-muted font-medium mb-2">
                    {bucketLabel(bucket)} · <span className="font-mono tabular-nums">{rows.length}</span>
                  </div>
                  <ul className="rounded-md border border-default divide-y divide-subtle bg-canvas">
                    {rows.map((n) => {
                      const meta = notificationMeta(n.type);
                      const Icon = meta.icon;
                      const title = (n.data?.title as string | undefined) ?? meta.label;
                      const message = (n.data?.message as string | undefined) ?? '';
                      const isUnread = !n.read_at;
                      return (
                        <li key={n.id}>
                          <button
                            type="button"
                            onClick={() => handleClickRow(n)}
                            className={cn(
                              'w-full text-left px-3 py-2.5 flex items-start gap-3 hover:bg-elevated transition-colors duration-fast',
                              isUnread && 'border-l-2 border-accent',
                            )}
                          >
                            <span
                              className={cn(
                                'shrink-0 inline-flex items-center justify-center w-7 h-7 rounded-md',
                                isUnread ? 'bg-accent text-accent-fg' : 'bg-elevated text-muted',
                              )}
                            >
                              <Icon size={13} />
                            </span>
                            <span className="min-w-0 flex-1">
                              <span className="block text-sm">
                                <span className={cn(isUnread && 'font-medium text-primary')}>{title}</span>
                                <span className="ml-2 text-2xs uppercase tracking-wider text-muted">
                                  {meta.label}
                                </span>
                              </span>
                              {message && (
                                <span className="block text-xs text-muted mt-0.5">{message}</span>
                              )}
                              <span className="block text-2xs text-muted font-mono tabular-nums mt-0.5">
                                {timeAgo(n.created_at)}
                              </span>
                            </span>
                            {isUnread && (
                              <button
                                type="button"
                                onClick={(e) => {
                                  e.stopPropagation();
                                  markRead.mutate(n.id);
                                }}
                                className="ml-auto shrink-0 p-1 rounded hover:bg-subtle text-muted hover:text-primary transition-colors"
                                aria-label="Mark as read"
                              >
                                <Eye size={12} />
                              </button>
                            )}
                          </button>
                        </li>
                      );
                    })}
                  </ul>
                </section>
              );
            })}
          </div>
        )}
      </div>

    </div>
  );
}
