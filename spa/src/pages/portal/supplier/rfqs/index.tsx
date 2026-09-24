import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { supplierRfqsApi } from '@/api/purchasing/rfqs';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { invitationStatus } from '@/lib/rfqStatus';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import type { SupplierRfq } from '@/types/purchasing';

const formatRelativeTime = (closesAt: string): string => {
  const now = new Date();
  const deadline = new Date(closesAt);
  if (deadline <= now) return 'Closed';

  const ms = deadline.getTime() - now.getTime();
  const days = Math.floor(ms / (1000 * 60 * 60 * 24));
  const hours = Math.floor((ms % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));

  if (days > 0) return `Closes in ${days}d ${hours}h`;
  return `Closes in ${hours}h`;
};

type RfqFilters = {
  page: number;
  per_page: number;
  status?: string;
  search?: string;
  sort?: 'closes_at' | 'rfq_number';
  direction?: 'asc' | 'desc';
};

const statusOptions = [
  { value: '', label: 'All statuses' },
  { value: 'open', label: 'Open' },
  { value: 'closed', label: 'Closed' },
  { value: 'awarded', label: 'Awarded' },
  { value: 'cancelled', label: 'Cancelled' },
];

const columns: Column<SupplierRfq>[] = [
  {
    key: 'rfq_number',
    header: 'RFQ #',
    sortable: true,
    cell: (row) => <span className="font-mono">{row.rfq_number}</span>,
  },
  {
    key: 'title',
    header: 'Title',
    cell: (row) => (
      <div>
        <div className="font-medium">{row.title}</div>
      </div>
    ),
  },
  {
    key: 'closes_at',
    header: 'Deadline',
    sortable: true,
    cell: (row) => {
      const relative = formatRelativeTime(row.closes_at);
      const absolute = new Date(row.closes_at).toLocaleDateString();
      return (
        <div className="text-sm">
          <div className="font-mono">{absolute}</div>
          <div className="text-2xs text-muted">{relative}</div>
        </div>
      );
    },
  },
  {
    key: 'invitation_status',
    header: 'Your response',
    cell: (row) => {
      const meta = invitationStatus(row.invitation_status);
      return <Chip variant={meta.variant}>{meta.label}</Chip>;
    },
  },
  {
    key: 'outcome',
    header: 'Outcome',
    cell: (row) => {
      if (row.outcome === 'awarded') return <Chip variant="success">Awarded to you</Chip>;
      if (row.outcome === 'not_awarded') return <Chip variant="neutral">Not selected</Chip>;
      if (row.outcome === 'cancelled') return <Chip variant="warning">Cancelled</Chip>;
      return <span className="text-muted">—</span>;
    },
  },
];

export default function SupplierRfqsPage() {
  const navigate = useNavigate();
  const [filters, setFilters] = useUrlFilters<RfqFilters>({
    page: 1,
    per_page: 10,
    status: undefined,
    search: undefined,
    sort: 'closes_at',
    direction: 'asc',
  });

  const query = useQuery({
    queryKey: ['portal', 'supplier', 'rfqs', filters],
    queryFn: () =>
      supplierRfqsApi.list({
        page: filters.page,
        per_page: filters.per_page,
        status: filters.status,
        search: filters.search,
        sort: filters.sort,
        direction: filters.direction,
      }),
    placeholderData: (previous) => previous,
  });

  const rows = query.data?.data ?? [];
  const hasFilters = filters.search !== undefined || filters.status !== undefined;

  return (
    <div>
      <PageHeader
        title="RFQ Invitations"
        subtitle={
          query.data ? `${query.data.meta.total} ${query.data.meta.total === 1 ? "invitation" : "invitations"}` : 'Private sourcing events from Ogami'
        }
      />

      <FilterBar
        filters={[
          {
            key: 'status',
            label: 'Status',
            type: 'select' as const,
            options: statusOptions,
          },
        ]}
        values={filters}
        onFilter={(key, value) =>
          setFilters((cur) => ({ ...cur, [key]: value === '' ? undefined : value, page: 1 }))
        }
        onSearch={(search) =>
          setFilters((cur) => ({ ...cur, search: search || undefined, page: 1 }))
        }
        searchPlaceholder="Search by RFQ # or title…"
      />

      {query.isLoading && !query.data && <SkeletonTable columns={5} rows={8} />}

      {query.isError && !query.data && (
        <EmptyState
          icon="alert-circle"
          title="RFQ invitations unavailable"
          action={<Button onClick={() => query.refetch()}>Retry</Button>}
        />
      )}

      {query.data && (
        <DataTable
          tableKey="portal-supplier-rfqs"
          columns={columns}
          data={rows}
          meta={query.data.meta}
          onPageChange={(page) => setFilters((cur) => ({ ...cur, page }))}
          onPageSizeChange={(per_page) => setFilters((cur) => ({ ...cur, per_page, page: 1 }))}
          onSort={(sort, direction) =>
            setFilters((cur) => ({
              ...cur,
              sort: sort === 'rfq_number' ? 'rfq_number' : 'closes_at',
              direction,
              page: 1,
            }))
          }
          currentSort={filters.sort}
          currentDirection={filters.direction}
          onRowClick={(row) => navigate(`/portal/supplier/rfqs/${row.id}`)}
          getRowId={(row) => row.id}
          emptyState={
            <EmptyState
              icon="file-text"
              title={hasFilters ? 'No invitations match your filters' : 'No RFQ invitations'}
              description={
                hasFilters
                  ? 'Try a different search term or clear filters.'
                  : 'New invitations will appear here when Ogami opens a sourcing event.'
              }
              action={
                hasFilters ? (
                  <Button
                    variant="secondary"
                    onClick={() =>
                      setFilters({
                        page: 1,
                        per_page: 10,
                        status: undefined,
                        search: undefined,
                        sort: 'closes_at',
                        direction: 'asc',
                      })
                    }
                  >
                    Clear filters
                  </Button>
                ) : undefined
              }
            />
          }
        />
      )}
    </div>
  );
}
