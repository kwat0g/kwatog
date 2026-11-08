import { reportMutationError } from '@/lib/formErrors';
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
import { LuTrash2, LuArchiveRestore, LuPencil, LuPlus } from '@/lib/icons';
import { scheduledExportsApi } from '@/api/exports';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { ArchiveFilter } from '@/components/ui/ArchiveFilter';
import { archiveToTrashed, type ArchiveScope } from '@/lib/archiveScope';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { LinkButton } from '@/components/ui/LinkButton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDateTime } from '@/lib/formatDate';
import type { ScheduledExport } from '@/types/exports';
import type { PaginatedResponse } from '@/types';

import { useUrlFilters } from '@/hooks/useUrlFilters';
import { showUndoToast } from '@/lib/undoToast';
import { ScheduledExportFormModal } from '@/components/exports/ScheduledExportFormModal';
import { useRealtimeList } from '@/hooks/useRealtimeRecord';
import { useQueryRecordTarget } from '@/hooks/useQueryRecordTarget';
import { useAuthStore } from '@/stores/authStore';
import { invalidateRealtimeChange } from '@/lib/realtime/invalidationMap';

function removeScheduledExport(
 current: PaginatedResponse<ScheduledExport> | undefined,
 id: string,
): PaginatedResponse<ScheduledExport> | undefined {
 if (!current) return current;
 const data = current.data.filter((row) => row.id !== id);
 const removed = current.data.length - data.length;
 return removed === 0
  ? current
  : { ...current, data, meta: { ...current.meta, total: Math.max(0, current.meta.total - removed) } };
}

