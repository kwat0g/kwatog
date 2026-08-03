/** U3 — Self-service > Loans. Lists active + history; lets employee apply. */
import { useEffect, useState } from 'react';
import { useDebounce } from '@/hooks/useDebounce';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Plus } from 'lucide-react';
import toast from 'react-hot-toast';
import { PageHeader } from '@/components/layout/PageHeader';
import {
  Button, Chip, EmptyState, Input, Modal, Select, SkeletonTable, Textarea, Td, Th,
} from '@/components/ui';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { selfServiceApi } from '@/api/self-service';
import type { SelfServiceLoan, SelfServiceLoansResponse } from '@/types/self-service';
import { tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { formatDate } from '@/lib/formatDate';
import { cn } from '@/lib/cn';
import { formatPeso } from '@/lib/formatNumber';

const schema = z.object({
  loan_type: z.string().min(1, 'Required'),
  amount: z.coerce.number().positive('Must be > 0'),
  periods: z.coerce.number().int().min(1),
  reason: z.string().max(500).optional().or(z.literal('')),
});

type FormValues = z.infer<typeof schema>;

function loanColumns(active: boolean, loanTypeLabels: ReadonlyMap<string, string>): Column<SelfServiceLoan>[] {
  return [
    {
      key: 'loan_type',
      header: 'Type',
      cell: (l) => <span className="font-medium">{l.loan_type_label ?? (l.loan_type ? loanTypeLabels.get(l.loan_type) ?? l.loan_type : 'Loan')}</span>,
    },
    {
      key: 'principal',
      header: 'Principal',
      align: 'right',
      cell: (l) => <NumCell>{formatPeso(l.principal)}</NumCell>,
    },
    {
      key: 'outstanding_balance',
      header: 'Outstanding',
      align: 'right',
      cell: (l) => <NumCell className="font-medium">{formatPeso(l.outstanding_balance)}</NumCell>,
    },
    {
      key: 'monthly_amortization',
      header: 'Amortization',
      align: 'right',
      cell: (l) => <NumCell>{formatPeso(l.monthly_amortization)}</NumCell>,
    },
    {
      key: 'progress',
      header: 'Periods',
      align: 'right',
      cell: (l) => (
        <NumCell>
          {l.periods - l.periods_remaining}/{l.periods} paid
        </NumCell>
      ),
    },
    {
      key: 'created_at',
      header: 'Filed',
      cell: (l) => <NumCell>{l.created_at ? formatDate(l.created_at) : '—'}</NumCell>,
    },
    {
      key: 'status',
      header: 'Status',
      cell: (l) => (
        <Chip
          variant={
            l.status === 'pending'
              ? 'warning'
              : active
              ? 'info'
              : l.status === 'paid' || l.status === 'closed'
              ? 'neutral'
              : 'success'
          }
        >
          {l.status_label ?? l.status}
        </Chip>
      ),
    },
  ];
}

export default function SelfServiceLoansPage() {
  const queryClient = useQueryClient();
  const [showApply, setShowApply] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery<SelfServiceLoansResponse>({
    queryKey: ['self-service', 'loans'],
    queryFn: () => selfServiceApi.loans(),
  });

  const apply = useMutation({
    mutationFn: (v: FormValues) =>
      selfServiceApi.applyLoan({
        loan_type: v.loan_type,
        amount: v.amount,
        periods: v.periods,
        reason: v.reason || undefined,
      }),
    onSuccess: (r) => {
      toast.success(r.message ?? 'Loan request submitted.');
      queryClient.invalidateQueries({ queryKey: ['self-service', 'loans'] });
      setShowApply(false);
    },
    onError: () => toast.error('Failed to submit loan request.'),
  });

  const totalCount = (data?.active.length ?? 0) + (data?.history.length ?? 0);
  const loanTypeLabels = new Map((data?.loan_types ?? []).map((type) => [type.value, type.label]));

  return (
    <div>
      <PageHeader
        title="Loans & Cash Advances"
        subtitle={data ? `${data.active.length} active · ${data.history.length} past` : undefined}
        actions={
          <Button variant="primary" size="sm" icon={<Plus size={14} />} onClick={() => setShowApply(true)}>
            Apply for a loan
          </Button>
        }
      />
      <div className="px-5 py-4 space-y-4">
        {isLoading && <SkeletonTable columns={7} rows={4} />}

        {isError && (
          <EmptyState
            icon="alert-circle"
            title="Couldn't load loans"
            description="An error occurred while loading your loans. Please try again."
            action={
              <Button variant="secondary" onClick={() => refetch()}>
                Retry
              </Button>
            }
          />
        )}

        {data && totalCount === 0 && (
          <EmptyState
            icon="inbox"
            title="No loans yet"
            description="You have no loan history. Apply for a company loan or cash advance to get started."
            action={
              <Button variant="primary" icon={<Plus size={14} />} onClick={() => setShowApply(true)}>
                Apply for a loan
              </Button>
            }
          />
        )}

        {data && data.active.length > 0 && (
          <section aria-label="Active loans">
            <h2 className="text-2xs uppercase tracking-wider text-muted font-medium mb-2">
              Active · {data.active.length}
            </h2>
            <DataTable columns={loanColumns(true, loanTypeLabels)} data={data.active} stickyHeader={false} />
          </section>
        )}

        {data && data.history.length > 0 && (
          <section aria-label="Loan history">
            <h2 className="text-2xs uppercase tracking-wider text-muted font-medium mb-2">
              History · {data.history.length}
            </h2>
            <DataTable columns={loanColumns(false, loanTypeLabels)} data={data.history} stickyHeader={false} />
          </section>
        )}

        <ApplyLoanModal
          isOpen={showApply}
          onClose={() => setShowApply(false)}
          onSubmit={(v) => apply.mutate(v)}
          pending={apply.isPending}
          loanTypes={data?.loan_types ?? []}
          maxPayPeriods={data?.max_pay_periods}
        />
      </div>
    </div>
  );
}

function ApplyLoanModal({
  isOpen,
  onClose,
  onSubmit,
  pending,
  loanTypes,
  maxPayPeriods,
}: {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (v: FormValues) => void;
  pending: boolean;
  loanTypes: Array<{ value: string; label: string }>;
  maxPayPeriods?: number;
}) {
  const {
    register,
    handleSubmit,
    formState: { errors },
    reset,
    watch,
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    // The permitted period count is returned by the live loan-limits query;
    // require the employee to choose it rather than using a stale default.
    defaultValues: { loan_type: '', amount: undefined as unknown as number, reason: '' },
  });

  useEffect(() => {
    if (loanTypes.length > 0 && !watch('loan_type')) {
      reset((current) => ({ ...current, loan_type: loanTypes[0].value }));
    }
  }, [loanTypes, reset, watch]);

  const watchedAmount = watch('amount');
  const watchedPeriods = watch('periods');
  const debouncedAmount = useDebounce(watchedAmount, 500);
  const debouncedPeriods = useDebounce(watchedPeriods, 300);

  const { data: preview, isFetching: previewLoading } = useQuery({
    queryKey: ['loan-preview', debouncedAmount, debouncedPeriods],
    queryFn: () => selfServiceApi.previewLoanAmortization(
      Number(debouncedAmount),
      Number(debouncedPeriods),
    ),
    enabled: Number(debouncedAmount) > 0 && Number(debouncedPeriods) >= 1,
    staleTime: 30_000,
  });

  return (
    <Modal
      isOpen={isOpen}
      onClose={() => {
        reset();
        onClose();
      }}
      title="Apply for a Loan"
    >
      <form
        onSubmit={handleSubmit((v) => onSubmit(v))}
        className="space-y-4 py-4"
      >
        <Select
          label="Type"
          {...register('loan_type')}
          error={errors.loan_type?.message}
          required
        >
          {loanTypes.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
        </Select>
        <div className="grid grid-cols-2 gap-3">
          <Input
            label="Amount"
            type="number"
            step="0.01"
            {...register('amount')}
            error={errors.amount?.message}
            prefix="₱"
            className="font-mono"
            required
          />
          <Input
            label="Periods (months)"
            type="number"
            max={maxPayPeriods}
            {...register('periods')}
            error={errors.periods?.message}
            required
          />
        </div>
        {/* Amortization preview */}
        {Number(watchedAmount) > 0 && Number(watchedPeriods) >= 1 && (
          <div className="rounded-md border border-default bg-surface p-3 space-y-2">
            <div className="flex items-center justify-between text-xs text-muted">
              <span>Estimated monthly deduction</span>
              {previewLoading && <span className="font-mono tabular-nums">…</span>}
              {!previewLoading && preview && (
                <span className="font-mono tabular-nums font-medium text-primary">
                  {formatPeso(preview.monthly_amortization)}
                </span>
              )}
            </div>
            {preview && preview.schedule.length > 0 && (
              <div className="max-h-44 overflow-y-auto rounded border border-subtle">
                <table className={cn(tableCls, 'font-mono tabular-nums')}>
                  <thead>
                    <tr className={theadTrCls}>
                      <Th className="font-normal">Period</Th>
                      <Th align="right" className="font-normal">Deduction</Th>
                      <Th align="right" className="font-normal">Balance</Th>
                    </tr>
                  </thead>
                  <tbody>
                    {preview.schedule.slice(0, 24).map((row) => (
                      <tr key={row.period} className={trCls}>
                        <Td>{row.period}</Td>
                        <Td align="right" mono>{formatPeso(row.amount)}</Td>
                        <Td align="right" mono className="text-muted">{formatPeso(row.running_balance)}</Td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            <p className="text-2xs text-muted">
              Estimate only — final schedule set after approval.
            </p>
          </div>
        )}
        <Textarea
          label="Reason (optional)"
          rows={3}
          {...register('reason')}
          error={errors.reason?.message}
        />
        <div className="flex justify-end gap-2 pt-2 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => { reset(); onClose(); }} disabled={pending}>
            Cancel
          </Button>
          <Button type="submit" variant="primary" disabled={pending} loading={pending}>
            {pending ? 'Submitting…' : 'Submit request'}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
