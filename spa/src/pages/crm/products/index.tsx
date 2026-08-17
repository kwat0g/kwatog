import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate} from 'react-router-dom';
import { LuArchiveRestore, LuPencil, LuPlus, LuTrash2 } from '@/lib/icons';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { productsApi, type ProductListParams } from '@/api/crm/products';
import { ArchiveFilter } from '@/components/ui/ArchiveFilter';
import { archiveToTrashed, type ArchiveScope } from '@/lib/archiveScope';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { Product } from '@/types/crm';
import { formatPeso } from '@/lib/formatNumber';

import { useUrlFilters } from '@/hooks/useUrlFilters';
import { ListEmptyState } from '@/components/ui/ListEmptyState';
export default function ProductsListPage() {
 const navigate = useNavigate();
 const qc = useQueryClient();
 const { can } = usePermission();
 const canManage = can('crm.products.manage');
 const [filters, setFilters] = useUrlFilters<ProductListParams>({ page: 1, per_page: 25, is_active: 'true' });
 const [confirmDelete, setConfirmDelete] = useState<Product | null>(null);
 const [scope, setScope] = useState<ArchiveScope>('active');

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['crm', 'products', filters, { trashed: archiveToTrashed(scope) }],
 queryFn: () => productsApi.list({ ...filters, trashed: archiveToTrashed(scope) }),
 placeholderData: (prev) => prev });

 const del = useMutation({
 mutationFn: (id: string) => productsApi.delete(id),
 onSuccess: () => {
 qc.invalidateQueries({ queryKey: ['crm', 'products'] });
 toast.success('Product archived.');
 setConfirmDelete(null);
 },
 onError: (e: AxiosError<{ message?: string }>) => {
  toast.error(e.response?.data?.message ?? 'Failed to delete product. Deactivate it instead if it has sales orders or BOMs.');
  } });

 const restore = useMutation({
  mutationFn: (id: string) => productsApi.restore(id),
  onSuccess: () => {
  qc.invalidateQueries({ queryKey: ['crm', 'products'] });
  toast.success('Product restored.');
  },
  onError: (e: AxiosError<{ message?: string }>) => {
  toast.error(e.response?.data?.message ?? 'Failed to restore product.');
  } });

 const columns: Column<Product>[] = [
 {
 key: 'part_number', header: 'Part #',
 cell: (r) => (
 <span className="font-mono">{r.part_number}</span>
 ) },
 {
 key: 'name', header: 'Name',
 cell: (r) => (
 <div>
 <div className="font-medium">{r.name}</div>
 {r.description && <div className="text-xs text-muted truncate max-w-md">{r.description}</div>}
 </div>
 ) },
 { key: 'uom', header: 'UOM', cell: (r) => r.unit_of_measure },
 {
 key: 'cost', header: 'Std Cost', align: 'right',
 cell: (r) => <NumCell>{formatPeso(r.standard_cost)}</NumCell> },
 {
 key: 'has_bom', header: 'BOM',
 cell: (r) => r.has_bom ? <Chip variant="success">Yes</Chip> : <Chip variant="neutral">—</Chip> },
 {
 key: 'active', header: 'Active',
 cell: (r) => r.is_active ? <Chip variant="success">Active</Chip> : <Chip variant="neutral">Inactive</Chip> },
 ...(canManage ? [{
 key: 'actions',
 header: '',
 align: 'right' as const,
 cell: (r: Product) => (
 <div className="flex justify-end gap-1">
 <Button
 type="button"
 variant="ghost"
 size="sm"
 iconOnly
 icon={<LuPencil size={14} />}
 aria-label={`Edit ${r.part_number}`}
 onClick={() => navigate(`/crm/products/${r.id}/edit`)}
 className="text-muted hover:text-primary"
 />
  <Button
  type="button"
  variant="ghost"
  size="sm"
  iconOnly
  aria-label={`${scope === 'only' ? 'Restore' : 'Delete'} ${r.part_number}`}
  onClick={() => scope === 'only' ? restore.mutate(r.id) : setConfirmDelete(r)}
  className={scope === 'only' ? 'text-muted hover:text-primary' : 'text-muted hover:text-danger-fg'}
  icon={scope === 'only' ? <LuArchiveRestore size={14} /> : <LuTrash2 size={14} />}
  />
  </div>
  ) }] : []),
 ];

 const filterConfig: FilterConfig[] = [
 { key: 'has_bom', label: 'Has BOM', type: 'select', options: [
 { value: '', label: 'All' }, { value: 'true', label: 'Yes' }, { value: 'false', label: 'No' },
 ]},
 { key: 'is_active', label: 'Active', type: 'select', options: [
 { value: '', label: 'All' }, { value: 'true', label: 'Active' }, { value: 'false', label: 'Inactive' },
 ]},
 ];

 return (
 <div>
 <PageHeader
 title="Products"
 subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'product' : 'products'}` : undefined}
 actions={canManage ? (
 <Button variant="primary" size="xs" icon={<LuPlus size={14} />} onClick={() => navigate('/crm/products/create')}>
 New product
 </Button>
 ) : null}
 />
 <FilterBar
 filters={filterConfig}
 values={filters}
 onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
 onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
 searchPlaceholder="Search part number or name…"
 actions={<ArchiveFilter value={scope} onChange={setScope} />}
 />
 {isLoading && !data && <SkeletonTable columns={canManage ? 7 : 6} rows={8} />}
 {isError && (
 <EmptyState
 icon="alert-circle"
 title="Failed to load products"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
 />
 )}
 {data && data.data.length === 0 && (
 <ListEmptyState />
 )}
 {data && data.data.length > 0 && (
 <div className="px-5 py-4">
 <DataTable
 onRowClick={(r) => navigate(`/crm/products/${r.id}`)}
 columns={columns}
 data={data.data}
 meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 />
 </div>
 )}

 <ConfirmDialog
 isOpen={!!confirmDelete}
 onClose={() => setConfirmDelete(null)}
 onConfirm={() => { if (confirmDelete) del.mutate(confirmDelete.id); }}
 title="Archive product?"
 description={
 confirmDelete ? (
 <>
 <span className="font-mono font-medium text-primary">{confirmDelete.part_number}</span>{' '}
 <span className="text-muted">— {confirmDelete.name}</span>
 <br />
 Archiving fails if the product appears on any sales order. Deactivate instead in that case. It will be archived and can be restored later.
 </>
 ) : null
 }
 confirmLabel="Archive"
 variant="danger"
 pending={del.isPending}
 />
 </div>
 );
}
