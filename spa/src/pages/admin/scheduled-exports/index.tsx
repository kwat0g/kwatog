/**
 * Series E (E2) — Scheduled exports admin page.
 *
 * Lists every scheduled export the user owns (or every one, for admins),
 * with the standard 5 list-page states (loading skeleton, error, empty,
 * data, stale). Inline toggle for is_active. Delete via confirm dialog.
 */

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Trash2 } from 'lucide-react';
import { scheduledExportsApi } from '@/api/exports';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDateTime } from '@/lib/formatDate';
import type { ScheduledExport } from '@/types/exports';

export default function ScheduledExportsPage() {
  const [page, setPage] = useState(1);
  const [deleteTarget, setDeleteTarget] = useState<ScheduledExport | null>(null);
  const queryClient = useQueryClient();

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['scheduled-exports', page],
    queryFn: () => scheduledExportsApi.list(page),
    placeholderData: (prev) => prev,
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => scheduledExportsApi.destroy(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['scheduled-exports'] });
      toast.success('Scheduled export removed.');
      setDeleteTarget(null);
    },
    onError: () => {
      toast.error('Failed to remove the scheduled export.');
    },
  });

  const toggleMutation = useMutation({
    mutationFn: (row: ScheduledExport) =>
      scheduledExportsApi.update(row.id, { is_active: !row.is_active }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['scheduled-exports'] });
    },
    onError: () => toast.error('Failed to toggle the schedule.'),
  });

  const columns: Column<ScheduledExport>[] = [
    {
      key: 'name',
      header: 'Name',
      cell: (row) => (
        <StackedCell
          primary={<span className="font-medium">{row.name}</span>}
          secondary={<span className="text-muted">{row.module}</span>}
        />
      ),
    },
    {
      key: 'frequency',
      header: 'Frequency',
      cell: (row) => (
        <span className="capitalize">
          {row.frequency}
          {row.frequency === 'weekly' && row.day_of_week !== null && ` · DoW ${row.day_of_week}`}
          {row.frequency === 'monthly' && row.day_of_month !== null && ` · day ${row.day_of_month}`}
          {row.time_of_day && ` · ${row.time_of_day}`}
        </span>
      ),
    },
    {
      key: 'format',
      header: 'Format',
      cell: (row) => <Chip variant="neutral">{row.format.toUpperCase()}</Chip>,
    },
    {
      key: 'recipients',
      header: 'Recipients',
      cell: (row) => (
        <span className="text-xs text-muted">
          {row.recipients.length === 0
            ? '—'
            : row.recipients.length === 1
              ? row.recipients[0]
              : `${row.recipients[0]} +${row.recipients.length - 1}`}
        </span>
      ),
    },
    {
      key: 'next_run_at',
      header: 'Next run',
      cell: (row) => <NumCell>{formatDateTime(row.next_run_at)}</NumCell>,
    },
    {
      key: 'last_run_at',
      header: 'Last run',
      cell: (row) => <NumCell>{row.last_run_at ? formatDateTime(row.last_run_at) : '—'}</NumCell>,
    },
    {
      key: 'is_active',
      header: 'Status',
      cell: (row) => (
        <button
          className="text-xs underline-offset-2 hover:underline"
          onClick={() => toggleMutation.mutate(row)}
        >
          <Chip variant={row.is_active ? 'success' : 'neutral'}>
            {row.is_active ? 'Active' : 'Paused'}
          </Chip>
        </button>
      ),
    },
    {
      key: 'actions',
      header: '',
      align: 'right',
      cell: (row) => (
        <Button
          size="sm"
          variant="ghost"
          icon={<Trash2 size={14} />}
          onClick={() => setDeleteTarget(row)}
        >
          Remove
        </Button>
      ),
    },
  ];

  return (
    <div>
      <PageHeader
        title="Scheduled exports"
        subtitle={data ? `${data.meta.total} schedules` : undefined}
      />

      {isLoading && !data && <SkeletonTable columns={7} rows={6} />}

      {isError && (
        <EmptyState
          title="Failed to load scheduled exports"
          description="An error occurred while loading the list. Please try again."
          action={
            <Button variant="secondary" size="sm" onClick={() => refetch()}>
              Retry
            </Button>
          }
        />
      )}

      {data && data.data.length === 0 && (
        <EmptyState
          title="No scheduled exports yet"
          description="Schedule a recurring export from any list page that supports configurable columns. The recipients will get the file by email at the chosen frequency."
        />
      )}

      {data && data.data.length > 0 && (
        <DataTable<ScheduledExport>
          columns={columns}
          data={data.data}
          meta={data.meta}
          onPageChange={setPage}
          getRowId={(row) => row.id}
        />
      )}

      <ConfirmDialog
        isOpen={!!deleteTarget}
        onClose={() => setDeleteTarget(null)}
        title="Remove scheduled export?"
        description={
          deleteTarget
            ? `\u201C${deleteTarget.name}\u201D will stop running. This cannot be undone.`
            : undefined
        }
        confirmLabel={deleteMutation.isPending ? 'Removing\u2026' : 'Remove'}
        variant="danger"
        onConfirm={() => {
          if (deleteTarget) deleteMutation.mutate(deleteTarget.id);
        }}
        pending={deleteMutation.isPending}
      />
    </div>
  );
}
