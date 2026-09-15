import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { rfqsApi } from '@/api/purchasing/rfqs';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { ListEmptyState } from '@/components/ui/ListEmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatDate } from '@/lib/formatDate';
import type { ListParams } from '@/types';
import type { RequestForQuote } from '@/types/purchasing';

type Filters = ListParams & { status?: string };

export default function RequestForQuotesPage() {
  const navigate = useNavigate();
  const [filters, setFilters] = useUrlFilters<Filters>({ page: 1, per_page: 25, status: '' });
  const query = useQuery({
    queryKey: ['purchasing', 'rfqs', filters],
    queryFn: ({ signal }) => rfqsApi.list(filters, signal),
    placeholderData: (previous) => previous,
  });
  const columns: Column<RequestForQuote>[] = [
    { key: 'number', header: 'RFQ #', cell: (row) => <span className="font-mono">{row.rfq_number}</span> },
    { key: 'title', header: 'Sourcing event', cell: (row) => <span className="font-medium">{row.title}</span> },
    { key: 'pr', header: 'Source PR', cell: (row) => <span className="font-mono">{row.purchase_request?.pr_number ?? '—'}</span> },
    { key: 'deadline', header: 'Deadline', cell: (row) => <span className="font-mono">{formatDate(row.closes_at)}</span> },
    { key: 'status', header: 'Status', cell: (row) => <Chip variant={chipVariantForStatus(row.status)}>{row.status_label ?? row.status.replace(/_/g, ' ')}</Chip> },
  ];
  return <div>
    <PageHeader title="Supplier RFQs" subtitle={query.data ? `${query.data.meta.total} sourcing events` : undefined} />
    {query.isLoading && !query.data && <SkeletonTable columns={5} rows={6} />}
    {query.isError && <EmptyState icon="alert-circle" title="Failed to load RFQs" action={<Button onClick={() => query.refetch()}>Retry</Button>} />}
    {query.data && query.data.data.length === 0 && <ListEmptyState />}
    {query.data && query.data.data.length > 0 && <div className="px-5 py-4">
      <DataTable tableKey="supplier-rfqs" data={query.data.data} columns={columns} meta={query.data.meta}
        onRowClick={(row) => navigate(`/purchasing/rfqs/${row.id}`)}
        onPageChange={(page) => setFilters((current) => ({ ...current, page }))}
        onPageSizeChange={(per_page) => setFilters((current) => ({ ...current, per_page, page: 1 }))} />
    </div>}
  </div>;
}
