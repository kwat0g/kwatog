import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { productsApi, type ProductListParams } from '@/api/crm/products';
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

export default function ProductsListPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
  const canManage = can('crm.products.manage');
  const [filters, setFilters] = useState<ProductListParams>({ page: 1, per_page: 25, is_active: 'true' });
  const [confirmDelete, setConfirmDelete] = useState<Product | null>(null);

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['crm', 'products', filters],
    queryFn: () => productsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const del = useMutation({
    mutationFn: (id: string) => productsApi.delete(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['crm', 'products'] });
      toast.success('Product deleted.');
      setConfirmDelete(null);
    },
    onError: (e: AxiosError<{ message?: string }>) => {
      toast.error(e.response?.data?.message ?? 'Failed to delete product. Deactivate it instead if it has sales orders or BOMs.');
    },
  });

  const columns: Column<Product>[] = [
    {
      key: 'part_number', header: 'Part #',
      cell: (r) => (
        <Link to={`/crm/products/${r.id}`} className="font-mono text-accent hover:underline">{r.part_number}</Link>
      ),
    },
    {
      key: 'name', header: 'Name',
      cell: (r) => (
        <div>
          <div className="font-medium">{r.name}</div>
          {r.description && <div className="text-xs text-muted truncate max-w-md">{r.description}</div>}
        </div>
      ),
    },
    { key: 'uom', header: 'UOM', cell: (r) => r.unit_of_measure },
    {
      key: 'cost', header: 'Std Cost', align: 'right',
      cell: (r) => <NumCell>₱ {Number(r.standard_cost).toFixed(2)}</NumCell>,
    },
    {
      key: 'has_bom', header: 'BOM',
      cell: (r) => r.has_bom ? <Chip variant="success">Yes</Chip> : <Chip variant="neutral">—</Chip>,
    },
    {
      key: 'active', header: 'Active',
      cell: (r) => r.is_active ? <Chip variant="success">Active</Chip> : <Chip variant="neutral">Inactive</Chip>,
    },
    ...(canManage ? [{
      key: 'actions',
      header: '',
      align: 'right' as const,
      cell: (r: Product) => (
        <div className="flex justify-end gap-1">
          <button
            type="button"
            onClick={() => navigate(`/crm/products/${r.id}/edit`)}
            className="p-1.5 text-text-muted hover:text-primary hover:bg-elevated rounded-sm transition-colors"
            aria-label={`Edit ${r.part_number}`}
          >
            <Pencil size={14} />
          </button>
          <button
            type="button"
            onClick={() => setConfirmDelete(r)}
            className="p-1.5 text-text-muted hover:text-danger hover:bg-elevated rounded-sm transition-colors"
            aria-label={`Delete ${r.part_number}`}
          >
            <Trash2 size={14} />
          </button>
        </div>
      ),
    }] : []),
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
          <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/crm/products/create')}>
            New product
          </Button>
        ) : null}
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by part number or name…"
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
        <EmptyState
          icon="package"
          title="No products found"
          description={canManage ? 'Add your first product to start receiving sales orders.' : 'Nothing here yet.'}
          action={canManage ? (
            <Button variant="primary" onClick={() => navigate('/crm/products/create')}>New product</Button>
          ) : undefined}
        />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
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
        title="Delete product?"
        description={
          confirmDelete ? (
            <>
              <span className="font-mono font-medium text-primary">{confirmDelete.part_number}</span>{' '}
              <span className="text-text-muted">— {confirmDelete.name}</span>
              <br />
              Deletion fails if the product appears on any sales order. Deactivate instead in that case.
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
