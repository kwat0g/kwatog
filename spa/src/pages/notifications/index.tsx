/** Sprint 8 — Task 77. Full notifications list. */
import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { client } from '@/api/client';
import { Button } from '@/components/ui/Button';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDateTime } from '@/lib/formatDate';

interface NotificationRow {
  id: string;
  type: string;
  data: Record<string, any>;
  read_at: string | null;
  created_at: string;
}

export default function NotificationsListPage() {
  const qc = useQueryClient();
  const [unreadOnly, setUnreadOnly] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['notifications', { unreadOnly }],
    queryFn: () => client.get<{ data: NotificationRow[]; meta: any }>('/notifications', {
      params: { per_page: 50, unread_only: unreadOnly ? 1 : 0 },
    }).then(r => r.data),
  });

  const markRead = useMutation({
    mutationFn: (id: string) => client.patch(`/notifications/${id}/read`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['notifications'] }),
  });
  const markAll = useMutation({
    mutationFn: () => client.patch('/notifications/read-all'),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['notifications'] }),
  });

  return (
    <div>
      <PageHeader
        title="Notifications"
        subtitle={data?.meta ? `${data.meta.unread_count ?? 0} unread of ${data.meta.total ?? 0} total` : undefined}
        actions={
          <div className="flex gap-1.5">
            <Button variant="secondary" size="sm" onClick={() => setUnreadOnly((v) => !v)}>
              {unreadOnly ? 'Show all' : 'Unread only'}
            </Button>
            <Button variant="secondary" size="sm" onClick={() => markAll.mutate()} loading={markAll.isPending}>
              Mark all read
            </Button>
          </div>
        }
      />
      <div className="px-5 py-4">
        {isLoading && <div className="space-y-2">{[1, 2, 3, 4].map((i) => <SkeletonBlock key={i} className="h-12 rounded-md" />)}</div>}
        {isError && <EmptyState icon="alert-circle" title="Failed to load notifications"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
        {data && data.data.length === 0 && (
          <EmptyState icon="bell" title={unreadOnly ? 'No unread notifications' : 'You have no notifications'} />
        )}
        {data && data.data.length > 0 && (
          <ul className="rounded-md border border-default divide-y divide-subtle bg-canvas">
            {data.data.map((n) => (
              <li key={n.id} className={`px-3 py-2.5 flex items-start justify-between gap-3 ${n.read_at ? '' : 'bg-subtle'}`}>
                <div className="min-w-0 flex-1">
                  <div className="text-sm">
                    <span className="font-mono text-xs text-muted mr-2">{n.type}</span>
                    {(n.data?.message as string) ?? JSON.stringify(n.data ?? {})}
                  </div>
                  <div className="text-xs text-muted font-mono mt-0.5">{formatDateTime(n.created_at)}</div>
                </div>
                {!n.read_at && (
                  <Button variant="secondary" size="sm" onClick={() => markRead.mutate(n.id)}>Mark read</Button>
                )}
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
