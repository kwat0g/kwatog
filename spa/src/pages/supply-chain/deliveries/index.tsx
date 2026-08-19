/** Sprint 7 — Task 67 — Deliveries list (outbound). */
import { useQuery } from '@tanstack/react-query';
import { useNavigate} from 'react-router-dom';
import { LuPlus } from '@/lib/icons';
import { deliveriesApi, type DeliveryListParams } from '@/api/supply-chain';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { deliveryStatusVariant } from '@/lib/statusVariants';
import type { Delivery } from '@/types/supplyChain';

import { ListEmptyState } from '@/components/ui/ListEmptyState';
const DEFAULT_FILTERS: DeliveryListParams = {
 page: 1, per_page: 25, status: 'scheduled',
};

export default function DeliveriesListPage() {
 // Bound to the URL so dashboard drill-downs (?status=scheduled) arrive
 // pre-filtered and the browser back button restores the previous view.
 const [filters, setFilters] = useUrlFilters<DeliveryListParams>(DEFAULT_FILTERS);
 const navigate = useNavigate();
 const { can } = usePermission();
 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['supply-chain', 'deliveries', filters],
 queryFn: () => deliveriesApi.list(filters),
 placeholderData: (prev) => prev });
 const { data: options } = useQuery({
 queryKey: ['supply-chain', 'delivery-options'],
 queryFn: deliveriesApi.options,
 staleTime: 5 * 60 * 1000 });
 const statusLabel = new Map((options?.statuses ?? []).map((status) => [status.value, status.label]));

 const columns: Column<Delivery>[] = [
 { key: 'delivery_number', header: 'Delivery',
 cell: (r) => <span className="font-mono">{r.delivery_number}</span> },
 { key: 'so', header: 'Sales Order',
 cell: (r) => r.sales_order ? <span className="font-mono">{r.sales_order.so_number}</span> : <span className="text-muted">—</span> },
 { key: 'vehicle', header: 'Vehicle', cell: (r) => r.vehicle ? `${r.vehicle.name} (${r.vehicle.plate_number})` : '—' },
 { key: 'driver', header: 'Driver', cell: (r) => r.driver?.name ?? '—' },
 { key: 'scheduled', header: 'Scheduled', align: 'right',
 cell: (r) => <NumCell>{r.scheduled_date ?? '—'}</NumCell> },
 { key: 'status', header: 'Status',
 cell: (r) => <Chip variant={deliveryStatusVariant[r.status]}>{statusLabel.get(r.status) ?? r.status_label ?? r.status}</Chip> },
 ];

 const filterConfig: FilterConfig[] = [
 { key: 'status', label: 'Status', type: 'select', options: [
 { value: '', label: 'All' },
 ...(options?.statuses ?? []),
 ] },
 ];

 return (
 <div>
 <PageHeader title="Outbound deliveries"
 subtitle={data ? `${data.meta.total} ${data.meta.total === 1 ? 'delivery' : 'deliveries'}` : undefined}
 actions={
 can('supply_chain.deliveries.create') ? (
 <Button
 variant="primary"
 size="sm"
 icon={<LuPlus size={14} />}
 onClick={() => navigate('/supply-chain/deliveries/create')}
 >
 New delivery
 </Button>
 ) : undefined
 }
 />
 <FilterBar
 filters={filterConfig}
 values={filters}
 onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
 onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
 searchPlaceholder="Search delivery number…"
 />
 {isLoading && !data && <SkeletonTable columns={6} rows={6} />}
 {isError && <EmptyState icon="alert-circle" title="Failed to load deliveries"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
 {data && data.data.length === 0 && (
 <ListEmptyState />
 )}
 {data && data.data.length > 0 && (
  <div className="px-5 py-4">
  <DataTable
  tableKey="deliveries"
  onRowClick={(r) => navigate(`/supply-chain/deliveries/${r.id}`)} columns={columns} data={data.data} meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))} />
 </div>
 )}
 </div>
 );
}
