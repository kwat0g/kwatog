import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Trash2 } from 'lucide-react';
import { sessionsApi, type ActiveSession } from '@/api/admin/sessions';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable, type Column, StackedCell } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';

function parseUserAgent(ua: string | null): string {
  if (!ua) return 'Unknown';
  if (ua.length > 60) return ua.slice(0, 57) + '...';
  return ua;
}

const columns: Column<ActiveSession>[] = [
  {
    key: 'user',
    header: 'User',
    cell: (s) => (
      <StackedCell
        primary={
          <span className="flex items-center gap-2">
            {s.user_name ?? 'Unknown'}
            {s.is_current && <Chip variant="info">Current</Chip>}
          </span>
        }
        secondary={s.user_email}
      />
    ),
  },
  {
    key: 'ip_address',
    header: 'IP Address',
    cell: (s) => <span className="font-mono text-xs">{s.ip_address ?? '—'}</span>,
  },
  {
    key: 'last_activity_at',
    header: 'Last Activity',
    cell: (s) => (
      <span className="text-xs">
        {new Date(s.last_activity_at).toLocaleString()}
      </span>
    ),
  },
  {
    key: 'user_agent',
    header: 'Device',
    cell: (s) => (
      <span className="text-xs text-muted">{parseUserAgent(s.user_agent)}</span>
    ),
  },
];

export default function SessionsPage() {
  const queryClient = useQueryClient();
  const [terminateTarget, setTerminateTarget] = useState<ActiveSession | null>(null);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'sessions'],
    queryFn: sessionsApi.list,
    refetchInterval: 30_000,
  });

  const terminate = useMutation({
    mutationFn: (id: string) => sessionsApi.terminate(id),
    onSuccess: () => {
      toast.success('Session terminated.');
      queryClient.invalidateQueries({ queryKey: ['admin', 'sessions'] });
      setTerminateTarget(null);
    },
    onError: () => toast.error('Could not terminate session.'),
  });

  return (
    <div>
      <PageHeader
        title="Active Sessions"
        subtitle="View and manage active user sessions across the system"
      />

      <div className="px-5 py-4">
        {isLoading && <SkeletonTable rows={5} columns={4} />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Failed to load sessions"
            action={
              <Button variant="secondary" onClick={() => window.location.reload()}>
                Retry
              </Button>
            }
          />
        )}

        {data && data.length === 0 && (
          <EmptyState icon="monitor" title="No active sessions" />
        )}

        {data && data.length > 0 && (
          <DataTable
            columns={[
              ...columns,
              {
                key: 'actions',
                header: '',
                align: 'right' as const,
                cell: (s: ActiveSession) => (
                  <Button
                    variant="ghost"
                    size="sm"
                    disabled={s.is_current}
                    onClick={() => setTerminateTarget(s)}
                    title={s.is_current ? 'Cannot terminate your own session' : 'Terminate session'}
                  >
                    <Trash2 size={14} />
                  </Button>
                ),
              },
            ]}
            data={data}
            getRowId={(s) => s.id}
          />
        )}

        <ConfirmDialog
          isOpen={!!terminateTarget}
          title="Terminate session?"
          description={`Terminate session for ${terminateTarget?.user_name ?? 'this user'}? They will be logged out immediately.`}
          confirmLabel="Terminate"
          variant="danger"
          onConfirm={() => { if (terminateTarget) terminate.mutate(terminateTarget.id); }}
          onClose={() => setTerminateTarget(null)}
          pending={terminate.isPending}
        />
      </div>
    </div>
  );
}
