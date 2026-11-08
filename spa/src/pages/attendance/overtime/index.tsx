import { useState } from 'react';
import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { LuCheck, LuPlus } from '@/lib/icons';
import toast from 'react-hot-toast';
import { overtimeApi, type OvertimeListParams } from '@/api/attendance/overtime';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable, NumCell, StackedCell, type BulkAction, type Column } from '@/components/ui/DataTable';
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
import { sameQueryExceptPage } from '@/lib/queryKeys/pageScope';
import { formatDate } from '@/lib/formatDate';
import { reportMutationError } from '@/lib/formErrors';
import { useRealtimeList } from '@/hooks/useRealtimeRecord';
import { useQueryRecordTarget } from '@/hooks/useQueryRecordTarget';
import type { OvertimeRequest } from '@/types/attendance';
import type { PaginatedResponse } from '@/types';

const DEFAULT_FILTERS: OvertimeListParams = {
 page: 1, per_page: 100, sort: 'date', direction: 'desc', status: '', signal: '',
};

function reconcileOvertimeList(queryClient: QueryClient, updatedRows: OvertimeRequest[]): void {
 const updates = new Map(updatedRows.map((request) => [request.id, request]));
 if (updates.size === 0) return;

 for (const [queryKey, cached] of queryClient.getQueriesData<PaginatedResponse<OvertimeRequest>>({
  queryKey: ['attendance', 'overtime'],
 })) {
  if (!cached || !Array.isArray(cached.data)) continue;
  const filters = queryKey[2] && typeof queryKey[2] === 'object'
   ? queryKey[2] as OvertimeListParams
   : undefined;
  queryClient.setQueryData<PaginatedResponse<OvertimeRequest>>(queryKey, {
   ...cached,
   data: cached.data.flatMap((request) => {
    const updated = updates.get(request.id);
    if (!updated) return [request];
    if (filters?.status && filters.status !== updated.status) return [];
    if (filters?.signal === 'awaiting_my_approval' && updated.status !== 'pending') return [];
    return [updated];
   }),
  });
 }
}

