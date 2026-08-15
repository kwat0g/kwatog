import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate} from 'react-router-dom';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { Play, Loader2 } from 'lucide-react';
import { mrpPlansApi, type MrpPlanListParams } from '@/api/mrp/mrpPlans';
import { mrpRunsApi } from '@/api/mrp-runs';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { MrpRunStatusPanel } from '@/components/mrp/MrpRunStatusPanel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import type { MrpPlan, MrpPlanStatus } from '@/types/mrp';

const variant: Record<MrpPlanStatus, 'success' | 'neutral' | 'danger'> = {
 active: 'success', superseded: 'neutral', cancelled: 'danger' };

const DEFAULT_FILTERS: MrpPlanListParams = {
 page: 1, per_page: 25, status: 'active',
};

export default function MrpPlansListPage() {
 const navigate = useNavigate();
 const queryClient = useQueryClient();
 const { can } = usePermission();
 // Bound to the URL so dashboard drill-downs (?status=active) arrive
 // pre-filtered and the browser back button restores the previous view.
 const [filters, setFilters] = useUrlFilters<MrpPlanListParams>(DEFAULT_FILTERS);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['mrp', 'plans', filters],
 queryFn: () => mrpPlansApi.list(filters),
 placeholderData: (prev) => prev });
 const { data: planOptions } = useQuery({
 queryKey: ['mrp', 'plans', 'options'],
 queryFn: mrpPlansApi.options,
 staleTime: 5 * 60 * 1000 });
 const statusLabels = new Map((planOptions?.statuses ?? []).map((option) => [option.value, option.label]));

 const latestRun = useQuery({
 queryKey: ['mrp', 'runs', 'latest'],
 queryFn: () => mrpRunsApi.latest(),
 enabled: can('mrp.runs.view'),
 staleTime: 5_000,
 refetchInterval: (query) => query.state.data?.status === 'running' ? 3_000 : 15_000 });
 const runHistory = useQuery({
 queryKey: ['mrp', 'runs', 'history'],
 queryFn: () => mrpRunsApi.list({ per_page: 8, page: 1 }),
 enabled: can('mrp.runs.view'),
 staleTime: 5_000,
 refetchInterval: 15_000 });
 const latestVisibleRun = latestRun.data ?? runHistory.data?.data[0];

 const triggerRun = useMutation({
 mutationFn: () => mrpRunsApi.trigger(),
 onSuccess: () => {
 toast.success('MRP run started — refresh in a moment for results');
 queryClient.invalidateQueries({ queryKey: ['mrp', 'runs', 'latest'] });
 queryClient.invalidateQueries({ queryKey: ['mrp', 'runs', 'history'] });
 queryClient.invalidateQueries({ queryKey: ['mrp', 'plans'] });
 },
 onError: (e) => {
 const msg = e instanceof AxiosError ? e.response?.data?.message : 'Failed to trigger MRP run';
 toast.error(msg ?? 'Failed to trigger MRP run');
 } });

 const columns: Column<MrpPlan>[] = [
 {
 key: 'no', header: 'Plan #',
 cell: (r) => (
 <span className="font-mono">{r.mrp_plan_no}</span>
 ) },
 {
 key: 'so', header: 'Sales order',
 cell: (r) => r.sales_order ? (
 <span className="font-mono">
 {r.sales_order.so_number}
 </span>
 ) : '—' },
 { key: 'cust', header: 'Customer', cell: (r) => r.sales_order?.customer?.name ?? '—' },
 { key: 'version', header: 'Version', align: 'right', cell: (r) => <NumCell>v{r.version}</NumCell> },
 {
 key: 'shortages', header: 'Shortages', align: 'right',
 cell: (r) => r.shortages_found > 0
 ? <NumCell className="text-warning-fg">{r.shortages_found}</NumCell>
 : <NumCell>0</NumCell> },
 { key: 'pr', header: 'Auto PRs', align: 'right', cell: (r) => <NumCell>{r.auto_pr_count}</NumCell> },
 { key: 'wo', header: 'Draft WOs', align: 'right', cell: (r) => <NumCell>{r.draft_wo_count}</NumCell> },
 { key: 'status', header: 'Status', cell: (r) => <Chip variant={variant[r.status]}>{r.status_label ?? statusLabels.get(r.status) ?? r.status}</Chip> },
 { key: 'gen', header: 'Generated', align: 'right', cell: (r) => <NumCell>{r.generated_at?.slice(0, 10)}</NumCell> },
 ];

 const filterConfig: FilterConfig[] = [
 { key: 'status', label: 'Status', type: 'select', options: [
 { value: '', label: 'All' }, ...(planOptions?.statuses ?? []),
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
 {can('mrp.runs.view') && (
 <MrpRunStatusPanel latest={latestVisibleRun} recent={runHistory.data?.data} />
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
  <DataTable tableKey="mrp-plans" onRowClick={(r) => navigate(`/mrp/plans/${r.id}`)}
 columns={columns} data={data.data} meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))} />
 </div>
 )}
 </div>
 );
}
