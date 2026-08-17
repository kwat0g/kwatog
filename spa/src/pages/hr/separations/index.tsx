/** Sprint 8 — Task 71. Separations list. */
import { useQuery } from '@tanstack/react-query';
import { useNavigate} from 'react-router-dom';
import { separationsApi, type SeparationListParams } from '@/api/separations';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import type { Clearance, ClearanceStatus } from '@/types/separations';
import { formatPeso } from '@/lib/formatNumber';

const STATUS_CHIP: Record<ClearanceStatus, 'success' | 'warning' | 'info' | 'neutral'> = {
 pending: 'warning', in_progress: 'info', completed: 'info', finalized: 'success', cancelled: 'neutral' };

const DEFAULT_FILTERS: SeparationListParams = {
 page: 1, per_page: 25, status: 'in_progress',
};

export default function SeparationsListPage() {
 const navigate = useNavigate();
 // Bound to the URL so dashboard drill-downs (?status=in_progress) arrive
 // pre-filtered and the browser back button restores the previous view.
 const [filters, setFilters] = useUrlFilters<SeparationListParams>(DEFAULT_FILTERS);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['hr', 'separations', filters],
 queryFn: () => separationsApi.list(filters),
 placeholderData: (prev) => prev });
 const { data: separationOptions } = useQuery({
 queryKey: ['hr', 'clearances', 'options'],
 queryFn: separationsApi.options,
 staleTime: 5 * 60 * 1000 });
 const labels = new Map([
 ...(separationOptions?.statuses ?? []),
 ...(separationOptions?.reasons ?? []),
 ].map((option) => [option.value, option.label]));

 const columns: Column<Clearance>[] = [
 {
 key: 'clearance_no', header: 'Clearance',
 cell: (r) => <span className="font-mono">{r.clearance_no}</span> },
 {
 key: 'employee', header: 'Employee',
 cell: (r) => r.employee
 ? <div><div className="text-sm">{r.employee.full_name}</div><div className="text-xs text-muted font-mono">{r.employee.employee_no}</div></div>
 : <span className="text-muted">—</span> },
 {
 key: 'department', header: 'Department',
 cell: (r) => r.employee?.department?.name ?? <span className="text-muted">—</span> },
 {
 key: 'separation_date', header: 'Separation date', align: 'right',
 cell: (r) => <NumCell>{r.separation_date ?? '—'}</NumCell> },
 { key: 'reason', header: 'Reason', cell: (r) => labels.get(r.separation_reason) ?? r.separation_reason },
 {
 key: 'progress', header: 'Progress', align: 'right',
 cell: (r) => <NumCell>{r.cleared_count}/{r.items_total} ({r.progress_pct}%)</NumCell> },
 {
 key: 'final_pay', header: 'Final pay', align: 'right',
 cell: (r) => r.final_pay_amount ? <NumCell>{formatPeso(r.final_pay_amount)}</NumCell> : <span className="text-muted">—</span> },
 { key: 'status', header: 'Status', cell: (r) => <Chip variant={STATUS_CHIP[r.status]}>{labels.get(r.status) ?? r.status}</Chip> },
 ];

 const filterConfig: FilterConfig[] = [
 {
 key: 'status', label: 'Status', type: 'select',
 options: [
 { value: '', label: 'All' },
 ...(separationOptions?.statuses ?? []),
 ] },
 {
 key: 'separation_reason', label: 'Reason', type: 'select',
 options: [
 { value: '', label: 'All' },
 ...(separationOptions?.reasons ?? []),
 ] },
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
  <DataTable
  tableKey="separations"
  onRowClick={(r) => navigate(`/hr/separations/${r.id}`)} columns={columns} data={data.data} meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))} />
 </div>
 )}
 </div>
 );
}
