import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LuPlus } from '@/lib/icons';
import toast from 'react-hot-toast';
import { isAxiosError } from 'axios';
import { stockAdjustmentsApi, type StockAdjustmentListParams } from '@/api/inventory/stock';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { ReasonDialog } from '@/components/ui/ReasonDialog';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { PageShell } from '@/components/layout/PageShell';
import { usePermission } from '@/hooks/usePermission';
import { useRealtimeList } from '@/hooks/useRealtimeRecord';
import { useQueryRecordTarget } from '@/hooks/useQueryRecordTarget';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { sameQueryExceptPage } from '@/lib/queryKeys/pageScope';
import { formatDateTime } from '@/lib/formatDate';
import { formatPeso, formatQuantity } from '@/lib/formatNumber';
import { reportMutationError } from '@/lib/formErrors';
import type { StockAdjustment } from '@/types/inventory';
import type { PaginatedResponse } from '@/types';
import { invalidateRealtimeChange } from '@/lib/realtime/invalidationMap';

const STATUS_VARIANT: Record<string, 'warning' | 'success' | 'danger' | 'neutral'> = {
 pending: 'warning',
 approved: 'success',
 rejected: 'danger',
 withdrawn: 'neutral',
};

const DEFAULT_FILTERS: StockAdjustmentListParams = {
 page: 1, per_page: 50, status: '', id: '',
};

function statusDetail(row: StockAdjustment): string {
 if (row.status === 'pending') return 'Awaiting Finance approval';
 if (row.status === 'approved') {
  return `Approved by ${row.approved_by?.name ?? 'Approver unavailable'}${row.approved_at ? ` · ${formatDateTime(row.approved_at)}` : ''}`;
 }
 return `${row.status === 'rejected' ? 'Rejected' : 'Withdrawn'} by ${row.resolved_by?.name ?? 'Actor unavailable'}${row.resolved_at ? ` · ${formatDateTime(row.resolved_at)}` : ''}${row.resolution_reason ? ` · ${row.resolution_reason}` : ''}`;
}

