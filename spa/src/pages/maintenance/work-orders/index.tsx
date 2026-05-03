/** Sprint 8 — Task 69. Maintenance work-orders list. */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import { workOrdersApi, type WorkOrderListParams } from '@/api/maintenance/workOrders';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import type { MaintenancePriority, MaintenanceWorkOrder, MaintenanceWorkOrderStatus } from '@/types/maintenance';

const STATUS_CHIP: Record<MaintenanceWorkOrderStatus, 'success' | 'danger' | 'warning' | 'info' | 'neutral'> = {
  open:        'warning',
  assigned:    'info',
  in_progress: 'info',
  completed:   'success',
  cancelled:   'neutral',
};

const PRIORITY_CHIP: Record<MaintenancePriority, 'success' | 'danger' | 'warning' | 'info' | 'neutral'> = {
  critical: 'danger',
  high:     'warning',
  medium:   'info',
  low:      'neutral',
};

export default function MaintenanceWorkOrdersListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useState<WorkOrderListParams>({ page: 1, per_page: 25 });

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['maintenance', 'work-orders', filters],
    queryFn: () => workOrdersApi.list(filters),
    placeholderData: (prev) => prev,
  });

  const columns: Column<MaintenanceWorkOrder>[] = [
    {
      key: 'mwo_number',
      header: 'WO',
      cell: (r) => (
        <Link to={`/maintenance/work-orders/${r.id}`} className="font-mono text-accent hover:underline">
          {r.mwo_number}
        </Link>
      ),
    },
    {
      key: 'target',
      header: 'Target',
      cell: (r) => r.maintainable
        ? <span><span className="font-mono">{r.maintainable.code ?? '—'}</span><span className="ml-2 text-muted">{r.maintainable.name}</span></span>
        : <span className="text-muted">—</span>,
    },
    {
      key: 'type',
      header: 'Type',
      cell: (r) => <Chip variant={r.type === 'preventive' ? 'info' : 'warning'}>{r.type}</Chip>,
    },
    {
      key: 'priority',
      header: 'Priority',
      cell: (r) => <Chip variant={PRIORITY_CHIP[r.priority]}>{r.priority}</Chip>,
    },
    {
      key: 'assignee',
      header: 'Assigned to',
      cell: (r) => r.assignee?.name ?? <span className="text-muted">—</span>,
    },
    {
      key: 'cost',
      header: 'Cost',
      align: 'right',
      cell: (r) => <NumCell>₱{r.cost ?? '0.00'}</NumCell>,
    },
    {
      key: 'status',
      header: 'Status',
      cell: (r) => <Chip variant={STATUS_CHIP[r.status]}>{r.status.replace('_', ' ')}</Chip>,
    },
  ];

  const filterConfig: FilterConfig[] = [
    {
      key: 'status', label: 'Status', type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'open', label: 'Open' },
        { value: 'assigned', label: 'Assigned' },
        { value: 'in_progress', label: 'In progress' },
        { value: 'completed', label: 'Completed' },
        { value: 'cancelled', label: 'Cancelled' },
      ],
    },
    {
      key: 'type', label: 'Type', type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'preventive', label: 'Preventive' },
        { value: 'corrective', label: 'Corrective' },
      ],
    },
    {
      key: 'priority', label: 'Priority', type: 'select',
      options: [
        { value: '', label: 'All' },
        { value: 'critical', label: 'Critical' },
        { value: 'high', label: 'High' },
        { value: 'medium', label: 'Medium' },
        { value: 'low', label: 'Low' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Maintenance work orders"
        subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'order' : 'orders'}` : undefined}
        actions={
          can('maintenance.wo.create') ? (
            <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/maintenance/work-orders/create')}>
              New work order
            </Button>
          ) : undefined
        }
      />
      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search by WO number or description…"
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
