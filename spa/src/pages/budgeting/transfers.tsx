import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Check, X } from 'lucide-react';
import toast from 'react-hot-toast';
import { budgetingApi } from '@/api/accounting/budgeting';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import type { BudgetTransfer } from '@/types/budgeting';

// ─── Transfer form ────────────────────────────────────────────────────────────

const schema = z.object({
  from_budget_line_id: z.string().min(1, 'Required'),
  to_budget_line_id: z.string().min(1, 'Required'),
  amount: z.coerce.number({ invalid_type_error: 'Must be a number' }).positive('Must be > 0'),
  reason: z.string().min(10, 'Reason must be at least 10 characters'),
});

type FormValues = z.infer<typeof schema>;

function TransferFormModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const { register, handleSubmit, reset, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
  });

  const mutation = useMutation({
    mutationFn: (d: FormValues) => budgetingApi.transfers.create({
      from_budget_line_id: d.from_budget_line_id,
      to_budget_line_id: d.to_budget_line_id,
      amount: d.amount,
      reason: d.reason,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['budget-transfers'] });
      toast.success('Transfer request submitted');
      reset();
      onClose();
    },
    onError: () => toast.error('Failed to submit transfer'),
  });

  return (
    <Modal isOpen={open} onClose={onClose} title="New budget transfer">
      <form onSubmit={handleSubmit((d) => mutation.mutate(d))} className="space-y-4">
        <Input
          label="From budget line ID"
          type="text"
          {...register('from_budget_line_id')}
          error={errors.from_budget_line_id?.message}
          required
          placeholder="e.g. yR3kLm"
          helper="Enter the budget line item ID (visible in budget detail)"
        />
        <Input
          label="To budget line ID"
          type="text"
          {...register('to_budget_line_id')}
          error={errors.to_budget_line_id?.message}
          required
          placeholder="e.g. xP4mBn"
        />
        <Input
          label="Amount (₱)"
          type="number"
          step="0.01"
          min="0.01"
          {...register('amount')}
          error={errors.amount?.message}
          required
          className="font-mono"
          placeholder="0.00"
        />
        <Input
          label="Reason"
          {...register('reason')}
          error={errors.reason?.message}
          required
          placeholder="Explain why this reallocation is needed…"
        />
        <div className="flex justify-end gap-2 pt-2 border-t border-default">
          <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="primary" loading={mutation.isPending}>Submit transfer</Button>
        </div>
      </form>
    </Modal>
  );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function BudgetTransfersPage() {
  const [page, setPage] = useState(1);
  const [modalOpen, setModalOpen] = useState(false);
  const [confirmApprove, setConfirmApprove] = useState<string | null>(null);
  const [confirmReject, setConfirmReject] = useState<string | null>(null);
  const { can } = usePermission();
  const qc = useQueryClient();

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['budget-transfers', page],
    queryFn: () => budgetingApi.transfers.list({ page, per_page: 25 }),
    placeholderData: (prev) => prev,
  });

  const approve = useMutation({
    mutationFn: (id: string) => budgetingApi.transfers.approve(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['budget-transfers'] }); toast.success('Transfer approved'); setConfirmApprove(null); },
    onError: () => toast.error('Approval failed'),
  });

  const reject = useMutation({
    mutationFn: (id: string) => budgetingApi.transfers.reject(id),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['budget-transfers'] }); toast.success('Transfer rejected'); setConfirmReject(null); },
    onError: () => toast.error('Rejection failed'),
  });

  const statusVariant = (s: string): 'warning' | 'success' | 'danger' => {
    if (s === 'approved') return 'success';
    if (s === 'rejected') return 'danger';
    return 'warning';
  };

  const columns: Column<BudgetTransfer>[] = [
    {
      key: 'from',
      header: 'From',
      cell: (r) => r.from_line_item?.account
        ? <span className="font-mono text-xs">{r.from_line_item.account.code} — {r.from_line_item.account.name}</span>
        : <span className="text-muted font-mono text-xs">Line #{r.from_budget_line_id}</span>,
    },
    {
      key: 'to',
      header: 'To',
      cell: (r) => r.to_line_item?.account
        ? <span className="font-mono text-xs">{r.to_line_item.account.code} — {r.to_line_item.account.name}</span>
        : <span className="text-muted font-mono text-xs">Line #{r.to_budget_line_id}</span>,
    },
    {
      key: 'amount', header: 'Amount', align: 'right',
      cell: (r) => <NumCell>{formatPeso(r.amount)}</NumCell>,
    },
    {
      key: 'status', header: 'Status',
      cell: (r) => <Chip variant={statusVariant(r.status)}>{r.status}</Chip>,
    },
    {
      key: 'reason', header: 'Reason',
      cell: (r) => <span className="text-xs text-muted line-clamp-1">{r.reason}</span>,
    },
    {
      key: 'actions', header: '',
      cell: (r) => r.status === 'pending' && can('budgeting.manage') ? (
        <div className="flex gap-1">
          <button
            onClick={() => setConfirmApprove(r.id)}
            title="Approve"
            className="p-1 rounded text-muted hover:text-success hover:bg-success/10 transition-colors"
          >
            <Check size={14} />
          </button>
          <button
            onClick={() => setConfirmReject(r.id)}
            title="Reject"
            className="p-1 rounded text-muted hover:text-danger hover:bg-danger/10 transition-colors"
          >
            <X size={14} />
          </button>
        </div>
      ) : null,
    },
  ];

  return (
    <div>
      <PageHeader
        title="Budget transfers"
        subtitle="Reallocate budget between line items"
        breadcrumbs={[{ label: 'Budgeting', href: '/budgeting' }, { label: 'Transfers' }]}
        actions={
          can('budgeting.manage') ? (
            <Button variant="primary" onClick={() => setModalOpen(true)}>New transfer</Button>
          ) : null
        }
      />

      {isLoading && !data && <SkeletonTable columns={5} rows={8} />}
      {isError && (
        <EmptyState icon="alert-circle" title="Failed to load transfers"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
      )}
      {data && data.data.length === 0 && (
        <EmptyState
          icon="arrow-left-right"
          title="No budget transfers yet"
          description="Create a transfer request to reallocate funds between budget line items."
          action={can('budgeting.manage') ? <Button variant="primary" onClick={() => setModalOpen(true)}>New transfer</Button> : undefined}
        />
      )}
      {data && data.data.length > 0 && (
        <div className="px-5 py-4">
          <Panel noPadding>
            <DataTable columns={columns} data={data.data} meta={data.meta} onPageChange={setPage} />
          </Panel>
        </div>
      )}

      <TransferFormModal open={modalOpen} onClose={() => setModalOpen(false)} />

      <ConfirmDialog
        isOpen={confirmApprove !== null}
        onClose={() => setConfirmApprove(null)}
        onConfirm={() => { if (confirmApprove) approve.mutate(confirmApprove); }}
        title="Approve budget transfer?"
        variant="warning"
        confirmLabel="Approve"
        pending={approve.isPending}
      />
      <ConfirmDialog
        isOpen={confirmReject !== null}
        onClose={() => setConfirmReject(null)}
        onConfirm={() => { if (confirmReject) reject.mutate(confirmReject); }}
        title="Reject budget transfer?"
        variant="danger"
        confirmLabel="Reject"
        pending={reject.isPending}
      />
    </div>
  );
}
