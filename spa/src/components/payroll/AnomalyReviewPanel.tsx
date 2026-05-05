/** Task A9 — Payroll Anomaly Review panel embedded on payroll period detail. */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { AlertTriangle, CheckCircle2 } from 'lucide-react';
import { payrollAnomaliesApi } from '@/api/payroll/anomalies';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Textarea } from '@/components/ui/Textarea';
import { usePermission } from '@/hooks/usePermission';
import { formatDateTime } from '@/lib/formatDate';
import type { PayrollAnomalyFlag, PayrollAnomalyType } from '@/types/payroll';

const TYPE_LABEL: Record<PayrollAnomalyType, string> = {
  large_change:   'Large net pay change',
  excessive_ot:   'Excessive overtime',
  high_deduction: 'High deduction ratio',
  first_payroll:  'First payroll',
  zero_pay:       'Zero net pay',
};

const TYPE_VARIANT: Record<PayrollAnomalyType, 'danger' | 'warning' | 'info'> = {
  large_change:   'warning',
  excessive_ot:   'warning',
  high_deduction: 'danger',
  first_payroll:  'info',
  zero_pay:       'danger',
};

interface Props {
  periodId: string;
}

export function AnomalyReviewPanel({ periodId }: Props) {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const [showResolved, setShowResolved] = useState(false);
  const [resolveTarget, setResolveTarget] = useState<PayrollAnomalyFlag | null>(null);
  const [remarks, setRemarks] = useState('');

  const { data, isLoading, isError } = useQuery({
    queryKey: ['payroll', periodId, 'anomalies', { resolved: showResolved }],
    queryFn: () => payrollAnomaliesApi.list(periodId, { is_resolved: showResolved, per_page: 100 }),
    enabled: can('payroll.anomalies.review'),
    placeholderData: (prev) => prev,
  });

  const resolve = useMutation({
    mutationFn: () => payrollAnomaliesApi.resolve(resolveTarget!.id, remarks),
    onSuccess: () => {
      toast.success('Anomaly marked as reviewed');
      queryClient.invalidateQueries({ queryKey: ['payroll', periodId, 'anomalies'] });
      setResolveTarget(null);
      setRemarks('');
    },
    onError: (e) => {
      const msg = e instanceof AxiosError ? e.response?.data?.message : undefined;
      toast.error(msg ?? 'Failed to resolve anomaly');
    },
  });

  if (!can('payroll.anomalies.review')) return null;

  const total = data?.meta.total ?? 0;
  const flags = data?.data ?? [];

  return (
    <Panel
      title="Anomaly review"
      meta={total > 0 ? `${total} ${showResolved ? 'resolved' : 'unresolved'}` : undefined}
      noPadding
    >
      <div className="px-3 pt-2 pb-2 flex items-center justify-between text-xs">
        <span className="text-muted">
          {showResolved
            ? 'Showing resolved anomalies (audit trail).'
            : 'Unresolved anomalies must be reviewed before payroll can be finalised.'}
        </span>
        <button
          onClick={() => setShowResolved((v) => !v)}
          className="text-muted hover:text-default underline-offset-2 hover:underline"
        >
          {showResolved ? 'Show unresolved' : 'Show resolved'}
        </button>
      </div>

      {isLoading && !data && <div className="px-3 pb-3"><SkeletonTable columns={1} rows={3} /></div>}
      {isError && (
        <div className="px-3 pb-3">
          <EmptyState icon="alert-circle" title="Failed to load anomalies" />
        </div>
      )}

      {data && flags.length === 0 && (
        <div className="px-3 pb-3">
          <EmptyState
            icon="check-circle"
            title={showResolved ? 'No resolved anomalies yet' : 'No anomalies flagged'}
            description={
              showResolved
                ? 'Resolved anomalies appear here for audit trail.'
                : 'The payroll computation for this period passed all anomaly checks.'
            }
          />
        </div>
      )}

      {flags.length > 0 && (
        <ul className="divide-y divide-subtle">
          {flags.map((f) => {
            const Icon = f.is_resolved ? CheckCircle2 : AlertTriangle;
            const Detail = (k: string) => (f.details as Record<string, unknown>)[k];
            const previous = Detail('previous');
            const current  = Detail('current');
            return (
              <li key={f.id} className="px-3 py-2.5 flex items-start gap-3">
                <Icon
                  size={14}
                  className={`mt-0.5 shrink-0 ${
                    f.is_resolved ? 'text-success-fg' : f.flag_type === 'zero_pay' || f.flag_type === 'high_deduction' ? 'text-danger-fg' : 'text-warning-fg'
                  }`}
                />
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-2 flex-wrap">
                    <Chip variant={TYPE_VARIANT[f.flag_type]}>{TYPE_LABEL[f.flag_type]}</Chip>
                    {f.employee && (
                      <span className="text-sm text-default">
                        <span className="font-mono text-muted">{f.employee.employee_no}</span>{' '}
                        {f.employee.name}
                      </span>
                    )}
                    {f.is_resolved && <Chip variant="success">resolved</Chip>}
                  </div>
                  {(previous != null || current != null) && (
                    <p className="text-2xs text-muted font-mono tabular-nums mt-0.5">
                      {previous != null && <>previous: <span className="text-default">{String(previous)}</span> </>}
                      {current != null && <>· current: <span className="text-default">{String(current)}</span></>}
                    </p>
                  )}
                  {f.is_resolved && f.resolution_remarks && (
                    <p className="text-xs text-muted mt-0.5">
                      <span className="text-subtle">Remarks:</span> {f.resolution_remarks}
                    </p>
                  )}
                  {f.is_resolved && f.resolved_at && (
                    <p className="text-2xs text-subtle font-mono tabular-nums mt-0.5">
                      Resolved {formatDateTime(f.resolved_at)}
                      {f.resolved_by ? ` · ${f.resolved_by.name}` : ''}
                    </p>
                  )}
                </div>
                {!f.is_resolved && (
                  <Button
                    variant="secondary"
                    size="sm"
                    onClick={() => { setResolveTarget(f); setRemarks(''); }}
                  >
                    Mark as reviewed
                  </Button>
                )}
              </li>
            );
          })}
        </ul>
      )}

      {resolveTarget && (
        <Modal isOpen onClose={() => setResolveTarget(null)} size="sm" title="Resolve anomaly">
          <div className="px-5 py-4 space-y-3">
            <p className="text-sm text-muted">
              Confirm review for <strong>{TYPE_LABEL[resolveTarget.flag_type]}</strong>
              {resolveTarget.employee ? <> on <span className="font-mono">{resolveTarget.employee.employee_no}</span> {resolveTarget.employee.name}</> : null}.
            </p>
            <Textarea
              label="Remarks"
              value={remarks}
              onChange={(e) => setRemarks(e.target.value)}
              placeholder="Reason for accepting this value (verified with employee, override approved by HR head, etc.)"
              rows={4}
            />
            <div className="flex items-center justify-end gap-2 pt-2 border-t border-default">
              <Button variant="secondary" size="sm" onClick={() => setResolveTarget(null)}>Cancel</Button>
              <Button
                variant="primary"
                size="sm"
                onClick={() => resolve.mutate()}
                disabled={resolve.isPending || remarks.trim().length < 3}
                loading={resolve.isPending}
              >
                Mark reviewed
              </Button>
            </div>
          </div>
        </Modal>
      )}
    </Panel>
  );
}
