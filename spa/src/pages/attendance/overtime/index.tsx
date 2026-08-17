import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { LuPlus } from '@/lib/icons';
import toast from 'react-hot-toast';
import { overtimeApi, type OvertimeListParams } from '@/api/attendance/overtime';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Textarea } from '@/components/ui/Textarea';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatDate } from '@/lib/formatDate';
import type { OvertimeRequest } from '@/types/attendance';

const DEFAULT_FILTERS: OvertimeListParams = {
 page: 1, per_page: 100, sort: 'date', direction: 'desc', status: 'pending',
};

export default function OvertimeListPage() {
 const { can } = usePermission();
 const navigate = useNavigate();
 const qc = useQueryClient();
 const [view, setView] = useState<'list' | 'kanban'>('list');
 // Bound to the URL so dashboard drill-downs (?status=pending) arrive
 // pre-filtered and the browser back button restores the previous view.
 const [filters, setFilters] = useUrlFilters<OvertimeListParams>(DEFAULT_FILTERS);
 const [reject, setReject] = useState<OvertimeRequest | null>(null);
 const [reason, setReason] = useState('');
 const [confirmApprove, setConfirmApprove] = useState<string | null>(null);
 const [showBulkApprove, setShowBulkApprove] = useState(false);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['attendance', 'overtime', filters],
 queryFn: () => overtimeApi.list(filters),
 placeholderData: (prev) => prev,
 });
 const { data: overtimeOptions } = useQuery({
  queryKey: ['attendance', 'overtime', 'options'],
  queryFn: overtimeApi.options,
  staleTime: 300_000,
 });
 const statusLabels = new Map((overtimeOptions?.statuses ?? []).map((option) => [option.value, option.label]));
 const statusLabel = (value: string) => statusLabels.get(value) ?? value.replaceAll('_', ' ');

 const approveMutation = useMutation({
 mutationFn: (id: string) => overtimeApi.approve(id),
 onSuccess: () => {
 qc.invalidateQueries({ queryKey: ['attendance', 'overtime'] });
 toast.success('Overtime approved.');
 setConfirmApprove(null);
 },
 onError: () => toast.error('Failed to approve.'),
 });

 const rejectMutation = useMutation({
 mutationFn: (id: string) => overtimeApi.reject(id, reason),
 onSuccess: () => {
 qc.invalidateQueries({ queryKey: ['attendance', 'overtime'] });
 toast.success('Overtime rejected.');
 setReject(null);
 setReason('');
 },
 onError: () => toast.error('Failed to reject.'),
 });

 // L-23 — bulk approve every visible pending request in one click.
 const bulkApproveMutation = useMutation({
 mutationFn: (ids: string[]) => overtimeApi.bulkApprove(ids),
 onSuccess: (res) => {
 qc.invalidateQueries({ queryKey: ['attendance', 'overtime'] });
 const failed = res.failed.length;
 if (failed === 0) toast.success(`Approved ${res.approved_count} request${res.approved_count === 1 ? '' : 's'}.`);
 else toast.success(`Approved ${res.approved_count}; ${failed} failed (see notifications).`);
 },
 onError: () => toast.error('Bulk approve failed.'),
 });

 const all = data?.data ?? [];
 const counts = {
 pending: all.filter((o) => o.status === 'pending').length,
 approved: all.filter((o) => o.status === 'approved').length,
 rejected: all.filter((o) => o.status === 'rejected').length,
 auto: all.filter((o) => o.is_auto_detected).length,
 };
 const grouped = {
 pending: all.filter((o) => o.status === 'pending'),
 approved: all.filter((o) => o.status === 'approved'),
 rejected: all.filter((o) => o.status === 'rejected'),
 };

 const columns: Column<OvertimeRequest>[] = [
 { key: 'date', header: 'Date', cell: (r) => <NumCell>{formatDate(r.date)}</NumCell> },
 { key: 'employee', header: 'Employee', cell: (r) => <StackedCell primary={r.employee?.full_name ?? '—'} secondary={<span className="font-mono">{r.employee?.employee_no}</span>} /> },
 { key: 'hours_requested', header: 'Hours', align: 'right', cell: (r) => <NumCell>{r.hours_requested}</NumCell> },
 { key: 'reason', header: 'Reason', cell: (r) => <span className="text-muted truncate block max-w-md" title={r.reason}>{r.reason}</span> },
 { key: 'approver', header: 'Approver', cell: (r) => r.approver?.name ?? '—' },
 { key: 'status', header: 'Status', cell: (r) => (
 <div className="flex items-center gap-1.5">
 <Chip variant={chipVariantForStatus(r.status)}>{r.status_label ?? r.status}</Chip>
 {r.is_auto_detected && <Chip variant="info">Auto</Chip>}
 </div>
 ) },
 ...(can('attendance.ot.approve') ? [{
 key: 'actions',
 header: '',
 align: 'right' as const,
 cell: (r: OvertimeRequest) => r.status !== 'pending' ? null : (
 <div className="flex items-center justify-end gap-1">
 <Button variant="primary" size="xs" onClick={() => { setConfirmApprove(r.id); }} disabled={approveMutation.isPending}>Approve</Button>
 <Button variant="danger" size="xs" onClick={() => { setReject(r); }}>Reject</Button>
 </div>
 ),
 }] : []),
 ];

 const filterConfig: FilterConfig[] = [
 {
 key: 'status',
 label: 'Status',
 type: 'select',
 options: [{ value: '', label: 'All' }, ...(overtimeOptions?.statuses ?? [])],
 },
 ];

 return (
 <div>
 <PageHeader
 title="Overtime requests"
 subtitle={data ? `${data.meta.total} total · ${counts.pending} pending` : undefined}
 actions={
 <>
 {can('attendance.ot.approve') && counts.pending > 0 && (
 <Button
 variant="secondary"
 size="sm"
 onClick={() => setShowBulkApprove(true)}
 disabled={bulkApproveMutation.isPending}
 >
 Bulk approve ({Math.min(counts.pending, 100)})
 </Button>
 )}
 <Button variant="secondary" size="xs" onClick={() => setView(view === 'list' ? 'kanban' : 'list')}>
 {view === 'list' ? 'Kanban view' : 'List view'}
 </Button>
 <Button variant="primary" size="xs" icon={<LuPlus size={14} />} onClick={() => navigate('/hr/attendance/overtime/create')}>
 New OT request
 </Button>
 </>
 }
 />

 <FilterBar
 filters={filterConfig}
 values={filters}
 onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
 onSearch={(search) => setFilters((f) => ({ ...f, search: search || undefined, page: 1 }))}
 searchPlaceholder="Search employee…"
 dateRange={{ fromKey: 'from', toKey: 'to', label: 'Date' }}
 />

 {data && all.length > 0 && (
 <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 px-5 py-4 border-b border-default">
 <StatCard
 label={statusLabel('pending')}
 value={counts.pending}
 helper="in current view"
 linkTo="/hr/attendance/overtime?status=pending"
 />
 <StatCard
 label={statusLabel('approved')}
 value={counts.approved}
 helper="in current view"
 linkTo="/hr/attendance/overtime?status=approved"
 />
 <StatCard
 label={statusLabel('rejected')}
 value={counts.rejected}
 helper="in current view"
 linkTo="/hr/attendance/overtime?status=rejected"
 />
 <StatCard
 label="Auto-detected"
 value={counts.auto}
 helper="from biometric punches"
 />
 </div>
 )}

 {isLoading && !data && <SkeletonTable columns={6} rows={6} />}
 {isError && <EmptyState icon="alert-circle" title="Failed to load overtime requests" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
 {data && all.length === 0 && (
 <EmptyState icon="inbox" title="No OT requests match" description="Adjust the filters, or submit an OT request to see it here." />
 )}

 {data && all.length > 0 && view === 'list' && (
 <div className="px-5 py-4"><DataTable
 tableKey="overtime-requests"
 onRowClick={(r) => navigate(`/hr/attendance/overtime/${r.id}`)}
 columns={columns}
 data={all}
 meta={data.meta}
 onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
 /></div>
 )}

 {data && all.length > 0 && view === 'kanban' && (
 <div className="grid grid-cols-1 md:grid-cols-3 gap-4 px-5 py-4">
 <KanbanColumn title={statusLabel('pending')} variant="warning" items={grouped.pending} onApprove={(id) => setConfirmApprove(id)} onReject={setReject} canApprove={can('attendance.ot.approve')} approving={approveMutation.isPending} onOpen={(r) => navigate(`/hr/attendance/overtime/${r.id}`)} />
 <KanbanColumn title={statusLabel('approved')} variant="success" items={grouped.approved} onOpen={(r) => navigate(`/hr/attendance/overtime/${r.id}`)} />
 <KanbanColumn title={statusLabel('rejected')} variant="danger" items={grouped.rejected} onOpen={(r) => navigate(`/hr/attendance/overtime/${r.id}`)} />
 </div>
 )}

 <ConfirmDialog
 isOpen={confirmApprove !== null}
 onClose={() => setConfirmApprove(null)}
 onConfirm={() => { if (confirmApprove) approveMutation.mutate(confirmApprove); }}
 title="Approve overtime request?"
 variant="warning"
 confirmLabel="Approve"
 pending={approveMutation.isPending}
 />

 <ConfirmDialog
 isOpen={showBulkApprove}
 onClose={() => setShowBulkApprove(false)}
 onConfirm={() => {
 const ids = grouped.pending.slice(0, 100).map((o) => o.id);
 if (ids.length > 0) bulkApproveMutation.mutate(ids);
 setShowBulkApprove(false);
 }}
 title={`Approve ${Math.min(counts.pending, 100)} pending overtime request${Math.min(counts.pending, 100) === 1 ? '' : 's'}?`}
 variant="warning"
 confirmLabel="Approve all"
 pending={bulkApproveMutation.isPending}
 />

 {reject && (
 <Modal isOpen onClose={() => { setReject(null); setReason(''); }} size="sm" title="Reject overtime request">
 <p className="text-sm py-2">
 Reject overtime for <span className="font-medium">{reject.employee?.full_name}</span> on {formatDate(reject.date)}?
 </p>
 <Textarea label="Reason for rejection" required value={reason} onChange={(e) => setReason(e.target.value)} rows={3} />
 <ModalFooter>
 <Button variant="secondary" onClick={() => { setReject(null); setReason(''); }}>Cancel</Button>
 <Button
 variant="danger"
 disabled={!reason.trim() || rejectMutation.isPending}
 loading={rejectMutation.isPending}
 onClick={() => reject && rejectMutation.mutate(reject.id)}
 >
 {rejectMutation.isPending ? 'Rejecting…' : 'Confirm reject'}
 </Button>
 </ModalFooter>
 </Modal>
 )}
 </div>
 );
}

