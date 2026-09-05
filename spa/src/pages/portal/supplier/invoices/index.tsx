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
import type { SupplierBillSummary } from '@/types/b2b';

type InvoiceFilters = { page: number; per_page: number; status?: string };

const DEFAULT_FILTERS: InvoiceFilters = { page: 1, per_page: 25 };

export default function SupplierInvoicesPage() {
  const navigate = useNavigate();
  const [filters, setFilters] = useUrlFilters<InvoiceFilters>(DEFAULT_FILTERS);
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['portal', 'supplier', 'invoices', filters],
    queryFn: () => supplierPortalApi.listInvoices(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<SupplierBillSummary>[] = [
    {
      key: 'bill_number',
      header: 'Invoice #',
      cell: (r) => <span className="font-mono font-medium text-accent">{r.bill_number}</span>,
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
      key: 'balance',
      header: 'Balance',
      align: 'right',
      cell: (r) => <NumCell>{formatPeso(r.balance)}</NumCell>,
    },
    {
      key: 'due_date',
      header: 'Due',
      cell: (r) => <span className="font-mono">{r.due_date ? formatDate(r.due_date) : '—'}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => (
        <Chip variant={chipVariantForStatus(r.status)}>
          {r.status_label ?? r.status}
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
        { value: 'unpaid', label: 'Unpaid' },
        { value: 'partial', label: 'Partially paid' },
        { value: 'paid', label: 'Paid' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Invoices"
        subtitle={
          data ? (
            <>{data.meta.total} bills you have issued to <CompanyName /></>
          ) : (
            <>Bills you have issued to <CompanyName /></>
          )
        }
      />

      <FilterBar
        filters={filterConfig}
        values={filters}
        onFilter={(key, value) => setFilters((current) => ({ ...current, [key]: value || undefined, page: 1 }))}
        searchable={false}
      />

      {isLoading && !data && <SkeletonTable columns={6} rows={8} />}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load invoices"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}

      {data && (
        <div className="px-5 py-4">
          <DataTable
            tableKey="portal-supplier-invoices"
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((current) => ({ ...current, page }))}
            onPageSizeChange={(per_page) => setFilters((current) => ({ ...current, per_page, page: 1 }))}
            onRowClick={(r) => navigate(`/portal/supplier/invoices/${r.id}`)}
            emptyState={
              <EmptyState
                icon="receipt"
                title="No invoices"
                description="Invoices you submitted will appear here with their AP review status."
              />
            }
          />
        </div>
      )}
    </div>
  );
}
