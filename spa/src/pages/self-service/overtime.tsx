/**
 * Task SS1 — Self-service overtime requests.
 *
 * Web layout: pending + history tables and a modal apply form with hour
 * quick-select, today's shift, and an estimated-pay preview.
 * Backend scopes everything to the session employee (never sends employee_id).
 */
import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { LuPlus } from '@/lib/icons';
import toast from 'react-hot-toast';
import { AxiosError } from 'axios';
import { selfServiceApi } from '@/api/self-service';
import { PageHeader } from '@/components/layout/PageHeader';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { DataTable, NumCell, type Column } from '@/components/ui/DataTable';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Textarea } from '@/components/ui/Textarea';
import { Input } from '@/components/ui/Input';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import { focusRing } from '@/lib/focus';
import { cn } from '@/lib/cn';
import type { ApiValidationError } from '@/types';
import type {
 SelfServiceOvertimeRequest,
 OvertimeStatus,
 ApplyOvertimePayload,
} from '@/types/self-service';

const STATUS_CHIP: Record<OvertimeStatus, 'success' | 'warning' | 'danger'> = {
 pending: 'warning',
 approved: 'success',
 rejected: 'danger',
};


function todayIso(): string {
 // Local YYYY-MM-DD (avoids UTC off-by-one from toISOString()).
 const d = new Date();
 const off = d.getTimezoneOffset() * 60_000;
 return new Date(d.getTime() - off).toISOString().slice(0, 10);
}

function requestColumns(
 onCancel?: (id: string) => void,
 onRestore?: (id: string) => void,
): Column<SelfServiceOvertimeRequest>[] {
 return [
 {
 key: 'date',
 header: 'Date',
 cell: (r) => <NumCell>{r.date ? formatDate(r.date) : '—'}</NumCell>,
 },
 {
 key: 'hours_requested',
 header: 'Hours',
 align: 'right',
 cell: (r) => <NumCell>{r.hours_requested}h</NumCell>,
 },
 {
 key: 'reason',
 header: 'Reason',
 cell: (r) => (
 <div className="max-w-[320px]">
 <span className="text-muted block truncate">{r.reason || '—'}</span>
 {r.status === 'rejected' && r.rejection_reason && (
 <span className="text-xs text-danger-fg block truncate">
 Rejected: {r.rejection_reason}
 </span>
 )}
 </div>
 ),
 },
 {
 key: 'approver',
 header: 'Approver',
 cell: (r) => r.approver ?? '—',
 },
 {
 key: 'status',
 header: 'Status',
 cell: (r) => (
 <Chip variant={r.status ? STATUS_CHIP[r.status] : 'neutral'}>
 {r.status_label ?? r.status ?? '—'}
 </Chip>
 ),
 },
 ...(onCancel
 ? [
 {
 key: 'actions',
 header: '',
 align: 'right' as const,
 cell: (r: SelfServiceOvertimeRequest) =>
 r.status === 'pending' ? (
 <Button
 variant="ghost"
 size="sm"
 className="text-danger-fg hover:bg-danger-bg"
 onClick={() => onCancel(r.id)}
 aria-label="Cancel this overtime request"
 >
 Cancel
 </Button>
 ) : null,
 },
 ]
 : []),
 ...(onRestore
 ? [
 {
 key: 'restore-actions',
 header: '',
 align: 'right' as const,
 cell: (r: SelfServiceOvertimeRequest) =>
 r.can_restore ? (
 <Button
 variant="ghost"
 size="sm"
 className="text-accent-fg hover:bg-accent-bg"
 onClick={() => onRestore(r.id)}
 aria-label="Restore this cancelled overtime request"
 >
 Restore
 </Button>
 ) : null,
 },
 ]
 : []),
 ];
}

