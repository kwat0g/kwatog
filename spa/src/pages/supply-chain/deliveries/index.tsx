/** Sprint 7 — Task 67 — Deliveries list (outbound). */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { deliveriesApi, type DeliveryListParams } from '@/api/supply-chain';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import type { Delivery, DeliveryStatus } from '@/types/supplyChain';

const STATUS_CHIP: Record<DeliveryStatus, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  scheduled: 'neutral', loading: 'info', in_transit: 'info',
  delivered: 'warning', confirmed: 'success', cancelled: 'neutral',
};

export default function DeliveriesListPage() {
  const [filters, setFilters] = useState<DeliveryListParams>({ page: 1, per_page: 25 });
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['supply-chain', 'deliveries', filters],
    queryFn: () => deliveriesApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<Delivery>[] = [
    { key: 'delivery_number', header: 'Delivery',
      cell: (r) => <Link to={`/supply-chain/deliveries/${r.id}`} className="font-mono text-accent hover:underline">{r.delivery_number}</Link> },
    { key: 'so', header: 'Sales Order',
      cell: (r) => r.sales_order ? <span className="font-mono">{r.sales_order.so_number}</span> : <span className="text-muted">—</span> },
    { key: 'vehicle', header: 'Vehicle', cell: (r) => r.vehicle ? `${r.vehicle.name} (${r.vehicle.plate_number})` : '—' },
    { key: 'driver', header: 'Driver', cell: (r) => r.driver?.name ?? '—' },
    { key: 'scheduled', header: 'Scheduled', align: 'right',
      cell: (r) => <NumCell>{r.scheduled_date ?? '—'}</NumCell> },
    { key: 'status', header: 'Status',
      cell: (r) => <Chip variant={STATUS_CHIP[r.status]}>{r.status.replace('_', ' ')}</Chip> },
  ];

  const filterConfig: FilterConfig[] = [
    { key: 'status', label: 'Status', type: 'select', options: [
      { value: '', label: 'All' },
      { value: 'scheduled', label: 'Scheduled' },
      { value: 'loading', label: 'Loading' },
      { value: 'in_transit', label: 'In transit' },
      { value: 'delivered', label: 'Delivered' },
      { value: 'confirmed', label: 'Confirmed' },
    ] },
  ];

  return (
    <div>
      <PageHeader title="Outbound deliveries"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'delivery' : 'deliveries'}` : undefined} />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by delivery number…"
      />
      {isLoading && !data && <SkeletonTable columns={6} rows={6} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load deliveries"
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && (
        <EmptyState icon="truck" title="No deliveries scheduled"
          description="Deliveries appear here once a confirmed sales order with passed outgoing QC is dispatched." />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable columns={columns} data={data.data} meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))} />
        </div>
      )}
    </div>
  );
}
