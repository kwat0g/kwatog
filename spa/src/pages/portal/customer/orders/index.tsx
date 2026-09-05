import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { customerPortalApi } from '@/api/b2b/customer';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { CompanyName } from '@/components/brand/CompanyName';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import type { PortalSoSummary } from '@/types/b2b';

type OrderFilters = { page: number; per_page: number; status?: string; search?: string };

const DEFAULT_FILTERS: OrderFilters = { page: 1, per_page: 25 };

const STATUS_OPTIONS = [
  { value: '', label: 'All' },
  { value: 'draft', label: 'Draft' },
  { value: 'confirmed', label: 'Confirmed' },
  { value: 'in_production', label: 'In production' },
  { value: 'partially_delivered', label: 'Partially delivered' },
  { value: 'delivered', label: 'Delivered' },
  { value: 'invoiced', label: 'Invoiced' },
  { value: 'cancelled', label: 'Cancelled' },
];

export default function CustomerOrdersPage() {
  const navigate = useNavigate();
  const [filters, setFilters] = useUrlFilters<OrderFilters>(DEFAULT_FILTERS);
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'customer', 'orders', filters],
    queryFn: () => customerPortalApi.listOrders(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<PortalSoSummary>[] = [
    {
      key: 'so_number',
      header: 'Order #',
      cell: (r) => <span className="font-mono font-medium text-accent">{r.so_number}</span>,
    },
    {
      key: 'date',
      header: 'Date',
      cell: (r) => <span className="font-mono">{r.date ? formatDate(r.date) : '—'}</span>,
    },
    {
      key: 'total_amount',
      header: 'Amount',
      align: 'right',
      cell: (r) => <NumCell className="font-medium">{formatPeso(r.total_amount)}</NumCell>,
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
    { key: 'status', label: 'Status', type: 'select', options: STATUS_OPTIONS },
  ];

  return (
    <div>
      <PageHeader
        title="My Orders"
        subtitle={
          data ? (
            <>{data.meta.total} sales orders placed with <CompanyName /></>
          ) : (
            <>Sales orders placed with <CompanyName /></>
          )
        }
      />

      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((current) => ({ ...current, search: search || undefined, page: 1 }))}
        onFilter={(key, value) => setFilters((current) => ({ ...current, [key]: value || undefined, page: 1 }))}
        searchPlaceholder="Search order number…"
      />

      {isLoading && !data && <SkeletonTable columns={4} rows={8} />}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load orders"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}

      {data && (
        <div className="px-5 py-4">
          <DataTable
            tableKey="portal-customer-orders"
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((current) => ({ ...current, page }))}
            onPageSizeChange={(per_page) => setFilters((current) => ({ ...current, per_page, page: 1 }))}
            onRowClick={(r) => navigate(`/portal/customer/orders/${r.id}`)}
            emptyState={
              <EmptyState
                icon="package"
                title="No orders"
                description="Your sales orders will appear here once placed."
              />
            }
          />
        </div>
      )}
    </div>
  );
}