function KanbanColumn({
 title, variant, items, onApprove, onReject, canApprove, approving, onOpen,
}: {
 title: string;
 variant: 'success' | 'warning' | 'danger';
 items: OvertimeRequest[];
 onApprove?: (id: string) => void;
 onReject?: (r: OvertimeRequest) => void;
 canApprove?: boolean;
 approving?: boolean;
 onOpen?: (r: OvertimeRequest) => void;
}) {
 return (
 <Panel
 title={
 <span className="flex items-center gap-2">
 <span>{title}</span>
 <Chip variant={variant}>{items.length}</Chip>
 </span>
 }
 noPadding
 >
 {items.length === 0 ? (
 <p className="text-xs text-muted px-4 py-4 text-center">Nothing here.</p>
 ) : (
 <ul className="divide-y divide-subtle">
 {items.map((o) => (
 <li key={o.id} className="px-4 py-3 hover:bg-subtle cursor-pointer" onClick={() => onOpen?.(o)}>
 <div className="flex items-start justify-between gap-2">
 <div className="min-w-0">
 <div className="text-sm font-medium truncate">{o.employee?.full_name ?? '—'}</div>
 <div className="text-xs text-muted font-mono">{o.employee?.employee_no} · {formatDate(o.date)}</div>
 <div className="flex items-center gap-1.5 mt-1.5">
 <Chip variant={chipVariantForStatus(o.status)}>{o.status_label ?? o.status}</Chip>
 {o.is_auto_detected && <Chip variant="info">Auto</Chip>}
 </div>
 <div className="text-xs mt-1.5 line-clamp-2">{o.reason}</div>
 </div>
 <div className="text-right shrink-0">
 <span className="font-mono tabular-nums text-sm">{o.hours_requested}h</span>
 {o.approver?.name && <div className="text-[11px] text-muted mt-1">by {o.approver.name}</div>}
 </div>
 </div>
 {canApprove && o.status === 'pending' && (
 <div className="flex gap-1 mt-2" onClick={(e) => e.stopPropagation()}>
 <Button variant="primary" size="xs" disabled={approving} onClick={() => onApprove?.(o.id)}>Approve</Button>
 <Button variant="danger" size="xs" onClick={() => onReject?.(o)}>Reject</Button>
 </div>
 )}
 </li>
 ))}
 </ul>
 )}
 </Panel>
 );
}