export default function StockAdjustmentsPage() {
 const qc = useQueryClient();
 const { can } = usePermission();
 useRealtimeList('stock_adjustment', can('inventory.adjust') || can('inventory.adjust.approve') ? 'visible' : undefined);
 const [filters, setFilters] = useUrlFilters<StockAdjustmentListParams>(DEFAULT_FILTERS);
 const handleMutationError = (error: unknown, fallback: string, closeAction: () => void) => {
  if (isAxiosError(error) && error.response?.status === 409) {
   closeAction();
  }
  void refetch();
  reportMutationError(error, fallback);
 };

 const queryKey = ['inventory', 'stock-adjustments', filters] as const;
 const { data, isLoading, isFetching, isError, refetch } = useQuery({
 queryKey,
 queryFn: () => stockAdjustmentsApi.list(filters),
 placeholderData: (previous, previousQuery) =>
   sameQueryExceptPage(previousQuery?.queryKey, queryKey, 2) ? previous : undefined,
 });
 const adjustmentRows = data?.data;
 const approveActionTarget = useQueryRecordTarget(adjustmentRows, { isFetching, isError });
 const rejectActionTarget = useQueryRecordTarget(adjustmentRows, { isFetching, isError });
 const withdrawActionTarget = useQueryRecordTarget(adjustmentRows, { isFetching, isError });
 const approveTarget = approveActionTarget.target;
 const rejectTarget = rejectActionTarget.target;
 const withdrawTarget = withdrawActionTarget.target;
 const canApproveTarget = approveActionTarget.isFresh && approveTarget?.available_actions?.approve === true;
 const canRejectTarget = rejectActionTarget.isFresh && rejectTarget?.available_actions?.reject === true;
 const canWithdrawTarget = withdrawActionTarget.isFresh && withdrawTarget?.available_actions?.withdraw === true;
 const reconcileAdjustmentResponse = (updated: StockAdjustment) => {
  qc.setQueryData<PaginatedResponse<StockAdjustment>>(queryKey, (current) => {
   if (!current || !current.data.some((row) => row.id === updated.id)) return current;
   const remainsInCurrentFilter = !filters.status || filters.status === updated.status;
   const rows = remainsInCurrentFilter
    ? current.data.map((row) => row.id === updated.id ? updated : row)
    : current.data.filter((row) => row.id !== updated.id);
   const removed = rows.length < current.data.length;
   const total = removed ? Math.max(0, current.meta.total - 1) : current.meta.total;
   const from = rows.length > 0
    ? (current.meta.current_page - 1) * current.meta.per_page + 1
    : null;
   const to = from === null ? null : from + rows.length - 1;
   return {
    ...current,
    data: rows,
    meta: {
     ...current.meta,
     total,
     last_page: Math.max(1, Math.ceil(total / current.meta.per_page)),
     from,
     to,
    },
   };
  });
  void invalidateRealtimeChange(qc, { entityType: 'stock_adjustment', entityId: updated.id });
 };
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
 onSuccess: (updated) => {
 toast.success('Adjustment approved — stock movement posted.');
 approveActionTarget.close();
 reconcileAdjustmentResponse(updated);
 },
 onError: (error) => handleMutationError(error, 'Could not approve the stock adjustment.', () => approveActionTarget.close()),
 });
 const reject = useMutation({
 mutationFn: ({ id, reason }: { id: string; reason: string }) => stockAdjustmentsApi.reject(id, reason),
 onSuccess: (updated) => {
 toast.success('Adjustment rejected. No stock movement was posted.');
 rejectActionTarget.close();
 reconcileAdjustmentResponse(updated);
 },
 onError: (error) => handleMutationError(error, 'Could not reject the stock adjustment.', () => rejectActionTarget.close()),
 });
 const withdraw = useMutation({
 mutationFn: (id: string) => stockAdjustmentsApi.withdraw(id),
 onSuccess: (updated) => {
 toast.success('Adjustment withdrawn. No stock movement was posted.');
 withdrawActionTarget.close();
 reconcileAdjustmentResponse(updated);
 },
 onError: (error) => handleMutationError(error, 'Could not withdraw the stock adjustment.', () => withdrawActionTarget.close()),
 });

 const columns: Column<StockAdjustment>[] = [
 { key: 'created_at', header: 'Requested', className: 'w-[180px] min-w-[180px] whitespace-nowrap', cell: (r) => <span className="font-sans tabular-nums">{formatDateTime(r.created_at)}</span> },
 { key: 'requested_by', header: 'Requested by', className: 'w-[180px] min-w-[180px]', cell: (r) => <span className="block truncate" title={r.requested_by?.name ?? '—'}>{r.requested_by?.name ?? '—'}</span> },
 { key: 'direction', header: 'Direction', className: 'w-[100px] min-w-[100px]', cell: (r) => (
 <Chip variant={r.direction === 'in' ? 'success' : 'danger'}>{directionLabels.get(r.direction) ?? r.direction}</Chip>
 ) },
 { key: 'item', header: 'Item', className: 'w-[150px] min-w-[150px]', cell: (r) => (
 <div className="min-w-0">
 <span className="block truncate font-mono" title={r.item?.code}>{r.item?.code}</span>
 <div className="text-caption text-muted leading-4 line-clamp-2" title={r.item?.name}>{r.item?.name}</div>
 </div>
 ) },
 { key: 'location', header: 'Location', className: 'w-[110px] min-w-[110px] whitespace-nowrap', cell: (r) => <span className="font-mono">{r.location?.code ?? '—'}</span> },
 { key: 'qty', header: 'Quantity', align: 'right', className: 'w-[160px] min-w-[160px] whitespace-nowrap', cell: (r) => <NumCell>{formatQuantity(r.quantity)}</NumCell> },
 { key: 'value', header: 'Value', align: 'right', className: 'w-[170px] min-w-[170px] whitespace-nowrap', cell: (r) => <NumCell>{formatPeso(r.value)}</NumCell> },
 { key: 'reason', header: 'Reason', className: 'w-[200px] min-w-[200px]', cell: (r) => (
 <span className="block truncate text-secondary" title={r.reason}>
 {r.reason}
 </span>
 ) },
 { key: 'status', header: 'Status', className: 'w-[200px] min-w-[200px]', cell: (r) => (
 <div className="flex flex-col items-start gap-1">
 <Chip variant={STATUS_VARIANT[r.status] ?? 'neutral'}>{r.status_label ?? statusLabels.get(r.status) ?? r.status}</Chip>
 <span className="block max-w-full truncate text-caption text-muted leading-4" title={statusDetail(r)}>{statusDetail(r)}</span>
 </div>
 ) },
 ...(can('inventory.adjust.approve') || can('inventory.adjust')
 ? [{ key: 'actions', header: 'Actions', className: 'w-[136px] min-w-[136px]', cell: (r: StockAdjustment) => r.status === 'pending' ? (
 <div className="flex flex-wrap justify-end gap-1">
 {r.available_actions?.approve === true && <Button size="xs" variant="primary" onClick={() => approveActionTarget.open(r)}>Approve</Button>}
 {r.available_actions?.reject === true && <Button size="xs" variant="danger" onClick={() => rejectActionTarget.open(r)}>Reject</Button>}
 {r.available_actions?.withdraw === true && <Button size="xs" variant="secondary" onClick={() => withdrawActionTarget.open(r)}>Withdraw</Button>}
 </div>
 ) : null,
 }]
 : []),
 ];

 return (
 <PageShell variant="fluid" viewport className="stock-adjustments-page">
 <PageHeader
 title="Stock Adjustments"
 subtitle="Manual in/out adjustments with finance approval for high-value entries"
  inset={false}
  className="mb-6"
   actions={can('inventory.adjust') ? <Link to="/inventory/stock-adjustments/create"><Button size="md" variant="primary" icon={<LuPlus size={14} />}>New adjustment</Button></Link> : null}
 />
 <FilterBar
 inset={false}
 className="mb-4"
 filters={filterConfig}
 values={filters}
 onFilter={(k, v) => setFilters((f) => ({ ...f, [k]: v, page: 1 }))}
 onSearch={(v) => setFilters((f) => ({ ...f, search: v || undefined, page: 1 }))}
 searchPlaceholder="Search item, reason…"
 />

 {filters.id && (
  <div className="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-surface-muted px-3 py-2 text-sm text-muted">
   <span>Showing one stock adjustment from your notification.</span>
   <Button size="xs" variant="secondary" onClick={() => setFilters((current) => ({ ...current, id: '', page: 1 }))}>Show all adjustments</Button>
  </div>
 )}

 {isLoading && <div className="min-h-0 flex-1 overflow-auto"><SkeletonTable columns={can('inventory.adjust.approve') || can('inventory.adjust') ? 10 : 9} rows={8} /></div>}
 {isError && (
 <div className="min-h-0 flex-1 flex items-center justify-center">
 <EmptyState
 icon="alert-circle"
 title="Failed to load adjustments"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
 />
 </div>
 )}
 {!isLoading && !isError && (data?.data.length ?? 0) === 0 && (
 <div className="min-h-0 flex-1 flex items-center justify-center">
 <EmptyState
 icon="inbox"
 title={filters.id ? 'Stock adjustment unavailable' : 'No adjustments found'}
 description={filters.id ? 'This adjustment may have been removed or you may no longer have access.' : filters.status || filters.search ? 'Try a different search or clear the filters.' : 'Record an adjustment when stock needs a manual correction.'}
 />
 </div>
 )}
 {!isLoading && !isError && (data?.data.length ?? 0) > 0 && (
 <DataTable
 columns={columns}
 data={data!.data}
 meta={data!.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
 tableKey="stock-adjustments"
 fillHeight
 fixedColumnLayout
 />
 )}

 <ConfirmDialog
 isOpen={approveActionTarget.targetId !== null}
 onClose={approveActionTarget.close}
 title="Approve adjustment?"
 description={approveActionTarget.isChecking ? 'Checking the latest adjustment status and available actions…' : approveActionTarget.isUnverified ? 'The latest adjustment status could not be checked. Refresh the list before continuing.' : !canApproveTarget ? 'This adjustment is no longer available for approval.' : `Post a ${approveTarget?.direction === 'in' ? 'receipt' : 'issue'} of ${formatQuantity(approveTarget?.quantity)} for ${approveTarget?.item?.code ?? 'this item'}? The stock movement is posted immediately.`}
 confirmLabel="Approve"
 pending={approve.isPending}
 disabled={!canApproveTarget}
 onConfirm={() => { if (approveTarget && canApproveTarget) approve.mutate(approveTarget.id); }}
 />
 <ReasonDialog
  isOpen={rejectActionTarget.targetId !== null}
  onClose={rejectActionTarget.close}
  title="Reject adjustment?"
  description={rejectActionTarget.isChecking ? 'Checking the latest adjustment status and available actions…' : rejectActionTarget.isUnverified ? 'The latest adjustment status could not be checked. Refresh the list before continuing.' : !canRejectTarget ? 'This adjustment is no longer available for rejection.' : `Reject the pending adjustment for ${rejectTarget?.item?.code ?? 'this item'} (${formatQuantity(rejectTarget?.quantity)})? No stock movement will be posted.`}
  reasonLabel="Rejection reason"
  reasonPlaceholder="Explain why Finance is rejecting this adjustment."
  minLength={1}
  maxLength={500}
  confirmLabel="Reject adjustment"
  pending={reject.isPending}
  disabled={!canRejectTarget}
  onConfirm={(reason) => { if (rejectTarget && canRejectTarget) reject.mutate({ id: rejectTarget.id, reason }); }}
 />
 <ConfirmDialog
  isOpen={withdrawActionTarget.targetId !== null}
  onClose={withdrawActionTarget.close}
  title="Withdraw adjustment?"
  description={withdrawActionTarget.isChecking ? 'Checking the latest adjustment status and available actions…' : withdrawActionTarget.isUnverified ? 'The latest adjustment status could not be checked. Refresh the list before continuing.' : !canWithdrawTarget ? 'This adjustment is no longer available for withdrawal.' : `Withdraw your pending adjustment of ${formatQuantity(withdrawTarget?.quantity)} for ${withdrawTarget?.item?.code ?? 'this item'}? It will close without posting a stock movement.`}
  confirmLabel="Withdraw"
  variant="danger"
  pending={withdraw.isPending}
  disabled={!canWithdrawTarget}
  onConfirm={() => { if (withdrawTarget && canWithdrawTarget) withdraw.mutate(withdrawTarget.id); }}
 />
 </PageShell>
 );
}
