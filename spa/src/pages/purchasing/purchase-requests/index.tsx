import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate} from 'react-router-dom';
import { LuPlus, LuShoppingCart, LuZap } from '@/lib/icons';
import toast from 'react-hot-toast';
import { purchaseRequestsApi } from '@/api/purchasing/purchase-requests';
import { ConvertPrToPoModal } from '@/components/purchasing/ConvertPrToPoModal';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column, type BulkAction } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { reportMutationError } from '@/lib/formErrors';
import type { ListParams } from '@/types';
import type { PurchaseRequest, PurchaseRequestPriority, PurchaseRequestStatus } from '@/types/purchasing';

import { ListEmptyState } from '@/components/ui/ListEmptyState';
const statusVariant: Record<PurchaseRequestStatus, 'neutral' | 'warning' | 'info' | 'success' | 'danger'> = {
  draft: 'neutral', pending: 'info', approved: 'success', rejected: 'danger',
  converted: 'neutral', cancelled: 'neutral' };
const priorityVariant: Record<PurchaseRequestPriority, 'neutral' | 'warning' | 'danger'> = {
  normal: 'neutral', urgent: 'warning', critical: 'danger' };

interface PurchaseRequestListParams extends ListParams {
  status?: string;
  priority?: string;
  is_auto_generated?: boolean | string;
  is_urgent?: boolean | string;
  from?: string;
  to?: string;
}

const DEFAULT_FILTERS: PurchaseRequestListParams = {
  page: 1, per_page: 25, status: '',
};

