/** Succession Plans — list page. */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { successionPlansApi, type SuccessionPlanListParams } from '@/api/hr/succession';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { SuccessionPlan, SuccessionStatus, SuccessionPriority, SuccessionReadiness } from '@/types/succession';

const STATUS_CHIP: Record<SuccessionStatus, 'success' | 'neutral' | 'danger'> = {
  active: 'success',
  completed: 'neutral',
  cancelled: 'danger',
};

const PRIORITY_CHIP: Record<SuccessionPriority, 'danger' | 'warning' | 'neutral'> = {
  critical: 'danger',
  high: 'warning',
  medium: 'neutral',
  low: 'neutral',
};

const READINESS_CHIP: Record<SuccessionReadiness, 'success' | 'info' | 'warning' | 'neutral'> = {
  ready_now: 'success',
  ready_1_year: 'info',
  ready_2_years: 'warning',
  development_needed: 'neutral',
};

const READINESS_LABEL: Record<SuccessionReadiness, string> = {
  ready_now: 'Ready now',
  ready_1_year: 'Ready in 1 year',
  ready_2_years: 'Ready in 2 years',
  development_needed: 'Development needed',
};

export default function SuccessionPlansListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useState<SuccessionPlanListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['succession-plans', filters],
    queryFn: () => successionPlansApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<SuccessionPlan>[] = [
    {
      key: 'position',
      header: 'Position',
      cell: (r) => <span className="font-medium">{r.position.title}</span>,
    },
    {
      key: 'incumbent',
      header: 'Incumbent',
      cell: (r) =>
        r.incumbent
          ? <span>{r.incumbent.first_name} {r.incumbent.last_name}</span>
          : <span className="text-muted">—</span>,
    },
    {
      key: 'successor',
      header: 'Successor',
      cell: (r) => <span>{r.successor.first_name} {r.successor.last_name}</span>,
    },
    {
      key: 'readiness',
      header: 'Readiness',
      cell: (r) => <Chip variant={READINESS_CHIP[r.readiness]}>{READINESS_LABEL[r.readiness]}</Chip>,
    },
    {
      key: 'priority',
      header: 'Priority',
      cell: (r) => <Chip variant={PRIORITY_CHIP[r.priority]}>{r.priority}</Chip>,
    },
    {
      key: 'target_date',
      header: 'Target date',
      cell: (r) =>
        r.target_date
          ? <span className="font-mono tabular-nums">{r.target_date.slice(0, 10)}</span>
          : <span className="text-muted">—</span>,
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => <Chip variant={STATUS_CHIP[r.status]}>{r.status}</Chip>,
    },
    {
      key: 'actions',
      header: '',
      cell: (r) =>
        can('hr.succession.manage') ? (
          <Button variant="ghost" size="sm" onClick={() => navigate(`/hr/succession-plans/${r.id}/edit`)}>
            Edit
          </Button>
        ) : null,
    },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'status',
      label: 'Status',
      type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'active', label: 'Active' },
        { value: 'completed', label: 'Completed' },
        { value: 'cancelled', label: 'Cancelled' },
      ],
    },
    {
      key: 'priority',
      label: 'Priority',
      type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'critical', label: 'Critical' },
        { value: 'high', label: 'High' },
        { value: 'medium', label: 'Medium' },
        { value: 'low', label: 'Low' },
      ],
    },
    {
      key: 'readiness',
      label: 'Readiness',
      type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'ready_now', label: 'Ready now' },
        { value: 'ready_1_year', label: 'Ready in 1 year' },
        { value: 'ready_2_years', label: 'Ready in 2 years' },
        { value: 'development_needed', label: 'Development needed' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Succession Plans"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'plan' : 'plans'}` : undefined}
        actions={
          can('hr.succession.manage') ? (
            <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/hr/succession-plans/create')}>
              New plan
            </Button>
          ) : undefined
        }
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by position or employee name…"
      />
      {isLoading && !data && <SkeletonTable columns={8} rows={6} />}
      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Failed to load succession plans"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      )}
      {data && data.data.length === 0 && (
        <EmptyState
          icon="users"
          title="No succession plans"
          description="Create a succession plan to track key position transitions."
          action={
            can('hr.succession.manage') ? (
              <Button variant="primary" onClick={() => navigate('/hr/succession-plans/create')}>New plan</Button>
            ) : undefined
          }
        />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
            columns={columns}
            data={data.data}
            meta={data.meta}
            onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
          />
        </div>
      )}
    </div>
  );
}