export default function SelfServiceOvertimePage() {
 const queryClient = useQueryClient();
 const [modalOpen, setModalOpen] = useState(false);
 const [confirmCancel, setConfirmCancel] = useState<string | null>(null);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['self-service', 'overtime'],
 queryFn: () => selfServiceApi.overtime(),
 });

 const cancel = useMutation({
 mutationFn: (id: string) => selfServiceApi.cancelOvertime(id),
 onSuccess: () => {
 toast.success('Overtime request cancelled.');
 queryClient.invalidateQueries({ queryKey: ['self-service', 'overtime'] });
 },
 onError: () => toast.error('Failed to cancel request.'),
 });

 const restore = useMutation({
 mutationFn: (id: string) => selfServiceApi.restoreOvertime(id),
 onSuccess: () => {
 toast.success('Overtime request restored and resubmitted.');
 queryClient.invalidateQueries({ queryKey: ['self-service', 'overtime'] });
 },
 onError: () => toast.error('Only a request you cancelled can be restored.'),
 });

 return (
 <div>
 <PageHeader
 title="Overtime Requests"
 subtitle={
 data
 ? `${data.pending.length} pending · ${data.history.length} past`
 : undefined
 }
 actions={
 <Button
 variant="primary"
 size="sm"
 icon={<LuPlus size={14} />}
 onClick={() => setModalOpen(true)}
 >
 Apply for OT
 </Button>
 }
 />
 <div className="px-5 py-4 space-y-4">
 {/* LOADING */}
 {isLoading && !data && <SkeletonTable columns={6} rows={5} />}

 {/* ERROR */}
 {isError && (
 <EmptyState
 icon="alert-circle"
 title="Couldn't load overtime requests"
 description="An error occurred while loading your requests. Please try again."
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
 />
 )}

 {/* DATA */}
 {data && data.pending.length === 0 && data.history.length === 0 && (
 <EmptyState
 icon="clipboard-list"
 title="No overtime requests yet"
 description="Apply for overtime to see your requests here."
 action={
 <Button variant="primary" icon={<LuPlus size={14} />} onClick={() => setModalOpen(true)}>
 Apply for OT
 </Button>
 }
 />
 )}

 {data && data.pending.length > 0 && (
 <section aria-label="Pending overtime requests">
 <h2 className="text-2xs uppercase tracking-wider text-muted font-medium mb-2">
 Pending · {data.pending.length}
 </h2>
 <DataTable
 columns={requestColumns((id) => setConfirmCancel(id))}
 data={data.pending}
 stickyHeader={false}
 />
 </section>
 )}

 {data && data.history.length > 0 && (
 <section aria-label="Overtime history">
 <h2 className="text-2xs uppercase tracking-wider text-muted font-medium mb-2">
 History · {data.history.length}
 </h2>
 <DataTable
 columns={requestColumns(undefined, (id) => restore.mutate(id))}
 data={data.history}
 stickyHeader={false}
 />
 </section>
 )}

 {data && <ApplyOvertimeModal
 isOpen={modalOpen}
 onClose={() => setModalOpen(false)}
 shift={data?.todays_shift ?? null}
 hourlyRate={data?.hourly_rate ?? null}
 minimumHours={data.minimum_hours}
 maximumHours={data.maximum_hours}
 premiumMultiplier={data.premium_multiplier}
 onApplied={() => {
 queryClient.invalidateQueries({ queryKey: ['self-service', 'overtime'] });
 setModalOpen(false);
 }}
 />}

 <ConfirmDialog
 isOpen={confirmCancel !== null}
 onClose={() => setConfirmCancel(null)}
 onConfirm={() => { if (confirmCancel) cancel.mutate(confirmCancel); }}
 title="Cancel overtime request?"
 variant="danger"
 confirmLabel="Yes, cancel"
 pending={cancel.isPending}
 />
 </div>
 </div>
 );
}

/* ───────────────────────── Apply modal ───────────────────────── */

