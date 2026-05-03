import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Check, X } from 'lucide-react';
import { loansApi } from '@/api/loans';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { Textarea } from '@/components/ui/Textarea';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { ChainHeader } from '@/components/chain';
import type { ChainStep } from '@/types/chain';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';

export default function LoanDetailPage() {
  const { id = '' } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [reject, setReject] = useState(false);
  const [reason, setReason] = useState('');

  const { data: loan, isLoading, isError, refetch } = useQuery({
    queryKey: ['loans', 'show', id],
    queryFn: () => loansApi.show(id),
  });

  const approve = useMutation({
    mutationFn: () => loansApi.approve(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['loans'] }); toast.success('Approved.'); },
    onError: () => toast.error('Approve failed.'),
  });
  const rejectMut = useMutation({
    mutationFn: () => loansApi.reject(id, reason),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['loans'] }); toast.success('Rejected.'); setReject(false); setReason(''); },
    onError: () => toast.error('Reject failed.'),
  });
  const cancel = useMutation({
    mutationFn: () => loansApi.cancel(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['loans'] }); toast.success('Cancelled.'); },
    onError: () => toast.error('Cancel failed.'),
  });

  if (isLoading) return <SkeletonDetail />;
  if (isError || !loan) {
    return <EmptyState icon="alert-circle" title="Loan not found" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;
  }

  const isPending = loan.status === 'pending';

  const totalPaid = parseFloat(loan.total_paid ?? '0');
  const balance = parseFloat(loan.balance ?? '0');
  const loanChain: ChainStep[] = [
    { key: 'submitted', label: 'Submitted', state: 'done', date: loan.created_at?.slice(0, 10) },
    { key: 'approved',  label: 'Approved',  state: ['active', 'paid'].includes(loan.status) ? 'done' : loan.status === 'pending' ? 'active' : 'pending', date: loan.approved_at?.slice(0, 10) },
    { key: 'disbursed', label: 'Disbursed', state: ['active', 'paid'].includes(loan.status) ? 'done' : 'pending', date: loan.start_date ?? undefined },
    { key: 'repaying',  label: 'Repaying',  state: loan.status === 'paid' ? 'done' : loan.status === 'active' && totalPaid > 0 ? 'active' : 'pending' },
    { key: 'settled',   label: 'Settled',   state: loan.status === 'paid' || balance <= 0 ? 'done' : 'pending', date: loan.end_date ?? undefined },
  ];
  const remainingPercent = parseFloat(loan.principal) > 0
    ? Math.min(100, (parseFloat(loan.total_paid) / parseFloat(loan.principal)) * 100)
    : 0;

  return (
    <div>
      <PageHeader
        title={
          <span className="flex items-center gap-2">
            <span className="font-mono">{loan.loan_no}</span>
            <Chip variant={chipVariantForStatus(loan.status)}>{loan.status}</Chip>
          </span>
        }
        subtitle={`${loan.employee?.full_name} · ${loan.loan_type === 'company_loan' ? 'Company loan' : 'Cash advance'}`}
        backTo="/hr/loans"
        backLabel="Loans"
        actions={
          <>
            {isPending && can('loans.approve') && (
              <>
                <Button variant="primary" size="sm" icon={<Check size={12} />} disabled={approve.isPending} loading={approve.isPending} onClick={() => approve.mutate()}>Approve</Button>
                <Button variant="danger" size="sm" icon={<X size={12} />} onClick={() => setReject(true)}>Reject</Button>
              </>
            )}
            {(isPending || loan.status === 'active') && can('loans.approve') && (
              <Button variant="secondary" size="sm" onClick={() => cancel.mutate()} disabled={cancel.isPending}>Cancel</Button>
            )}
          </>
        }
      />

      <div className="px-5 pt-4">
        <Panel title="Loan lifecycle">
          <ChainHeader steps={loanChain} />
        </Panel>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-4 px-5 py-4">
        <div className="space-y-4">
          <Panel title="Loan summary">
            <div className="grid grid-cols-3 gap-3">
              <Stat label="Principal" value={formatPeso(loan.principal)} />
              <Stat label="Total paid" value={formatPeso(loan.total_paid)} variant="success" />
              <Stat label="Balance" value={formatPeso(loan.balance)} variant="warning" />
            </div>
            <div className="mt-3">
              <div className="flex items-center justify-between text-xs text-muted mb-1">
                <span>Repayment progress</span>
                <span className="font-mono tabular-nums">{remainingPercent.toFixed(1)}%</span>
              </div>
              <div className="h-1.5 bg-elevated rounded-sm overflow-hidden">
                <div className="h-full bg-success" style={{ width: `${remainingPercent}%` }} />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-4 mt-4 text-sm">
              <Item label="Pay periods" value={`${loan.pay_periods_remaining}/${loan.pay_periods_total}`} mono />
              <Item label="Per period" value={formatPeso(loan.monthly_amortization)} mono />
              <Item label="Start date" value={loan.start_date ? formatDate(loan.start_date) : '—'} mono />
              <Item label="End date" value={loan.end_date ? formatDate(loan.end_date) : '—'} mono />
              <Item label="Interest rate" value={`${loan.interest_rate}%`} mono />
              <Item label="Approval chain" value={`${loan.approval_chain_size} steps`} />
            </div>
            {loan.purpose && (
              <div className="mt-3">
                <div className="text-2xs uppercase tracking-wider text-muted font-medium mb-1">Purpose</div>
                <p className="text-sm">{loan.purpose}</p>
              </div>
            )}
          </Panel>

          <Panel title={`Payments (${loan.payments?.length ?? 0})`} noPadding>
            {(loan.payments?.length ?? 0) === 0 ? (
              <p className="text-xs text-muted px-4 py-6 text-center">No payments yet.</p>
            ) : (
              <table className="w-full text-sm">
                <thead className="bg-subtle text-2xs uppercase tracking-wider text-muted">
                  <tr>
                    <th className="h-8 px-4 text-left">Date</th>
                    <th className="h-8 px-4 text-right">Amount</th>
                    <th className="h-8 px-4 text-left">Type</th>
                    <th className="h-8 px-4 text-left">Remarks</th>
                  </tr>
                </thead>
                <tbody>
                  {loan.payments!.map((p) => (
                    <tr key={p.id} className="h-8 border-b border-subtle hover:bg-subtle">
                      <td className="px-4 font-mono">{formatDate(p.payment_date)}</td>
                      <td className="px-4 text-right font-mono tabular-nums font-medium">{formatPeso(p.amount)}</td>
                      <td className="px-4">{p.payment_type.replace('_', ' ')}</td>
                      <td className="px-4 text-muted">{p.remarks ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </Panel>
        </div>

        <div className="space-y-4">
          <Panel title="Approval chain">
            <p className="text-sm text-muted mb-2">{loan.approval_chain_size}-step workflow.</p>
            <p className="text-xs text-muted">
              {loan.loan_type === 'company_loan'
                ? 'Dept Head → Manager → Accounting → VP'
                : 'Dept Head → Accounting → VP'}
            </p>
            <p className="text-xs text-muted mt-2">
              Status: <Chip variant={chipVariantForStatus(loan.status)}>{loan.status}</Chip>
            </p>
          </Panel>
          <Panel title="Employee">
            <div className="text-sm">
              <div className="font-medium">{loan.employee?.full_name}</div>
              <div className="text-xs text-muted font-mono">{loan.employee?.employee_no}</div>
            </div>
          </Panel>
        </div>
      </div>

      {reject && (
        <Modal isOpen onClose={() => { setReject(false); setReason(''); }} size="sm" title="Reject loan request">
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

function Stat({ label, value, variant = 'neutral' }: { label: string; value: string; variant?: 'success' | 'warning' | 'danger' | 'neutral' }) {
  const colour = variant === 'success' ? 'text-success-fg' : variant === 'warning' ? 'text-warning-fg' : variant === 'danger' ? 'text-danger-fg' : 'text-primary';
  return (
    <div className="p-3 border border-default rounded-md bg-surface">
      <div className="text-2xs uppercase tracking-wider text-muted font-medium mb-1">{label}</div>
      <div className={`text-xl font-medium font-mono tabular-nums ${colour}`}>{value}</div>
    </div>
  );
}

function Item({ label, value, mono }: { label: string; value: React.ReactNode; mono?: boolean }) {
  return (
    <div>
      <dt className="text-2xs uppercase tracking-wider text-muted font-medium">{label}</dt>
      <dd className={mono ? 'font-mono tabular-nums' : ''}>{value}</dd>
    </div>
  );
}
