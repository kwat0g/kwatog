import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import { stockLevelsApi } from '@/api/inventory/stock';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import type { StockLevel } from '@/types/inventory';

export default function StockLevelsPage() {
  const [search] = useSearchParams();
  const itemFilter = search.get('item_id') ?? '';
  const [filters, setFilters] = useState<any>({ page: 1, per_page: 50, item_id: itemFilter || undefined });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['inventory', 'stock-levels', filters],
    queryFn: () => stockLevelsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<StockLevel>[] = [
    { key: 'item', header: 'Item', cell: (r) => (
      <div>
        <Link to={`/inventory/items/${r.item?.id}`} className="font-mono text-accent">{r.item?.code}</Link>
        <div className="text-xs text-muted">{r.item?.name}</div>
      </div>
    ) },
    { key: 'loc', header: 'Location', cell: (r) => <span className="font-mono">{r.location?.full_code}</span> },
    { key: 'qty', header: 'Quantity', align: 'right', cell: (r) => <NumCell>{Number(r.quantity).toFixed(3)}</NumCell> },
    { key: 'res', header: 'Reserved', align: 'right', cell: (r) => <NumCell>{Number(r.reserved_quantity).toFixed(3)}</NumCell> },
    { key: 'avail', header: 'Available', align: 'right', cell: (r) => <NumCell>{Number(r.available).toFixed(3)}</NumCell> },
    { key: 'wac', header: 'WAC', align: 'right', cell: (r) => <NumCell>{Number(r.weighted_avg_cost).toFixed(4)}</NumCell> },
    { key: 'val', header: 'Total value', align: 'right', cell: (r) => <NumCell className="font-medium">{Number(r.total_value).toFixed(2)}</NumCell> },
  ];

  const filterConfig: FilterConfig[] = [
    { key: 'item_type', label: 'Type', type: 'select', options: [
      { value: '', label: 'All' },
      { value: 'raw_material', label: 'Raw material' },
      { value: 'packaging', label: 'Packaging' },
      { value: 'spare_part', label: 'Spare part' },
      { value: 'finished_good', label: 'Finished good' },
    ]},
  ];

  return (
    <div>
      <PageHeader title="Stock levels" subtitle={data ? `${data.meta.total} entries` : undefined} />
      <FilterBar filters={filterConfig} values={filters}
        onSearch={(s) => setFilters((f: any) => ({ ...f, search: s, page: 1 }))}
        onFilter={(k, v) => setFilters((f: any) => ({ ...f, [k]: v, page: 1 }))}
        searchPlaceholder="Search by item…" />
      {isLoading && !data && <SkeletonTable columns={7} rows={8} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load stock" action={<Button onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && <EmptyState icon="inbox" title="No stock found" />}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable columns={columns} data={data.data} meta={data.meta} onPageChange={(page) => setFilters((f: any) => ({ ...f, page }))} />
        </div>
      )}
    </div>
  );
}
