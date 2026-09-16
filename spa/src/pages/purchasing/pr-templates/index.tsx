import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { LuPlus, LuPencil, LuTrash2, LuCopy, LuArchiveRestore } from '@/lib/icons';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { prTemplatesApi } from '@/api/purchasing/purchase-requests';
import { purchaseRequestsApi } from '@/api/purchasing/purchase-requests';
import { ArchiveFilter } from '@/components/ui/ArchiveFilter';
import { archiveToTrashed, type ArchiveScope } from '@/lib/archiveScope';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { LinkButton } from '@/components/ui/LinkButton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import type { PurchaseRequestTemplate } from '@/types/purchasing';

import { useUrlFilters } from '@/hooks/useUrlFilters';
const errMsg = (e: unknown, fallback: string) =>
 (e instanceof AxiosError ? e.response?.data?.message : undefined) ?? fallback;

export default function PrTemplatesListPage() {
 const navigate = useNavigate();
 const qc = useQueryClient();
 const { can } = usePermission();
 const [filters, setFilters] = useUrlFilters<Record<string, unknown>>({ page: 1, per_page: 25 });
 const [deleteId, setDeleteId] = useState<string | null>(null);
 const [restoreId, setRestoreId] = useState<string | null>(null);
 const [scope, setScope] = useState<ArchiveScope>('active');
 const [templateChoice, setTemplateChoice] = useState<PurchaseRequestTemplate | null>(null);
 const [sourcingMethod, setSourcingMethod] = useState<'direct_po' | 'rfq' | ''>('');

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['purchasing', 'pr-templates', filters, { trashed: archiveToTrashed(scope) }],
 queryFn: () => prTemplatesApi.list({ ...filters, trashed: archiveToTrashed(scope) }),
 placeholderData: (prev) => prev,
 });

 const deleteMutation = useMutation({
 mutationFn: (id: string) => prTemplatesApi.delete(id),
 onSuccess: () => {
 qc.invalidateQueries({ queryKey: ['purchasing', 'pr-templates'] });
 toast.success('Template archived.');
 setDeleteId(null);
 },
 onError: (e) => toast.error(errMsg(e, 'Failed to delete template.')),
 });

 const restoreMutation = useMutation({
 mutationFn: (id: string) => prTemplatesApi.restoreTemplate(id),
 onSuccess: () => {
 qc.invalidateQueries({ queryKey: ['purchasing', 'pr-templates'] });
 toast.success('Template restored.');
 setRestoreId(null);
 },
 onError: (e) => toast.error(errMsg(e, 'Failed to restore template.')),
 });

 const useTemplate = useMutation({
  mutationFn: ({ template, sourcing_method }: { template: PurchaseRequestTemplate; sourcing_method: 'direct_po' | 'rfq' }) => {
  return purchaseRequestsApi.create({
  template_id: template.id,
  sourcing_method,
 department_id: template.department?.id,
 items: template.items.map((i) => ({
 item_id: i.item_id ?? null,
 description: i.description,
 quantity: String(i.quantity),
 unit: i.unit ?? undefined,
 estimated_unit_price: i.estimated_unit_price ?? undefined,
 })),
 });
 },
  onSuccess: (pr) => {
  setTemplateChoice(null);
  setSourcingMethod('');
 toast.success('PR created from template.');
 navigate(`/purchasing/purchase-requests/${pr.id}`);
 },
  onError: (e) => toast.error(errMsg(e, 'Failed to create PR from template.')),
 });

 const columns: Column<PurchaseRequestTemplate>[] = [
 { key: 'name', header: 'Name', cell: (r) => (
 <LinkButton className="font-medium text-left" onClick={() => navigate(`/purchasing/pr-templates/${r.id}`)}>{r.name}</LinkButton>
 )},
 { key: 'department', header: 'Department', cell: (r) => r.department?.name ?? '—' },
 { key: 'items', header: 'Items', cell: (r) => `${r.items.length} line(s)` },
 { key: 'notes', header: 'Notes', cell: (r) => r.notes ?? '—' },
 { key: 'active', header: 'Active', cell: (r) => (
 <Chip variant={r.is_active ? 'success' : 'neutral'}>{r.is_active ? 'Active' : 'Inactive'}</Chip>
 )},
 { key: 'created', header: 'Created', cell: (r) => r.created_at ? formatDate(r.created_at) : '—' },
 { key: 'actions', header: '', cell: (r) => (
 <div className="flex items-center gap-1">
 <Button
 type="button"
 variant="ghost"
 size="sm"
 iconOnly
 icon={<LuCopy size={14} />}
 aria-label="Use template"
  onClick={() => { setTemplateChoice(r); setSourcingMethod(''); }}
 className="text-muted hover:text-accent"
 />
 <Button
 type="button"
 variant="ghost"
 size="sm"
 iconOnly
 icon={<LuPencil size={14} />}
 aria-label="Edit template"
 onClick={() => navigate(`/purchasing/pr-templates/${r.id}/edit`)}
 className="text-muted hover:text-primary"
 />
{can('purchasing.pr.create') && (
 <Button
 type="button"
 variant="ghost"
 size="sm"
 iconOnly
 aria-label={scope === 'only' ? 'Restore template' : 'Delete template'}
 onClick={() => (scope === 'only' ? setRestoreId(r.id) : setDeleteId(r.id))}
 className={scope === 'only' ? 'text-muted hover:text-primary' : 'text-muted hover:text-danger-fg'}
 icon={scope === 'only' ? <LuArchiveRestore size={14} /> : <LuTrash2 size={14} />}
 />
)}
 </div>
 )},
 ];

 const filterConfig: FilterConfig[] = [
 { key: 'is_active', label: 'Status', type: 'select', options: [
 { value: '', label: 'All' }, { value: 'true', label: 'Active' }, { value: 'false', label: 'Inactive' },
 ]},
 ];

 return (
 <div>
 <PageHeader title="PR Templates" subtitle={data ? `${data.meta.total} templates` : undefined}
 actions={can('purchasing.pr.create') ? (
 <Button variant="primary" size="xs" icon={<LuPlus size={14} />} onClick={() => navigate('/purchasing/pr-templates/create')}>New Template</Button>
 ) : null} />
 <FilterBar filters={filterConfig} values={filters}
 onSearch={(s) => setFilters(f => ({ ...f, search: s, page: 1 }))}
 onFilter={(k, v) => setFilters(f => ({ ...f, [k]: v, page: 1 }))}
 searchPlaceholder="Search template name…"
 actions={<ArchiveFilter value={scope} onChange={setScope} />} />
 {isLoading && !data && <SkeletonTable columns={7} rows={6} />}
 {isError && <EmptyState icon="alert-circle" title="Failed to load templates" action={<Button onClick={() => refetch()}>Retry</Button>} />}
 {data && data.data.length === 0 && (
 <EmptyState icon="inbox" title="No PR templates"
 action={can('purchasing.pr.create') ? <Button variant="primary" onClick={() => navigate('/purchasing/pr-templates/create')}>New Template</Button> : undefined} />
 )}
 {data && data.data.length > 0 && (
 <div className="px-5 py-4">
 <DataTable columns={columns} data={data.data} meta={data.meta} 
 onRowClick={(r) => navigate(`/purchasing/pr-templates/${r.id}/edit`)}
 onPageChange={(page) => setFilters(f => ({ ...f, page }))}
 onPageSizeChange={(per_page) => setFilters(f => ({ ...f, per_page, page: 1 }))} />
 </div>
 )}

  <ConfirmDialog
 isOpen={deleteId !== null}
 onClose={() => setDeleteId(null)}
 onConfirm={() => { if (deleteId !== null) deleteMutation.mutate(deleteId); }}
 title="Delete template?"
 description="The template will be archived and can be restored later. PRs already created from this template are unaffected."
 confirmLabel="Delete"
 variant="danger"
 pending={deleteMutation.isPending}
  />

  <Modal isOpen={templateChoice !== null} onClose={() => setTemplateChoice(null)} title="Choose sourcing method">
  <p className="text-sm text-muted mb-3">There is no default sourcing path. Choose how this template-created PR should be sourced before it is created.</p>
  <Select label="Sourcing method" required value={sourcingMethod} onChange={(event) => setSourcingMethod(event.target.value as 'direct_po' | 'rfq' | '')}>
  <option value="">— Select sourcing method —</option>
  <option value="direct_po">Direct PO</option>
  <option value="rfq">Competitive RFQ</option>
  </Select>
  <div className="flex justify-end gap-2 mt-4"><Button variant="secondary" onClick={() => setTemplateChoice(null)}>Cancel</Button><Button variant="primary" disabled={!sourcingMethod || useTemplate.isPending} loading={useTemplate.isPending} onClick={() => { if (templateChoice && sourcingMethod) useTemplate.mutate({ template: templateChoice, sourcing_method: sourcingMethod }); }}>Create PR</Button></div>
  </Modal>

 <ConfirmDialog
 isOpen={restoreId !== null}
 onClose={() => setRestoreId(null)}
 onConfirm={() => { if (restoreId !== null) restoreMutation.mutate(restoreId); }}
 title="Restore template?"
 description="The template will be restored and available for creating PRs again."
 confirmLabel="Restore"
 variant="primary"
 pending={restoreMutation.isPending}
 />
 </div>
 );
}
