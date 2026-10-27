import { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { materialIssuesApi } from '@/api/inventory/material-issues';
import { Chip } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { PageShell } from '@/components/layout/PageShell';
import { FilterBar } from '@/components/ui/FilterBar';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatDate } from '@/lib/formatDate';
import type { ListParams } from '@/types';
import type { MaterialIssueSlip } from '@/types/inventory';
import { formatPeso } from '@/lib/formatNumber';

import { ListEmptyState } from '@/components/ui/ListEmptyState';
interface MaterialIssueListParams extends ListParams {
  search?: string;
  status?: string;
  date?: string;
  from?: string;
  to?: string;
}

const DEFAULT_FILTERS: MaterialIssueListParams = {
  search: '', page: 1, per_page: 25,
};

export default function MaterialIssuesListPage() {
  const navigate = useNavigate();
  const [filters, setFilters] = useUrlFilters<MaterialIssueListParams>(DEFAULT_FILTERS);

  // Dashboard KPI links to ?date=today — expand to a from/to range at mount
  // because the backend filters on issued_date via from/to.
  useEffect(() => {
    if (filters.date === 'today') {
      const now = new Date();
      const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
      setFilters((f) => ({ ...f, date: undefined, from: today, to: today }));
    }
  }, [filters.date, setFilters]);
 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['inventory', 'material-issues', filters],
 queryFn: () => materialIssuesApi.list(filters),
 placeholderData: (prev) => prev,
 });

 const columns: Column<MaterialIssueSlip>[] = [
    {
      key: 'slip',
      header: 'Slip',
      width: 180,
      minWidth: 150,
      maxWidth: 220,
      pinned: 'left',
      cell: (r) => (
        <Link
          to={`/inventory/material-issues/${r.id}`}
          data-touch-target
          className="font-mono text-link hover:text-link-hover hover:underline"
        >
          {r.slip_number}
        </Link>
      ),
    },
    {
      key: 'date',
      header: 'Issued',
      width: 170,
      minWidth: 150,
      maxWidth: 190,
      cell: (r) => <span className="tabular-nums">{formatDate(r.issued_date)}</span>,
    },
    {
      key: 'wo',
      header: 'Work order',
      width: 420,
      minWidth: 240,
      maxWidth: 540,
      cell: (r) => {
        const reference = r.work_order?.wo_number ?? r.reference_text ?? '—';
        return <span className="block truncate" title={reference}>{reference}</span>;
      },
    },
    {
      key: 'status',
      header: 'Status',
      width: 140,
      minWidth: 120,
      maxWidth: 180,
      cell: (r) => (
        <Chip variant={r.status === 'issued' ? 'info' : r.status === 'cancelled' ? 'neutral' : 'warning'}>
          {r.status_label ?? r.status}
        </Chip>
      ),
    },
    {
      key: 'value',
      header: 'Value',
      align: 'right',
      width: 190,
      minWidth: 160,
      maxWidth: 240,
      cell: (r) => <span className="tabular-nums font-medium">{formatPeso(r.total_value)}</span>,
    },
 ];

 return (
    <PageShell viewport className="flex flex-col gap-3">
      <PageHeader
        inset={false}
        title="Material issues"
        subtitle={data ? `${data.meta.total} slips` : undefined}
      />
      <FilterBar
        inset={false}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        searchPlaceholder="Search MIS number..."
      />
      {isLoading && !data && (
        <div className="min-h-0 flex-1 overflow-auto">
          <SkeletonTable rows={6} columns={5} />
        </div>
      )}
      {isError && (
        <div className="min-h-0 flex-1 flex items-center justify-center">
          <EmptyState
            icon="alert-circle"
            title="Failed to load material issues"
            action={<Button onClick={() => refetch()}>Retry</Button>}
          />
        </div>
      )}
      {!isError && data && data.data.length === 0 && (
        <div className="min-h-0 flex-1 flex items-center justify-center">
          <ListEmptyState searchTerm={filters.search || (filters.status ? `status: ${filters.status.replaceAll('_', ' ')}` : filters.from || filters.to ? 'selected dates' : undefined)} />
        </div>
      )}
      {data && data.data.length > 0 && (
        <div className="min-h-0 flex-1 flex flex-col">
          <DataTable
            tableKey="material-issues"
            fillHeight
            fixedColumnLayout
            onRowClick={(r) => navigate(`/inventory/material-issues/${r.id}`)}
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
            onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
          />
        </div>
      )}
    </PageShell>
 );
}
