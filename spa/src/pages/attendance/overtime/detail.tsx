import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Check, X, RotateCcw } from 'lucide-react';
import { overtimeApi } from '@/api/attendance/overtime';
import { attendancesApi } from '@/api/attendance/attendances';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Panel } from '@/components/ui/Panel';
import { Textarea } from '@/components/ui/Textarea';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useAuthStore } from '@/stores/authStore';
import { formatDate, formatDateTime, formatTime } from '@/lib/formatDate';

export default function OvertimeDetailPage() {
 const { id = '' } = useParams<{ id: string }>();
 const qc = useQueryClient();
 const user = useAuthStore((s) => s.user);
 const { can } = usePermission();
 const [reject, setReject] = useState(false);
 const [reason, setReason] = useState('');
 const [confirmApprove, setConfirmApprove] = useState(false);
 const [confirmCancel, setConfirmCancel] = useState(false);

 const { data: ot, isLoading, isError, refetch } = useQuery({
 queryKey: ['attendance', 'overtime', 'request', id],
 queryFn: () => overtimeApi.show(id),
 });

 // Context panel — the punches behind this OT request, so approvers see
 // shift, clock times and computed hours without leaving the page.
 const { data: attendancePage, isLoading: attLoading } = useQuery({
 queryKey: ['attendance', 'records', ot?.employee?.id ?? null, ot?.date ?? null],
 queryFn: () => attendancesApi.list({ employee_id: ot?.employee?.id ?? '', from: ot?.date, to: ot?.date, per_page: 5 }),
 enabled: Boolean(ot?.employee?.id && ot?.date),
 });
 const attendance = attendancePage?.data?.[0];

 const detailKey = ['attendance', 'overtime', 'request', id];

 function useActionMutation<TVar = void>(
 fn: (v: TVar) => Promise<unknown>,
 nextStatus: string,
 opts: { successMsg: string; errorMsg: string; afterSuccess?: () => void },
 ) {
 return useMutation<unknown, unknown, TVar, { prev?: unknown }>({
 mutationFn: fn,
 onMutate: async () => {
 await qc.cancelQueries({ queryKey: detailKey });
 const prev = qc.getQueryData(detailKey);
 qc.setQueryData(detailKey, (old: typeof ot) => old ? { ...old, status: nextStatus } : old);
 return { prev };
 },
 onError: (_e, _v, ctx) => {
 if (ctx?.prev) qc.setQueryData(detailKey, ctx.prev);
 toast.error(opts.errorMsg);
 },
 onSuccess: () => { toast.success(opts.successMsg); opts.afterSuccess?.(); },
 onSettled: () => { qc.invalidateQueries({ queryKey: ['attendance', 'overtime'] }); },
 });
 }

 const approveMut = useActionMutation(() => overtimeApi.approve(id), 'approved', { successMsg: 'Overtime approved.', errorMsg: 'Failed to approve.' });
 const rejectMut = useActionMutation(() => overtimeApi.reject(id, reason), 'rejected', { successMsg: 'Overtime rejected.', errorMsg: 'Failed to reject.', afterSuccess: () => { setReject(false); setReason(''); } });
 const cancelMut = useActionMutation(() => overtimeApi.cancel(id, reason || undefined), 'rejected', { successMsg: 'Overtime request cancelled.', errorMsg: 'Failed to cancel.', afterSuccess: () => { setReason(''); } });

 if (isLoading) return <SkeletonDetail />;
 if (isError || !ot) {
 return <EmptyState icon="alert-circle" title="Overtime request not found" action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />;
 }

 const isOwner = user?.employee?.id === ot.employee?.id;
 const canApprove = ot.status === 'pending' && can('attendance.ot.approve');
 const canCancel = ot.status === 'pending' && (isOwner || can('attendance.ot.approve'));

 return (
 <div>
 <PageHeader
 title={
 <span className="flex items-center gap-2">
 <span>Overtime request</span>
 <Chip variant={chipVariantForStatus(ot.status)}>{ot.status_label ?? ot.status}</Chip>
 {ot.is_auto_detected && <Chip variant="info">Auto-detected</Chip>}
 </span>
 }
 subtitle={`${ot.employee?.full_name ?? '—'} · ${formatDate(ot.date)}`}
 backTo="/hr/attendance/overtime"
 backLabel="Overtime"
 breadcrumbs={[
 { label: 'HR', href: '/hr/employees' },
 { label: 'Attendance', href: '/hr/attendance' },
 { label: 'Overtime', href: '/hr/attendance/overtime' },
 { label: formatDate(ot.date) },
 ]}
 actions={
 <>
 {canApprove && (
 <>
 <Button variant="primary" size="xs" icon={<Check size={12} />} disabled={approveMut.isPending} loading={approveMut.isPending} onClick={() => setConfirmApprove(true)}>Approve</Button>
 <Button variant="danger" size="xs" icon={<X size={12} />} onClick={() => setReject(true)}>Reject</Button>
 </>
 )}
 {canCancel && (
 <Button variant="secondary" size="sm" icon={<RotateCcw size={12} />} onClick={() => setConfirmCancel(true)} disabled={cancelMut.isPending}>Cancel request</Button>
 )}
 </>
 }
 />

 <div className="grid grid-cols-1 lg:grid-cols-[1fr_320px] gap-4 px-5 py-4">
 <div className="space-y-4">
 <Panel title="Request details">
 <dl className="grid grid-cols-2 gap-4 text-sm">
 <Item label="Employee" value={ot.employee?.full_name} sub={ot.employee?.employee_no} />
 <Item label="Date" value={formatDate(ot.date)} mono />
 <Item label="Hours requested" value={`${ot.hours_requested}h`} mono />
 <Item label="Submitted" value={formatDateTime(ot.created_at)} mono />
 </dl>
 {ot.reason && (
 <div className="mt-4">
 <div className="text-2xs uppercase tracking-wider text-muted font-medium mb-1">Reason</div>
 <p className="text-sm">{ot.reason}</p>
 </div>
 )}
 </Panel>

 <Panel title={`Attendance on ${formatDate(ot.date)}`}>
 {attLoading ? (
 <p className="text-xs text-muted">Loading punches…</p>
 ) : !attendance ? (
 <p className="text-xs text-muted">No attendance record for this date.</p>
 ) : (
 <>
 <dl className="grid grid-cols-2 gap-4 text-sm">
 <Item label="Time in" value={attendance.time_in ? formatTime(attendance.time_in) : 'No punch'} mono />
 <Item label="Time out" value={attendance.time_out ? formatTime(attendance.time_out) : 'No punch'} mono />
 <Item label="Shift" value={attendance.shift?.name ?? '—'} />
 <Item label="Day type" value={attendance.is_rest_day ? 'Rest day' : (attendance.holiday_type ? attendance.holiday_type : 'Regular')} />
 </dl>
 <dl className="grid grid-cols-3 gap-4 text-sm mt-4">
 <Item label="Regular" value={attendance.regular_hours} mono />
 <Item label="OT" value={attendance.overtime_hours} mono />
 <Item label="Night diff" value={attendance.night_diff_hours} mono />
 </dl>
 <div className="text-xs text-muted mt-3">
 {attendance.tardiness_minutes > 0
 ? `${attendance.tardiness_minutes}m tardy`
 : 'No tardiness'}
 {' · '}day rate {attendance.day_type_rate}x
 {attendance.is_manual_entry && ' · manual entry'}
 </div>
 </>
 )}
 </Panel>
 </div>

 <Panel title="Approval">
 <dl className="space-y-3 text-sm">
 <Item label="Status" value={<Chip variant={chipVariantForStatus(ot.status)}>{ot.status_label ?? ot.status}</Chip>} />
 <Item label="Approved by" value={ot.approver?.name ?? '—'} sub={ot.approved_at ? formatDateTime(ot.approved_at) : undefined} />
 </dl>
 {ot.rejection_reason && (
 <div className="mt-3 p-3 bg-danger-bg text-danger-fg rounded-md">
 <div className="text-2xs uppercase tracking-wider font-medium mb-1">Rejection reason</div>
 <p className="text-sm">{ot.rejection_reason}</p>
 </div>
 )}
 {ot.is_auto_detected && (
 <p className="mt-3 text-xs text-muted">
 This request was auto-detected from a biometric punch past the shift end and needs an approver&apos;s confirmation.
 </p>
 )}
 </Panel>
 </div>

 {reject && (
 <Modal isOpen onClose={() => { setReject(false); setReason(''); }} size="sm" title="Reject overtime request">
 <Textarea label="Reason for rejection" required value={reason} onChange={(e) => setReason(e.target.value)} rows={3} />
 <ModalFooter>
 <Button variant="secondary" onClick={() => { setReject(false); setReason(''); }}>Cancel</Button>
 <Button variant="danger" disabled={!reason.trim() || rejectMut.isPending} loading={rejectMut.isPending} onClick={() => rejectMut.mutate()}>
 {rejectMut.isPending ? 'Rejecting…' : 'Confirm reject'}
 </Button>
 </ModalFooter>
 </Modal>
 )}

 <ConfirmDialog
 isOpen={confirmApprove}
 onClose={() => setConfirmApprove(false)}
 onConfirm={() => { approveMut.mutate(); setConfirmApprove(false); }}
 title="Approve overtime request?"
 description={`${ot.hours_requested}h overtime for ${ot.employee?.full_name ?? 'the employee'} on ${formatDate(ot.date)}. This will be included in payroll.`}
 confirmLabel="Approve"
 variant="warning"
 pending={approveMut.isPending}
 />

 <ConfirmDialog
 isOpen={confirmCancel}
 onClose={() => setConfirmCancel(false)}
 onConfirm={() => { cancelMut.mutate(); setConfirmCancel(false); }}
 title="Cancel overtime request?"
 description="The request will be withdrawn and marked as rejected with reason 'Cancelled'."
 confirmLabel="Yes, cancel"
 variant="danger"
 pending={cancelMut.isPending}
 />
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
