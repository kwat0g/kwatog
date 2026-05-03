/** Sprint 7 — Task 67 — Shipments list (inbound, imported POs). */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { shipmentsApi, type ShipmentListParams } from '@/api/supply-chain';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import type { Shipment, ShipmentStatus } from '@/types/supplyChain';

const STATUS_CHIP: Record<ShipmentStatus, 'success' | 'danger' | 'warning' | 'neutral' | 'info'> = {
  ordered: 'neutral', shipped: 'info', in_transit: 'info',
  customs: 'warning', cleared: 'info', received: 'success', cancelled: 'neutral',
};

export default function ShipmentsListPage() {
  const [filters, setFilters] = useState<ShipmentListParams>({ page: 1, per_page: 25 });
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['supply-chain', 'shipments', filters],
    queryFn: () => shipmentsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<Shipment>[] = [
    { key: 'shipment_number', header: 'Shipment',
      cell: (r) => <span className="font-mono text-accent">{r.shipment_number}</span> },
    { key: 'po', header: 'PO',
      cell: (r) => r.purchase_order ? <span className="font-mono">{r.purchase_order.po_number}</span> : <span className="text-muted">—</span> },
    { key: 'carrier', header: 'Carrier', cell: (r) => r.carrier ?? '—' },
    { key: 'container', header: 'Container', cell: (r) => r.container_number ?? '—' },
    { key: 'eta', header: 'ETA', align: 'right',
      cell: (r) => <NumCell>{r.eta ?? '—'}</NumCell> },
    { key: 'status', header: 'Status',
      cell: (r) => <Chip variant={STATUS_CHIP[r.status]}>{r.status.replace('_', ' ')}</Chip> },
  ];

  const filterConfig: FilterConfig[] = [
    { key: 'status', label: 'Status', type: 'select', options: [
      { value: '', label: 'All' },
      { value: 'ordered', label: 'Ordered' },
      { value: 'shipped', label: 'Shipped' },
      { value: 'in_transit', label: 'In transit' },
      { value: 'customs', label: 'Customs' },
      { value: 'cleared', label: 'Cleared' },
      { value: 'received', label: 'Received' },
    ] },
  ];

  return (
    <div>
      <PageHeader
        title="Inbound shipments"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'shipment' : 'shipments'}` : undefined}
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by shipment, container, or B/L number…"
      />
      {isLoading && !data && <SkeletonTable columns={6} rows={6} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load shipments"
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && (
        <EmptyState icon="package" title="No shipments yet" description="Imported POs will appear here once an ImpEx Officer opens a shipment." />
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
