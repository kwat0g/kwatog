import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { bomsApi, type BomListParams } from '@/api/mrp/boms';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import type { Bom } from '@/types/mrp';

export default function BomsListPage() {
  const [filters, setFilters] = useState<BomListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['mrp', 'boms', filters],
    queryFn: () => bomsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<Bom>[] = [
    {
      key: 'product', header: 'Product',
      cell: (r) => r.product
        ? <Link to={`/mrp/boms/${r.id}`} className="hover:underline">
            <span className="font-mono text-accent">{r.product.part_number}</span>
            <span className="ml-2 text-muted">{r.product.name}</span>
          </Link>
        : '—',
    },
    { key: 'version', header: 'Version', align: 'right', cell: (r) => <NumCell>v{r.version}</NumCell> },
    { key: 'lines', header: 'Lines', align: 'right', cell: (r) => <NumCell>{r.item_count}</NumCell> },
    {
      key: 'active', header: 'Status',
      cell: (r) => r.is_active ? <Chip variant="success">Active</Chip> : <Chip variant="neutral">Archived</Chip>,
    },
    { key: 'updated', header: 'Updated', align: 'right', cell: (r) => <NumCell>{r.updated_at?.slice(0, 10)}</NumCell> },
  ];

  const filterConfig: FilterConfig[] = [
    { key: 'is_active', label: 'Status', type: 'select', options: [
      { value: '', label: 'All' }, { value: 'true', label: 'Active' }, { value: 'false', label: 'Archived' },
    ]},
  ];

  return (
    <div>
      <PageHeader
        title="Bills of materials"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'BOM' : 'BOMs'}` : undefined}
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search…"
      />
      {isLoading && !data && <SkeletonTable columns={5} rows={6} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load BOMs"
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && (
        <EmptyState icon="inbox" title="No BOMs yet"
          description="BOMs are created from a product detail page once the product master is in place." />
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
    </div>
  );
}
