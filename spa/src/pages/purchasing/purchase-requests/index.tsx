import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate} from 'react-router-dom';
import { Plus, ShoppingCart, Zap } from 'lucide-react';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { vendorsApi } from '@/api/accounting/vendors';
import { purchaseRequestsApi } from '@/api/purchasing/purchase-requests';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, type Column, type BulkAction } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import type { ListParams } from '@/types';
import type { PurchaseRequest, PurchaseRequestPriority, PurchaseRequestStatus } from '@/types/purchasing';

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
  page: 1, per_page: 25, status: 'pending',
};

const errMsg = (e: unknown, fallback: string) =>
 (e instanceof AxiosError ? e.response?.data?.message : undefined) ?? fallback;

export default function PurchaseRequestsListPage() {
 const navigate = useNavigate();
 const qc = useQueryClient();
 const { can } = usePermission();
  const [filters, setFilters] = useUrlFilters<PurchaseRequestListParams>(DEFAULT_FILTERS);
  const [convertTarget, setConvertTarget] = useState<PurchaseRequest | null>(null);
  const [vendorMap, setVendorMap] = useState<Record<string, string>>({});

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
 mutationFn: (ids: string[]) => purchaseRequestsApi.bulkApprove(ids),
 onSuccess: (results) => {
 qc.invalidateQueries({ queryKey: ['purchasing', 'purchase-requests'] });
 const approved = results.filter((r: { status: string }) => r.status === 'approved').length;
 const skipped = results.filter((r: { status: string }) => r.status === 'skipped').length;
 toast.success(`${approved} approved, ${skipped} skipped`);
 },
 onError: (e) => toast.error(errMsg(e, 'Failed to bulk approve.')) });

 const convertDetail = useQuery({
 queryKey: ['purchasing', 'purchase-requests', convertTarget?.id, 'conversion'],
 queryFn: () => purchaseRequestsApi.show(convertTarget!.id),
 enabled: !!convertTarget });
 const vendors = useQuery({
 queryKey: ['accounting', 'vendors', 'pr-conversion'],
 queryFn: () => vendorsApi.list({ per_page: 200, is_active: 'true' }),
 enabled: !!convertTarget });
 const convertMut = useMutation({
 mutationFn: (assignments: Record<string, string>) => purchaseRequestsApi.convert(convertTarget!.id, assignments),
 onSuccess: (orders) => {
 qc.invalidateQueries({ queryKey: ['purchasing', 'purchase-requests'] });
 setConvertTarget(null);
 setVendorMap({});
 toast.success(`${orders.length} purchase order${orders.length === 1 ? '' : 's'} created.`);
 navigate(orders.length === 1 ? `/purchasing/purchase-orders/${orders[0].id}` : '/purchasing/purchase-orders');
 },
 onError: (e) => toast.error(errMsg(e, 'Failed to convert PR.')) });

 const openConversion = (request: PurchaseRequest) => {
 setVendorMap({});
 setConvertTarget(request);
 };

 const conversionItems = convertDetail.data?.items ?? [];
 const effectiveVendorMap = Object.fromEntries(conversionItems.map((item) => [
 item.id,
 vendorMap[item.id] ?? item.suggested_vendor?.id ?? '',
 ]));
 const allVendorsAssigned = conversionItems.length > 0
 && conversionItems.every((item) => effectiveVendorMap[item.id]);

 const bulkActions: BulkAction<PurchaseRequest>[] = [
 {
 label: 'Approve selected',
 variant: 'primary',
 onClick: (rows) => {
 const ids = rows.map((r) => r.id);
 bulkApproveMut.mutate(ids);
 } },
 ];

 const columns: Column<PurchaseRequest>[] = [
 { key: 'pr', header: 'PR #', cell: (r) => (
 <div>
 <span className="font-mono">{r.pr_number}</span>
 {r.is_auto_generated && <Chip variant="warning" className="ml-2">AUTO</Chip>}
 {r.is_urgent && <Chip variant="danger" className="ml-1"><Zap size={10} className="inline mr-0.5" />URGENT</Chip>}
 </div>
 ) },
 { key: 'date', header: 'Date', cell: (r) => <span className="font-mono">{formatDate(r.date)}</span> },
 { key: 'requester', header: 'Requester', cell: (r) => r.requester?.name ?? '—' },
 { key: 'dept', header: 'Dept', cell: (r) => r.department?.code ?? '—' },
 { key: 'priority', header: 'Priority', cell: (r) => (
 <span className="flex items-center gap-1">
 <Chip variant={priorityVariant[r.priority]}>{r.priority_label ?? r.priority}</Chip>
 {r.is_urgent && <Zap size={12} className="text-danger-fg" />}
 </span>
 ) },
 { key: 'status', header: 'Status', cell: (r) => (
 <span className="flex items-center gap-1.5">
 <Chip variant={statusVariant[r.status]}>{r.status_label ?? statusLabels.get(r.status) ?? r.status}</Chip>
 {r.status === 'approved' && r.po_conversion_status === 'manual_required' && (
 <Chip variant="warning">manual PO</Chip>
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
 icon={<ShoppingCart size={13} />}
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
 <Button variant="primary" size="xs" icon={<Plus size={14} />} onClick={() => navigate('/purchasing/purchase-requests/create')}>New PR</Button>
 ) : null} />
 <FilterBar filters={filterConfig} values={filters}
 onSearch={(s) => setFilters(f => ({ ...f, search: s, page: 1 }))}
 onFilter={(k, v) => setFilters(f => ({ ...f, [k]: v, page: 1 }))}
 searchPlaceholder="Search PR number…" />
 {isLoading && !data && <SkeletonTable columns={7} rows={6} />}
 {isError && <EmptyState icon="alert-circle" title="Failed to load PRs" action={<Button onClick={() => refetch()}>Retry</Button>} />}
 {data && data.data.length === 0 && (
 <EmptyState icon="inbox" title="No purchase requests"
 action={can('purchasing.pr.create') ? <Button variant="primary" onClick={() => navigate('/purchasing/purchase-requests/create')}>New PR</Button> : undefined} />
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
 selectable={can('purchasing.pr.approve')}
 bulkActions={can('purchasing.pr.approve') ? bulkActions : undefined}
 />
 </div>
 )}

 <Modal
 isOpen={!!convertTarget}
 onClose={() => { setConvertTarget(null); setVendorMap({}); }}
 title={`Convert ${convertTarget?.pr_number ?? 'PR'} to PO`}
 size="lg"
 >
 <div className="py-4 space-y-3">
 {convertDetail.isLoading ? <SkeletonTable rows={3} columns={4} /> : conversionItems.map((item) => (
 <div key={item.id} className="grid grid-cols-[1fr_120px_220px] gap-3 items-end border-b border-subtle pb-3">
 <div>
 <div className="font-medium text-sm">{item.item?.code ?? 'Uncoded item'} · {item.description}</div>
 <div className="text-xs text-muted">{item.quantity} {item.unit ?? item.item?.unit_of_measure ?? '—'} · {formatPeso(item.estimated_unit_price)}</div>
 </div>
 <div className="text-xs text-muted">{formatPeso(item.estimated_total)}</div>
 <Select
 label="Supplier"
 value={vendorMap[item.id] ?? item.suggested_vendor?.id ?? ''}
 onChange={(event) => setVendorMap((current) => ({ ...current, [item.id]: event.target.value }))}
 >
 <option value="">Select supplier…</option>
 {vendors.data?.data.map((vendor) => <option key={vendor.id} value={vendor.id}>{vendor.name}</option>)}
 </Select>
 </div>
 ))}
 <ModalFooter>
 <Button variant="secondary" onClick={() => { setConvertTarget(null); setVendorMap({}); }}>Cancel</Button>
 <Button
 variant="primary"
 loading={convertMut.isPending}
 disabled={!allVendorsAssigned || convertMut.isPending}
 onClick={() => convertMut.mutate(effectiveVendorMap)}
 >
 Create PO
 </Button>
 </ModalFooter>
 </div>
 </Modal>
 </div>
 );
}
