import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Check, X, RotateCcw } from 'lucide-react';
import { leaveRequestsApi } from '@/api/leave';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { ChainHeader, ApprovalTimeline } from '@/components/chain';
import { buildLeaveChain } from '@/lib/chains';
import { fromLeaveRequest } from '@/lib/approvals';
import { CanDo } from '@/components/guards/CanDo';
import { useAuthStore } from '@/stores/authStore';
import { formatDate } from '@/lib/formatDate';

export default function LeaveDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const user = useAuthStore((s) => s.user);
  const [reject, setReject] = useState(false);
  const [reason, setReason] = useState('');

  const { data: req, isLoading, isError, refetch } = useQuery({
    queryKey: ['leaves', 'request', id],
    queryFn: () => leaveRequestsApi.show(id),
  });

  const approveDept = useMutation({
    mutationFn: () => leaveRequestsApi.approveDept(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['leaves'] }); toast.success('Approved.'); },
    onError: () => toast.error('Failed to approve.'),
  });
  const approveHR = useMutation({
    mutationFn: () => leaveRequestsApi.approveHR(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['leaves'] }); toast.success('Approved.'); },
    onError: () => toast.error('Failed to approve.'),
  });
  const rejectMut = useMutation({
    mutationFn: () => leaveRequestsApi.reject(id, reason),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['leaves'] }); toast.success('Rejected.'); setReject(false); setReason(''); },
    onError: () => toast.error('Failed to reject.'),
  });
  const cancelMut = useMutation({
    mutationFn: () => leaveRequestsApi.cancel(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['leaves'] }); toast.success('Cancelled.'); },
    onError: () => toast.error('Failed to cancel.'),
  });

  if (isLoading) return <SkeletonDetail />;
  if (isError || !req) {
    return <EmptyState icon="alert-circle" title="Leave request not found" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;
  }

  const isOwner = (user as any)?.employee?.id === req.employee?.id;
  const canCancel = isOwner && ['pending_dept', 'pending_hr', 'approved'].includes(req.status);

  return (
    <div>
      <PageHeader
        title={
          <span className="flex items-center gap-2">
            <span className="font-mono">{req.leave_request_no}</span>
            <Chip variant={chipVariantForStatus(req.status)}>{req.status.replace('_', ' ')}</Chip>
          </span>
        }
        subtitle={`${req.employee?.full_name} · ${req.leave_type?.code}`}
        backTo="/hr/leaves"
        backLabel="Leaves"
        actions={
          <>
            {/* Series R/R3 — declarative permission gating via <CanDo>. */}
            {req.status === 'pending_dept' && (
              <CanDo permission="leave.approve_dept">
                <Button variant="primary" size="sm" icon={<Check size={12} />} disabled={approveDept.isPending} loading={approveDept.isPending} onClick={() => approveDept.mutate()}>Approve</Button>
                <Button variant="danger" size="sm" icon={<X size={12} />} onClick={() => setReject(true)}>Reject</Button>
              </CanDo>
            )}
            {req.status === 'pending_hr' && (
              <CanDo permission="leave.approve_hr">
                <Button variant="primary" size="sm" icon={<Check size={12} />} disabled={approveHR.isPending} loading={approveHR.isPending} onClick={() => approveHR.mutate()}>Approve</Button>
                <Button variant="danger" size="sm" icon={<X size={12} />} onClick={() => setReject(true)}>Reject</Button>
              </CanDo>
            )}
            {canCancel && (
              <Button variant="secondary" size="sm" icon={<RotateCcw size={12} />} onClick={() => cancelMut.mutate()} disabled={cancelMut.isPending}>Cancel</Button>
            )}
          </>
        }
        bottom={<ChainHeader steps={buildLeaveChain(req)} className="mt-2" />}
      />

      <div className="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-4 px-5 py-4">
        <div className="space-y-4">
          <Panel title="Request details">
            <dl className="grid grid-cols-2 gap-4 text-sm">
              <Item label="Employee" value={req.employee?.full_name} sub={req.employee?.employee_no} />
              <Item label="Department" value={req.employee?.department ?? '—'} />
              <Item label="Leave type" value={`${req.leave_type?.code} — ${req.leave_type?.name}`} />
              <Item label="Days" value={req.days} mono />
              <Item label="Start date" value={formatDate(req.start_date)} mono />
              <Item label="End date" value={formatDate(req.end_date)} mono />
            </dl>
            {req.reason && (
              <div className="mt-4">
                <div className="text-2xs uppercase tracking-wider text-muted font-medium mb-1">Reason</div>
                <p className="text-sm">{req.reason}</p>
              </div>
            )}
            {req.rejection_reason && (
              <div className="mt-4 p-3 bg-danger-bg text-danger-fg rounded-md">
                <div className="text-2xs uppercase tracking-wider font-medium mb-1">Rejection reason</div>
                <p className="text-sm">{req.rejection_reason}</p>
              </div>
            )}
          </Panel>
        </div>

        <Panel title="Approval chain">
          <ApprovalTimeline steps={fromLeaveRequest(req)} />
        </Panel>
      </div>

      {reject && (
        <Modal isOpen onClose={() => { setReject(false); setReason(''); }} size="sm" title="Reject leave request">
          <Textarea label="Reason for rejection" required value={reason} onChange={(e) => setReason(e.target.value)} rows={3} />
          <div className="flex justify-end gap-2 pt-3 mt-3 border-t border-default">
            <Button variant="secondary" onClick={() => { setReject(false); setReason(''); }}>Cancel</Button>
            <Button variant="danger" disabled={!reason.trim() || rejectMut.isPending} loading={rejectMut.isPending} onClick={() => rejectMut.mutate()}>
              {rejectMut.isPending ? 'Rejecting…' : 'Confirm reject'}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  );
}

function Item({ label, value, sub, mono }: { label: string; value: React.ReactNode; sub?: React.ReactNode; mono?: boolean }) {
  return (
    <div>
      <dt className="text-2xs uppercase tracking-wider text-muted font-medium">{label}</dt>
      <dd className={mono ? 'font-mono tabular-nums' : ''}>{value || <span className="text-text-subtle">—</span>}</dd>
      {sub && <dd className="text-xs text-muted font-mono">{sub}</dd>}
    </div>
  );
}


