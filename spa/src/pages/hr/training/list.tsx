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
export default function TrainingListPage() {
 const navigate = useNavigate();
 const { can } = usePermission();
 const qc = useQueryClient();
const [deleteTarget, setDeleteTarget] = useState<Training | null>(null);
 const [restoreTarget, setRestoreTarget] = useState<Training | null>(null);
 const [filters, setFilters] = useState<ListParams>({ page: 1, per_page: 25 });
 const [scope, setScope] = useState<ArchiveScope>('active');

 const params = useMemo(() => ({ ...filters, trashed: archiveToTrashed(scope) }), [filters, scope]);

 const { data, isLoading, isError, refetch } = useQuery({
  queryKey: ['hr', 'trainings', params],
  queryFn: () => trainingsApi.list(params),
  placeholderData: (prev) => prev,
 });

 const deleteMutation = useMutation({
  mutationFn: (id: string) => trainingsApi.delete(id),
  onSuccess: (_data, archivedId: string) => {
  qc.invalidateQueries({ queryKey: ['hr', 'trainings'] });
  showUndoToast({
    message: 'Training archived.',
    // Archiving is reversible and one click; the restore endpoint is right
    // here. An undo is the honest price for it — a modal asking whether the
    // user meant it is a toll booth on something trivially taken back.
    onUndo: () => restoreMutation.mutate(archivedId),
  });
  setDeleteTarget(null);
  },
  onError: () => toast.error('Failed to archive training.'),
 });

 const restoreMutation = useMutation({
  mutationFn: (id: string) => trainingsApi.restore(id),
  onSuccess: () => {
  qc.invalidateQueries({ queryKey: ['hr', 'trainings'] });
  toast.success('Training restored.');
  setRestoreTarget(null);
  setScope('active');
  },
  onError: () => toast.error('Failed to restore training.'),
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
  <Button variant="ghost" size="xs" iconOnly aria-label={`Edit ${row.name}`} icon={<LuPencil size={12} />} onClick={(e) => { e.stopPropagation(); navigate(`/hr/trainings/${row.id}/edit`); }} />
  {scope === 'only' ? (
  <Button variant="ghost" size="xs" iconOnly aria-label={`Restore ${row.name}`} icon={<LuArchiveRestore size={12} />} onClick={(e) => { e.stopPropagation(); setRestoreTarget(row); }} />
  ) : (
  <Button variant="ghost" size="xs" iconOnly aria-label={`Archive ${row.name}`} icon={<LuTrash2 size={12} />} onClick={(e) => { e.stopPropagation(); setDeleteTarget(row); }} />
  )}
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
  values={filters}
  onFilter={(key, value) => setFilters((p) => ({ ...p, [key]: value, page: 1 }))}
  onSearch={(search) => setFilters((p) => ({ ...p, search, page: 1 }))}
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
 onRowClick={(row) => navigate(`/hr/trainings/${row.id}/edit`)}
 />
 )}
 {/* These were two hand-built Modals reproducing what ConfirmDialog already
 does — and losing its focus trap, Esc handling and busy state in the
 process. */}
 <ConfirmDialog
 isOpen={deleteTarget !== null}
 onClose={() => setDeleteTarget(null)}
 title="Archive training?"
 description={deleteTarget && (
 <><span className="font-medium">{deleteTarget.name}</span> will be hidden from active lists and can be restored later.</>
 )}
 confirmLabel="Archive"
 variant="danger"
 pending={deleteMutation.isPending}
 onConfirm={() => { if (deleteTarget) deleteMutation.mutate(deleteTarget.id); }}
 />
 <ConfirmDialog
 isOpen={restoreTarget !== null}
 onClose={() => setRestoreTarget(null)}
 title="Restore training?"
 description={restoreTarget && (
 <><span className="font-medium">{restoreTarget.name}</span> will reappear in active lists.</>
 )}
 confirmLabel="Restore"
 pending={restoreMutation.isPending}
 onConfirm={() => { if (restoreTarget) restoreMutation.mutate(restoreTarget.id); }}
 />
 </div>
 );
}
