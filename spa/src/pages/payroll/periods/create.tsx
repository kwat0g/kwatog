import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { periodsApi } from '@/api/payroll/periods';
import { Button } from '@/components/ui/Button';
import { Checkbox } from '@/components/ui/Checkbox';
import { Input } from '@/components/ui/Input';
import { PageHeader } from '@/components/layout/PageHeader';
import { applyServerValidationErrors, onFormInvalid } from '@/lib/formErrors';
import { formatPeso } from '@/lib/formatNumber';
import type { ApiValidationError } from '@/types';

const schema = z.object({
  period_start:  z.string().min(1, 'Start date is required'),
  period_end:    z.string().min(1, 'End date is required'),
  payroll_date:  z.string().min(1, 'Payroll date is required'),
  scope_label:   z.string().max(255).optional().or(z.literal('')),
});

type FormValues = z.infer<typeof schema>;

/** Derived cycle feedback: which half the dates land in, or why they're invalid. */
type CycleInfo =
  | { state: 'empty' }
  | { state: 'invalid'; message: string }
  | { state: 'ok'; isFirstHalf: boolean; label: string };

/** Toggle a value in a string[] without mutating it. */
function toggle(list: string[], value: string): string[] {
  return list.includes(value) ? list.filter((v) => v !== value) : [...list, value];
}

