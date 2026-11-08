import { reportMutationError } from '@/lib/formErrors';
import { useMemo, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { LuPlus, LuPencil, LuTrash2, LuArchiveRestore } from '@/lib/icons';
import { trainingsApi } from '@/api/hr/trainings';
import { ArchiveFilter } from '@/components/ui/ArchiveFilter';
import { archiveToTrashed, type ArchiveScope } from '@/lib/archiveScope';
import { DataTable } from '@/components/ui/DataTable';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { PageHeader } from '@/components/layout/PageHeader';
import { FilterBar } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { usePermission } from '@/hooks/usePermission';
import toast from 'react-hot-toast';
import type { ListParams } from '@/types';
import type { Training } from '@/types/hr';

import { QueryErrorState } from '@/components/ui/QueryErrorState';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { showUndoToast } from '@/lib/undoToast';
import { useRealtimeList } from '@/hooks/useRealtimeRecord';
import { invalidateRealtimeChange } from '@/lib/realtime/invalidationMap';
import { sameQueryExceptPage } from '@/lib/queryKeys/pageScope';
import { useQueryRecordTarget } from '@/hooks/useQueryRecordTarget';
export default function TrainingListPage() {
 const navigate = useNavigate();
 const { can } = usePermission();
 const qc = useQueryClient();
 useRealtimeList('training', can('hr.trainings.view') ? 'visible' : undefined);
 const [filters, setFilters] = useState<ListParams>({ page: 1, per_page: 25 });
 const [scope, setScope] = useState<ArchiveScope>('active');

 const params = useMemo(() => ({ ...filters, trashed: archiveToTrashed(scope) }), [filters, scope]);

  const queryKey = ['hr', 'trainings', params] as const;
  const { data, isLoading, isError, isFetching, refetch } = useQuery({
   queryKey,
  queryFn: () => trainingsApi.list(params),
   placeholderData: (previous, previousQuery) =>
     sameQueryExceptPage(previousQuery?.queryKey, queryKey, 2) ? previous : undefined,
 });

 const deleteActionTarget = useQueryRecordTarget(data?.data, { isFetching, isError });
 const restoreActionTarget = useQueryRecordTarget(data?.data, { isFetching, isError });
 const deleteTarget = deleteActionTarget.target;
 const restoreTarget = restoreActionTarget.target;
 const canArchiveTarget = Boolean(deleteTarget && deleteActionTarget.isFresh && deleteTarget.available_actions.includes('archive'));
 const canRestoreTarget = Boolean(restoreTarget && restoreActionTarget.isFresh && restoreTarget.available_actions.includes('restore'));

 const deleteMutation = useMutation({
  mutationFn: (id: string) => trainingsApi.delete(id),
  onSuccess: (_data, archivedId: string) => {
  deleteActionTarget.close();
  qc.setQueriesData<{ data: Training[] }>({ queryKey: ['hr', 'trainings'] }, (current) => current
   ? { ...current, data: current.data.filter((row) => row.id !== archivedId) }
   : current);
  void invalidateRealtimeChange(qc, { entityType: 'training', entityId: archivedId });
  showUndoToast({
    message: 'Training archived.',
    // Archiving is reversible and one click; the restore endpoint is right
    // here. An undo is the honest price for it — a modal asking whether the
    // user meant it is a toll booth on something trivially taken back.
    onUndo: () => restoreMutation.mutate(archivedId),
  });
  },
  onError: (error, trainingId) => {
  void invalidateRealtimeChange(qc, { entityType: 'training', entityId: trainingId });
  reportMutationError(error, 'Failed to archive training.');
  },
 });

 const restoreMutation = useMutation({
  mutationFn: (id: string) => trainingsApi.restore(id),
  onSuccess: (_data, restoredId: string) => {
  restoreActionTarget.close();
  qc.setQueriesData<{ data: Training[] }>({ queryKey: ['hr', 'trainings'] }, (current) => current
   ? { ...current, data: current.data.filter((row) => row.id !== restoredId) }
   : current);
  void invalidateRealtimeChange(qc, { entityType: 'training', entityId: restoredId });
  toast.success('Training restored.');
  setScope('active');
  },
  onError: (error, trainingId) => {
  void invalidateRealtimeChange(qc, { entityType: 'training', entityId: trainingId });
  reportMutationError(error, 'Failed to restore training.');
  },
 });

 const columns = [
 { key: 'name', header: 'Name', cell: (row: Training) => <span className="font-medium">{row.name}</span> },
 {
 key: 'department', header: 'Department',
 cell: (row: Training) => row.department?.name ?? '—',
 },
 { key: 'duration_hours', header: 'Duration (h)', cell: (row: Training) => row.duration_hours ?? '—' },
 { key: 'validity_months', header: 'Validity (mo)', cell: (row: Training) => row.validity_months ?? '—' },
 {
 key: 'is_certification', header: 'Certification',
 cell: (row: Training) => row.is_certification ? <Chip variant="success">Yes</Chip> : <Chip variant="neutral">No</Chip>,
 },
 {
 key: 'is_active', header: 'Active',
 cell: (row: Training) => row.is_active ? <Chip variant="success">Active</Chip> : <Chip variant="neutral">Inactive</Chip>,
 },
 {
  key: 'actions', header: '',
  cell: (row: Training) => (
  <div className="flex gap-1">
  {row.available_actions.includes('update') && (
    <Button variant="ghost" size="xs" iconOnly aria-label={`Edit ${row.name}`} icon={<LuPencil size={12} />} onClick={(e) => { e.stopPropagation(); navigate(`/hr/trainings/${row.id}/edit`); }} />
  )}
  {row.available_actions.includes('restore') ? (
  <Button variant="ghost" size="xs" iconOnly aria-label={`Restore ${row.name}`} icon={<LuArchiveRestore size={12} />} onClick={(e) => { e.stopPropagation(); restoreActionTarget.open(row); }} />
  ) : row.available_actions.includes('archive') ? (
  <Button variant="ghost" size="xs" iconOnly aria-label={`Archive ${row.name}`} icon={<LuTrash2 size={12} />} onClick={(e) => { e.stopPropagation(); deleteActionTarget.open(row); }} />
  ) : null}
  </div>
  ),
 },
 ];

 return (
 <div>
 <PageHeader
 title="Trainings"
 subtitle={data ? `${data.meta.total} trainings` : undefined}
 actions={can('hr.trainings.manage') && (
 <Button variant="primary" size="xs" icon={<LuPlus size={14} />} onClick={() => navigate('/hr/trainings/create')}>
 Add Training
 </Button>
 )}
 />
<FilterBar
  values={{ ...filters, archive_scope: scope }}
  onFilter={(key, value) => setFilters((p) => ({ ...p, [key]: value, page: 1 }))}
  onSearch={(search) => setFilters((p) => ({ ...p, search, page: 1 }))}
  onApplySavedView={(saved, clearKeys) => {
    const nextScope = saved.archive_scope;
    if (nextScope === 'active' || nextScope === 'with' || nextScope === 'only') setScope(nextScope);
    setFilters((current) => {
      const next: Record<string, unknown> = { ...current, page: 1 };
      clearKeys.filter((key) => key !== 'archive_scope').forEach((key) => { delete next[key]; });
      const applied = Object.fromEntries(Object.entries(saved).filter(([key]) => key !== 'archive_scope'));
      return { ...next, ...applied, page: 1 } as ListParams;
    });
  }}
  searchPlaceholder="Search trainings..."
  actions={
  <ArchiveFilter
  value={scope}
  onChange={(s) => { setScope(s); setFilters((p) => ({ ...p, page: 1 })); }}
  />
  }
  />
 {isLoading && !data && <SkeletonTable columns={6} rows={8} />}
 {isError && <QueryErrorState subject="the training list" onRetry={() => void refetch()} />}
 {data && data.data.length === 0 && <EmptyState icon="file-text" title="No trainings yet" />}
 {data && data.data.length > 0 && (
 <DataTable columns={columns} data={data.data} meta={data.meta}
 onPageChange={(page) => setFilters((p) => ({ ...p, page }))}
 onRowClick={(row) => {
   if (scope !== 'only' && !row.deleted_at) navigate(`/hr/trainings/${row.id}/edit`);
 }}
 />
 )}
 {/* These were two hand-built Modals reproducing what ConfirmDialog already
 does — and losing its focus trap, Esc handling and busy state in the
 process. */}
 <ConfirmDialog
 isOpen={deleteActionTarget.targetId !== null}
 onClose={deleteActionTarget.close}
 title="Archive training?"
 description={canArchiveTarget && deleteTarget ? (
 <><span className="font-medium">{deleteTarget.name}</span> will be hidden from active lists and can be restored later.</>
 ) : isFetching ? 'Checking the latest training actions…'
  : isError ? 'Could not verify the latest training actions. Retry before archiving.'
   : !deleteTarget ? 'This training is no longer available in the current list.'
    : 'The current server state no longer allows archiving this training.'}
 confirmLabel="Archive"
 variant="danger"
 pending={deleteMutation.isPending}
 disabled={!canArchiveTarget}
 onConfirm={() => {
  const latest = qc.getQueryData<{ data: Training[] }>(queryKey)?.data.find((row) => row.id === deleteActionTarget.targetId);
  if (!deleteActionTarget.targetId || !deleteActionTarget.isFresh || !latest?.available_actions.includes('archive')) return;
  deleteMutation.mutate(deleteActionTarget.targetId);
 }}
 />
 <ConfirmDialog
 isOpen={restoreActionTarget.targetId !== null}
 onClose={restoreActionTarget.close}
 title="Restore training?"
 description={canRestoreTarget && restoreTarget ? (
 <><span className="font-medium">{restoreTarget.name}</span> will reappear in active lists.</>
 ) : isFetching ? 'Checking the latest training actions…'
  : isError ? 'Could not verify the latest training actions. Retry before restoring.'
   : !restoreTarget ? 'This training is no longer available in the current list.'
    : 'The current server state no longer allows restoring this training.'}
 confirmLabel="Restore"
 pending={restoreMutation.isPending}
 disabled={!canRestoreTarget}
 onConfirm={() => {
  const latest = qc.getQueryData<{ data: Training[] }>(queryKey)?.data.find((row) => row.id === restoreActionTarget.targetId);
  if (!restoreActionTarget.targetId || !restoreActionTarget.isFresh || !latest?.available_actions.includes('restore')) return;
  restoreMutation.mutate(restoreActionTarget.targetId);
 }}
 />
 </div>
 );
}
