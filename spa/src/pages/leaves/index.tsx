import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { LuPlus, LuListChecks, LuRotateCcw, LuCheck } from '@/lib/icons';
import toast from 'react-hot-toast';
import { leaveRequestsApi, type BulkApproveLeaveResult, type LeaveListParams } from '@/api/leave';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { DataTable, NumCell, StackedCell, type Column, type BulkAction } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { Textarea } from '@/components/ui/Textarea';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useRealtimeList } from '@/hooks/useRealtimeRecord';
import { useQueryRecordTarget } from '@/hooks/useQueryRecordTarget';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatDate } from '@/lib/formatDate';
import { leaveStatusVariant } from '@/lib/statusVariants';
import { reportMutationError } from '@/lib/formErrors';
import { LeaveTypesManager } from './types';
import { YearEndLeaveModal } from './year-end';
import type { PaginatedResponse } from '@/types';
import type { LeaveRequest, LeaveRequestAction } from '@/types/leave';
import { sameQueryExceptPage } from '@/lib/queryKeys/pageScope';
import { invalidateRealtimeChange, invalidateRealtimeChanges, invalidateRealtimeList } from '@/lib/realtime/invalidationMap';

const DEFAULT_FILTERS: LeaveListParams = {
 page: 1, per_page: 100, sort: 'created_at', direction: 'desc', status: '', signal: '',
};

function hasLeaveAction(request: LeaveRequest, action: LeaveRequestAction): boolean {
 return request.available_actions?.includes(action) ?? false;
}