export default function CreatePayrollPeriodPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const [submitting, setSubmitting] = useState(false);

  // Scope selections live outside react-hook-form: they are multi-select
  // checkbox groups, not single inputs, and the preview query keys off them.
  const [employmentTypes, setEmploymentTypes] = useState<string[]>([]);
  const [payTypes, setPayTypes] = useState<string[]>([]);
  const [departmentIds, setDepartmentIds] = useState<string[]>([]);

  const { data: periodOptions } = useQuery({
    queryKey: ['payroll-periods', 'options'],
    queryFn: periodsApi.options,
    staleTime: 300_000,
  });

  const { register, handleSubmit, setError, watch, formState: { errors } } = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: { scope_label: '' },
  });

  const periodStart = watch('period_start');
  const periodEnd   = watch('period_end');

  // Mirrors PayrollPeriod::deriveIsFirstHalf() and the server's straddle guard.
  // Purely to show the operator what the server will conclude — the server
  // derives it again and never trusts anything sent from here.
  const cycle = useMemo((): CycleInfo => {
    if (!periodStart || !periodEnd) return { state: 'empty' };

    const start = new Date(`${periodStart}T00:00:00`);
    const end   = new Date(`${periodEnd}T00:00:00`);
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return { state: 'empty' };
    if (end < start) return { state: 'invalid', message: 'Period end must be on or after the period start.' };

    if (start.getFullYear() !== end.getFullYear() || start.getMonth() !== end.getMonth()) {
      return { state: 'invalid', message: 'A payroll cutoff must stay within one month. Create one period per month.' };
    }

    const startsFirstHalf = start.getDate() <= 15;
    if (startsFirstHalf !== (end.getDate() <= 15)) {
      return {
        state: 'invalid',
        message: 'A payroll cutoff must stay within one half of the month — it cannot cross the 15th/16th boundary.',
      };
    }

    const monthLabel = start.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });

    return {
      state: 'ok',
      isFirstHalf: startsFirstHalf,
      label: `${startsFirstHalf ? '1st half' : '2nd half'} of ${monthLabel}`,
    };
  }, [periodStart, periodEnd]);

  const isCompanyWide = employmentTypes.length === 0 && payTypes.length === 0 && departmentIds.length === 0;

  const scopePayload = useMemo(() => ({
    period_start: periodStart,
    period_end: periodEnd,
    scope_employment_types: employmentTypes,
    scope_pay_types: payTypes,
    scope_department_ids: departmentIds,
  }), [periodStart, periodEnd, employmentTypes, payTypes, departmentIds]);

  // Live headcount + collision check. Only runs once both dates are set, since
  // the preview is meaningless without a window to check claims against.
  const { data: preview, isFetching: previewLoading } = useQuery({
    queryKey: ['payroll-periods', 'scope-preview', scopePayload],
    queryFn: () => periodsApi.scopePreview(scopePayload),
    enabled: Boolean(periodStart && periodEnd) && cycle.state === 'ok',
    staleTime: 30_000,
  });

  const mutation = useMutation({
    mutationFn: (data: FormValues) => periodsApi.create({
      period_start: data.period_start,
      period_end: data.period_end,
      payroll_date: data.payroll_date,
      // Send only what is actually selected so an unscoped run stays
      // unambiguously company-wide on the server.
      ...(employmentTypes.length ? { scope_employment_types: employmentTypes } : {}),
      ...(payTypes.length ? { scope_pay_types: payTypes } : {}),
      ...(departmentIds.length ? { scope_department_ids: departmentIds } : {}),
      ...(data.scope_label ? { scope_label: data.scope_label } : {}),
    }),
    onSuccess: (period) => {
      qc.invalidateQueries({ queryKey: ['payroll-periods'] });
      toast.success('Payroll period created.');
      navigate(`/payroll/periods/${period.id}`);
    },
    onError: (error: AxiosError<ApiValidationError>) => {
      if (!applyServerValidationErrors(error, setError, 'Failed to create payroll period.')) {
        toast.error(error.response?.data?.message ?? 'Failed to create payroll period.');
      }
    },
  });

  const onSubmit = (data: FormValues) => {
    setSubmitting(true);
    mutation.mutate(data, { onSettled: () => setSubmitting(false) });
  };

  const busy = submitting || mutation.isPending;
  const blocked = (preview?.already_paid_count ?? 0) > 0
    || preview?.employee_count === 0
    || cycle.state === 'invalid';

  return (
    <div>
      <PageHeader title="New Payroll Period" backTo="/payroll/periods" backLabel="Payroll" breadcrumbs={[{ label: 'Payroll', href: '/payroll/periods' }, { label: 'Periods', href: '/payroll/periods' }, { label: 'New Period' }]} />
      <form onSubmit={handleSubmit(onSubmit, onFormInvalid<FormValues>())} className="max-w-2xl mx-auto px-5 py-4">
        <fieldset className="mb-8">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-4">Schedule</legend>
          <div className="grid grid-cols-2 gap-3">
            <Input label="Period start" type="date" required {...register('period_start')} error={errors.period_start?.message} />
            <Input label="Period end"   type="date" required {...register('period_end')}   error={errors.period_end?.message} />
            <Input label="Payroll date" type="date" required {...register('payroll_date')} error={errors.payroll_date?.message} />
          </div>

          {/* The cycle is DERIVED from the dates, never chosen. It used to be a
              dropdown, which let the label contradict the window (Aug 16–31
              marked "1st half"). That inverted the pay-cycle key, so the same
              employee could be paid twice in one month, and it moved government
              contributions onto the wrong cutoff. Shown read-only instead. */}
          <div className="mt-3 rounded-md border border-default p-3">
            <p className="text-xs uppercase tracking-wider text-muted font-medium mb-1">Cycle</p>
            {cycle.state === 'empty' && (
              <p className="text-sm text-muted">Set the period start and end to determine the cycle.</p>
            )}
            {cycle.state === 'invalid' && (
              <p className="text-sm text-danger">{cycle.message}</p>
            )}
            {cycle.state === 'ok' && (
              <p className="text-sm">
                <span className="font-medium">{cycle.label}</span>
                <span className="text-muted">
                  {cycle.isFirstHalf
                    ? ' · government deductions apply on this run'
                    : ' · no government deductions (withheld on the 1st half)'}
                </span>
              </p>
            )}
          </div>
        </fieldset>

        <fieldset className="mb-8">
          <legend className="text-xs uppercase tracking-wider text-muted font-medium mb-1">Who this run pays</legend>
          <p className="text-xs text-muted mb-4">
            Leave everything unchecked to pay the whole company. Selecting filters narrows the run —
            they combine, so <span className="font-medium">Probationary</span> plus a department pays
            probationary staff in that department only.
          </p>

          <div className="grid grid-cols-2 gap-x-6 gap-y-5">
            <div>
              <p className="text-xs font-medium mb-2">Employment type</p>
              <div className="flex flex-col gap-1.5">
                {(periodOptions?.employment_types ?? []).map((option) => (
                  <Checkbox
                    key={option.value}
                    label={option.label}
                    checked={employmentTypes.includes(option.value)}
                    onChange={() => setEmploymentTypes((prev) => toggle(prev, option.value))}
                  />
                ))}
              </div>
            </div>

            <div>
              <p className="text-xs font-medium mb-2">Pay type</p>
              <div className="flex flex-col gap-1.5">
                {(periodOptions?.pay_types ?? []).map((option) => (
                  <Checkbox
                    key={option.value}
                    label={option.label}
                    checked={payTypes.includes(option.value)}
                    onChange={() => setPayTypes((prev) => toggle(prev, option.value))}
                  />
                ))}
              </div>
            </div>

            <div className="col-span-2">
              <p className="text-xs font-medium mb-2">Departments</p>
              <div className="grid grid-cols-2 gap-1.5 max-h-52 overflow-y-auto border border-default rounded-md p-3">
                {(periodOptions?.departments ?? []).map((option) => (
                  <Checkbox
                    key={option.value}
                    label={option.label}
                    checked={departmentIds.includes(option.value)}
                    onChange={() => setDepartmentIds((prev) => toggle(prev, option.value))}
                  />
                ))}
              </div>
              {departmentIds.length > 0 && (
                <button
                  type="button"
                  className="mt-2 text-xs text-accent hover:underline"
                  onClick={() => setDepartmentIds([])}
                >
                  Clear {departmentIds.length} selected
                </button>
              )}
            </div>

            {!isCompanyWide && (
              <div className="col-span-2">
                <Input
                  label="Run name (optional)"
                  placeholder="e.g. Plant contractuals"
                  helper="Shown on the period header so scoped runs are easy to tell apart."
                  {...register('scope_label')}
                  error={errors.scope_label?.message}
                />
              </div>
            )}
          </div>
        </fieldset>

        {periodStart && periodEnd && (
          <div className="mb-8 rounded-md border border-default p-4">
            <div className="flex items-baseline justify-between mb-2">
              <p className="text-xs uppercase tracking-wider text-muted font-medium">Preview</p>
              {previewLoading && <span className="text-xs text-muted">Checking…</span>}
            </div>

            {preview ? (
              <>
                <div className="grid grid-cols-3 gap-4">
                  <div>
                    <p className="text-xs text-muted">Employees paid</p>
                    <p className="font-mono tabular-nums text-lg">
                      {preview.employee_count}
                      <span className="text-xs text-muted"> / {preview.total_active}</span>
                    </p>
                  </div>
                  <div>
                    <p className="text-xs text-muted">Estimated gross</p>
                    <p className="font-mono tabular-nums text-lg">{formatPeso(preview.estimated_gross)}</p>
                  </div>
                  <div>
                    <p className="text-xs text-muted">Scope</p>
                    <p className="text-sm">{preview.is_company_wide ? 'Company-wide' : 'Filtered'}</p>
                  </div>
                </div>

                {preview.employee_count === 0 && (
                  <p className="mt-3 text-xs text-danger">
                    This scope matches no active employee hired on or before {periodEnd}. Compute would have
                    nothing to do — widen the filters.
                  </p>
                )}

                {preview.already_paid_count > 0 && (
                  <div className="mt-3 rounded border border-danger/40 bg-danger/5 p-3">
                    <p className="text-xs text-danger font-medium">
                      {preview.already_paid_count} employee(s) in this scope were already paid for this cutoff.
                    </p>
                    <ul className="mt-1.5 space-y-0.5">
                      {preview.already_paid_sample.map((row) => (
                        <li key={row.employee_no} className="text-xs text-muted">
                          <span className="font-mono">{row.employee_no}</span> {row.name} — {row.period}
                        </li>
                      ))}
                    </ul>
                    <p className="mt-1.5 text-xs text-muted">
                      Narrow this scope, or void the other period first.
                    </p>
                  </div>
                )}
              </>
            ) : (
              <p className="text-xs text-muted">Set the period dates to preview who this run pays.</p>
            )}
          </div>
        )}

        <div className="flex justify-end gap-2 pt-4 border-t border-default">
          <Button type="button" variant="secondary" onClick={() => navigate('/payroll/periods')} disabled={busy}>
            Cancel
          </Button>
          <Button type="submit" variant="primary" disabled={busy || blocked} loading={mutation.isPending}>
            {mutation.isPending ? 'Creating…' : 'Create period'}
          </Button>
        </div>
      </form>
    </div>
  );
}
