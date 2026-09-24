import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { LuPlus, LuSearch } from '@/lib/icons';
import { rfqsApi } from '@/api/purchasing/rfqs';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { rfqStatus } from '@/lib/rfqStatus';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { ListEmptyState } from '@/components/ui/ListEmptyState';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatDateTime } from '@/lib/formatDate';
import type { ListParams } from '@/types';
import type { RequestForQuote } from '@/types/purchasing';

type Filters = ListParams & { status?: string; search?: string };

export default function RequestForQuotesPage() {
  const navigate = useNavigate();
  const [filters, setFilters] = useUrlFilters<Filters>({
    page: 1,
    per_page: 25,
    status: '',
    search: '',
  });

  const query = useQuery({
    queryKey: ['purchasing', 'rfqs', filters],
    queryFn: ({ signal }) => rfqsApi.list(filters, signal),
    placeholderData: (previous) => previous,
  });

  const columns: Column<RequestForQuote>[] = [
    {
      key: 'rfq_number',
      header: 'RFQ #',
      cell: (row) => <span className="font-mono">{row.rfq_number}</span>,
    },
    {
      key: 'title',
      header: 'Title',
      cell: (row) => <span className="font-medium">{row.title}</span>,
    },
    {
      key: 'purchase_request',
      header: 'Source PR',
      cell: (row) => <span className="font-mono">{row.purchase_request?.pr_number ?? '—'}</span>,
    },
    {
      key: 'closes_at',
      header: 'Deadline',
      cell: (row) => <span className="font-mono text-sm">{formatDateTime(row.closes_at)}</span>,
    },
    {
      key: 'responses',
      header: 'Responses',
      cell: (row) => (
        <span className="font-mono tabular-nums text-sm">
          {row.responded_count}/{row.invited_count}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      cell: (row) => (
        <Chip variant={rfqStatus(row.status).variant}>{rfqStatus(row.status).label}</Chip>
      ),
    },
  ];

  return (
    <div>
      <PageHeader
        title="Supplier RFQs"
        subtitle={query.data ? `${query.data.meta.total} total` : undefined}
        actions={
          <Button
            size="sm"
            variant="primary"
            icon={<LuPlus size={14} />}
            onClick={() => navigate('/purchasing/rfqs/create')}
          >
            New RFQ
          </Button>
        }
      />

      {query.isFetching && query.data && (
        <div className="px-5 text-xs text-muted" role="status">
          Refreshing…
        </div>
      )}

      {query.isLoading && !query.data && <SkeletonTable columns={6} rows={6} />}

      {query.isError && !query.data && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load RFQs"
          action={<Button onClick={() => query.refetch()}>Retry</Button>}
        />
      )}

      {query.data && query.data.data.length === 0 && <ListEmptyState />}

      {query.data && query.data.data.length > 0 && (
        <div className="px-5 py-4 space-y-4">
          <div className="flex flex-col sm:flex-row gap-3">
            <div className="relative flex-1">
              <LuSearch
                size={14}
                className="absolute left-3 top-1/2 -translate-y-1/2 text-muted pointer-events-none"
              />
              <Input
                placeholder="Search RFQ # or title…"
                value={filters.search ?? ''}
                onChange={(e) => setFilters((cur) => ({ ...cur, search: e.target.value, page: 1 }))}
                className="pl-8"
              />
            </div>
            <Select
              value={filters.status ?? ''}
              onChange={(e) => setFilters((cur) => ({ ...cur, status: e.target.value, page: 1 }))}
              className="sm:w-48"
            >
              <option value="">All statuses</option>
              <option value="draft">Draft</option>
              <option value="open">Open</option>
              <option value="closed">Closed</option>
              <option value="awarded">Awarded</option>
              <option value="cancelled">Cancelled</option>
            </Select>
          </div>

          <DataTable
            tableKey="purchasing-rfqs"
            data={query.data.data}
            columns={columns}
            meta={query.data.meta}
            onRowClick={(row) => navigate(`/purchasing/rfqs/${row.id}`)}
            onPageChange={(page) => setFilters((cur) => ({ ...cur, page }))}
            onPageSizeChange={(per_page) => setFilters((cur) => ({ ...cur, per_page, page: 1 }))}
          />
        </div>
      )}
    </div>
  );
}
