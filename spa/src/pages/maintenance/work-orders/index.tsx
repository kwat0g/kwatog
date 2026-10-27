/** Sprint 8 — Task 69. Maintenance work-orders list. */
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { LuPlus } from '@/lib/icons';
import { workOrdersApi, type WorkOrderListParams } from '@/api/maintenance/workOrders';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { DataTablePagination } from '@/components/ui/DataTablePagination';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useEcho } from '@/hooks/useEcho';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { useAuthStore } from '@/stores/authStore';
import {
 maintenancePriorityVariant as PRIORITY_CHIP,
 maintenanceStatusVariant as STATUS_CHIP } from '@/lib/statusVariants';
import type { MaintenanceWorkOrder } from '@/types/maintenance';
import { formatPeso } from '@/lib/formatNumber';
import { focusRing } from '@/lib/focus';
import { cn } from '@/lib/cn';

import { ListEmptyState } from '@/components/ui/ListEmptyState';
const DEFAULT_FILTERS: WorkOrderListParams = {
  page: 1, per_page: 25, status: '',
};

export default function MaintenanceWorkOrdersListPage() {
 const navigate = useNavigate();
 const selfService = useLocation().pathname.startsWith('/self-service/');
 const orderPath = selfService ? '/self-service/maintenance-work-orders' : '/maintenance/work-orders';
 const { can } = usePermission();
 const employeeId = useAuthStore((s) => s.user?.employee?.id);
 const qc = useQueryClient();
 // Bound to the URL so dashboard drill-downs (?status=in_progress) arrive
 // pre-filtered and the browser back button restores the previous view.
 const [filters, setFilters] = useUrlFilters<WorkOrderListParams>(DEFAULT_FILTERS);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['maintenance', 'work-orders', filters],
 queryFn: () => workOrdersApi.list(filters),
 refetchInterval: 60_000,
 placeholderData: (prev) => prev });

 useEcho(can('maintenance.view') ? 'maintenance.dashboard' : '', '.maintenance.wo_created', () => {
  qc.invalidateQueries({ queryKey: ['maintenance', 'work-orders'] });
 });
 const { data: options } = useQuery({
 queryKey: ['maintenance', 'work-order-options'],
 queryFn: () => workOrdersApi.options() });
 const typeLabels = new Map((options?.types ?? []).map((type) => [type.value, type.label]));
 const priorityLabels = new Map((options?.priorities ?? []).map((priority) => [priority.value, priority.label]));
 const pagePartsCost = data?.data?.some((workOrder) => workOrder.cost != null)
  ? formatPeso(data.data.reduce((sum, workOrder) => sum + Number(workOrder.cost ?? 0), 0))
  : '—';

 const columns: Column<MaintenanceWorkOrder>[] = [
 {
 key: 'mwo_number',
 header: 'WO',
 cell: (r) => (
 <span className="font-mono">
 {r.mwo_number}
 </span>
 ) },
 {
 key: 'target',
 header: 'Target',
 cell: (r) => r.maintainable
 ? <span><span className="font-mono">{r.maintainable.code ?? '—'}</span><span className="ml-2 text-muted">{r.maintainable.name}</span></span>
 : <span className="text-muted">—</span> },
 {
 key: 'type',
 header: 'Type',
 cell: (r) => <Chip variant={r.type === 'preventive' ? 'info' : 'warning'}>{r.type_label ?? r.type}</Chip> },
 {
 key: 'priority',
 header: 'Priority',
 cell: (r) => <Chip variant={PRIORITY_CHIP[r.priority]}>{r.priority_label ?? r.priority}</Chip> },
 {
 key: 'assignee',
 header: 'Assigned to',
 cell: (r) => r.assignee?.name ?? <span className="text-muted">—</span> },
 {
 key: 'cost',
 header: 'Parts cost',
 align: 'right',
 cell: (r) => <NumCell>{formatPeso(r.cost)}</NumCell> },
 {
 key: 'status',
 header: 'Status',
 cell: (r) => <Chip variant={STATUS_CHIP[r.status]}>{r.status_label ?? r.status.replace('_', ' ')}</Chip> },
 ];

 const filterConfig: FilterConfig[] = [
 ...(can('maintenance.wo.assign') ? [{
 key: 'queue', label: 'Queue', type: 'select' as const,
 options: [{ value: '', label: 'All work' }, { value: 'open', label: 'Open' }, { value: 'unassigned', label: 'Unassigned' }, { value: 'critical', label: 'Critical' }, { value: 'blocked_by_production', label: 'Blocked by production' }, { value: 'awaiting_verification', label: 'Awaiting verification' }, { value: 'in_progress', label: 'In progress' }, { value: 'overdue_preventive', label: 'Overdue preventive' }],
 }] : []),
 ...(employeeId ? [{
 key: 'assigned_to', label: 'Assignment', type: 'select' as const,
 options: [{ value: '', label: 'All orders' }, { value: employeeId, label: 'My assigned' }],
 }] : []),
 {
 key: 'status', label: 'Status', type: 'select',
 options: [
 { value: '', label: 'All' },
 ...(options?.statuses ?? []).map((status) => ({ value: status.value, label: status.label })),
 ] },
 {
 key: 'type', label: 'Type', type: 'select',
 options: [
 { value: '', label: 'All' },
 ...(options?.types ?? []).map((type) => ({ value: type.value, label: type.label })),
 ] },
 {
 key: 'priority', label: 'Priority', type: 'select',
 options: [
 { value: '', label: 'All' },
 ...(options?.priorities ?? []).map((priority) => ({ value: priority.value, label: priority.label })),
 ] },
 ];

 return (
 <div>
 <PageHeader
 title={selfService ? "My maintenance work orders" : "Maintenance work orders"}
 subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'order' : 'orders'}` : undefined}
 actions={
 <div className="flex items-center gap-2">
 {can('maintenance.wo.create') && (
 <Button variant="primary" size="sm" className="min-h-11 md:min-h-0" icon={<LuPlus size={14} />} onClick={() => navigate('/maintenance/work-orders/create')}>
 New work order
 </Button>
 )}
 </div>
 }
 />
 <FilterBar
 filters={filterConfig}
 values={filters}
 onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
 onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
 searchPlaceholder="Search WO number or description…"
 className="[&_form]:min-h-11 [&_select]:min-h-11 [&_button]:min-h-11 md:[&_form]:min-h-0 md:[&_select]:min-h-0 md:[&_button]:min-h-0"
 />
 {isLoading && !data && <SkeletonTable columns={7} rows={6} />}
 {isError && (
 <EmptyState icon="alert-circle" title="Failed to load work orders"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
 )}
 {data && (
   <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 px-5 py-4 border-b border-default bg-canvas">
   <StatCard
     label={typeLabels.get('preventive') ?? '—'}
     value={data.data.filter(w => w.type === 'preventive').length}
     helper="on this page"
     linkTo="?type=preventive"
   />
   <StatCard
     label={typeLabels.get('corrective') ?? '—'}
     value={data.data.filter(w => w.type === 'corrective').length}
     helper="on this page"
     linkTo="?type=corrective"
   />
   <StatCard
     label={`${priorityLabels.get('critical') ?? 'Critical'} priority`}
     value={data.data.filter(w => w.priority === 'critical').length}
     helper="on this page"
     linkTo="?queue=critical"
     className="border-danger/30 bg-danger-bg/20"
   />
   <StatCard
     label="Parts cost on this page"
     value={pagePartsCost}
     helper=""
   />
   </div>
  )}

{data && data.data.length === 0 && (
 <ListEmptyState searchTerm={filters.search as string | undefined} />
 )}


  {data && data.data.length > 0 && (
   <div className="hidden px-5 py-4 md:block">
  <DataTable
  tableKey="maintenance-wo"
  onRowClick={(r) => navigate(`${orderPath}/${r.id}`)}
 columns={columns}
 data={data.data}
 meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
 />
 </div>
 )}
 {data && data.data.length > 0 && (
  <div className="space-y-3 p-4 md:hidden">
   {data.data.map((wo) => (
    <Link key={wo.id} to={`${orderPath}/${wo.id}`}
      className={cn('block rounded-md border border-default bg-canvas p-4 active:bg-subtle', focusRing)}>
      <div className="flex items-start justify-between gap-2">
       <span className="font-mono text-sm font-medium tabular-nums text-link">{wo.mwo_number}</span>
       <Chip variant={PRIORITY_CHIP[wo.priority]}>{wo.priority_label ?? wo.priority}</Chip>
      </div>
      <div className="mt-2 text-sm font-medium">{wo.maintainable?.name ?? '—'}</div>
      <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted">
       <span>{wo.type_label ?? wo.type}</span>
       <Chip variant={STATUS_CHIP[wo.status]}>{wo.status_label ?? wo.status.replace('_', ' ')}</Chip>
      </div>
      <div className="mt-3 flex items-center justify-between gap-2 text-xs text-muted">
       <span>{wo.assignee ? `Assigned: ${wo.assignee.name}` : 'Unassigned'}</span>
       <span className="font-mono tabular-nums">{formatPeso(wo.cost)}</span>
      </div>
    </Link>
   ))}
   <div className="[&_button]:min-h-11 [&_button]:min-w-11">
    <DataTablePagination meta={data.meta} onPageChange={(page) => setFilters((f) => ({ ...f, page }))} />
   </div>
  </div>
 )}
 </div>
 );
}
