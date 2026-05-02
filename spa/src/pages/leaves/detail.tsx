import { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
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
import { usePermission } from '@/hooks/usePermission';
import { useAuthStore } from '@/stores/authStore';
import { formatDate, formatDateTime } from '@/lib/formatDate';

export default function LeaveDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { can } = usePermission();
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
            {req.status === 'pending_dept' && can('leave.approve_dept') && (
              <>
                <Button variant="primary" size="sm" icon={<Check size={12} />} disabled={approveDept.isPending} loading={approveDept.isPending} onClick={() => approveDept.mutate()}>Approve</Button>
                <Button variant="danger" size="sm" icon={<X size={12} />} onClick={() => setReject(true)}>Reject</Button>
              </>
            )}
            {req.status === 'pending_hr' && can('leave.approve_hr') && (
              <>
                <Button variant="primary" size="sm" icon={<Check size={12} />} disabled={approveHR.isPending} loading={approveHR.isPending} onClick={() => approveHR.mutate()}>Approve</Button>
                <Button variant="danger" size="sm" icon={<X size={12} />} onClick={() => setReject(true)}>Reject</Button>
              </>
            )}
            {canCancel && (
              <Button variant="secondary" size="sm" icon={<RotateCcw size={12} />} onClick={() => cancelMut.mutate()} disabled={cancelMut.isPending}>Cancel</Button>
            )}
          </>
        }
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
          <ol className="space-y-3 text-sm">
            <ChainStep
              label="Submitted"
              done
              by={req.employee?.full_name ?? null}
              when={req.created_at}
            />
            <ChainStep
              label="Department head"
              done={!!req.dept_approver}
              active={req.status === 'pending_dept'}
              by={req.dept_approver?.name ?? null}
              when={req.dept_approved_at}
            />
            <ChainStep
              label="HR Officer"
              done={!!req.hr_approver && req.status === 'approved'}
              active={req.status === 'pending_hr'}
              by={req.hr_approver?.name ?? null}
              when={req.hr_approved_at}
            />
          </ol>
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

function ChainStep({
  label, done, active, by, when,
}: {
  label: string;
  done?: boolean;
  active?: boolean;
  by?: string | null;
  when?: string | null;
}) {
  return (
    <li className="flex items-start gap-3">
      <span className={
        'mt-0.5 inline-flex items-center justify-center w-4 h-4 rounded-full ring-1 ring-inset shrink-0 ' +
        (done ? 'bg-success-bg text-success-fg ring-success-fg' :
         active ? 'bg-info-bg text-info-fg ring-info-fg' : 'bg-elevated text-muted ring-default')
      }>
        {done && <Check size={10} />}
      </span>
      <div className="min-w-0">
        <div className={'text-sm ' + (done || active ? 'font-medium text-primary' : 'text-muted')}>{label}</div>
        {by && <div className="text-xs text-muted">{by}</div>}
        {when && <div className="text-xs text-muted font-mono">{formatDateTime(when)}</div>}
      </div>
    </li>
  );
}
