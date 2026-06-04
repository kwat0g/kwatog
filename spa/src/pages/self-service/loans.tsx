import { useState } from 'react';
import { useDebounce } from '@/hooks/useDebounce';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { PageHeader } from '@/components/layout/PageHeader';
import {
  BottomSheet,
  Button,
  Chip,
  EmptyState,
  Input,
  Select,
  SkeletonBlock,
  Textarea,
} from '@/components/ui';
import { selfServiceApi } from '@/api/self-service';
import type { SelfServiceLoan, SelfServiceLoansResponse } from '@/types/self-service';

const schema = z.object({
  loan_type: z.string().min(1, 'Required'),
  amount: z.coerce.number().positive('Must be > 0'),
  periods: z.coerce.number().int().min(1).max(24),
  reason: z.string().max(500).optional().or(z.literal('')),
});

type FormValues = z.infer<typeof schema>;

/** U3 — Self-service > Loans. Lists active + history; lets employee apply. */
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

  return (
    <div>
      <PageHeader title="Loans" backTo="/self-service" backLabel="Dashboard" />
      <div className="px-5 py-4 space-y-4">
        <div className="flex items-center justify-end">
          <Button variant="primary" size="sm" onClick={() => setShowApply(true)}>
            Apply
          </Button>
        </div>

      {isLoading && (
        <div className="space-y-3">
          {[1, 2].map((i) => (
            <SkeletonBlock key={i} className="h-20 rounded-md" />
          ))}
        </div>
      )}

      {isError && (
        <EmptyState
          icon="alert-circle"
          title="Couldn't load loans"
          description="Tap retry to try again."
          action={
            <Button variant="secondary" onClick={() => refetch()}>
              Retry
            </Button>
          }
        />
      )}

      {data && data.active.length === 0 && data.history.length === 0 && (
        <EmptyState icon="inbox" title="No loans yet" description="You have no loan history." />
      )}

      {data && data.active.length > 0 && (
        <section>
          <h2 className="text-2xs uppercase tracking-wider text-muted font-medium mb-2">
            Active
          </h2>
          <div className="space-y-2">
            {data.active.map((loan) => (
              <LoanCard key={loan.id} loan={loan} active />
            ))}
          </div>
        </section>
      )}

      {data && data.history.length > 0 && (
        <section>
          <h2 className="text-2xs uppercase tracking-wider text-muted font-medium mb-2 mt-4">
            History
          </h2>
          <div className="space-y-2">
            {data.history.map((loan) => (
              <LoanCard key={loan.id} loan={loan} />
            ))}
          </div>
        </section>
      )}

      <ApplyLoanSheet
        isOpen={showApply}
        onClose={() => setShowApply(false)}
        onSubmit={(v) => apply.mutate(v)}
        pending={apply.isPending}
      />
      </div>{/* .px-5 py-4 */}
    </div>
  );
}

function LoanCard({ loan, active = false }: { loan: SelfServiceLoan; active?: boolean }) {
  return (
    <article className="border border-default rounded-md p-3 bg-canvas">
      <div className="flex items-center justify-between">
        <div>
          <div className="text-sm font-medium">{loan.loan_type ?? 'Loan'}</div>
          <div className="text-xs text-muted">
            {loan.periods_remaining}/{loan.periods} periods remaining
          </div>
        </div>
        <Chip
          variant={
            loan.status === 'pending'
              ? 'warning'
              : active
              ? 'info'
              : loan.status === 'paid' || loan.status === 'closed'
              ? 'neutral'
              : 'success'
          }
        >
          {loan.status}
        </Chip>
      </div>
      <div className="grid grid-cols-2 gap-2 mt-3 text-xs">
        <div>
          <div className="text-subtle">Principal</div>
          <div className="font-mono tabular-nums">₱ {loan.principal}</div>
        </div>
        <div>
          <div className="text-subtle">Outstanding</div>
          <div className="font-mono tabular-nums font-medium">₱ {loan.outstanding_balance}</div>
        </div>
        <div>
          <div className="text-subtle">Monthly</div>
          <div className="font-mono tabular-nums">₱ {loan.monthly_amortization}</div>
        </div>
      </div>
    </article>
  );
}

function ApplyLoanSheet({
  isOpen,
  onClose,
  onSubmit,
  pending,
}: {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (v: FormValues) => void;
  pending: boolean;
}) {
  const {
    register,
    handleSubmit,
    formState: { errors },
    reset,
    watch,
  } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { loan_type: 'company_loan', amount: 0, periods: 6, reason: '' },
  });

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
    <BottomSheet
      isOpen={isOpen}
      onClose={() => {
        reset();
        onClose();
      }}
      title="Apply for a Loan"
    >
      <form
        onSubmit={handleSubmit((v) => onSubmit(v))}
        className="space-y-4"
      >
        <Select
          label="Type"
          {...register('loan_type')}
          error={errors.loan_type?.message}
          required
        >
          <option value="company_loan">Company Loan</option>
          <option value="cash_advance">Cash Advance</option>
        </Select>
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
          {...register('periods')}
          error={errors.periods?.message}
          required
        />
        {/* Amortization preview */}
        {Number(watchedAmount) > 0 && Number(watchedPeriods) >= 1 && (
          <div className="rounded-md border border-default bg-surface p-3 space-y-2">
            <div className="flex items-center justify-between text-xs text-muted">
              <span>Estimated monthly deduction</span>
              {previewLoading && <span className="font-mono tabular-nums">…</span>}
              {!previewLoading && preview && (
                <span className="font-mono tabular-nums font-medium text-primary">
                  ₱{preview.monthly_amortization}
                </span>
              )}
            </div>
            {preview && preview.schedule.length > 0 && (
              <div className="max-h-36 overflow-y-auto">
                <table className="w-full text-xs font-mono tabular-nums">
                  <thead>
                    <tr className="text-muted border-b border-subtle">
                      <th className="text-left py-1 font-normal">Period</th>
                      <th className="text-right py-1 font-normal">Deduction</th>
                      <th className="text-right py-1 font-normal">Balance</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-subtle">
                    {preview.schedule.slice(0, 24).map((row) => (
                      <tr key={row.period}>
                        <td className="py-1">{row.period}</td>
                        <td className="text-right py-1">₱{row.amount}</td>
                        <td className="text-right py-1 text-muted">₱{row.running_balance}</td>
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
            {pending ? 'Submitting…' : 'Submit Request'}
          </Button>
        </div>
      </form>
    </BottomSheet>
  );
}