export default function LeavesPage() {
 useRealtimeList('leave_request', 'visible');
 useRealtimeList('leave_type', 'visible');
 const { can } = usePermission();
 const navigate = useNavigate();
 const qc = useQueryClient();
 const [showTypes, setShowTypes] = useState(false);
 const [showYearEnd, setShowYearEnd] = useState(false);
 const [view, setView] = useState<'list' | 'kanban'>('list');
 // Bound to the URL so dashboard drill-downs (?status=pending_hr) arrive
 // pre-filtered and the browser back button restores the previous view.
 const [filters, setFilters] = useUrlFilters<LeaveListParams>(DEFAULT_FILTERS);
 const listQueryKey = ['leaves', filters] as const;

 const [rejectReason, setRejectReason] = useState('');

 const { data, isLoading, isFetching, isError, refetch } = useQuery({
 queryKey: listQueryKey,
 queryFn: () => leaveRequestsApi.list(filters),
 placeholderData: (prev, previousQuery) =>
  sameQueryExceptPage(previousQuery?.queryKey, listQueryKey, 1) ? prev : undefined,
 });
 const all = data?.data ?? [];
 const rejectActionTarget = useQueryRecordTarget(all, { isFetching, isError });
 const rejectTarget = rejectActionTarget.target;
 const approveDeptActionTarget = useQueryRecordTarget(all, { isFetching, isError });
 const approveDeptTarget = approveDeptActionTarget.target;
 const approveHRActionTarget = useQueryRecordTarget(all, { isFetching, isError });
 const approveHRTarget = approveHRActionTarget.target;
 const reconcileLeaveListResponse = (updated: LeaveRequest) => {
  qc.setQueryData<PaginatedResponse<LeaveRequest>>(listQueryKey, (current) => {
   if (!current || !current.data.some((request) => request.id === updated.id)) return current;
  const remainsActionable = (updated.available_actions?.length ?? 0) > 0;
   const stillMatches = (!filters.status || filters.status === updated.status)
    && (filters.signal !== 'awaiting_my_approval' || remainsActionable);
   const rows = stillMatches
    ? current.data.map((request) => request.id === updated.id ? updated : request)
    : current.data.filter((request) => request.id !== updated.id);
   if (rows.length === current.data.length) return { ...current, data: rows };
   const total = Math.max(0, current.meta.total - 1);
   const currentPage = current.meta.current_page;
   const pageStart = (currentPage - 1) * current.meta.per_page + 1;
   return {
    ...current,
    data: rows,
    meta: {
     ...current.meta,
     total,
     last_page: Math.max(1, Math.ceil(total / Math.max(1, current.meta.per_page))),
     from: total === 0 || pageStart > total ? null : pageStart,
     to: rows.length === 0 ? null : Math.min(total, (current.meta.to ?? pageStart) - 1),
    },
   };
  });
 };
 const refreshLeaveMutationViews = async (updated: LeaveRequest) => {
  reconcileLeaveListResponse(updated);
  // Preserve the just-installed list response while central invalidation cancels
  // any older request; then fetch once to refill filtered pages and counts.
  await invalidateRealtimeChange(qc, { entityType: 'leave_request', entityId: updated.id }, [], [listQueryKey]);
  await qc.invalidateQueries({ queryKey: listQueryKey, exact: true });
 };
 const { data: leaveOptions } = useQuery({
 queryKey: ['leaves', 'requests', 'options'],
  queryFn: () => leaveRequestsApi.options(),
 staleTime: 5 * 60 * 1000,
 });
 const statusLabels = new Map((leaveOptions?.statuses ?? []).map((option) => [option.value, option.label]));
 const halfDayLabels = new Map((leaveOptions?.half_day_periods ?? []).map((option) => [option.value, option.label]));
 const statusLabel = (value: string) => statusLabels.get(value) ?? value.replaceAll('_', ' ');

 const approveDept = useMutation({
 mutationFn: (id: string) => leaveRequestsApi.approveDept(id),
 onSuccess: async (request) => {
  await refreshLeaveMutationViews(request);
  toast.success('Approved.');
 },
 onError: (error) => {
  void invalidateRealtimeList(qc, 'leave_request');
  reportMutationError(error, 'Approval failed.');
 },
 });
 const approveHR = useMutation({
 mutationFn: (id: string) => leaveRequestsApi.approveHR(id),
 onSuccess: async (request) => {
  await refreshLeaveMutationViews(request);
  toast.success('Approved.');
 },
 onError: (error) => {
  void invalidateRealtimeList(qc, 'leave_request');
  reportMutationError(error, 'Approval failed.');
 },
 });
 const rejectMut = useMutation({
 mutationFn: ({ id, reason }: { id: string; reason: string }) => leaveRequestsApi.reject(id, reason),
 onSuccess: async (request) => {
 await refreshLeaveMutationViews(request);
 toast.success('Rejected.');
 rejectActionTarget.close(); setRejectReason('');
 },
 onError: (error) => {
  void invalidateRealtimeList(qc, 'leave_request');
  reportMutationError(error, 'Reject failed.');
 },
 });

 // ─── Bulk approve ───────────────────────────────────────────────
 //
 // Leave has two approval stages with two permissions, so one selection can
 // contain rows the caller may act on at the dept stage, rows only an HR
 // officer may act on, and rows in neither state. Splitting by stage here is
 // what keeps the request honest: a row the caller cannot approve is never
 // submitted, so the batch cannot half-fail on 403s the UI could have
 // predicted. Anything not submitted is reported as untouched, not as done.
 const splitByStage = (rows: LeaveRequest[]) => {
 const dept = rows.filter((r) => hasLeaveAction(r, 'approve_dept'));
 const hr = rows.filter((r) => hasLeaveAction(r, 'approve_hr'));
 return { dept, hr, untouched: rows.length - dept.length - hr.length };
 };

 /**
  * Submit one stage and normalise its outcome.
  *
  * The try/catch is the whole point. Two sequential awaits used to share one
  * `onError`, so a transport failure on the HR call discarded the dept call's
  * result — and the dept call's rows were already committed, each in its own
  * `DB::transaction`. Five approved rows were reported as "No requests were
  * approved", the `onSettled` refetch failed too, and the list still showed them
  * as pending. That is the defect this whole task set out to remove.
  *
  * A stage whose call did not complete reports `unconfirmed`, not `failed`.
  * Those two are genuinely different: the server said no to a failure, whereas
  * a lost response means the rows may well have gone through. Calling that
  * "failed" would be the same overclaim in the other direction.
  */
 const runStage = async (
 rows: LeaveRequest[],
 submit: (ids: string[]) => Promise<BulkApproveLeaveResult>,
 ) => {
 if (rows.length === 0) return { approved: [] as LeaveRequest[], failed: [] as Array<{ reason: string }>, unconfirmed: 0 };
 try {
 const result = await submit(rows.map((r) => r.id));
 return { approved: result.approved, failed: result.failed, unconfirmed: 0 };
 } catch {
 return { approved: [] as LeaveRequest[], failed: [] as Array<{ reason: string }>, unconfirmed: rows.length };
 }
 };

 const bulkApprove = useMutation({
 mutationFn: async (rows: LeaveRequest[]) => {
 const { dept, hr, untouched } = splitByStage(rows);
 // Sequential rather than parallel: both endpoints write the same rows'
 // approval records, and two batches in flight would race for them.
 const deptResult = await runStage(dept, leaveRequestsApi.bulkApproveDept);
 const hrResult = await runStage(hr, leaveRequestsApi.bulkApproveHR);
 return {
 selected: rows.length,
 approved: deptResult.approved.length + hrResult.approved.length,
 approvedRows: [...deptResult.approved, ...hrResult.approved],
 failed: [...deptResult.failed, ...hrResult.failed],
 unconfirmed: deptResult.unconfirmed + hrResult.unconfirmed,
 untouched,
 };
 },
 // `onSettled` rather than `onSuccess`: a batch that threw partway may still
 // have committed its first half, so the list is stale either way.
 onSettled: async (result) => {
  const approvedRows = result?.approvedRows ?? [];
  if (approvedRows.length > 0) {
   await qc.cancelQueries({ queryKey: listQueryKey, exact: true }, { revert: true });
   approvedRows.forEach(reconcileLeaveListResponse);
   await invalidateRealtimeChanges(
    qc,
    approvedRows.map((request) => ({ entityType: 'leave_request' as const, entityId: request.id })),
    [],
    [listQueryKey],
   );
   await qc.invalidateQueries({ queryKey: listQueryKey, exact: true });
   return;
  }
  await invalidateRealtimeList(qc, 'leave_request');
 },
 onSuccess: (r) => {
 // Unconfirmed first: it is the only outcome the user cannot resolve by
 // reading the list, because the list may itself be stale.
 if (r.unconfirmed > 0) {
 toast.error(
 `Approved ${r.approved} of ${r.selected}. ${r.unconfirmed} could not be confirmed — the request did not complete. Reload before retrying, since some may have gone through.`,
 { duration: 8000 },
 );
 return;
 }
 const reason = r.failed[0]?.reason;
 if (r.failed.length > 0) {
 // Never a success toast for a partial outcome — the count that matters
 // is how many of the selected rows actually moved.
 toast.error(
 `Approved ${r.approved} of ${r.selected}. ${r.failed.length} failed${reason ? `: ${reason}` : '.'}`,
 { duration: 6000 },
 );
 return;
 }
 if (r.untouched > 0) {
 toast(
 `Approved ${r.approved} of ${r.selected}. ${r.untouched} left unchanged — not awaiting your approval.`,
 { duration: 6000 },
 );
 return;
 }
 toast.success(`${r.approved} leave request${r.approved === 1 ? '' : 's'} approved.`);
 },
 // Reachable only if something throws outside the two submissions, which is
 // why the copy no longer asserts that nothing was approved — after
 // `runStage` this handler can no longer know that.
 onError: (e) => reportMutationError(e, 'Bulk approval could not be completed.'),
 });

 const bulkActions: BulkAction<LeaveRequest>[] = [
 {
 label: 'Approve selected',
 variant: 'primary',
 icon: <LuCheck size={13} />,
 onClick: (rows) => {
 const { dept, hr } = splitByStage(rows);
 if (dept.length + hr.length === 0) {
 toast.error('None of the selected requests are awaiting your approval.');
 return;
 }
 bulkApprove.mutate(rows);
 },
 },
 ];

 const counts = {
 pending_dept: all.filter((l) => l.status === 'pending_dept').length,
 pending_hr: all.filter((l) => l.status === 'pending_hr').length,
 approved: all.filter((l) => l.status === 'approved').length,
 rejected: all.filter((l) => ['rejected', 'cancelled'].includes(l.status)).length,
 };
 const grouped = {
 pending_dept: all.filter((l) => l.status === 'pending_dept'),
 pending_hr: all.filter((l) => l.status === 'pending_hr'),
 approved: all.filter((l) => l.status === 'approved'),
 rejected: all.filter((l) => ['rejected', 'cancelled'].includes(l.status)),
 };

 const hasFilters =
 (filters.status !== undefined && filters.status !== '') ||
 (filters.signal !== undefined && filters.signal !== '') ||
 Boolean(filters.from || filters.to || filters.search);

 const columns: Column<LeaveRequest>[] = [
 { key: 'leave_request_no', header: 'No', cell: (r) => <span className="font-mono">{r.leave_request_no}</span> },
 { key: 'employee', header: 'Employee', cell: (r) => <StackedCell primary={r.employee?.full_name ?? '—'} secondary={<span className="font-mono">{r.employee?.employee_no}</span>} /> },
 { key: 'type', header: 'Type', cell: (r) => r.leave_type?.code ?? '—' },
 { key: 'dates', header: 'Dates', cell: (r) => <NumCell>{formatDate(r.start_date)} → {formatDate(r.end_date)}</NumCell> },
 { key: 'days', header: 'Days', align: 'right', cell: (r) => (
 <NumCell>
 {r.days}
 {r.half_day_period && (
 <span className="ml-1 text-2xs font-medium text-muted">· {halfDayLabels.get(r.half_day_period) ?? r.half_day_period}</span>
 )}
 </NumCell>
 ) },
 { key: 'status', header: 'Status', cell: (r) => <Chip variant={leaveStatusVariant[r.status]}>{statusLabels.get(r.status) ?? r.status.replace('_', ' ')}</Chip> },
 {
 key: 'actions',
 header: '',
 align: 'right',
 cell: (r) => (
 <div className="flex items-center justify-end gap-1">
 {r.status === 'pending_dept' && hasLeaveAction(r, 'approve_dept') && (
 <>
 <Button variant="primary" size="xs" disabled={approveDept.isPending || bulkApprove.isPending} onClick={() => { approveDeptActionTarget.open(r); }}>Approve</Button>
 {hasLeaveAction(r, 'reject') && <Button variant="danger" size="xs" onClick={() => { rejectActionTarget.open(r); }}>Reject</Button>}
 </>
 )}
 {r.status === 'pending_hr' && hasLeaveAction(r, 'approve_hr') && (
 <>
 <Button variant="primary" size="xs" disabled={approveHR.isPending || bulkApprove.isPending} onClick={() => { approveHRActionTarget.open(r); }}>Approve</Button>
 {hasLeaveAction(r, 'reject') && <Button variant="danger" size="xs" onClick={() => { rejectActionTarget.open(r); }}>Reject</Button>}
 </>
 )}
 </div>
 ),
 },
 ];

 const filterConfig: FilterConfig[] = filters.signal === 'awaiting_my_approval' ? [] : [
 {
 key: 'status', label: 'Status', type: 'select',
 options: [
 { value: '', label: 'All' },
 ...(leaveOptions?.statuses ?? []),
 ],
 },
 ];

 return (
 <div>
 <PageHeader
 title="Leave requests"
 subtitle={data ? `${data.meta.total} total · ${counts.pending_dept + counts.pending_hr} awaiting approval` : undefined}
 actions={
 <>
 <Button variant="secondary" size="xs" onClick={() => setView(view === 'list' ? 'kanban' : 'list')}>
 {view === 'list' ? 'Kanban view' : 'List view'}
 </Button>
 {can('leave.types.manage') && (
 <>
 <Button variant="secondary" size="xs" icon={<LuListChecks size={14} />} onClick={() => setShowTypes(true)}>
 Manage Types
 </Button>
 <Button variant="secondary" size="xs" icon={<LuRotateCcw size={14} />} onClick={() => setShowYearEnd(true)}>
 Year-End Leave
 </Button>
 </>
 )}
 {can('leave.create') && (
 <Button variant="primary" size="xs" icon={<LuPlus size={14} />} onClick={() => navigate('/hr/leaves/create')}>
 Request leave
 </Button>
 )}
 </>
 }
 />

 <FilterBar
 filters={filterConfig}
 values={filters}
 onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
 onSearch={(search) => setFilters((f) => ({ ...f, search: search || undefined, page: 1 }))}
 searchPlaceholder="Search request no or employee…"
 dateRange={{ fromKey: 'from', toKey: 'to', label: 'Date' }}
 />

 {data && all.length > 0 && (
 <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 px-5 py-4 border-b border-default">
 <StatCard label={statusLabel('pending_dept')} value={counts.pending_dept} helper="in current view" linkTo="/hr/leaves?status=pending_dept" />
 <StatCard label={statusLabel('pending_hr')} value={counts.pending_hr} helper="in current view" linkTo="/hr/leaves?status=pending_hr" />
 <StatCard label={statusLabel('approved')} value={counts.approved} helper="in current view" linkTo="/hr/leaves?status=approved" />
 <StatCard label={`${statusLabel('rejected')} / ${statusLabel('cancelled')}`} value={counts.rejected} helper="in current view" linkTo="/hr/leaves?status=rejected" />
 </div>
 )}

 {isLoading && !data && <SkeletonTable columns={6} rows={6} />}
 {isError && <EmptyState icon="alert-circle" title="Failed to load leave requests" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
 {data && all.length === 0 && (
 <EmptyState
 icon="inbox"
 title={hasFilters ? 'No leave requests match' : 'No leave requests'}
 description={hasFilters
 ? 'Adjust the filters to widen the result.'
 : (can('leave.create') ? 'Submit one to get started.' : 'Nothing to show yet.')}
 action={!hasFilters && can('leave.create') ? <Button variant="primary" onClick={() => navigate('/hr/leaves/create')}>Request leave</Button> : undefined}
 />
 )}

 {data && all.length > 0 && view === 'list' && (
 <div className="px-5 py-4"><DataTable
 tableKey="leave-requests"
 onRowClick={(r) => navigate(`/hr/leaves/${r.id}`)} columns={columns} data={all} meta={data.meta} onPageChange={(page) => setFilters((f) => ({ ...f, page }))}
 onPageSizeChange={(per_page) => setFilters((f) => ({ ...f, per_page, page: 1 }))}
 selectable={all.some((r) => hasLeaveAction(r, 'approve_dept') || hasLeaveAction(r, 'approve_hr'))}
 bulkActions={all.some((r) => hasLeaveAction(r, 'approve_dept') || hasLeaveAction(r, 'approve_hr')) ? bulkActions : undefined} /></div>
 )}

 {data && all.length > 0 && view === 'kanban' && (
 <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 px-5 py-4">
 <KanbanCol title={statusLabel('pending_dept')} variant="warning" items={grouped.pending_dept} />
 <KanbanCol title={statusLabel('pending_hr')} variant="info" items={grouped.pending_hr} />
 <KanbanCol title={statusLabel('approved')} variant="success" items={grouped.approved} />
 <KanbanCol title={`${statusLabel('rejected')} / ${statusLabel('cancelled')}`} variant="neutral" items={grouped.rejected} />
 </div>
 )}

 {rejectActionTarget.targetId && (
 <Modal isOpen onClose={() => { rejectActionTarget.close(); setRejectReason(''); }} size="sm" title="Reject leave request">
 {rejectActionTarget.isChecking ? (
 <p className="text-sm py-2" role="status">Checking the latest request status…</p>
 ) : rejectActionTarget.isUnverified ? (
 <p className="text-sm py-2 text-danger-fg" role="alert">The latest request status could not be checked. Refresh the list before rejecting.</p>
 ) : !rejectTarget || !hasLeaveAction(rejectTarget, 'reject') ? (
 <p className="text-sm py-2" role="alert">This request is no longer available for rejection. Your reason is kept.</p>
 ) : (
 <p className="text-sm py-2">
 Reject <span className="font-mono">{rejectTarget.leave_request_no}</span>?
 </p>
 )}
 <Textarea label="Reason for rejection" required value={rejectReason} onChange={(e) => setRejectReason(e.target.value)} rows={3} />
 <ModalFooter>
 <Button variant="secondary" onClick={() => { rejectActionTarget.close(); setRejectReason(''); }}>Cancel</Button>
 <Button
 variant="danger"
 disabled={!rejectReason.trim() || rejectMut.isPending || !rejectActionTarget.isFresh || !rejectTarget || !hasLeaveAction(rejectTarget, 'reject')}
 loading={rejectMut.isPending}
 onClick={() => rejectActionTarget.isFresh && rejectTarget && hasLeaveAction(rejectTarget, 'reject') && rejectMut.mutate({ id: rejectTarget.id, reason: rejectReason })}
 >
 {rejectMut.isPending ? 'Rejecting…' : 'Confirm reject'}
 </Button>
 </ModalFooter>
 </Modal>
 )}

 <ConfirmDialog
 isOpen={approveDeptActionTarget.targetId !== null}
 onClose={approveDeptActionTarget.close}
 onConfirm={() => {
  if (!approveDeptActionTarget.isFresh || !approveDeptTarget || !hasLeaveAction(approveDeptTarget, 'approve_dept')) return;
  approveDept.mutate(approveDeptTarget.id);
  approveDeptActionTarget.close();
 }}
 title="Approve leave request?"
 description={approveDeptActionTarget.isChecking
  ? 'Checking the latest request status and available actions…'
  : approveDeptActionTarget.isUnverified
   ? 'The latest request status could not be checked. Refresh the list before approving.'
   : !approveDeptTarget || !hasLeaveAction(approveDeptTarget, 'approve_dept')
    ? 'This request is no longer available for department approval.'
    : 'This will grant department-level approval.'}
 confirmLabel="Approve"
 variant="warning"
 pending={approveDept.isPending}
 disabled={!approveDeptActionTarget.isFresh || !approveDeptTarget || !hasLeaveAction(approveDeptTarget, 'approve_dept')}
 />

 <ConfirmDialog
 isOpen={approveHRActionTarget.targetId !== null}
 onClose={approveHRActionTarget.close}
 onConfirm={() => {
  if (!approveHRActionTarget.isFresh || !approveHRTarget || !hasLeaveAction(approveHRTarget, 'approve_hr')) return;
  approveHR.mutate(approveHRTarget.id);
  approveHRActionTarget.close();
 }}
 title="Approve leave request?"
 description={approveHRActionTarget.isChecking
  ? 'Checking the latest request status and available actions…'
  : approveHRActionTarget.isUnverified
   ? 'The latest request status could not be checked. Refresh the list before approving.'
   : !approveHRTarget || !hasLeaveAction(approveHRTarget, 'approve_hr')
    ? 'This request is no longer available for HR approval.'
    : 'This will grant HR-level approval.'}
 confirmLabel="Approve"
 variant="warning"
 pending={approveHR.isPending}
 disabled={!approveHRActionTarget.isFresh || !approveHRTarget || !hasLeaveAction(approveHRTarget, 'approve_hr')}
 />

 {/* Manage Types — PASS 6 consolidation: leave types moved from a standalone page into this modal */}
 <Modal isOpen={showTypes} onClose={() => setShowTypes(false)} size="xl" title="Leave Types">
 <LeaveTypesManager />
 </Modal>

 {/* Year-End Leave — scope cut: standalone page folded into a modal on the Leave page */}
 <YearEndLeaveModal
 open={showYearEnd}
 onClose={() => setShowYearEnd(false)}
 onSuccess={() => { void invalidateRealtimeList(qc, 'leave_request'); }}
 />
 </div>
 );
}

function KanbanCol({
 title, variant, items,
}: {
 title: string;
 variant: 'success' | 'warning' | 'danger' | 'info' | 'neutral';
 items: LeaveRequest[];
}) {
 return (
 <Panel title={<span className="flex items-center gap-2">{title} <Chip variant={variant}>{items.length}</Chip></span>} noPadding>
 {items.length === 0 ? (
 <p className="text-xs text-muted px-4 py-4 text-center">Nothing here.</p>
 ) : (
 <ul className="divide-y divide-subtle">
 {items.map((l) => (
 <li key={l.id} className="px-4 py-3 hover:bg-subtle">
 <Link to={`/hr/leaves/${l.id}`} className="block">
 <div className="text-sm font-medium truncate">{l.employee?.full_name ?? '—'}</div>
 <div className="text-xs font-mono"><span className="text-link">{l.leave_request_no}</span> · {l.leave_type?.code}</div>
 <div className="text-xs mt-0.5 font-mono tabular-nums">{formatDate(l.start_date)} → {formatDate(l.end_date)} · {l.days}d</div>
 </Link>
 </li>
 ))}
 </ul>
 )}
 </Panel>
 );
}
