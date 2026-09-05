import { useQuery } from '@tanstack/react-query';
import { supplierPortalApi } from '@/api/b2b/supplier';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { CompanyName } from '@/components/brand/CompanyName';
import { formatDate } from '@/lib/formatDate';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import type { SupplierDeliverySummary } from '@/types/b2b';

type DeliveryFilters = { page: number; per_page: number; status?: string };

const DEFAULT_FILTERS: DeliveryFilters = { page: 1, per_page: 25 };

export default function SupplierDeliveriesPage() {
  const [filters, setFilters] = useUrlFilters<DeliveryFilters>(DEFAULT_FILTERS);
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'supplier', 'deliveries', filters],
    queryFn: () => supplierPortalApi.listDeliveries(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<SupplierDeliverySummary>[] = [
    {
      key: 'grn_number',
      header: 'GRN #',
      cell: (r) => <span className="font-mono font-medium">{r.grn_number}</span>,
    },
    {
      key: 'purchase_order',
      header: 'PO',
      cell: (r) =>
        r.purchase_order ? (
          <span className="font-mono text-accent">{r.purchase_order.po_number}</span>
        ) : (
          '—'
        ),
    },
    {
      key: 'received_date',
      header: 'Received Date',
      cell: (r) => (
        <span className="font-mono">{r.received_date ? formatDate(r.received_date) : '—'}</span>
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
        { value: 'pending_qc', label: 'Pending QC' },
        { value: 'accepted', label: 'Accepted' },
        { value: 'partial_accepted', label: 'Partially accepted' },
        { value: 'rejected', label: 'Rejected' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Deliveries"
        subtitle={
          data ? (
            <>{data.meta.total} shipments you have sent to <CompanyName /></>
          ) : (
            <>Shipments you have sent to <CompanyName /></>
          )
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
            tableKey="portal-supplier-deliveries"
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((current) => ({ ...current, page }))}
            onPageSizeChange={(per_page) => setFilters((current) => ({ ...current, per_page, page: 1 }))}
            emptyState={
              <EmptyState
                icon="truck"
                title="No deliveries"
                description="Goods receipts against your purchase orders will appear here."
              />
            }
          />
        </div>
      )}
    </div>
  );
}
