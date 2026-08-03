/** Sprint 8 — Task 69. Maintenance work-orders list. */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate} from 'react-router-dom';
import { Plus, Smartphone } from 'lucide-react';
import { workOrdersApi, type WorkOrderListParams } from '@/api/maintenance/workOrders';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import {
  maintenancePriorityVariant as PRIORITY_CHIP,
  maintenanceStatusVariant as STATUS_CHIP } from '@/lib/statusVariants';
import type { MaintenanceWorkOrder } from '@/types/maintenance';
import { formatPeso } from '@/lib/formatNumber';

export default function MaintenanceWorkOrdersListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useState<WorkOrderListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['maintenance', 'work-orders', filters],
    queryFn: () => workOrdersApi.list(filters),
    placeholderData: (prev) => prev });
  const { data: options } = useQuery({
    queryKey: ['maintenance', 'work-order-options'],
    queryFn: () => workOrdersApi.options() });

  const columns: Column<MaintenanceWorkOrder>[] = [
    {
      key: 'mwo_number',
      header: 'WO',
      cell: (r) => (
        <span className="font-mono text-accent">
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
      header: 'Cost',
      align: 'right',
      cell: (r) => <NumCell>{formatPeso(r.cost ?? '0.00')}</NumCell> },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => <Chip variant={STATUS_CHIP[r.status]}>{r.status_label ?? r.status.replace('_', ' ')}</Chip> },
  ];

  const filterConfig: FilterConfig[] = [
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
        title="Maintenance work orders"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'order' : 'orders'}` : undefined}
        actions={
          <div className="flex items-center gap-2">
            <Button variant="secondary" size="sm" icon={<Smartphone size={14} />} onClick={() => navigate('/maintenance/mobile')}>
              Mobile view
            </Button>
            {can('maintenance.wo.create') && (
              <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/maintenance/work-orders/create')}>
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
      />
      {isLoading && !data && <SkeletonTable columns={7} rows={6} />}
      {isError && (
        <EmptyState icon="alert-circle" title="Failed to load work orders"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
      )}
      {data && data.data.length === 0 && (
        <EmptyState icon="wrench" title="No maintenance work orders"
          description={filters.search ? `No results for "${filters.search}".` : 'New preventive WOs are auto-created from due schedules. Corrective WOs can be filed manually.'}
          action={can('maintenance.wo.create') ? (
            <Button variant="primary" onClick={() => navigate('/maintenance/work-orders/create')}>New work order</Button>
          ) : undefined}
        />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <DataTable
            onRowClick={(r) => navigate(`/maintenance/work-orders/${r.id}`)}
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
