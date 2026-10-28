import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { adjustmentsApi } from '@/api/payroll/adjustments';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { PageHeader } from '@/components/layout/PageHeader';
import { Textarea } from '@/components/ui/Textarea';
import { usePermission } from '@/hooks/usePermission';
import { formatDate } from '@/lib/formatDate';
import { formatPeso } from '@/lib/formatNumber';
import { reportMutationError } from '@/lib/formErrors';
import { payrollAdjustmentStatusVariant } from '@/lib/statusVariants';
import type { PayrollAdjustment } from '@/types/payroll';


export default function PayrollAdjustmentDetailPage() {
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const { canApprove } = usePermission();
  const [approveOpen, setApproveOpen] = useState(false);
  const [rejectOpen, setRejectOpen] = useState(false);
  const [rejectRemarks, setRejectRemarks] = useState('');
  const { data: adjustment, isLoading, isError, refetch } = useQuery({
    queryKey: ['payroll-adjustment', id],
    queryFn: () => adjustmentsApi.show(id!),
    enabled: Boolean(id),
  });

  const refresh = (updated: PayrollAdjustment) => {
    queryClient.setQueryData(['payroll-adjustment', id], updated);
    queryClient.invalidateQueries({ queryKey: ['payroll-adjustments'] });
  };
  const approveMutation = useMutation({
    mutationFn: () => adjustmentsApi.approve(id!),
    onSuccess: (updated) => {
      refresh(updated);
      setApproveOpen(false);
      toast.success('Adjustment approved.');
    },
    onError: (error) => reportMutationError(error, 'Could not approve the payroll adjustment.'),
  });
  const rejectMutation = useMutation({
    mutationFn: () => adjustmentsApi.reject(id!, rejectRemarks),
    onSuccess: (updated) => {
      refresh(updated);
      setRejectOpen(false);
      setRejectRemarks('');
      toast.success('Adjustment rejected.');
    },
    onError: (error) => reportMutationError(error, 'Could not reject the payroll adjustment.'),
  });

  return (
    <div>
      <PageHeader
        title="Payroll adjustment"
        subtitle={adjustment?.employee?.full_name}
        backTo="/payroll/adjustments"
        backLabel="Adjustments"
        actions={adjustment?.status === 'pending' ? (
          <div className="flex items-center gap-1.5">
            {canApprove('payroll.adjustments.approve') && (
              <Button variant="primary" size="xs" onClick={() => setApproveOpen(true)}>
                Approve
              </Button>
            )}
            {canApprove('payroll.adjustments.reject') && (
              <Button variant="secondary" size="xs" onClick={() => setRejectOpen(true)}>
                Reject
              </Button>
            )}
          </div>
        ) : null}
      />

      <div className="max-w-4xl px-5 py-4">
        {isLoading && (
          <div className="rounded-md border border-default p-5 text-sm text-muted" role="status">
            Loading payroll adjustment…
          </div>
        )}
        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Adjustment unavailable"
            description="This adjustment could not be opened. It may have been removed or you may no longer have access."
            action={<Button variant="secondary" onClick={() => void refetch()}>Try again</Button>}
          />
        )}
        {adjustment && (
          <Panel
            title="Adjustment details"
            meta={<Chip variant={payrollAdjustmentStatusVariant[adjustment.status]}>{adjustment.status_label}</Chip>}
          >
            <dl className="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2">
              <div>
                <dt className="text-xs text-muted">Employee</dt>
                <dd className="mt-1 text-sm text-primary">{adjustment.employee?.full_name ?? '—'}</dd>
                <dd className="font-mono text-xs text-muted">{adjustment.employee?.employee_no ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-xs text-muted">Payroll period</dt>
                <dd className="mt-1 text-sm text-primary">{adjustment.period?.label ?? '—'}</dd>
              </div>
              <div>
                <dt className="text-xs text-muted">Adjustment type</dt>
                <dd className="mt-1 text-sm text-primary">{adjustment.type_label}</dd>
              </div>
              <div>
                <dt className="text-xs text-muted">Amount</dt>
                <dd className="mt-1 font-mono text-sm tabular-nums text-primary">{formatPeso(adjustment.amount)}</dd>
              </div>
              <div>
                <dt className="text-xs text-muted">Submitted</dt>
                <dd className="mt-1 text-sm text-primary">{formatDate(adjustment.created_at)}</dd>
              </div>
              {adjustment.approver && (
                <div>
                  <dt className="text-xs text-muted">Decision by</dt>
                  <dd className="mt-1 text-sm text-primary">{adjustment.approver.name}</dd>
                </div>
              )}
              <div className="sm:col-span-2 border-t border-default pt-4">
                <dt className="text-xs text-muted">Reason</dt>
                <dd className="mt-1 whitespace-pre-wrap break-words text-sm text-primary">{adjustment.reason}</dd>
              </div>
            </dl>
          </Panel>
        )}
      </div>

      <ConfirmDialog
        isOpen={approveOpen}
        onClose={() => setApproveOpen(false)}
        onConfirm={() => approveMutation.mutate()}
        title="Approve payroll adjustment?"
        description={adjustment ? `${formatPeso(adjustment.amount)} for ${adjustment.employee?.full_name ?? 'this employee'}. Once approved, it applies to the next payroll run.` : undefined}
        variant="warning"
        confirmLabel="Approve"
        pending={approveMutation.isPending}
      />

      <Modal isOpen={rejectOpen} onClose={() => setRejectOpen(false)} size="sm" title="Reject adjustment">
        <div className="py-3">
          <p className="mb-2 text-xs text-muted">
            Reject {adjustment?.employee?.full_name}&rsquo;s {adjustment?.type_label.toLowerCase()} of{' '}
            <span className="font-mono">{formatPeso(adjustment?.amount)}</span>?
          </p>
          <Textarea
            label="Reason for rejection"
            value={rejectRemarks}
            onChange={(event) => setRejectRemarks(event.target.value)}
            placeholder="Provide a reason…"
            rows={3}
          />
        </div>
        <ModalFooter>
          <Button variant="secondary" onClick={() => setRejectOpen(false)} disabled={rejectMutation.isPending}>
            Cancel
          </Button>
          <Button
            variant="danger"
            onClick={() => rejectMutation.mutate()}
            disabled={!rejectRemarks.trim() || rejectMutation.isPending}
            loading={rejectMutation.isPending}
          >
            Confirm reject
          </Button>
        </ModalFooter>
      </Modal>
    </div>
  );
}
