import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import { stockMovementsApi } from '@/api/inventory/stock';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDateTime } from '@/lib/formatDate';
import type { StockMovement } from '@/types/inventory';

const chip = (t: string): 'success' | 'info' | 'warning' | 'danger' | 'neutral' => {
  if (['grn_receipt', 'production_receipt', 'adjustment_in'].includes(t)) return 'success';
  if (['material_issue', 'delivery'].includes(t)) return 'info';
  if (['adjustment_out', 'transfer', 'cycle_count'].includes(t)) return 'warning';
  if (['scrap', 'return_to_vendor'].includes(t)) return 'danger';
  return 'neutral';
};

export default function StockMovementsPage() {
  const [search] = useSearchParams();
  const [filters, setFilters] = useState<any>({
    page: 1, per_page: 50, item_id: search.get('item_id') ?? undefined,
  });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['inventory', 'movements', filters],
    queryFn: () => stockMovementsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<StockMovement>[] = [
    { key: 'created_at', header: 'When', cell: (r) => <span className="font-mono">{formatDateTime(r.created_at)}</span> },
    { key: 'type', header: 'Type', cell: (r) => <Chip variant={chip(r.movement_type)}>{r.movement_type.replace(/_/g, ' ')}</Chip> },
    { key: 'item', header: 'Item', cell: (r) => (
      <div>
        <span className="font-mono">{r.item?.code}</span>
        <div className="text-xs text-muted">{r.item?.name}</div>
      </div>
    ) },
    { key: 'from', header: 'From', cell: (r) => <span className="font-mono">{r.from_location?.code ?? '—'}</span> },
    { key: 'to',   header: 'To',   cell: (r) => <span className="font-mono">{r.to_location?.code ?? '—'}</span> },
    { key: 'qty', header: 'Qty', align: 'right', cell: (r) => <NumCell>{Number(r.quantity).toFixed(3)}</NumCell> },
    { key: 'cost', header: 'Unit cost', align: 'right', cell: (r) => <NumCell>{Number(r.unit_cost).toFixed(4)}</NumCell> },
    { key: 'total', header: 'Total cost', align: 'right', cell: (r) => <NumCell className="font-medium">{Number(r.total_cost).toFixed(2)}</NumCell> },
    { key: 'ref', header: 'Reference', cell: (r) => r.reference_type ? <span className="text-xs">{r.reference_type} #{r.reference_id}</span> : '—' },
  ];

  const filterConfig: FilterConfig[] = [
    { key: 'movement_type', label: 'Type', type: 'select', options: [
      { value: '', label: 'All' },
      { value: 'grn_receipt', label: 'GRN receipt' },
      { value: 'material_issue', label: 'Material issue' },
      { value: 'transfer', label: 'Transfer' },
      { value: 'adjustment_in', label: 'Adjust IN' },
      { value: 'adjustment_out', label: 'Adjust OUT' },
      { value: 'scrap', label: 'Scrap' },
      { value: 'return_to_vendor', label: 'Return' },
    ]},
  ];

  return (
    <div>
      <PageHeader title="Stock movements" subtitle={data ? `${data.meta.total} movements` : undefined} />
      <FilterBar filters={filterConfig} values={filters}
        onSearch={() => undefined}
        onFilter={(k, v) => setFilters((f: any) => ({ ...f, [k]: v, page: 1 }))}
        searchPlaceholder="" />
      {isLoading && !data && <SkeletonTable columns={9} rows={10} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load movements" action={<Button onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && <EmptyState icon="inbox" title="No movements yet" />}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable columns={columns} data={data.data} meta={data.meta} onPageChange={(page) => setFilters((f: any) => ({ ...f, page }))} />
        </div>
      )}
    </div>
  );
}
