import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { itemsApi, type ItemListParams } from '@/api/inventory/items';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { Item } from '@/types/inventory';

const stockChip = (status: 'ok' | 'low' | 'critical') => ({
  ok: 'success' as const, low: 'warning' as const, critical: 'danger' as const,
}[status]);

export default function ItemsListPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const canManage = can('inventory.items.manage');
  const [filters, setFilters] = useState<ItemListParams>({ page: 1, per_page: 25, is_active: 'true' });
  const [confirmDelete, setConfirmDelete] = useState<Item | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['inventory', 'items', filters],
    queryFn: () => itemsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const del = useMutation({
    mutationFn: (id: string) => itemsApi.delete(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['inventory', 'items'] });
      toast.success('Item deleted.');
      setConfirmDelete(null);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to delete item. Deactivate instead if it has stock or movements.');
    },
  });

  const columns: Column<Item>[] = [
    { key: 'code', header: 'Code', cell: (r) => (
      <Link to={`/inventory/items/${r.id}`} className="font-mono text-accent hover:underline">{r.code}</Link>
    ) },
    { key: 'name', header: 'Name', cell: (r) => (
      <div>
        <div className="font-medium">{r.name}</div>
        <div className="text-xs text-muted">{r.category?.name ?? '—'} · {r.item_type_label}</div>
      </div>
    ) },
    { key: 'uom', header: 'UOM', cell: (r) => r.unit_of_measure },
    { key: 'cost', header: 'Std Cost', align: 'right', cell: (r) => <NumCell>{Number(r.standard_cost).toFixed(4)}</NumCell> },
    { key: 'on_hand', header: 'On hand', align: 'right', cell: (r) => <NumCell>{Number(r.on_hand_quantity).toFixed(3)}</NumCell> },
    { key: 'available', header: 'Available', align: 'right', cell: (r) => (
      <NumCell className={r.stock_status === 'critical' ? 'text-danger-fg' : r.stock_status === 'low' ? 'text-warning-fg' : ''}>
        {Number(r.available_quantity).toFixed(3)}
      </NumCell>
    ) },
    { key: 'reorder', header: 'Reorder pt', align: 'right', cell: (r) => <NumCell>{Number(r.reorder_point).toFixed(3)}</NumCell> },
    { key: 'status', header: 'Stock', cell: (r) => <Chip variant={stockChip(r.stock_status)}>{r.stock_status}</Chip> },
    { key: 'active', header: '', cell: (r) => r.is_active ? null : <Chip variant="neutral">inactive</Chip> },
    ...(canManage ? [{
      key: 'actions',
      header: '',
      align: 'right' as const,
      cell: (r: Item) => (
        <div className="flex justify-end gap-1">
          <button
            type="button"
            onClick={() => navigate(`/inventory/items/${r.id}/edit`)}
            className="p-1.5 text-text-muted hover:text-primary hover:bg-elevated rounded-sm transition-colors"
            aria-label={`Edit ${r.code}`}
          >
            <Pencil size={14} />
          </button>
          <button
            type="button"
            onClick={() => setConfirmDelete(r)}
            className="p-1.5 text-text-muted hover:text-danger hover:bg-elevated rounded-sm transition-colors"
            aria-label={`Delete ${r.code}`}
          >
            <Trash2 size={14} />
          </button>
        </div>
      ),
    }] : []),
  ];

  const filterConfig: FilterConfig[] = [
    { key: 'item_type', label: 'Type', type: 'select', options: [
      { value: '', label: 'All' },
      { value: 'raw_material', label: 'Raw material' },
      { value: 'finished_good', label: 'Finished good' },
      { value: 'packaging', label: 'Packaging' },
      { value: 'spare_part', label: 'Spare part' },
    ]},
    { key: 'stock_status', label: 'Stock status', type: 'select', options: [
      { value: '', label: 'All' },
      { value: 'critical', label: 'Critical' },
      { value: 'low', label: 'Low' },
      { value: 'ok', label: 'OK' },
    ]},
    { key: 'is_active', label: 'Active', type: 'select', options: [
      { value: '', label: 'All' }, { value: 'true', label: 'Active' }, { value: 'false', label: 'Inactive' },
    ]},
  ];

  return (
    <div>
      <PageHeader
        title="Items"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'item' : 'items'}` : undefined}
        actions={canManage ? (
          <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/inventory/items/create')}>
            New item
          </Button>
        ) : null}
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by code or name…"
      />
      {isLoading && !data && <SkeletonTable columns={canManage ? 10 : 9} rows={8} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load items" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && (
        <EmptyState icon="inbox" title="No items found"
          description={canManage ? 'Add your first item to start tracking stock.' : 'Nothing here yet.'}
          action={canManage ? <Button variant="primary" onClick={() => navigate('/inventory/items/create')}>New item</Button> : undefined}
        />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable columns={columns} data={data.data} meta={data.meta} onPageChange={(page) => setFilters((f) => ({ ...f, page }))} />
        </div>
      )}

      <ConfirmDialog
        isOpen={!!confirmDelete}
        onClose={() => setConfirmDelete(null)}
        onConfirm={() => { if (confirmDelete) del.mutate(confirmDelete.id); }}
        title="Delete item?"
        description={
          confirmDelete ? (
            <>
              <span className="font-mono font-medium text-primary">{confirmDelete.code}</span>{' '}
              <span className="text-text-muted">— {confirmDelete.name}</span>
              <br />
              Deletion fails if there is existing stock or movement history; deactivate instead in that case.
            </>
          ) : null
        }
        confirmLabel="Delete"
        variant="danger"
        pending={del.isPending}
      />
    </div>
  );
}
