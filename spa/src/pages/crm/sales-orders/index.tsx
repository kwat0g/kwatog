import { useQuery } from '@tanstack/react-query';
import { useNavigate} from 'react-router-dom';
import { LuPlus } from '@/lib/icons';
import { salesOrdersApi, type SalesOrderListParams } from '@/api/crm/salesOrders';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatPeso } from '@/lib/formatNumber';
import type { SalesOrder, SalesOrderStatus } from '@/types/crm';

import { ListEmptyState } from '@/components/ui/ListEmptyState';
const statusVariant: Record<SalesOrderStatus, 'success' | 'info' | 'warning' | 'neutral' | 'danger'> = {
 draft: 'neutral',
 confirmed: 'info',
 in_production: 'info',
 partially_delivered: 'warning',
 delivered: 'success',
 invoiced: 'success',
 cancelled: 'danger' };

const DEFAULT_FILTERS: SalesOrderListParams = {
 page: 1, per_page: 25, status: 'confirmed',
};

export default function SalesOrdersListPage() {
 const navigate = useNavigate();
 const { can } = usePermission();
 const canCreate = can('crm.sales_orders.create');
 // Bound to the URL so dashboard drill-downs (?status=confirmed) arrive
 // pre-filtered and the browser back button restores the previous view.
 const [filters, setFilters] = useUrlFilters<SalesOrderListParams>(DEFAULT_FILTERS);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['crm', 'sales-orders', filters],
 queryFn: () => salesOrdersApi.list(filters),
 placeholderData: (prev) => prev });
 const { data: salesOrderOptions } = useQuery({
 queryKey: ['crm', 'sales-orders', 'options'],
 queryFn: salesOrdersApi.options,
 staleTime: 5 * 60 * 1000 });
 const statusLabels = new Map((salesOrderOptions?.statuses ?? []).map((option) => [option.value, option.label]));
 const statusLabel = (value: string) => statusLabels.get(value) ?? value.replaceAll('_', ' ');
 const totalValue = data?.data.some((order) => order.total_amount != null)
  ? formatPeso(data.data.reduce((sum, order) => sum + Number(order.total_amount ?? 0), 0))
  : '—';

 const columns: Column<SalesOrder>[] = [
 {
 key: 'so_number', header: 'SO #',
 cell: (r) => (
 <span className="font-mono">{r.so_number}</span>
 ) },
 { key: 'customer', header: 'Customer', cell: (r) => r.customer?.name ?? '—' },
 { key: 'date', header: 'Date', align: 'right', cell: (r) => <NumCell>{r.date}</NumCell> },
 { key: 'items', header: 'Lines', align: 'right', cell: (r) => <NumCell>{r.item_count}</NumCell> },
 {
 key: 'total', header: 'Total', align: 'right',
 cell: (r) => <NumCell>{formatPeso(r.total_amount)}</NumCell> },
 {
 key: 'status', header: 'Status',
 cell: (r) => <Chip variant={statusVariant[r.status]}>{statusLabels.get(r.status) ?? r.status_label}</Chip> },
 ];

 const filterConfig: FilterConfig[] = [
 { key: 'status', label: 'Status', type: 'select', options: [
 { value: '', label: 'All' },
 ...(salesOrderOptions?.statuses ?? []),
 ]},
 ];

 return (
 <div>
 <PageHeader
 title="Sales orders"
 subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'order' : 'orders'}` : undefined}
 actions={canCreate ? (
 <Button variant="primary" size="sm" icon={<LuPlus size={14} />} onClick={() => navigate('/crm/sales-orders/create')}>
 New sales order
 </Button>
 ) : null}
 />
 <FilterBar
 filters={filterConfig}
 values={filters}
 onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
 onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
 searchPlaceholder="Search SO number or customer…"
 />
 {isLoading && !data && <SkeletonTable columns={6} rows={8} />}
 {isError && (
 <EmptyState
 icon="alert-circle"
 title="Failed to load sales orders"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
 />
 )}
 {data && (
  <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 px-5 py-4 border-b border-default bg-canvas">
  <StatCard
    label={statusLabel('draft')}
    value={data.data.filter(i => i.status === 'draft').length}
    helper="in current view"
    linkTo="?status=draft"
  />
  <StatCard
    label={statusLabel('confirmed')}
    value={data.data.filter(i => i.status === 'confirmed').length}
    helper="in current view"
    linkTo="?status=confirmed"
  />
  <StatCard
    label={statusLabel('in_production')}
    value={data.data.filter(i => i.status === 'in_production').length}
    helper="in current view"
    linkTo="?status=in_production"
  />
  <StatCard
    label="Total Value"
    value={totalValue}
    helper="in current view"
  />
  </div>
  )}

{data && data.data.length === 0 && (
 <ListEmptyState />
 )}

 {data && data.data.length > 0 && (
  <div className="px-5 py-4">
  <DataTable
  tableKey="sales-orders"
  onRowClick={(r) => navigate(`/crm/sales-orders/${r.id}`)}
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