export default function PurchaseRequestsListPage() {
 const navigate = useNavigate();
 const qc = useQueryClient();
 const { can } = usePermission();
  const [filters, setFilters] = useUrlFilters<PurchaseRequestListParams>(DEFAULT_FILTERS);
  const [convertTarget, setConvertTarget] = useState<PurchaseRequest | null>(null);

  // Dashboard drill-downs use the short flag (?is_auto_generated=1); the
  // FilterBar select options use 'true'/'false' — reconcile once at mount.
  useEffect(() => {
    const v = filters.is_auto_generated;
    if (v === '1' || v === '0') {
      setFilters((f) => ({ ...f, is_auto_generated: v === '1' ? 'true' : 'false' }));
    }
  }, [filters.is_auto_generated, setFilters]);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['purchasing', 'purchase-requests', filters],
 queryFn: ({ signal }) => purchaseRequestsApi.list(filters, signal),
 placeholderData: (prev) => prev });
 const { data: requestOptions } = useQuery({
 queryKey: ['purchasing', 'purchase-request-options'],
 queryFn: () => purchaseRequestsApi.options() });
 const statusLabels = new Map((requestOptions?.statuses ?? []).map((option) => [option.value, option.label]));

 const bulkApproveMut = useMutation({
 mutationFn: async (rows: PurchaseRequest[]) => ({
 results: await purchaseRequestsApi.bulkApprove(rows.map((r) => r.id)),
 selected: rows.length,
 }),
 // A batch that rejects partway may still have committed rows, so refetch
 // regardless of outcome rather than only on success.
 onSettled: () => qc.invalidateQueries({ queryKey: ['purchasing', 'purchase-requests'] }),
 onSuccess: ({ results, selected }) => {
 const approved = results.filter((r) => r.status === 'approved').length;
 const skipped = results.filter((r) => r.status !== 'approved');
 // This used to be `toast.success('N approved, M skipped')`: a green banner
 // for a partial failure, with the server's per-row reason — which is the
 // only thing that tells the user what to do next — discarded.
 if (skipped.length > 0) {
 const reason = skipped.find((r) => r.message)?.message;
 toast.error(
 `Approved ${approved} of ${selected}. ${skipped.length} skipped${reason ? `: ${reason}` : '.'}`,
 { duration: 6000 },
 );
 return;
 }
 toast.success(`${approved} purchase request${approved === 1 ? '' : 's'} approved.`);
 },
 onError: (e) => reportMutationError(e, 'Bulk approval failed. No requests were approved.') });

 const openConversion = (request: PurchaseRequest) => {
 setConvertTarget(request);
 };

 const bulkActions: BulkAction<PurchaseRequest>[] = [
 {
 label: 'Approve selected',
 variant: 'primary',
 onClick: (rows) => {
 bulkApproveMut.mutate(rows);
 } },
 ];

 const columns: Column<PurchaseRequest>[] = [
 { key: 'pr', header: 'PR #', cell: (r) => (
 <div>
 <span className="font-mono">{r.pr_number}</span>
 {r.is_auto_generated && <Chip variant="warning" className="ml-2">AUTO</Chip>}
 {r.is_urgent && <Chip variant="danger" className="ml-1"><LuZap size={10} className="inline mr-0.5" />URGENT</Chip>}
 </div>
 ) },
 { key: 'date', header: 'Date', cell: (r) => <span className="font-mono">{formatDate(r.date)}</span> },
 { key: 'requester', header: 'Requester', cell: (r) => r.requester?.name ?? '—' },
 { key: 'dept', header: 'Dept', cell: (r) => r.department?.code ?? '—' },
 { key: 'priority', header: 'Priority', cell: (r) => (
 <span className="flex items-center gap-1">
 <Chip variant={priorityVariant[r.priority]}>{r.priority_label ?? r.priority}</Chip>
 {r.is_urgent && <LuZap size={12} className="text-danger-fg" />}
 </span>
 ) },
 { key: 'status', header: 'Status', cell: (r) => (
 <span className="flex items-center gap-1.5">
 <Chip variant={statusVariant[r.status]}>{r.status_label ?? statusLabels.get(r.status) ?? r.status}</Chip>
 {r.status === 'approved' && r.po_conversion_status === 'manual_required' && (
 <Chip variant="warning">manual PO</Chip>
 )}
 {r.status === 'approved' && r.po_conversion_status === 'partial' && (
 <Chip variant="warning">partial PO</Chip>
 )}
 {r.has_overdue_approval && (
 <span title={`Approval pending beyond ${requestOptions?.approval_sla_hours ?? 'configured'} hours`}><Chip variant="danger">overdue</Chip></span>
 )}
 </span>
 ) },
 { key: 'total', header: 'Estimated', align: 'right', cell: (r) => <NumCell>{formatPeso(r.total_estimated_amount)}</NumCell> },
 ...(can('purchasing.po.create') ? [{
 key: 'actions',
 header: '',
 align: 'right' as const,
 cell: (r: PurchaseRequest) => r.status === 'approved' ? (
 <Button
 size="sm"
 variant="secondary"
 icon={<LuShoppingCart size={13} />}
 onClick={() => openConversion(r)}
 >
 Convert to PO
 </Button>
 ) : null }] : []),
 ];

 const filterConfig: FilterConfig[] = [
 { key: 'status', label: 'Status', type: 'select', options: [
 { value: '', label: 'All' },
 ...(requestOptions?.statuses ?? []),
 ]},
 { key: 'priority', label: 'Priority', type: 'select', options: [
 { value: '', label: 'All' },
 ...(requestOptions?.priorities ?? []).map((priority) => ({ value: priority.value, label: priority.label })),
 ]},
 { key: 'is_auto_generated', label: 'Source', type: 'select', options: [
 { value: '', label: 'All' }, { value: 'true', label: 'Auto-generated' }, { value: 'false', label: 'Manual' },
 ]},
 ];

 return (
 <div>
 <PageHeader title="Purchase requests" subtitle={data ? `${data.meta.total} requests` : undefined}
 actions={can('purchasing.pr.create') ? (
 <Button variant="primary" size="xs" icon={<LuPlus size={14} />} onClick={() => navigate('/purchasing/purchase-requests/create')}>New PR</Button>
 ) : null} />
 <FilterBar filters={filterConfig} values={filters}
 onSearch={(s) => setFilters(f => ({ ...f, search: s, page: 1 }))}
 onFilter={(k, v) => setFilters(f => ({ ...f, [k]: v, page: 1 }))}
 searchPlaceholder="Search PR number…" />
 {isLoading && !data && <SkeletonTable columns={7} rows={6} />}
 {isError && <EmptyState icon="alert-circle" title="Failed to load PRs" action={<Button onClick={() => refetch()}>Retry</Button>} />}
 {data && data.data.length === 0 && (
 <ListEmptyState />
 )}
 {data && data.data.length > 0 && (
 <div className="px-5 py-4">
  <DataTable
  tableKey="purchase-requests"
  onRowClick={(r) => navigate(`/purchasing/purchase-requests/${r.id}`)}
 columns={columns}
 data={data.data}
 meta={data.meta}
 onPageChange={(page) => setFilters(f => ({ ...f, page }))}
 onPageSizeChange={(per_page) => setFilters(f => ({ ...f, per_page, page: 1 }))}
 selectable={can('purchasing.pr.approve')}
 bulkActions={can('purchasing.pr.approve') ? bulkActions : undefined}
 />
 </div>
 )}

 <ConvertPrToPoModal purchaseRequest={convertTarget} onClose={() => setConvertTarget(null)} />
 </div>
 );
}
