/** Task A9 — Payroll Anomaly Review panel embedded on payroll period detail. */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { LuTriangleAlert, LuCircleCheck } from '@/lib/icons';
import { payrollAnomaliesApi } from '@/api/payroll/anomalies';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Textarea } from '@/components/ui/Textarea';
import { LinkButton } from '@/components/ui/LinkButton';
import { usePermission } from '@/hooks/usePermission';
import { useQueryRecordTarget } from '@/hooks/useQueryRecordTarget';
import { useAuthStore } from '@/stores/authStore';
import { formatDateTime } from '@/lib/formatDate';
import { invalidateRealtimeChange } from '@/lib/realtime/invalidationMap';
import type { PayrollAnomalyFlag, PayrollAnomalyType, PayrollPeriodStatus } from '@/types/payroll';

interface AnomalyPage {
 data: PayrollAnomalyFlag[];
 meta: { total: number };
}

const TYPE_VARIANT: Record<PayrollAnomalyType, 'danger' | 'warning' | 'info'> = {
 large_change: 'warning',
 excessive_ot: 'warning',
 high_deduction: 'danger',
 first_payroll: 'info',
 zero_pay: 'danger',
 open_attendance: 'warning',
};

interface Props {
 periodId: string;
 periodStatus: PayrollPeriodStatus;
 makerIds: Array<string | null | undefined>;
}

