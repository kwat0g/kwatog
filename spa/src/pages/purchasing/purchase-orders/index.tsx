import { useQuery } from '@tanstack/react-query';
import { useNavigate} from 'react-router-dom';
import { Plus, Printer } from 'lucide-react';
import { purchaseOrdersApi } from '@/api/purchasing/purchase-orders';
import { bulkPrint } from '@/api/print';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type BulkAction, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import type { ListParams } from '@/types';
import type { PurchaseOrder, PurchaseOrderStatus } from '@/types/purchasing';

const variant: Record<PurchaseOrderStatus, 'neutral' | 'info' | 'warning' | 'success' | 'danger'> = {
  draft: 'neutral', pending_approval: 'info', approved: 'success', sent: 'info',
  partially_received: 'warning', received: 'success', closed: 'neutral', cancelled: 'danger' };

interface PurchaseOrderListParams extends ListParams {
  status?: string;
  vendor_id?: string;
  requires_vp_approval?: boolean | string;
  overdue?: boolean | string;
  from?: string;
  to?: string;
}

const DEFAULT_FILTERS: PurchaseOrderListParams = {
  page: 1, per_page: 25, status: 'pending_approval',
};

export default function PurchaseOrdersListPage() {
  const navigate = useNavigate();
  const { can } = usePermission();
  const [filters, setFilters] = useUrlFilters<PurchaseOrderListParams>(DEFAULT_FILTERS);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['purchasing', 'purchase-orders', filters],
 queryFn: ({ signal }) => purchaseOrdersApi.list(filters, signal),
 placeholderData: (prev) => prev });
 const { data: orderOptions } = useQuery({
 queryKey: ['purchasing', 'purchase-orders', 'options'],
 queryFn: purchaseOrdersApi.options,
 staleTime: 5 * 60 * 1000 });
 const statusLabels = new Map((orderOptions?.statuses ?? []).map((option) => [option.value, option.label]));
 const statusLabel = (value: string) => statusLabels.get(value) ?? value.replaceAll('_', ' ');
 const totalValue = data?.data.some((order) => order.total_amount != null)
  ? formatPeso(data.data.reduce((sum, order) => sum + Number(order.total_amount ?? 0), 0))
  : '—';

 const columns: Column<PurchaseOrder>[] = [
 { key: 'po', header: 'PO #', cell: (r) => (
 <span className="flex items-center gap-2">
 <span className="font-mono">{r.po_number}</span>
 {r.is_auto_generated && (
 <span title="Auto-generated for critical stock"><Chip variant="info">Auto</Chip></span>
 )}
 </span>
 ) },
 { key: 'date', header: 'Date', cell: (r) => <span className="font-mono">{formatDate(r.date)}</span> },
 { key: 'vendor', header: 'Vendor', cell: (r) => r.vendor?.name ?? '—' },
 { key: 'eta', header: 'Expected', cell: (r) => (
 <span className={'font-mono ' + (r.expected_delivery_date && new Date(r.expected_delivery_date) < new Date() && r.status !== 'received' ? 'text-danger-fg' : '')}>
 {r.expected_delivery_date ? formatDate(r.expected_delivery_date) : '—'}
 </span>
 ) },
 { key: 'total', header: 'Total', align: 'right', cell: (r) => <NumCell className="font-medium">{formatPeso(r.total_amount)}</NumCell> },
 { key: 'status', header: 'Status', cell: (r) => (
 <span className="flex items-center gap-1.5">
 <Chip variant={variant[r.status]}>{statusLabels.get(r.status) ?? r.status.replace(/_/g, ' ')}</Chip>
 {r.has_overdue_approval && (
 <span title={`Approval pending beyond ${orderOptions?.approval_sla_hours ?? 'configured'} hours`}><Chip variant="danger">overdue</Chip></span>
 )}
 </span>
 ) },
 { key: 'rcv', header: 'Received', align: 'right', cell: (r) => <NumCell>{r.quantity_received_pct.toFixed(0)}%</NumCell> },
 ];

 const filterConfig: FilterConfig[] = [
 { key: 'status', label: 'Status', type: 'select', options: [
 { value: '', label: 'All' },
 ...(orderOptions?.statuses ?? []),
 ]},
 { key: 'requires_vp_approval', label: 'VP threshold', type: 'select', options: [
 { value: '', label: 'All' }, { value: 'true', label: 'Yes' }, { value: 'false', label: 'No' },
 ]},
 ];

 return (
 <div>
 <PageHeader title="Purchase orders" subtitle={data ? `${data.meta.total} POs` : undefined}
 actions={can('purchasing.po.create') ? (
 <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/purchasing/purchase-orders/create')}>New PO</Button>
 ) : null} />
 <FilterBar filters={filterConfig} values={filters}
 onSearch={(s) => setFilters(f => ({ ...f, search: s, page: 1 }))}
 onFilter={(k, v) => setFilters(f => ({ ...f, [k]: v, page: 1 }))}
 searchPlaceholder="Search PO number…" />
 {isLoading && !data && <SkeletonTable columns={7} rows={6} />}
 {isError && <EmptyState icon="alert-circle" title="Failed to load POs" action={<Button onClick={() => refetch()}>Retry</Button>} />}
 {data && (
  <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 px-5 py-4 border-b border-default bg-canvas">
  <StatCard
    label={statusLabel('draft')}
    value={data.data.filter(i => i.status === 'draft').length}
    helper="in current view"
    linkTo="?status=draft"
  />
  <StatCard
    label={statusLabel('pending_approval')}
    value={data.data.filter(i => i.status === 'pending_approval').length}
    helper="in current view"
    linkTo="?status=pending_approval"
  />
  <StatCard
    label={statusLabel('approved')}
    value={data.data.filter(i => i.status === 'approved').length}
    helper="in current view"
    linkTo="?status=approved"
  />
  <StatCard
    label="Total Value"
    value={totalValue}
    helper="in current view"
  />
  </div>
  )}

{data && data.data.length === 0 && (
 <EmptyState icon="inbox" title="No purchase orders"
 action={can('purchasing.po.create') ? <Button variant="primary" onClick={() => navigate('/purchasing/purchase-orders/create')}>New PO</Button> : undefined} />
 )}

 {data && data.data.length > 0 && (
 <div className="px-5 py-4">
  <DataTable
  tableKey="purchase-orders"
  onRowClick={(r) => navigate(`/purchasing/purchase-orders/${r.id}`)}
 columns={columns}
 data={data.data}
 meta={data.meta}
 onPageChange={(page) => setFilters(f => ({ ...f, page }))}
 selectable
 bulkActions={[
 {
 label: 'Print PDFs',
 icon: <Printer size={14} />,
 onClick: (rows: PurchaseOrder[]) => bulkPrint('purchase_order', rows.map((r) => r.id)) } as BulkAction<PurchaseOrder>,
 ]}
 />
 </div>
 )}
 </div>
 );
}
