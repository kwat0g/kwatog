/** Sprint 8 — Task 71. Separations list. */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { separationsApi, type SeparationListParams } from '@/api/separations';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import type { Clearance, ClearanceStatus } from '@/types/separations';

const STATUS_CHIP: Record<ClearanceStatus, 'success' | 'warning' | 'info' | 'neutral'> = {
  pending: 'warning', in_progress: 'info', completed: 'info', finalized: 'success', cancelled: 'neutral',
};

export default function SeparationsListPage() {
  const [filters, setFilters] = useState<SeparationListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['hr', 'separations', filters],
    queryFn: () => separationsApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<Clearance>[] = [
    {
      key: 'clearance_no', header: 'Clearance',
      cell: (r) => <Link to={`/hr/separations/${r.id}`} className="font-mono text-accent hover:underline">{r.clearance_no}</Link>,
    },
    {
      key: 'employee', header: 'Employee',
      cell: (r) => r.employee
        ? <div><div className="text-sm">{r.employee.full_name}</div><div className="text-xs text-muted font-mono">{r.employee.employee_no}</div></div>
        : <span className="text-muted">—</span>,
    },
    {
      key: 'department', header: 'Department',
      cell: (r) => r.employee?.department?.name ?? <span className="text-muted">—</span>,
    },
    {
      key: 'separation_date', header: 'Separation date', align: 'right',
      cell: (r) => <NumCell>{r.separation_date ?? '—'}</NumCell>,
    },
    { key: 'reason', header: 'Reason', cell: (r) => r.separation_reason.replace('_', ' ') },
    {
      key: 'progress', header: 'Progress', align: 'right',
      cell: (r) => <NumCell>{r.cleared_count}/{r.items_total} ({r.progress_pct}%)</NumCell>,
    },
    {
      key: 'final_pay', header: 'Final pay', align: 'right',
      cell: (r) => r.final_pay_amount ? <NumCell>₱{r.final_pay_amount}</NumCell> : <span className="text-muted">—</span>,
    },
    { key: 'status', header: 'Status', cell: (r) => <Chip variant={STATUS_CHIP[r.status]}>{r.status.replace('_', ' ')}</Chip> },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'status', label: 'Status', type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'pending', label: 'Pending' },
        { value: 'in_progress', label: 'In progress' },
        { value: 'completed', label: 'Completed' },
        { value: 'finalized', label: 'Finalized' },
        { value: 'cancelled', label: 'Cancelled' },
      ],
    },
    {
      key: 'separation_reason', label: 'Reason', type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'resigned', label: 'Resigned' },
        { value: 'terminated', label: 'Terminated' },
        { value: 'retired', label: 'Retired' },
        { value: 'end_of_contract', label: 'End of contract' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader title="Separations & clearances"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'clearance' : 'clearances'}` : undefined} />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search…"
      />
      {isLoading && !data && <SkeletonTable columns={8} rows={6} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load separations"
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && (
        <EmptyState icon="user-x" title="No separations" description="Separations are initiated from an employee detail page." />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable columns={columns} data={data.data} meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))} />
        </div>
      )}
    </div>
  );
}
