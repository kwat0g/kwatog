import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router-dom';
import { Plus } from 'lucide-react';
import toast from 'react-hot-toast';
import { leaveRequestsApi, type LeaveListParams } from '@/api/leave';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { FilterBar, type FilterConfig } from '@/components/ui/FilterBar';
import { Modal } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { Textarea } from '@/components/ui/Textarea';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useUrlFilters } from '@/hooks/useUrlFilters';
import { formatDate } from '@/lib/formatDate';
import type { LeaveRequest } from '@/types/leave';

const HALF_DAY_LABEL: Record<string, string> = { am: 'AM', pm: 'PM' };

const DEFAULT_FILTERS: LeaveListParams = {
 page: 1, per_page: 100, sort: 'created_at', direction: 'desc',
};

export default function LeavesPage() {
 const { can } = usePermission();
 const navigate = useNavigate();
 const qc = useQueryClient();
 const [view, setView] = useState<'list' | 'kanban'>('list');
 // Bound to the URL so dashboard drill-downs (?status=pending_hr) arrive
 // pre-filtered and the browser back button restores the previous view.
 const [filters, setFilters] = useUrlFilters<LeaveListParams>(DEFAULT_FILTERS);

 const [actionTarget, setActionTarget] = useState<{ req: LeaveRequest; mode: 'reject' } | null>(null);
 const [rejectReason, setRejectReason] = useState('');
 const [confirmApproveDept, setConfirmApproveDept] = useState<string | null>(null);
 const [confirmApproveHR, setConfirmApproveHR] = useState<string | null>(null);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['leaves', filters],
 queryFn: () => leaveRequestsApi.list(filters),
 placeholderData: (prev) => prev,
 });
 const { data: leaveOptions } = useQuery({
 queryKey: ['leaves', 'requests', 'options'],
 queryFn: leaveRequestsApi.options,
 staleTime: 5 * 60 * 1000,
 });
 const statusLabels = new Map((leaveOptions?.statuses ?? []).map((option) => [option.value, option.label]));

 const approveDept = useMutation({
 mutationFn: (id: string) => leaveRequestsApi.approveDept(id),
 onSuccess: () => { qc.invalidateQueries({ queryKey: ['leaves'] }); toast.success('Approved.'); },
 onError: () => toast.error('Approval failed.'),
 });
 const approveHR = useMutation({
 mutationFn: (id: string) => leaveRequestsApi.approveHR(id),
 onSuccess: () => { qc.invalidateQueries({ queryKey: ['leaves'] }); toast.success('Approved.'); },
 onError: () => toast.error('Approval failed.'),
 });
 const rejectMut = useMutation({
 mutationFn: ({ id, reason }: { id: string; reason: string }) => leaveRequestsApi.reject(id, reason),
 onSuccess: () => {
 qc.invalidateQueries({ queryKey: ['leaves'] });
 toast.success('Rejected.');
 setActionTarget(null); setRejectReason('');
 },
 onError: () => toast.error('Reject failed.'),
 });

 const all = data?.data ?? [];
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
 <span className="ml-1 text-2xs font-medium text-muted">· {HALF_DAY_LABEL[r.half_day_period] ?? r.half_day_period}</span>
 )}
 </NumCell>
 ) },
 { key: 'status', header: 'Status', cell: (r) => <Chip variant={chipVariantForStatus(r.status)}>{statusLabels.get(r.status) ?? r.status.replace('_', ' ')}</Chip> },
 {
 key: 'actions',
 header: '',
 align: 'right',
 cell: (r) => (
 <div className="flex items-center justify-end gap-1">
 {r.status === 'pending_dept' && can('leave.approve_dept') && (
 <>
 <Button variant="primary" size="sm" disabled={approveDept.isPending} onClick={() => { setConfirmApproveDept(r.id); }}>Approve</Button>
 <Button variant="danger" size="sm" onClick={() => { setActionTarget({ req: r, mode: 'reject' }); }}>Reject</Button>
 </>
 )}
 {r.status === 'pending_hr' && can('leave.approve_hr') && (
 <>
 <Button variant="primary" size="sm" disabled={approveHR.isPending} onClick={() => { setConfirmApproveHR(r.id); }}>Approve</Button>
 <Button variant="danger" size="sm" onClick={() => { setActionTarget({ req: r, mode: 'reject' }); }}>Reject</Button>
 </>
 )}
 </div>
 ),
 },
 ];

 const filterConfig: FilterConfig[] = [
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
 backTo="/hr/leaves"
 backLabel="Leave"
 actions={
 <>
 <Button variant="secondary" size="sm" onClick={() => setView(view === 'list' ? 'kanban' : 'list')}>
 {view === 'list' ? 'Kanban view' : 'List view'}
 </Button>
 {can('leave.create') && (
 <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/hr/leaves/create')}>
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
 <StatCard label="Pending dept" value={counts.pending_dept} helper="in current view" linkTo="/hr/leaves?status=pending_dept" />
 <StatCard label="Pending HR" value={counts.pending_hr} helper="in current view" linkTo="/hr/leaves?status=pending_hr" />
 <StatCard label="Approved" value={counts.approved} helper="in current view" linkTo="/hr/leaves?status=approved" />
 <StatCard label="Rejected / Cancelled" value={counts.rejected} helper="in current view" linkTo="/hr/leaves?status=rejected" />
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
 onRowClick={(r) => navigate(`/hr/leaves/${r.id}`)} columns={columns} data={all} meta={data.meta} onPageChange={(page) => setFilters((f) => ({ ...f, page }))} /></div>
 )}

 {data && all.length > 0 && view === 'kanban' && (
 <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 px-5 py-4">
 <KanbanCol title="Pending dept" variant="warning" items={grouped.pending_dept} />
 <KanbanCol title="Pending HR" variant="info" items={grouped.pending_hr} />
 <KanbanCol title="Approved" variant="success" items={grouped.approved} />
 <KanbanCol title="Rejected / Cancelled" variant="neutral" items={grouped.rejected} />
 </div>
 )}

 {actionTarget && actionTarget.mode === 'reject' && (
 <Modal isOpen onClose={() => { setActionTarget(null); setRejectReason(''); }} size="sm" title="Reject leave request">
 <p className="text-sm py-2">
 Reject <span className="font-mono">{actionTarget.req.leave_request_no}</span>?
 </p>
 <Textarea label="Reason for rejection" required value={rejectReason} onChange={(e) => setRejectReason(e.target.value)} rows={3} />
 <div className="flex justify-end gap-2 pt-3 mt-3 border-t border-default">
 <Button variant="secondary" onClick={() => { setActionTarget(null); setRejectReason(''); }}>Cancel</Button>
 <Button
 variant="danger"
 disabled={!rejectReason.trim() || rejectMut.isPending}
 loading={rejectMut.isPending}
 onClick={() => actionTarget && rejectMut.mutate({ id: actionTarget.req.id, reason: rejectReason })}
 >
 {rejectMut.isPending ? 'Rejecting…' : 'Confirm reject'}
 </Button>
 </div>
 </Modal>
 )}

 <ConfirmDialog
 isOpen={confirmApproveDept !== null}
 onClose={() => setConfirmApproveDept(null)}
 onConfirm={() => { if (confirmApproveDept) approveDept.mutate(confirmApproveDept); setConfirmApproveDept(null); }}
 title="Approve leave request?"
 description="This will grant department-level approval."
 confirmLabel="Approve"
 variant="warning"
 pending={approveDept.isPending}
 />

 <ConfirmDialog
 isOpen={confirmApproveHR !== null}
 onClose={() => setConfirmApproveHR(null)}
 onConfirm={() => { if (confirmApproveHR) approveHR.mutate(confirmApproveHR); setConfirmApproveHR(null); }}
 title="Approve leave request?"
 description="This will grant HR-level approval."
 confirmLabel="Approve"
 variant="warning"
 pending={approveHR.isPending}
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
 <div className="text-xs text-muted font-mono">{l.leave_request_no} · {l.leave_type?.code}</div>
 <div className="text-xs mt-0.5 font-mono tabular-nums">{formatDate(l.start_date)} → {formatDate(l.end_date)} · {l.days}d</div>
 </Link>
 </li>
 ))}
 </ul>
 )}
 </Panel>
 );
}
