import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LuPlus } from '@/lib/icons';
import toast from 'react-hot-toast';
import { stockAdjustmentsApi, type StockAdjustmentListParams } from '@/api/inventory/stock';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatDateTime } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import type { StockAdjustment } from '@/types/inventory';

const STATUS_VARIANT: Record<string, 'warning' | 'success' | 'neutral'> = {
 pending: 'warning',
 approved: 'success',
};

const DEFAULT_FILTERS: StockAdjustmentListParams = {
 page: 1, per_page: 50, status: 'pending',
};

export default function StockAdjustmentsPage() {
 const qc = useQueryClient();
 const { can } = usePermission();
 const [filters, setFilters] = useUrlFilters<StockAdjustmentListParams>(DEFAULT_FILTERS);
 const [approveTarget, setApproveTarget] = useState<StockAdjustment | null>(null);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['inventory', 'stock-adjustments', filters],
 queryFn: () => stockAdjustmentsApi.list(filters),
 placeholderData: (prev) => prev,
 });
 const { data: options } = useQuery({
  queryKey: ['inventory', 'stock-adjustments', 'options'],
  queryFn: stockAdjustmentsApi.options,
  staleTime: 300_000,
 });
 const filterConfig: FilterConfig[] = [{
  key: 'status', type: 'select', label: 'Status', options: [
   { value: '', label: 'All' },
   ...(options?.statuses ?? []),
  ],
 }];
 const directionLabels = new Map((options?.directions ?? []).map((option) => [option.value, option.label]));
 const statusLabels = new Map((options?.statuses ?? []).map((option) => [option.value, option.label]));

 const approve = useMutation({
 mutationFn: (id: string) => stockAdjustmentsApi.approve(id),
 onSuccess: () => {
 toast.success('Adjustment approved — stock movement posted.');
 setApproveTarget(null);
 qc.invalidateQueries({ queryKey: ['inventory', 'stock-adjustments'] });
 qc.invalidateQueries({ queryKey: ['inventory', 'stock-levels'] });
 },
 onError: () => toast.error('Failed to approve adjustment.'),
 });

 const columns: Column<StockAdjustment>[] = [
 { key: 'created_at', header: 'Requested', cell: (r) => <span className="font-mono">{formatDateTime(r.created_at)}</span> },
 { key: 'direction', header: 'Dir', cell: (r) => (
 <Chip variant={r.direction === 'in' ? 'success' : 'danger'}>{directionLabels.get(r.direction) ?? r.direction}</Chip>
 ) },
 { key: 'item', header: 'Item', cell: (r) => (
 <div>
 <span className="font-mono">{r.item?.code}</span>
 <div className="text-xs text-muted">{r.item?.name}</div>
 </div>
 ) },
 { key: 'location', header: 'Location', cell: (r) => <span className="font-mono">{r.location?.code ?? '—'}</span> },
 { key: 'qty', header: 'Qty', align: 'right', cell: (r) => <NumCell>{Number(r.quantity).toFixed(3)}</NumCell> },
 { key: 'value', header: 'Value', align: 'right', cell: (r) => <NumCell>{formatPeso(r.value)}</NumCell> },
 { key: 'reason', header: 'Reason', cell: (r) => (
 <span className="block max-w-[260px] truncate text-muted" title={r.reason}>
 {r.reason}
 </span>
 ) },
 { key: 'status', header: 'Status', cell: (r) => (
 <Chip variant={STATUS_VARIANT[r.status] ?? 'neutral'}>{r.status_label ?? statusLabels.get(r.status) ?? r.status}</Chip>
 ) },
 ...(can('inventory.adjust.approve')
 ? [{ key: 'actions', header: '', cell: (r: StockAdjustment) =>
 r.status === 'pending' ? (
 <Button size="xs" variant="primary" onClick={() => setApproveTarget(r)}>Approve</Button>
 ) : (
 <span className="text-xs text-muted">{r.approved_by?.name ? `by ${r.approved_by.name}` : ''}</span>
 ),
 }]
 : []),
 ];

 return (
 <div>
 <PageHeader
 title="Stock Adjustments"
 subtitle="Manual in/out adjustments with finance approval for high-value entries"
 actions={<Link to="/inventory/stock-adjustments/create"><Button size="xs" icon={<LuPlus size={14} />}>New adjustment</Button></Link>}
 />
 <FilterBar
 filters={filterConfig}
 values={filters}
 onFilter={(k, v) => setFilters((f) => ({ ...f, [k]: v, page: 1 }))}
 onSearch={(v) => setFilters((f) => ({ ...f, search: v || undefined, page: 1 }))}
 searchPlaceholder="Search item, reason…"
 />

 {isLoading && <SkeletonTable columns={7} rows={8} />}
 {isError && (
 <EmptyState
 icon="alert-circle"
 title="Failed to load adjustments"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
 />
 )}
 {!isLoading && !isError && (data?.data.length ?? 0) === 0 && (
 <EmptyState
 icon="inbox"
 title="No adjustments found"
 description={filters.status ? 'Try clearing the status filter.' : 'Record an adjustment when stock needs a manual correction.'}
 />
 )}
 {!isLoading && !isError && (data?.data.length ?? 0) > 0 && (
 <DataTable
 columns={columns}
 data={data!.data}
 meta={data!.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 tableKey="stock-adjustments"
 />
 )}

 <ConfirmDialog
 isOpen={approveTarget !== null}
 onClose={() => setApproveTarget(null)}
 title="Approve adjustment?"
 description={`Post a ${approveTarget?.direction === 'in' ? 'receipt' : 'issue'} of ${Number(approveTarget?.quantity ?? 0).toFixed(3)} for ${approveTarget?.item?.code ?? 'this item'}? The stock movement is posted immediately.`}
 confirmLabel="Approve"
 pending={approve.isPending}
 onConfirm={() => { if (approveTarget) approve.mutate(approveTarget.id); }}
 />
 </div>
 );
}