function ApplyOvertimeModal({
 isOpen,
 onClose,
 shift,
 hourlyRate,
 minimumHours,
 maximumHours,
 premiumMultiplier,
 onApplied,
}: {
 isOpen: boolean;
 onClose: () => void;
 shift: { name: string; time_in: string; time_out: string } | null;
 hourlyRate: string | null;
 minimumHours: number;
 maximumHours: number;
 premiumMultiplier: number;
 onApplied: () => void;
}) {
 const [date, setDate] = useState(todayIso());
 const [hours, setHours] = useState<number>(minimumHours);
 const [reason, setReason] = useState('');
 const [error, setError] = useState<string | null>(null);

 const estimate = useMemo(() => {
 const rate = Number(hourlyRate ?? 0);
 if (!rate) return null;
 return rate * hours * premiumMultiplier;
 }, [hourlyRate, hours, premiumMultiplier]);

 const mutation = useMutation({
 mutationFn: (payload: ApplyOvertimePayload) => selfServiceApi.applyOvertime(payload),
 onSuccess: () => {
 toast.success('Overtime request submitted for approval.');
 setReason('');
 setHours(minimumHours);
 setDate(todayIso());
 onApplied();
 },
 onError: (err: AxiosError<ApiValidationError>) => {
 const data = err.response?.data;
 if (err.response?.status === 422 && data?.errors) {
 setError(Object.values(data.errors)[0]?.[0] ?? 'Please check your input.');
 } else {
 toast.error('Failed to submit overtime request.');
 }
 },
 });

 const handleSubmit = () => {
 setError(null);
 if (reason.trim().length < 5) {
 setError('Please provide a reason (at least 5 characters).');
 return;
 }
 mutation.mutate({ date, hours_requested: hours, reason: reason.trim() });
 };

 return (
 <Modal isOpen={isOpen} onClose={onClose} title="Apply for Overtime">
 <div className="space-y-4 py-4">
 <div className="grid grid-cols-2 gap-3">
 <Input
 label="Date"
 type="date"
 value={date}
 onChange={(e) => setDate(e.target.value)}
 />
 <div>
 <label className="text-xs text-muted font-medium">Hours</label>
 <div className="flex flex-wrap gap-1.5 mt-1">
 {Array.from({ length: Math.max(0, Math.floor((maximumHours - minimumHours) / 0.5) + 1) }, (_, i) => minimumHours + i * 0.5).map((h) => (
 <button
 key={h}
 type="button"
 onClick={() => setHours(h)}
 className={cn(
 'h-8 min-w-[2.75rem] px-2.5 rounded-md border text-sm font-mono tabular-nums cursor-pointer',
 focusRing,
 hours === h
 ? 'border-accent bg-accent text-accent-fg font-medium'
 : 'border-default bg-canvas text-primary hover:bg-elevated',
 )}
 aria-pressed={hours === h}
 >
 {h.toFixed(1)}
 </button>
 ))}
 </div>
 <p className="text-2xs text-muted mt-1">Maximum {maximumHours} hours per day.</p>
 </div>
 </div>

 <Textarea
 label="Reason"
 value={reason}
 onChange={(e) => setReason(e.target.value)}
 rows={3}
 placeholder="Why is overtime needed? (sent to your Dept Head)"
 error={error ?? undefined}
 />

 {/* Context: shift + estimated pay */}
 <div className="rounded-md border border-default bg-surface p-3 space-y-1 text-xs">
 {shift ? (
 <div className="flex justify-between">
 <span className="text-muted">Your shift</span>
 <span className="font-mono tabular-nums">
 {shift.name} · {shift.time_in?.slice(0, 5)}–{shift.time_out?.slice(0, 5)}
 </span>
 </div>
 ) : (
 <div className="text-muted">No shift assigned for today.</div>
 )}
 {estimate !== null && (
 <div className="flex justify-between">
 <span className="text-muted">Estimated pay</span>
 <span className="font-mono tabular-nums text-primary">
 {formatPeso(hourlyRate)}/hr × {hours.toFixed(1)} × {premiumMultiplier} ={' '}
 <span className="font-medium">{formatPeso(estimate)}</span>
 </span>
 </div>
 )}
 <p className="text-2xs text-muted pt-1">
 Estimate only — final OT pay is computed at payroll.
 </p>
 </div>

 <ModalFooter>
 <Button variant="secondary" onClick={onClose} disabled={mutation.isPending}>
 Cancel
 </Button>
 <Button
 variant="primary"
 onClick={handleSubmit}
 disabled={mutation.isPending}
 loading={mutation.isPending}
 >
 {mutation.isPending ? 'Submitting…' : 'Submit for approval'}
 </Button>
 </ModalFooter>
 </div>
 </Modal>
 );
}
