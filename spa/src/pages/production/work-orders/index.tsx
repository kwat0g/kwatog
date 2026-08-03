import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { workOrdersApi, type WorkOrderListParams } from '@/api/production/workOrders';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatInt } from '@/lib/formatNumber';
import { workOrderStatusVariant as variant } from '@/lib/statusVariants';
import type { WorkOrder } from '@/types/production';

export default function WorkOrdersListPage() {
  const [filters, setFilters] = useState<WorkOrderListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['production', 'work-orders', filters],
    queryFn: () => workOrdersApi.list(filters),
    placeholderData: (prev) => prev });
  const { data: workOrderOptions } = useQuery({
    queryKey: ['production', 'work-orders', 'options'],
    queryFn: workOrdersApi.options,
    staleTime: 5 * 60 * 1000 });
  const statusLabels = new Map((workOrderOptions?.statuses ?? []).map((option) => [option.value, option.label]));

  const columns: Column<WorkOrder>[] = [
    {
      key: 'wo', header: 'WO #',
      cell: (r) => (
        <span className="font-mono text-accent">{r.wo_number}</span>
      ) },
    {
      key: 'product', header: 'Product',
      cell: (r) => r.product
        ? <div><div className="font-mono text-xs">{r.product.part_number}</div><div className="text-muted text-xs">{r.product.name}</div></div>
        : '—' },
    {
      key: 'so', header: 'SO',
      cell: (r) => r.sales_order
        ? <span className="font-mono text-accent">{r.sales_order.so_number}</span>
        : <span className="text-muted">—</span> },
    {
      key: 'machine', header: 'Machine',
      cell: (r) => r.machine ? <span className="font-mono text-xs">{r.machine.machine_code}</span> : <span className="text-muted">—</span> },
    { key: 'qty', header: 'Target', align: 'right', cell: (r) => <NumCell>{formatInt(r.quantity_target)}</NumCell> },
    {
      key: 'progress', header: 'Progress', align: 'right',
      cell: (r) => (
        <div className="flex flex-col items-end gap-0.5 min-w-[120px]">
          <span className="font-mono tabular-nums text-xs">
            {formatInt(r.quantity_produced)} / {formatInt(r.quantity_target)}
          </span>
          <div className="w-full h-1 bg-elevated rounded-full overflow-hidden">
            <div className="h-1 bg-accent rounded-full" style={{ width: `${Math.min(100, r.progress_percentage)}%` }} aria-hidden />
          </div>
        </div>
      ) },
    { key: 'planned', header: 'Planned start', align: 'right', cell: (r) => <NumCell>{r.planned_start?.slice(0, 10) ?? '—'}</NumCell> },
    { key: 'status', header: 'Status', cell: (r) => <Chip variant={variant[r.status]}>{statusLabels.get(r.status) ?? r.status_label ?? r.status}</Chip> },
  ];

  const filterConfig: FilterConfig[] = [
    { key: 'status', label: 'Status', type: 'select', options: [
      { value: '', label: 'All' },
      ...(workOrderOptions?.statuses ?? []),
    ]},
  ];

  return (
    <div>
      <PageHeader title="Work orders"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'WO' : 'WOs'}` : undefined} />
      <FilterBar
        filters={filterConfig} values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search WO number or product…"
      />
      {isLoading && !data && <SkeletonTable columns={8} rows={8} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load work orders"
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && (
        <EmptyState icon="factory" title="No work orders yet"
          description="Work orders are auto-created by the MRP engine when a sales order is confirmed." />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable onRowClick={(r) => navigate(`/production/work-orders/${r.id}`)}
            columns={columns} data={data.data} meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))} />
        </div>
      )}
    </div>
  );
}
