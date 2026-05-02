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
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import type { LeaveRequest } from '@/types/leave';

export default function LeavesPage() {
  const { can } = usePermission();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [view, setView] = useState<'list' | 'kanban'>('list');
  const [filters, setFilters] = useState<LeaveListParams>({ page: 1, per_page: 100, sort: 'created_at', direction: 'desc' });

  const [actionTarget, setActionTarget] = useState<{ req: LeaveRequest; mode: 'reject' } | null>(null);
  const [rejectReason, setRejectReason] = useState('');

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['leaves', filters],
    queryFn: () => leaveRequestsApi.list(filters),
    placeholderData: (prev) => prev,
  });

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
  const grouped = {
    pending_dept: all.filter((l) => l.status === 'pending_dept'),
    pending_hr:   all.filter((l) => l.status === 'pending_hr'),
    approved:     all.filter((l) => l.status === 'approved'),
    rejected:     all.filter((l) => ['rejected', 'cancelled'].includes(l.status)),
  };

  const columns: Column<LeaveRequest>[] = [
    { key: 'leave_request_no', header: 'No', cell: (r) => <Link to={`/leaves/${r.id}`} className="font-mono text-accent hover:underline">{r.leave_request_no}</Link> },
    { key: 'employee', header: 'Employee', cell: (r) => <StackedCell primary={r.employee?.full_name ?? '—'} secondary={<span className="font-mono">{r.employee?.employee_no}</span>} /> },
    { key: 'type', header: 'Type', cell: (r) => r.leave_type?.code ?? '—' },
    { key: 'dates', header: 'Dates', cell: (r) => <NumCell>{formatDate(r.start_date)} → {formatDate(r.end_date)}</NumCell> },
    { key: 'days', header: 'Days', align: 'right', cell: (r) => <NumCell>{r.days}</NumCell> },
    { key: 'status', header: 'Status', cell: (r) => <Chip variant={chipVariantForStatus(r.status)}>{r.status.replace('_', ' ')}</Chip> },
    {
      key: 'actions',
      header: '',
      align: 'right',
      cell: (r) => (
        <div className="flex items-center justify-end gap-1">
          {r.status === 'pending_dept' && can('leave.approve_dept') && (
            <>
              <Button variant="primary" size="sm" disabled={approveDept.isPending} onClick={(e) => { e.stopPropagation(); approveDept.mutate(r.id); }}>Approve</Button>
              <Button variant="danger" size="sm" onClick={(e) => { e.stopPropagation(); setActionTarget({ req: r, mode: 'reject' }); }}>Reject</Button>
            </>
          )}
          {r.status === 'pending_hr' && can('leave.approve_hr') && (
            <>
              <Button variant="primary" size="sm" disabled={approveHR.isPending} onClick={(e) => { e.stopPropagation(); approveHR.mutate(r.id); }}>Approve</Button>
              <Button variant="danger" size="sm" onClick={(e) => { e.stopPropagation(); setActionTarget({ req: r, mode: 'reject' }); }}>Reject</Button>
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
        { value: 'pending_dept', label: 'Pending dept head' },
        { value: 'pending_hr', label: 'Pending HR' },
        { value: 'approved', label: 'Approved' },
        { value: 'rejected', label: 'Rejected' },
        { value: 'cancelled', label: 'Cancelled' },
      ],
    },
  ];

  return (
    <div>
      <PageHeader
        title="Leave requests"
        subtitle={data ? `${data.meta.total} total · ${grouped.pending_dept.length + grouped.pending_hr.length} awaiting approval` : undefined}
        actions={
          <>
            <Button variant="secondary" size="sm" onClick={() => setView(view === 'list' ? 'kanban' : 'list')}>
              {view === 'list' ? 'Kanban view' : 'List view'}
            </Button>
            {can('leave.create') && (
              <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => navigate('/leaves/create')}>
                Request leave
              </Button>
            )}
          </>
        }
      />

      <FilterBar
        filters={filterConfig}
        values={filters}
        onSearch={(search) => setFilters((f) => ({ ...f, search, page: 1 }))}
        onFilter={(key, value) => setFilters((f) => ({ ...f, [key]: value, page: 1 }))}
        searchPlaceholder="Search…"
      />

      {isLoading && !data && <SkeletonTable columns={6} rows={6} />}
      {isError && <EmptyState icon="alert-circle" title="Failed to load leave requests" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />}
      {data && all.length === 0 && (
        <EmptyState
          icon="inbox"
          title="No leave requests"
          description={can('leave.create') ? 'Submit one to get started.' : 'Nothing to show yet.'}
          action={can('leave.create') ? <Button variant="primary" onClick={() => navigate('/leaves/create')}>Request leave</Button> : undefined}
        />
      )}

      {data && all.length > 0 && view === 'list' && (
        <div className="px-5 py-4"><DataTable columns={columns} data={all} meta={data.meta} onPageChange={(page) => setFilters((f) => ({ ...f, page }))} /></div>
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
        <p className="text-xs text-muted px-4 py-6 text-center">Nothing here.</p>
      ) : (
        <ul className="divide-y divide-subtle">
          {items.map((l) => (
            <li key={l.id} className="px-4 py-3">
              <Link to={`/leaves/${l.id}`} className="block">
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