export function AnomalyReviewPanel({ periodId, periodStatus, makerIds }: Props) {
 const queryClient = useQueryClient();
 const { can } = usePermission();
 const actorId = useAuthStore((state) => state.user?.id);
 const [showResolved, setShowResolved] = useState(false);
 const [remarks, setRemarks] = useState('');

 const { data, isLoading, isFetching, isError } = useQuery({
 queryKey: ['payroll', periodId, 'anomalies', { resolved: showResolved }],
 queryFn: () => payrollAnomaliesApi.list(periodId, { is_resolved: showResolved, per_page: 100 }),
 enabled: can('payroll.anomalies.review'),
 });
 const flags = data?.data ?? [];
 const resolveActionTarget = useQueryRecordTarget(flags, { isFetching, isError });
 const resolveTarget = resolveActionTarget.target;
 const targetResolved = resolveTarget?.is_resolved === true;

 const resolve = useMutation({
 mutationFn: () => payrollAnomaliesApi.resolve(resolveActionTarget.targetId!, remarks),
 onSuccess: (resolvedFlag) => {
 toast.success('Anomaly marked as reviewed');
  queryClient.setQueryData<AnomalyPage>(
   ['payroll', periodId, 'anomalies', { resolved: false }],
   (page) => page ? {
    ...page,
    data: page.data.filter((flag) => flag.id !== resolvedFlag.id),
    meta: { ...page.meta, total: Math.max(0, page.meta.total - 1) },
   } : page,
  );
  void invalidateRealtimeChange(
   queryClient,
   { entityType: 'payroll_anomaly', entityId: periodId },
   [['payroll-period', periodId]],
  );
 resolveActionTarget.close();
 setRemarks('');
 },
 onError: (e) => {
 void queryClient.invalidateQueries({ queryKey: ['payroll', periodId, 'anomalies'] });
 const msg = e instanceof AxiosError ? e.response?.data?.message : undefined;
 toast.error(msg ?? 'Failed to resolve anomaly');
 },
 });

 if (!can('payroll.anomalies.review')) return null;

 const total = data?.meta.total ?? 0;
 const hasUnresolved = flags.some((flag) => !flag.is_resolved);
 const blockedReason = makerIds.includes(actorId)
  ? 'You created or computed this payroll. A different authorized reviewer must resolve its anomaly flags.'
  : !['computed', 'approved'].includes(periodStatus)
   ? 'Anomalies can only be reviewed while the period is computed or approved.'
   : null;

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
 <LinkButton tone="muted" onClick={() => setShowResolved((v) => !v)}>
 {showResolved ? 'Show unresolved' : 'Show resolved'}
 </LinkButton>
 </div>

 {isLoading && !data && <div className="px-3 pb-3"><SkeletonTable columns={1} rows={3} /></div>}
 {isError && (
 <div className="px-3 pb-3">
 <EmptyState icon="alert-circle" title="Failed to load anomalies" />
 </div>
 )}

 {!showResolved && hasUnresolved && blockedReason && (
 <p role="note" className="px-3 pb-2 text-xs text-warning-fg">{blockedReason}</p>
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
 const Icon = f.is_resolved ? LuCircleCheck : LuTriangleAlert;
 const Detail = (k: string) => (f.details as Record<string, unknown>)[k];
 const previous = Detail('previous');
 const current = Detail('current');
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
 <Chip variant={TYPE_VARIANT[f.flag_type]}>{f.flag_type_label ?? f.flag_type}</Chip>
 {f.employee && (
 <span className="text-sm text-primary">
 <span className="font-mono text-muted">{f.employee.employee_no}</span>{' '}
 {f.employee.name}
 </span>
 )}
 {f.is_resolved && <Chip variant="success">resolved</Chip>}
 </div>
 {(previous != null || current != null) && (
 <p className="text-2xs text-muted font-mono tabular-nums mt-0.5">
 {previous != null && <>previous: <span className="text-primary">{String(previous)}</span> </>}
 {current != null && <>· current: <span className="text-primary">{String(current)}</span></>}
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
 {!f.is_resolved && !blockedReason && (
 <Button
 variant="secondary"
 size="sm"
 onClick={() => { resolveActionTarget.open(f); setRemarks(''); }}
 >
 Mark as reviewed
 </Button>
 )}
 </li>
 );
 })}
 </ul>
 )}

 {resolveActionTarget.targetId && (
 <Modal isOpen onClose={resolveActionTarget.close} size="sm" title="Resolve anomaly">
 <div className="px-5 py-4 space-y-3">
 {resolveActionTarget.isChecking && (
 <p role="status" className="text-sm text-muted">Checking the latest anomaly status. Your remarks are kept.</p>
 )}
 {resolveActionTarget.isUnverified && (
 <p role="alert" className="text-sm text-danger-fg">The latest status could not be checked. Your remarks are kept; retry the refresh before resolving.</p>
 )}
 {blockedReason && (
 <p role="alert" className="text-sm text-warning-fg">{blockedReason}</p>
 )}
 {!resolveActionTarget.isChecking && !resolveActionTarget.isUnverified && (resolveActionTarget.isUnavailable || targetResolved) && (
 <p role="alert" className="text-sm text-warning-fg">
 This anomaly is no longer unresolved. The list has refreshed. Your remarks are kept; close this dialog to return to the updated list.
 </p>
 )}
 {resolveActionTarget.isFresh && resolveTarget && !targetResolved && (
 <p className="text-sm text-muted">
 Confirm review for <strong>{resolveTarget.flag_type_label ?? resolveTarget.flag_type}</strong>
 {resolveTarget.employee ? <> on <span className="font-mono">{resolveTarget.employee.employee_no}</span> {resolveTarget.employee.name}</> : null}.
 </p>
 )}
 <Textarea
 label="Remarks"
 value={remarks}
 onChange={(e) => setRemarks(e.target.value)}
 placeholder="Reason for accepting this value (verified with employee, override approved by HR head, etc.)"
 rows={4}
 />
 <div className="flex items-center justify-end gap-2 pt-2 border-t border-default">
 <Button variant="secondary" size="sm" onClick={resolveActionTarget.close}>Cancel</Button>
 <Button
 variant="primary"
 size="sm"
 onClick={() => {
  if (!blockedReason && resolveActionTarget.isFresh && resolveTarget && !targetResolved && remarks.trim().length >= 3) {
   resolve.mutate();
  }
 }}
 disabled={
  resolve.isPending ||
  Boolean(blockedReason) ||
  !resolveActionTarget.isFresh ||
  !resolveTarget ||
  targetResolved ||
  remarks.trim().length < 3
 }
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
