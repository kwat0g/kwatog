/**
 * ADV7 — NCR Template list page.
 *
 * Lists saved NCR templates. Each row has a "Use" button that pre-fills
 * the NCR creation form with the template's values, plus edit/delete.
 */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { LuPlus, LuPencil, LuTrash2, LuCopy, LuArchiveRestore } from '@/lib/icons';
import toast from 'react-hot-toast';
import { ncrTemplatesApi } from '@/api/quality/ncr-templates';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { ArchiveFilter } from '@/components/ui/ArchiveFilter';
import { archiveToTrashed, type ArchiveScope } from '@/lib/archiveScope';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { AxiosError } from 'axios';
import type { NcrTemplate } from '@/types/quality';

import { showUndoToast } from '@/lib/undoToast';
const SEVERITY_CHIP: Record<string, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
 minor: 'neutral',
 major: 'warning',
 critical: 'danger',
};

export default function NcrTemplatesListPage() {
 const navigate = useNavigate();
 const queryClient = useQueryClient();
 const { can } = usePermission();
const [deleteId, setDeleteId] = useState<string | null>(null);
 const [restoreId, setRestoreId] = useState<string | null>(null);
 const [scope, setScope] = useState<ArchiveScope>('active');

 const { data, isLoading, isError, refetch } = useQuery({
  // Keep the list cache separate from the active-template picker cache used
  // by the NCR create page. Both routes can be soft-navigated in one session.
  queryKey: ['quality', 'ncr-templates', 'list', scope],
  queryFn: () => ncrTemplatesApi.list({ per_page: 100, trashed: archiveToTrashed(scope) }),
  placeholderData: (prev) => prev,
 });
 const { data: templateOptions } = useQuery({
 queryKey: ['quality', 'ncr-templates', 'options'],
 queryFn: ncrTemplatesApi.options,
 staleTime: 300_000,
 });
 const sourceLabels = new Map((templateOptions?.sources ?? []).map((option) => [option.value, option.label]));
 const severityLabels = new Map((templateOptions?.severities ?? []).map((option) => [option.value, option.label]));

const deleteMut = useMutation({
  mutationFn: (id: string) => ncrTemplatesApi.destroy(id),
  onSuccess: (_data, archivedId: string) => {
  showUndoToast({
    message: 'Template archived',
    // Archiving is reversible and one click; the restore endpoint is right
    // here. An undo is the honest price for it — a modal asking whether the
    // user meant it is a toll booth on something trivially taken back.
    onUndo: () => restoreMut.mutate(archivedId),
  });
  queryClient.invalidateQueries({ queryKey: ['quality', 'ncr-templates'] });
  },
  onError: (e: AxiosError<{ message?: string }>) => {
  toast.error(e.response?.data?.message ?? 'Failed to archive template');
  },
 });

 const restoreMut = useMutation({
  mutationFn: (id: string) => ncrTemplatesApi.restore(id),
  onSuccess: () => {
  toast.success('Template restored');
  queryClient.invalidateQueries({ queryKey: ['quality', 'ncr-templates'] });
  },
  onError: (e: AxiosError<{ message?: string }>) => {
  toast.error(e.response?.data?.message ?? 'Failed to restore template');
  },
 });

 const handleUseTemplate = (tpl: NcrTemplate) => {
 navigate('/quality/ncrs/new', { state: { template: tpl } });
 };

 const columns: Column<NcrTemplate>[] = [
 { key: 'name', header: 'Name', cell: (r) => <span className="font-medium">{r.name}</span> },
 {
 key: 'product',
 header: 'Product',
 cell: (r) =>
 r.product ? (
 <span>
 <span className="font-mono">{r.product.part_number}</span>
 <span className="ml-2 text-muted">{r.product.name}</span>
 </span>
 ) : (
 <span className="text-muted">—</span>
 ),
 },
 {
 key: 'source',
 header: 'Source',
 cell: (r) => <Chip variant="neutral">{r.source_label ?? sourceLabels.get(r.source) ?? r.source}</Chip>,
 },
 {
 key: 'severity',
 header: 'Severity',
 cell: (r) => <Chip variant={SEVERITY_CHIP[r.severity]}>{r.severity_label ?? severityLabels.get(r.severity) ?? r.severity}</Chip>,
 },
 {
 key: 'is_active',
 header: 'Active',
 cell: (r) => (r.is_active ? <Chip variant="success">Yes</Chip> : <Chip variant="neutral">No</Chip>),
 },
 {
 key: 'actions',
 header: '',
 align: 'right',
 cell: (r) => (
 <div className="flex items-center justify-end gap-1">
 <Button
 size="sm"
 variant="ghost"
 icon={<LuCopy size={13} />}
 onClick={() => { handleUseTemplate(r);
 }}
 >
 Use
 </Button>
{can('quality.ncr.manage') && (
  <>
  <Button
  size="sm"
  variant="ghost"
  icon={<LuPencil size={13} />}
  onClick={() => { navigate(`/quality/ncr-templates/${r.id}/edit`);
  }}
  />
  {scope === 'only' ? (
  <Button
  size="sm"
  variant="ghost"
  icon={<LuArchiveRestore size={13} />}
  onClick={() => { setRestoreId(r.id);
  }}
  />
  ) : (
  <Button
  size="sm"
  variant="ghost"
  icon={<LuTrash2 size={13} />}
  onClick={() => { setDeleteId(r.id);
  }}
  />
  )}
  </>
  )}
 </div>
 ),
 },
 ];

 return (
 <div>
 <PageHeader
 title="NCR templates"
 subtitle={data ? `${data.meta.total} template${data.meta.total === 1 ? '' : 's'}` : undefined}
 actions={
 can('quality.ncr.manage') ? (
 <Button
 variant="primary"
 size="sm"
 icon={<LuPlus size={14} />}
 onClick={() => navigate('/quality/ncr-templates/new')}
 >
 New template
 </Button>
 ) : undefined
 }
/>
  <div className="px-5 pt-4 flex justify-end">
  <ArchiveFilter value={scope} onChange={setScope} />
  </div>
  {isLoading && !data && <SkeletonTable columns={6} rows={4} />}
 {isError && (
 <EmptyState
 icon="alert-circle"
 title="Failed to load templates"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
 />
 )}
 {data && data.data.length === 0 && (
 <EmptyState
 icon="file-text"
 title="No NCR templates"
 description="Create templates for common quality issues to speed up NCR creation."
 />
 )}
 {data && data.data.length > 0 && (
 <div className="px-5 py-4">
 <DataTable columns={columns} data={data.data} meta={data.meta} 
 onRowClick={(r) => navigate(`/quality/ncr-templates/${r.id}/edit`)} />
 </div>
 )}
<ConfirmDialog
  isOpen={deleteId !== null}
  onClose={() => setDeleteId(null)}
  onConfirm={() => {
  if (deleteId !== null) {
  deleteMut.mutate(deleteId);
  setDeleteId(null);
  }
  }}
  title="Archive template?"
  description="It will be archived and can be restored later."
  variant="danger"
  confirmLabel="Archive"
  pending={deleteMut.isPending}
  />
  <ConfirmDialog
  isOpen={restoreId !== null}
  onClose={() => setRestoreId(null)}
  onConfirm={() => {
  if (restoreId !== null) {
  restoreMut.mutate(restoreId);
  setRestoreId(null);
  }
  }}
  title="Restore template?"
  description="It will be restored and available for new NCRs."
  confirmLabel="Restore"
  pending={restoreMut.isPending}
  />
 </div>
 );
}
