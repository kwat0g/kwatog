import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { supplierRfqsApi } from '@/api/purchasing/rfqs';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import type { RequestForQuote, RfqInvitationStatus } from '@/types/purchasing';

const formatDateTime = (value: string | null) => value ? new Date(value).toLocaleString() : '—';

const statusOptions = [
  { value: 'open', label: 'Open — quoting now' },
  { value: 'draft', label: 'Draft' },
  { value: 'closed', label: 'Closed — under evaluation' },
  { value: 'under_evaluation', label: 'Under evaluation' },
  { value: 'awarded', label: 'Awarded' },
  { value: 'partially_awarded', label: 'Partially awarded' },
  { value: 'no_award', label: 'No award' },
  { value: 'invited', label: 'Not yet viewed' },
  { value: 'viewed', label: 'Viewed — not quoted' },
  { value: 'submitted', label: 'Quotation submitted' },
  { value: 'withdrawn', label: 'Quotation withdrawn' },
  { value: 'not_awarded', label: 'Not awarded' },
];

const columns: Column<RequestForQuote>[] = [
  {
    key: 'rfq_number',
    header: 'RFQ #',
    sortable: true,
    cell: (row) => (
      <Link to={`/portal/supplier/rfqs/${row.id}`} className="font-mono text-accent hover:underline">
        {row.rfq_number}
      </Link>
    ),
  },
  {
    key: 'title',
    header: 'Sourcing event',
    cell: (row) => (
      <div>
        <div className="font-medium">{row.title}</div>
        {row.purchase_request && <div className="text-xs text-muted font-mono">{row.purchase_request.pr_number}</div>}
      </div>
    ),
  },
  {
    key: 'closes_at',
    header: 'Deadline',
    sortable: true,
    cell: (row) => (
      <span className="font-mono tabular-nums">{formatDateTime(row.closes_at)}</span>
    ),
  },
  {
    key: 'invitation_status',
    header: 'Your response',
    cell: (row) => {
      const status = row.invitation_status as RfqInvitationStatus | undefined;
      if (status === 'submitted') return <Chip variant="success">Quotation submitted</Chip>;
      if (status === 'withdrawn') return <Chip variant="warning">Quotation withdrawn</Chip>;
      if (status === 'awarded') return <Chip variant="success">Awarded to you</Chip>;
      if (status === 'not_awarded') return <Chip variant="neutral">Not awarded</Chip>;
      if (status === 'viewed') return <Chip variant="info">Viewed — not quoted</Chip>;
      return <Chip variant="warning">Awaiting response</Chip>;
    },
  },
  {
    key: 'status',
    header: 'RFQ status',
    cell: (row) => (
      <Chip variant={chipVariantForStatus(row.status)}>{row.status_label ?? row.status.replace(/_/g, ' ')}</Chip>
    ),
  },
];

export default function SupplierRfqsPage() {
  const navigate = useNavigate();
  const [filters, setFilters] = useState<{ page: number; per_page: number; sort?: string; direction?: 'asc' | 'desc'; search?: string; status?: string }>({
    page: 1,
    per_page: 10,
  });

  const query = useQuery({
    queryKey: ['portal', 'supplier', 'rfqs', filters],
    queryFn: () => supplierRfqsApi.list(filters),
    placeholderData: (previous) => previous,
  });

  const handleFilter = (key: string, value: unknown) => {
    setFilters((current) => ({ ...current, [key]: value === '' ? undefined : value, page: 1 }));
  };

  const handleSort = (sort: string, direction: 'asc' | 'desc') => {
    setFilters((current) => ({ ...current, sort, direction, page: 1 }));
  };

  const rows = query.data?.data ?? [];
  const hasFilters = filters.search !== undefined || filters.status !== undefined;

  return (
    <div>
      <PageHeader title="Supplier RFQs" subtitle={query.data ? `${query.data.meta.total} invitations` : 'Private sourcing invitations from Ogami'} />

      <FilterBar
        filters={[{ key: 'status', label: 'Status', type: 'select' as const, options: statusOptions }]}
        values={filters}
        onFilter={handleFilter}
        onSearch={(search) => handleFilter('search', search)}
        searchPlaceholder="Search by RFQ number or title…"
      />

      {query.isLoading && !query.data && <SkeletonTable columns={5} rows={8} />}

      {query.isError && !query.data && (
        <EmptyState
          icon="alert-circle"
          title="Supplier RFQs unavailable"
          action={<Button onClick={() => query.refetch()}>Retry</Button>}
        />
      )}

      {query.data && (
        <DataTable
          tableKey="portal-supplier-rfqs"
          columns={columns}
          data={rows}
          meta={query.data.meta}
          onPageChange={(page) => setFilters((current) => ({ ...current, page }))}
          onPageSizeChange={(per_page) => setFilters((current) => ({ ...current, per_page, page: 1 }))}
          onSort={handleSort}
          currentSort={filters.sort}
          currentDirection={filters.direction}
          onRowClick={(row) => navigate(`/portal/supplier/rfqs/${row.id}`)}
          getRowId={(row) => row.id}
          emptyState={
            <EmptyState
              icon="file-text"
              title={hasFilters ? 'No invitations match your filters' : 'No RFQ invitations'}
              description={hasFilters
                ? 'Try a different search term or clear the status filter.'
                : 'New invitations will appear here when Ogami opens a sourcing event for your company.'}
              action={hasFilters ? (
                <Button variant="secondary" onClick={() => setFilters({ page: 1, per_page: 10 })}>Clear filters</Button>
              ) : undefined}
            />
          }
        />
      )}
    </div>
  );
}
