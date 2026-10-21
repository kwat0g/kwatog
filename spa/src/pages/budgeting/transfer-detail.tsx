import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { budgetingApi } from '@/api/accounting/budgeting';
import { usePermission } from '@/hooks/usePermission';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryErrorState } from '@/components/ui/QueryErrorState';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { reportMutationError } from '@/lib/formErrors';
import toast from 'react-hot-toast';

const STATUS_VARIANT = { pending: 'warning', approved: 'success', rejected: 'neutral' } as const;

export default function BudgetTransferDetailPage() {
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const { canApprove } = usePermission();
  const [confirmReject, setConfirmReject] = useState(false);
  const query = useQuery({
    queryKey: ['budget-transfer', id],
    queryFn: () => budgetingApi.transfer(id!),
    enabled: Boolean(id),
  });
  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['budget-transfer', id] });
    queryClient.invalidateQueries({ queryKey: ['budget-transfers'] });
  };
  const approve = useMutation({
    mutationFn: () => budgetingApi.approveTransfer(id!),
    onSuccess: () => { refresh(); toast.success('Transfer approved and applied.'); },
    onError: (error) => reportMutationError(error, 'Could not approve the transfer.'),
  });
  const reject = useMutation({
    mutationFn: () => budgetingApi.rejectTransfer(id!),
    onSuccess: () => { refresh(); setConfirmReject(false); toast.success('Transfer rejected; nothing moved.'); },
    onError: (error) => reportMutationError(error, 'Could not reject the transfer.'),
  });

  if (query.isLoading) return <SkeletonDetail />;
  if (query.isError) return <QueryErrorState subject="this budget transfer" onRetry={() => void query.refetch()} />;
  const transfer = query.data;
  if (!transfer) return <EmptyState icon="alert-circle" title="Transfer not found" />;

  const canDecide = transfer.status === 'pending' && transfer.can_approve === true && canApprove('budgeting.approve');
  const lineName = (line: typeof transfer.from_line) => [line?.account_code, line?.account_name].filter(Boolean).join(' — ') || 'Unavailable';

  return (
    <div className="p-5 space-y-6">
      <PageHeader
        title={transfer.transfer_number}
        subtitle={<Chip variant={STATUS_VARIANT[transfer.status]}>{transfer.status_label ?? transfer.status}</Chip>}
        backTo="/budgeting/transfers"
        backLabel="Budget transfers"
        actions={canDecide ? <div className="flex gap-2">
          <Button variant="primary" loading={approve.isPending} onClick={() => approve.mutate()}>Approve transfer</Button>
          <Button variant="secondary" onClick={() => setConfirmReject(true)}>Reject</Button>
        </div> : undefined}
      />
      <Panel title="Transfer details">
        <dl className="grid gap-4 p-4 sm:grid-cols-2">
          <div><dt className="text-sm text-muted">Amount</dt><dd className="font-medium">{formatPeso(transfer.amount)}</dd></div>
          <div><dt className="text-sm text-muted">Month</dt><dd className="font-medium uppercase">{transfer.month}</dd></div>
          <div><dt className="text-sm text-muted">From</dt><dd className="font-medium">{lineName(transfer.from_line)}</dd><p className="text-sm text-muted">{transfer.from_line?.budget_name ?? 'Budget unavailable'}</p></div>
          <div><dt className="text-sm text-muted">To</dt><dd className="font-medium">{lineName(transfer.to_line)}</dd><p className="text-sm text-muted">{transfer.to_line?.budget_name ?? 'Budget unavailable'}</p></div>
          <div><dt className="text-sm text-muted">Requested by</dt><dd className="font-medium">{transfer.requested_by?.name ?? 'Unavailable'}</dd></div>
          <div><dt className="text-sm text-muted">Requested</dt><dd className="font-medium">{formatDate(transfer.created_at)}</dd></div>
          <div className="sm:col-span-2"><dt className="text-sm text-muted">Reason</dt><dd className="whitespace-pre-wrap break-words">{transfer.reason}</dd></div>
          {transfer.approved_by && <div><dt className="text-sm text-muted">Approved by</dt><dd className="font-medium">{transfer.approved_by.name} · {transfer.approved_at ? formatDate(transfer.approved_at) : ''}</dd></div>}
          {transfer.rejected_by && <div><dt className="text-sm text-muted">Rejected by</dt><dd className="font-medium">{transfer.rejected_by.name} · {transfer.rejected_at ? formatDate(transfer.rejected_at) : ''}</dd></div>}
        </dl>
      </Panel>
      <p className="text-sm text-muted">Approval changes the two budget lines. Rejection leaves both unchanged.</p>
      <ConfirmDialog
        isOpen={confirmReject}
        onClose={() => setConfirmReject(false)}
        onConfirm={() => reject.mutate()}
        title="Reject this transfer?"
        description="Nothing moves. The requester can submit a corrected transfer."
        variant="warning"
        confirmLabel="Reject"
        pending={reject.isPending}
      />
    </div>
  );
}