export default function ScheduledExportsPage() {
const [filters, setFilters] = useUrlFilters<{ page: number; scope: ArchiveScope }>({
 page: 1,
 scope: 'active',
 });
 const { page, scope } = filters;
 const setPage = (next: number) => setFilters((f) => ({ ...f, page: next }));
 const setScope = (next: ArchiveScope) => setFilters((f) => ({ ...f, scope: next, page: 1 }));
 const [formOpen, setFormOpen] = useState(false);
 const [editTarget, setEditTarget] = useState<ScheduledExport | null>(null);
 const queryClient = useQueryClient();
 const user = useAuthStore((state) => state.user);
 const canViewAll = useAuthStore((state) => state.permissions.has('admin.audit_logs.view'));
 const cacheScope = `${user?.id ?? 'anonymous'}:${canViewAll ? 'admin' : 'owner'}`;
 useRealtimeList('scheduled_export', canViewAll ? 'admin' : user?.id ? `user.${user.id}` : undefined);
 const reconcileSchedule = (schedule: ScheduledExport) => {
  const currentAuth = useAuthStore.getState();
  if (currentAuth.user?.id !== user?.id || currentAuth.permissions.has('admin.audit_logs.view') !== canViewAll) return;
  queryClient.setQueriesData<PaginatedResponse<ScheduledExport>>(
   { queryKey: ['scheduled-exports', cacheScope] },
   (current) => current
    ? { ...current, data: current.data.map((row) => row.id === schedule.id ? schedule : row) }
    : current,
  );
 };

 const queryKey = ['scheduled-exports', cacheScope, page, scope] as const;
 const { data, isLoading, isError, isFetching, refetch } = useQuery({
  queryKey,
  queryFn: () => scheduledExportsApi.list(page, archiveToTrashed(scope)),
  placeholderData: (previousData, previousQuery) =>
   previousQuery?.queryKey[1] === cacheScope ? previousData : undefined,
 });

 const deleteActionTarget = useQueryRecordTarget(data?.data, { isFetching, isError });
 const restoreActionTarget = useQueryRecordTarget(data?.data, { isFetching, isError });
 const deleteTarget = deleteActionTarget.target;
 const restoreTarget = restoreActionTarget.target;
 const canArchiveTarget = Boolean(deleteTarget?.available_actions.archive && deleteActionTarget.isFresh);
 const canRestoreTarget = Boolean(restoreTarget?.available_actions.restore && restoreActionTarget.isFresh);

 const deleteMutation = useMutation({
  mutationFn: (id: string) => scheduledExportsApi.destroy(id),
  onSuccess: (_data, archivedId: string) => {
  deleteActionTarget.close();
  if (scope === 'active') {
   queryClient.setQueryData<PaginatedResponse<ScheduledExport>>(queryKey, (current) => removeScheduledExport(current, archivedId));
  }
  void invalidateRealtimeChange(queryClient, { entityType: 'scheduled_export', entityId: archivedId });
  showUndoToast({
    message: 'Scheduled export archived.',
    // Archiving is reversible and one click; the restore endpoint is right
    // here. An undo is the honest price for it — a modal asking whether the
    // user meant it is a toll booth on something trivially taken back.
    onUndo: () => restoreMutation.mutate(archivedId),
  });
  },
  onError: (_error, archivedId: string) => {
  void invalidateRealtimeChange(queryClient, { entityType: 'scheduled_export', entityId: archivedId });
  toast.error('Failed to archive the scheduled export.');
  },
 });

 const restoreMutation = useMutation({
  mutationFn: (id: string) => scheduledExportsApi.restore(id),
  onSuccess: (_data, restoredId: string) => {
  restoreActionTarget.close();
  if (scope === 'only') {
   queryClient.setQueryData<PaginatedResponse<ScheduledExport>>(queryKey, (current) => removeScheduledExport(current, restoredId));
  }
  void invalidateRealtimeChange(queryClient, { entityType: 'scheduled_export', entityId: restoredId });
  toast.success('Scheduled export restored.');
  },
  onError: (_error, restoredId: string) => {
  void invalidateRealtimeChange(queryClient, { entityType: 'scheduled_export', entityId: restoredId });
  toast.error('Failed to restore the scheduled export.');
  },
 });

 const toggleMutation = useMutation({
 mutationFn: (row: ScheduledExport) =>
 scheduledExportsApi.update(row.id, { is_active: !row.is_active }),
 onSuccess: (updated: ScheduledExport) => {
 const currentAuth = useAuthStore.getState();
 if (currentAuth.user?.id === user?.id
  && currentAuth.permissions.has('admin.audit_logs.view') === canViewAll) {
  queryClient.setQueriesData<PaginatedResponse<ScheduledExport>>(
   { queryKey: ['scheduled-exports', cacheScope] },
   (current) => current
    ? { ...current, data: current.data.map((row) => row.id === updated.id ? updated : row) }
    : current,
  );
 }
  void invalidateRealtimeChange(queryClient, { entityType: 'scheduled_export', entityId: updated.id });
 },
 onError: (error, row) => {
  void invalidateRealtimeChange(queryClient, { entityType: 'scheduled_export', entityId: row.id });
  reportMutationError(error, 'Failed to toggle the schedule.');
 },
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
 cell: (row) => (
 <StackedCell
 primary={<NumCell>{row.last_run_at ? formatDateTime(row.last_run_at) : '—'}</NumCell>}
 secondary={
 row.processing ? 'Running…' : row.last_error ? `Retry pending: ${row.last_error}` : undefined
 }
 />
 ),
 },
 {
 key: 'is_active',
 header: 'Status',
 cell: (row) => row.available_actions.update ? (
 <LinkButton
 tone="muted"
 className="text-xs hover:no-underline"
 aria-label={row.is_active ? 'Pause this export' : 'Resume this export'}
 disabled={!row.available_actions.update || isFetching || toggleMutation.isPending}
 onClick={() => {
  const current = queryClient.getQueryData<PaginatedResponse<ScheduledExport>>(queryKey)
   ?.data.find((item) => item.id === row.id);
  if (!isFetching && current?.available_actions.update) toggleMutation.mutate(current);
 }}
 >
 <Chip variant={row.is_active ? 'success' : 'neutral'}>
 {row.is_active ? 'Active' : 'Paused'}
 </Chip>
 </LinkButton>
 ) : <Chip variant={row.is_active ? 'success' : 'neutral'}>{row.is_active ? 'Active' : 'Paused'}</Chip>,
 },
 {
 key: 'actions',
  header: '',
  align: 'right',
  cell: (row) => (
  <div className="flex items-center justify-end gap-1">
    {row.available_actions.update && (
      <Button
        size="sm"
        variant="ghost"
        icon={<LuPencil size={14} />}
        onClick={() => {
          setEditTarget(row);
          setFormOpen(true);
        }}
      >
        Edit
      </Button>
    )}
    {row.available_actions.restore ? (
      <Button
        size="sm"
        variant="ghost"
        icon={<LuArchiveRestore size={14} />}
        onClick={() => restoreActionTarget.open(row)}
      >
        Restore
      </Button>
    ) : row.available_actions.archive ? (
      <Button
        size="sm"
        variant="ghost"
        icon={<LuTrash2 size={14} />}
        onClick={() => deleteActionTarget.open(row)}
      >
        Archive
      </Button>
    ) : null}
  </div>
  ),
  },
  ];

 return (
 <div>
<PageHeader
  title="Scheduled exports"
  subtitle={data ? `${data.meta.total} schedules` : undefined}
  actions={
    <Button
      variant="primary"
      size="sm"
      icon={<LuPlus size={14} />}
      onClick={() => {
        setEditTarget(null);
        setFormOpen(true);
      }}
    >
      New schedule
    </Button>
  }
  />

  <div className="px-5 pt-4 flex justify-end">
  <ArchiveFilter value={scope} onChange={setScope} />
  </div>

  {isLoading && !data && <SkeletonTable columns={7} rows={6} />}

 {isError && (
 <EmptyState
 title="Failed to load scheduled exports"
 description="This data could not be loaded. Check your connection and try again."
 action={
 <Button variant="secondary" size="xs" onClick={() => refetch()}>
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
  isOpen={deleteActionTarget.targetId !== null}
  onClose={deleteActionTarget.close}
  title="Archive scheduled export?"
  description={canArchiveTarget && deleteTarget
   ? `\u201C${deleteTarget.name}\u201D will stop running and be archived. It can be restored later.`
   : deleteActionTarget.isChecking
    ? 'Checking the current schedule state…'
    : 'This schedule is no longer available to archive. Refresh the list for current actions.'}
  confirmLabel={deleteMutation.isPending ? 'Archiving\u2026' : 'Archive'}
  variant="danger"
  onConfirm={() => {
  if (deleteTarget && canArchiveTarget) deleteMutation.mutate(deleteTarget.id);
  }}
  pending={deleteMutation.isPending}
  disabled={!canArchiveTarget}
  />

  <ConfirmDialog
  isOpen={restoreActionTarget.targetId !== null}
  onClose={restoreActionTarget.close}
  title="Restore scheduled export?"
  description={canRestoreTarget && restoreTarget
   ? `\u201C${restoreTarget.name}\u201D will resume running on its schedule.`
   : restoreActionTarget.isChecking
    ? 'Checking the current schedule state…'
    : 'This schedule is no longer available to restore. Refresh the list for current actions.'}
  confirmLabel={restoreMutation.isPending ? 'Restoring\u2026' : 'Restore'}
  onConfirm={() => {
  if (restoreTarget && canRestoreTarget) restoreMutation.mutate(restoreTarget.id);
  }}
  pending={restoreMutation.isPending}
  disabled={!canRestoreTarget}
  />

  <ScheduledExportFormModal
    isOpen={formOpen}
    onClose={() => {
      setFormOpen(false);
      setEditTarget(null);
    }}
    module={editTarget?.module ?? 'hr.employees'}
    filters={editTarget?.filters}
    initial={editTarget}
    onSaved={(schedule) => {
      reconcileSchedule(schedule);
      if (page === 1 && scope === 'active' && !data?.data.some((row) => row.id === schedule.id)) {
        const currentAuth = useAuthStore.getState();
        if (currentAuth.user?.id === user?.id
          && currentAuth.permissions.has('admin.audit_logs.view') === canViewAll) {
          queryClient.setQueryData<PaginatedResponse<ScheduledExport>>(
            ['scheduled-exports', cacheScope, page, scope],
            (current) => current
              ? {
                  ...current,
                  data: [schedule, ...current.data].slice(0, 25),
                  meta: { ...current.meta, total: current.meta.total + 1 },
                }
              : current,
          );
        }
      }
      void invalidateRealtimeChange(queryClient, { entityType: 'scheduled_export', entityId: schedule.id });
    }}
  />
 </div>
 );
}