export default function OvertimeListPage() {
 const { can, canApprove } = usePermission();
 const navigate = useNavigate();
 const qc = useQueryClient();
 const [view, setView] = useState<'list' | 'kanban'>('list');
 // Bound to the URL so dashboard drill-downs (?status=pending) arrive
 // pre-filtered and the browser back button restores the previous view.
 const [filters, setFilters] = useUrlFilters<OvertimeListParams>(DEFAULT_FILTERS);
 const [reason, setReason] = useState('');
 const [showBulkApprove, setShowBulkApprove] = useState(false);

 useRealtimeList(
  'overtime_request',
  can('attendance.view') || can('attendance.ot.approve') ? 'visible' : undefined,
 );

  const queryKey = ['attendance', 'overtime', filters] as const;
  const { data, isLoading, isFetching, isError, refetch } = useQuery({
  queryKey,
 queryFn: () => overtimeApi.list(filters),
  placeholderData: (previous, previousQuery) =>
    sameQueryExceptPage(previousQuery?.queryKey, queryKey, 2) ? previous : undefined,
 });
 const all = data?.data ?? [];
 const approveActionTarget = useQueryRecordTarget(all, { isFetching, isError });
 const rejectActionTarget = useQueryRecordTarget(all, { isFetching, isError });
 const approveTarget = approveActionTarget.target;
 const rejectTarget = rejectActionTarget.target;
 const canApproveTarget = approveActionTarget.isFresh && approveTarget?.available_actions?.approve === true;
 const canRejectTarget = rejectActionTarget.isFresh && rejectTarget?.available_actions?.reject === true;
 const { data: overtimeOptions } = useQuery({
  queryKey: ['attendance', 'overtime', 'options'],
  queryFn: overtimeApi.options,
  staleTime: 300_000,
 });
 const statusLabels = new Map((overtimeOptions?.statuses ?? []).map((option) => [option.value, option.label]));
 const statusLabel = (value: string) => statusLabels.get(value) ?? value.replaceAll('_', ' ');

 const approveMutation = useMutation({
 mutationFn: ({ id, version }: { id: string; version?: number }) => overtimeApi.approve(id, undefined, version),
 onSuccess: (updated) => {
 reconcileOvertimeList(qc, [updated]);
 toast.success('Overtime approved.');
 approveActionTarget.close();
 },
 onSettled: () => qc.invalidateQueries({ queryKey: ['attendance', 'overtime'] }),
 onError: (error) => reportMutationError(error, 'Failed to approve overtime.'),
 });

 const rejectMutation = useMutation({
 mutationFn: ({ id, reason: rejectionReason, version }: { id: string; reason: string; version?: number }) =>
 overtimeApi.reject(id, rejectionReason, version),
 onSuccess: (updated) => {
 reconcileOvertimeList(qc, [updated]);
 toast.success('Overtime rejected.');
 rejectActionTarget.close();
 setReason('');
 },
 onSettled: () => qc.invalidateQueries({ queryKey: ['attendance', 'overtime'] }),
 onError: (error) => reportMutationError(error, 'Failed to reject overtime.'),
 });

 // L-23 — bulk approve. Two entry points share this mutation: the header
 // button (every visible pending request) and the table's bulk-action bar
 // (exactly the rows the user ticked).
 //
 // `selected` is carried through the mutation because the server only reports
 // what it approved. Without the number asked for, "Approved 7" cannot be told
 // apart from "Approved 7 of 10" — and that gap is the whole bug this reporting
 // replaced: the old toast announced a partial failure through `toast.success`
 // and sent the user to "notifications" for reasons the response already had.
 const bulkApproveMutation = useMutation({
 mutationFn: async (rows: OvertimeRequest[]) => {
 // The server owns both state and available actions. Submit only the rows
 // the current response says this actor may approve.
 const actionable = rows.filter((r) => r.available_actions?.approve === true);
 const versions = Object.fromEntries(actionable.flatMap((row) =>
  row.version === undefined ? [] : [[row.id, String(row.version)]],
 ));
 const res = await overtimeApi.bulkApprove(actionable.map((r) => r.id), undefined, versions);
 return { ...res, selected: rows.length, submitted: actionable.length, untouched: rows.length - actionable.length };
 },
 // A batch that rejects partway may still have committed rows, so refetch
 // regardless of outcome rather than only on success.
 onSettled: () => qc.invalidateQueries({ queryKey: ['attendance', 'overtime'] }),
 onSuccess: (res) => {
 reconcileOvertimeList(qc, res.data);
 const reason = res.failed[0]?.reason;
 if (res.failed.length > 0) {
 toast.error(
 `Approved ${res.approved_count} of ${res.selected}. ${res.failed.length} failed${reason ? `: ${reason}` : '.'}`,
 { duration: 6000 },
 );
 return;
 }
 if (res.approved_count < res.submitted || res.untouched > 0) {
 // Rows without server-provided approval authority were not submitted.
 toast(
 `Approved ${res.approved_count} of ${res.selected}. ${res.untouched + Math.max(0, res.submitted - res.approved_count)} left unchanged — no longer available for approval.`,
 { duration: 6000 },
 );
 return;
 }
 toast.success(`Approved ${res.approved_count} request${res.approved_count === 1 ? '' : 's'}.`);
 },
 onError: (e) => reportMutationError(e, 'Bulk approve failed. No requests were approved.'),
 });

 const bulkActions: BulkAction<OvertimeRequest>[] = [
 {
 label: 'Approve selected',
 variant: 'primary',
 icon: <LuCheck size={13} />,
 onClick: (rows) => {
 if (!rows.some((r) => r.available_actions?.approve === true)) {
 toast.error('None of the selected requests are available for approval.');
 return;
 }
 bulkApproveMutation.mutate(rows);
 },
 },
 ];

 const actionablePending = all.filter((request) => request.available_actions?.approve === true);
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
 ...(canApprove('attendance.ot.approve') ? [{
 key: 'actions',
 header: '',
 align: 'right' as const,
 cell: (r: OvertimeRequest) => r.available_actions?.approve !== true ? null : (
 <div className="flex items-center justify-end gap-1">
 <Button variant="primary" size="xs" onClick={() => approveActionTarget.open(r)} disabled={approveMutation.isPending || bulkApproveMutation.isPending}>Approve</Button>
 {r.available_actions?.reject === true && <Button variant="danger" size="xs" onClick={() => rejectActionTarget.open(r)} disabled={rejectMutation.isPending || bulkApproveMutation.isPending}>Reject</Button>}
 </div>
 ),
 }] : []),
 ];

 const filterConfig: FilterConfig[] = filters.signal === 'awaiting_my_approval' ? [] : [{
 key: 'status',
 label: 'Status',
 type: 'select',
 options: [{ value: '', label: 'All' }, ...(overtimeOptions?.statuses ?? [])],
 }];

 return (
 <div>
 <PageHeader
 title="Overtime requests"
 subtitle={data ? `${data.meta.total} total · ${counts.pending} pending` : undefined}
 actions={
 <>
 {canApprove('attendance.ot.approve') && actionablePending.length > 0 && (
 <Button
 variant="secondary"
 size="sm"
 onClick={() => setShowBulkApprove(true)}
 disabled={bulkApproveMutation.isPending}
 >
 Bulk approve ({Math.min(actionablePending.length, 100)})
 </Button>
 )}
 <Button variant="secondary" size="xs" onClick={() => setView(view === 'list' ? 'kanban' : 'list')}>
 {view === 'list' ? 'Kanban view' : 'List view'}
 </Button>
 {can('attendance.ot.create') && <Button variant="primary" size="xs" icon={<LuPlus size={14} />} onClick={() => navigate('/hr/attendance/overtime/create')}>
 New OT request
 </Button>}
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
 selectable={canApprove('attendance.ot.approve')}
 bulkActions={canApprove('attendance.ot.approve') ? bulkActions : undefined}
 /></div>
 )}

 {data && all.length > 0 && view === 'kanban' && (
 <div className="grid grid-cols-1 md:grid-cols-3 gap-4 px-5 py-4">
 <KanbanColumn title={statusLabel('pending')} variant="warning" items={grouped.pending} onApprove={approveActionTarget.open} onReject={rejectActionTarget.open} canApprove={canApprove('attendance.ot.approve')} approving={approveMutation.isPending || bulkApproveMutation.isPending} rejecting={rejectMutation.isPending} onOpen={(r) => navigate(`/hr/attendance/overtime/${r.id}`)} />
 <KanbanColumn title={statusLabel('approved')} variant="success" items={grouped.approved} onOpen={(r) => navigate(`/hr/attendance/overtime/${r.id}`)} />
 <KanbanColumn title={statusLabel('rejected')} variant="danger" items={grouped.rejected} onOpen={(r) => navigate(`/hr/attendance/overtime/${r.id}`)} />
 </div>
 )}

 <ConfirmDialog
 isOpen={approveActionTarget.targetId !== null}
 onClose={approveActionTarget.close}
 onConfirm={() => {
 if (canApproveTarget && approveTarget) approveMutation.mutate({ id: approveTarget.id, version: approveTarget.version });
 }}
 title="Approve overtime request?"
 description={canApproveTarget
 ? `Approve overtime for ${approveTarget.employee?.full_name ?? 'this employee'} on ${formatDate(approveTarget.date)}?`
 : approveActionTarget.isChecking
 ? 'Checking the current approval status…'
 : approveActionTarget.isUnverified
 ? 'The current approval status could not be verified. Retry the list before continuing.'
 : 'This overtime request is no longer available for approval. Refresh the list to review its current status.'}
 variant="warning"
 confirmLabel="Approve"
 pending={approveMutation.isPending}
 disabled={!canApproveTarget}
 />

 <ConfirmDialog
 isOpen={showBulkApprove}
 onClose={() => setShowBulkApprove(false)}
 onConfirm={() => {
 const rows = actionablePending.slice(0, 100);
 if (rows.length > 0) bulkApproveMutation.mutate(rows);
 setShowBulkApprove(false);
 }}
 title={`Approve ${Math.min(actionablePending.length, 100)} pending overtime request${Math.min(actionablePending.length, 100) === 1 ? '' : 's'}?`}
 variant="warning"
 confirmLabel="Approve all"
 pending={bulkApproveMutation.isPending}
 />

 {rejectActionTarget.targetId !== null && (
 <Modal isOpen onClose={() => { rejectActionTarget.close(); setReason(''); }} size="sm" title="Reject overtime request">
 {canRejectTarget && rejectTarget ? (
 <p className="text-sm py-2">
 Reject overtime for <span className="font-medium">{rejectTarget.employee?.full_name}</span> on {formatDate(rejectTarget.date)}?
 </p>
 ) : (
 <p role="status" className="text-sm py-2">
 {rejectActionTarget.isChecking
 ? 'Checking the current rejection status…'
 : rejectActionTarget.isUnverified
 ? 'The current rejection status could not be verified. Retry the list before continuing.'
 : 'Rejection is no longer available for this request. Refresh the list to review its current status.'}
 </p>
 )}
 <Textarea label="Reason for rejection" required value={reason} onChange={(e) => setReason(e.target.value)} rows={3} />
 <ModalFooter>
 <Button variant="secondary" onClick={() => { rejectActionTarget.close(); setReason(''); }}>Cancel</Button>
 <Button
 variant="danger"
 disabled={!reason.trim() || rejectMutation.isPending || !canRejectTarget}
 loading={rejectMutation.isPending}
 onClick={() => {
 if (canRejectTarget && rejectTarget) rejectMutation.mutate({ id: rejectTarget.id, reason, version: rejectTarget.version });
 }}
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
 title, variant, items, onApprove, onReject, canApprove, approving, rejecting, onOpen,
}: {
 title: string;
 variant: 'success' | 'warning' | 'danger';
 items: OvertimeRequest[];
 onApprove?: (request: OvertimeRequest) => void;
 onReject?: (r: OvertimeRequest) => void;
 canApprove?: boolean;
 approving?: boolean;
 rejecting?: boolean;
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
 {canApprove && o.available_actions?.approve === true && (
 <div className="flex gap-1 mt-2" onClick={(e) => e.stopPropagation()}>
 <Button variant="primary" size="xs" disabled={approving} onClick={() => onApprove?.(o)}>Approve</Button>
 {o.available_actions?.reject === true && <Button variant="danger" size="xs" disabled={rejecting || approving} onClick={() => onReject?.(o)}>Reject</Button>}
 </div>
 )}
 </li>
 ))}
 </ul>
 )}
 </Panel>
 );
}
