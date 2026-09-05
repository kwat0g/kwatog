import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { supplierPortalApi } from '@/api/b2b/supplier';
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
import type { PortalPoSummary } from '@/types/b2b';

type PurchaseOrderFilters = {
  page: number;
  per_page: number;
  status?: string;
  search?: string;
  sort?: string;
  dir?: 'asc' | 'desc';
};

const DEFAULT_FILTERS: PurchaseOrderFilters = { page: 1, per_page: 25 };

export default function SupplierPurchaseOrdersPage() {
  const navigate = useNavigate();
  const [filters, setFilters] = useUrlFilters<PurchaseOrderFilters>(DEFAULT_FILTERS);
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'supplier', 'pos', filters],
    queryFn: () => supplierPortalApi.listPos(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<PortalPoSummary>[] = [
    {
      key: 'po_number',
      header: 'PO #',
      sortable: true,
      cell: (r) => <span className="font-mono font-medium text-accent">{r.po_number}</span>,
    },
    {
      key: 'date',
      header: 'Date',
      sortable: true,
      cell: (r) => <span className="font-mono">{r.date ? formatDate(r.date) : '—'}</span>,
    },
    {
      key: 'total_amount',
      header: 'Amount',
      align: 'right',
      sortable: true,
      cell: (r) => <NumCell className="font-medium">{formatPeso(r.total_amount)}</NumCell>,
    },
    {
      key: 'expected_delivery_date',
      header: 'Expected Delivery',
      cell: (r) => (
        <span className="font-mono">
          {r.expected_delivery_date ? formatDate(r.expected_delivery_date) : '—'}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      sortable: true,
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
        { value: 'approved', label: 'Approved' },
        { value: 'sent', label: 'Sent' },
        { value: 'partially_received', label: 'Partially received' },
        { value: 'received', label: 'Received' },
        { value: 'closed', label: 'Closed' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Purchase Orders"
        subtitle={
          data ? (
            <>{data.meta.total} orders issued to you by <CompanyName /></>
          ) : (
            <>Orders issued to you by <CompanyName /></>
          )
        }
      />

      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((current) => ({ ...current, search: search || undefined, page: 1 }))}
        onFilter={(key, value) => setFilters((current) => ({ ...current, [key]: value || undefined, page: 1 }))}
        searchPlaceholder="Search PO number…"
      />

      {isLoading && !data && <SkeletonTable columns={5} rows={8} />}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load purchase orders"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}

      {data && (
        <div className="px-5 py-4">
          <DataTable
            tableKey="portal-supplier-purchase-orders"
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((current) => ({ ...current, page }))}
            onPageSizeChange={(per_page) => setFilters((current) => ({ ...current, per_page, page: 1 }))}
            onSort={(sort, direction) =>
              setFilters((current) => ({ ...current, sort, dir: direction, page: 1 }))
            }
            currentSort={filters.sort}
            currentDirection={filters.dir}
            onRowClick={(r) => navigate(`/portal/supplier/purchase-orders/${r.id}`)}
            emptyState={
              <EmptyState
                icon="file-text"
                title="No purchase orders"
                description="Purchase orders from Ogami will appear here."
              />
            }
          />
        </div>
      )}
    </div>
  );
}
