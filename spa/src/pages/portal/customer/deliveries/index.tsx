import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { customerPortalApi } from '@/api/b2b/customer';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { formatDate } from '@/lib/formatDate';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import type { PortalDeliverySummary } from '@/types/b2b';

type DeliveryFilters = { page: number; per_page: number; status?: string };

const DEFAULT_FILTERS: DeliveryFilters = { page: 1, per_page: 25 };

export default function CustomerDeliveriesPage() {
  const navigate = useNavigate();
  const [filters, setFilters] = useUrlFilters<DeliveryFilters>(DEFAULT_FILTERS);
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'deliveries', filters],
    queryFn: () => customerPortalApi.listDeliveries(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<PortalDeliverySummary>[] = [
    {
      key: 'delivery_number',
      header: 'DR #',
      cell: (r) => <span className="font-mono font-medium text-accent">{r.delivery_number}</span>,
    },
    {
      key: 'sales_order',
      header: 'Order',
      cell: (r) =>
        r.sales_order ? <span className="font-mono text-accent">{r.sales_order.so_number}</span> : '—',
    },
    {
      key: 'date',
      header: 'Delivery Date',
      cell: (r) => (
        <span className="font-mono">
          {formatDate(r.delivered_at ?? r.scheduled_date)}
          {!r.delivered_at && r.scheduled_date && (
            <span className="ml-1 text-2xs text-text-subtle">(scheduled)</span>
          )}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => (
        <Chip variant={chipVariantForStatus(r.status)}>
          {r.status_label ?? r.status.replace(/_/g, ' ')}
        </Chip>
      ),
    },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'status',
      label: 'Status',
      type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'scheduled', label: 'Scheduled' },
        { value: 'loading', label: 'Loading' },
        { value: 'in_transit', label: 'In transit' },
        { value: 'delivered', label: 'Delivered' },
        { value: 'confirmed', label: 'Confirmed' },
        { value: 'cancelled', label: 'Cancelled' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Deliveries"
        subtitle={
          data ? `${data.meta.total} shipments dispatched to your sites` : 'Shipments dispatched to your sites'
        }
      />

      <FilterBar
        filters={filterConfig}
        values={filters}
        onFilter={(key, value) => setFilters((current) => ({ ...current, [key]: value || undefined, page: 1 }))}
        searchable={false}
      />

      {isLoading && !data && <SkeletonTable columns={4} rows={8} />}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load deliveries"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}

      {data && (
        <div className="px-5 py-4">
          <DataTable
            tableKey="portal-customer-deliveries"
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((current) => ({ ...current, page }))}
            onPageSizeChange={(per_page) => setFilters((current) => ({ ...current, per_page, page: 1 }))}
            onRowClick={(r) => navigate(`/portal/customer/deliveries/${r.id}`)}
            emptyState={
              <EmptyState
                icon="truck"
                title="No deliveries"
                description="Your deliveries will appear here once dispatched."
              />
            }
          />
        </div>
      )}
    </div>
  );
}
