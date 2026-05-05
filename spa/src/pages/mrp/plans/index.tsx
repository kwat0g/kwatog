import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { Play, Loader2, Activity } from 'lucide-react';
import { mrpPlansApi, type MrpPlanListParams } from '@/api/mrp/mrpPlans';
import { mrpRunsApi } from '@/api/mrp-runs';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDateTime } from '@/lib/formatDate';
import type { MrpPlan, MrpPlanStatus } from '@/types/mrp';

const variant: Record<MrpPlanStatus, 'success' | 'neutral' | 'danger'> = {
  active: 'success', superseded: 'neutral', cancelled: 'danger',
};

export default function MrpPlansListPage() {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const [filters, setFilters] = useState<MrpPlanListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['mrp', 'plans', filters],
    queryFn: () => mrpPlansApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const lastRun = useQuery({
    queryKey: ['mrp', 'runs', 'latest'],
    queryFn: () => mrpRunsApi.latest(),
    enabled: can('mrp.runs.view'),
    staleTime: 30_000,
  });

  const triggerRun = useMutation({
    mutationFn: () => mrpRunsApi.trigger(),
    onSuccess: () => {
      toast.success('MRP run started — refresh in a moment for results');
      queryClient.invalidateQueries({ queryKey: ['mrp', 'runs', 'latest'] });
      queryClient.invalidateQueries({ queryKey: ['mrp', 'plans'] });
    },
    onError: (e) => {
      const msg = e instanceof AxiosError ? e.response?.data?.message : 'Failed to trigger MRP run';
      toast.error(msg ?? 'Failed to trigger MRP run');
    },
  });

  const columns: Column<MrpPlan>[] = [
    {
      key: 'no', header: 'Plan #',
      cell: (r) => (
        <Link to={`/mrp/plans/${r.id}`} className="font-mono text-accent hover:underline">{r.mrp_plan_no}</Link>
      ),
    },
    {
      key: 'so', header: 'Sales order',
      cell: (r) => r.sales_order ? (
        <Link to={`/crm/sales-orders/${r.sales_order.id}`} className="font-mono text-accent hover:underline">
          {r.sales_order.so_number}
        </Link>
      ) : '—',
    },
    { key: 'cust', header: 'Customer', cell: (r) => r.sales_order?.customer?.name ?? '—' },
    { key: 'version', header: 'Version', align: 'right', cell: (r) => <NumCell>v{r.version}</NumCell> },
    {
      key: 'shortages', header: 'Shortages', align: 'right',
      cell: (r) => r.shortages_found > 0
        ? <NumCell className="text-warning-fg">{r.shortages_found}</NumCell>
        : <NumCell>0</NumCell>,
    },
    { key: 'pr', header: 'Auto PRs', align: 'right', cell: (r) => <NumCell>{r.auto_pr_count}</NumCell> },
    { key: 'wo', header: 'Draft WOs', align: 'right', cell: (r) => <NumCell>{r.draft_wo_count}</NumCell> },
    { key: 'status', header: 'Status', cell: (r) => <Chip variant={variant[r.status]}>{r.status}</Chip> },
    { key: 'gen', header: 'Generated', align: 'right', cell: (r) => <NumCell>{r.generated_at?.slice(0, 10)}</NumCell> },
  ];

  const filterConfig: FilterConfig[] = [
    { key: 'status', label: 'Status', type: 'select', options: [
      { value: '', label: 'All' }, { value: 'active', label: 'Active' },
      { value: 'superseded', label: 'Superseded' }, { value: 'cancelled', label: 'Cancelled' },
    ]},
  ];

  return (
    <div>
      <PageHeader
        title="MRP plans"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'plan' : 'plans'}` : undefined}
        actions={can('mrp.runs.trigger') ? (
          <Button
            variant="primary"
            size="sm"
            icon={triggerRun.isPending ? <Loader2 size={14} className="animate-spin" /> : <Play size={14} />}
            disabled={triggerRun.isPending}
            onClick={() => triggerRun.mutate()}
          >
            {triggerRun.isPending ? 'Running…' : 'Run MRP now'}
          </Button>
        ) : undefined}
      />
      {can('mrp.runs.view') && lastRun.data && (
        <div className="px-5 pb-3">
          <div className="flex items-center justify-between gap-4 rounded-md border border-subtle bg-subtle px-3 py-2 text-xs">
            <div className="flex items-center gap-3">
              <Activity size={14} className="text-muted" />
              <div className="flex items-center gap-3">
                <span className="text-muted">Last MRP run</span>
                <span className="font-mono tabular-nums text-default">{formatDateTime(lastRun.data.run_at)}</span>
                <Chip variant={lastRun.data.triggered_by === 'scheduled' ? 'info' : 'neutral'}>
                  {lastRun.data.triggered_by}
                </Chip>
                <Chip
                  variant={
                    lastRun.data.status === 'completed'
                      ? 'success'
                      : lastRun.data.status === 'failed'
                        ? 'danger'
                        : 'warning'
                  }
                >
                  {lastRun.data.status}
                </Chip>
              </div>
            </div>
            <div className="flex items-center gap-4 font-mono tabular-nums text-muted">
              <span><span className="text-default">{lastRun.data.shortages_found}</span> shortages</span>
              <span><span className="text-default">{lastRun.data.prs_created}</span> PRs created</span>
              <span><span className="text-default">{lastRun.data.prs_updated}</span> PRs updated</span>
              {lastRun.data.duration_ms != null && (
                <span className="text-subtle">{(lastRun.data.duration_ms / 1000).toFixed(1)}s</span>
              )}
            </div>
          </div>
        </div>
      )}
      <FilterBar
        filters={filterConfig} values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
      />
      {isLoading && !data && <SkeletonTable columns={9} rows={6} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load MRP plans"
        action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && data.data.length === 0 && (
        <EmptyState icon="layers" title="No MRP plans yet"
          description="Plans are generated automatically when a sales order is confirmed." />
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
