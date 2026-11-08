import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { LuDownload, LuRefreshCw, LuFilePenLine } from '@/lib/icons';
import toast from 'react-hot-toast';
import { downloadAuthenticatedFile } from '@/api/download';
import { payrollsApi } from '@/api/payroll/payrolls';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { useRealtimeRecord } from '@/hooks/useRealtimeRecord';
import { invalidateRealtimeChange } from '@/lib/realtime/invalidationMap';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate } from '@/lib/formatDate';
import { Td, Th, tableCls, trCls } from '@/components/ui/table-cells';

export default function PayrollEmployeeDetailPage() {
 const { id, eid } = useParams<{ id: string; eid: string }>();
 const qc = useQueryClient();
 const { can } = usePermission();

 useRealtimeRecord('payroll_period', id, eid ? [['payroll', eid]] : []);

 const { data, isLoading, isError, refetch } = useQuery({
 queryKey: ['payroll', eid],
 queryFn: () => payrollsApi.show(eid!),
 enabled: !!eid,
 });

 const recomputeMutation = useMutation({
 mutationFn: () => payrollsApi.recompute(eid!),
 onSuccess: (updated) => {
 toast.success('Recomputed.');
 qc.setQueryData(['payroll', eid], updated);
 qc.invalidateQueries({ queryKey: ['payroll-period', id] });
 },
 onError: (e: { response?: { status?: number; data?: { message?: string } } }) => {
 if (e.response?.status === 409 && id) {
 void invalidateRealtimeChange(qc, { entityType: 'payroll_period', entityId: id }, [['payroll', eid]]);
 }
 toast.error(e.response?.data?.message ?? 'Failed to recompute.');
 },
 });

 if (isLoading) return <SkeletonDetail />;
 if (isError || !data) {
 return (
 <div>
 <PageHeader title="Employee payroll" backTo={`/payroll/periods/${id}`} backLabel="Period" />
 <EmptyState icon="alert-circle" title="Failed to load payroll"
 action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
 </div>
 );
 }

 const emp = data.employee;
 const payslipPublished = data.period_status === 'finalized' || data.period_status === 'disbursed';
 const canRecompute = data.available_actions?.recompute === true;
 const recomputeDateBlocked = Boolean(data.compute_available_on);
 const showRecompute = canRecompute || (
 recomputeDateBlocked
 && data.permissions?.recompute === true
 && (data.period_status === 'draft' || data.period_status === 'computed')
 );
 const attendance = data.attendance_summary;
 const attendanceMetrics = attendance ? [
 { label: 'Days counted in payroll', value: data.days_worked ?? '—' },
 { label: 'Present', value: attendance.present_days },
 { label: 'Late', value: attendance.late_days },
 { label: 'Absent', value: attendance.absent_days },
 { label: 'Half-day', value: attendance.half_days },
 { label: 'On leave', value: attendance.leave_days },
 { label: 'Holidays', value: attendance.holiday_days },
 { label: 'Rest days', value: attendance.rest_days },
 { label: 'DTR records', value: attendance.dtr_record_count },
 ] : [];
 const earningRows = [
 { label: 'Basic Pay', value: data.basic_pay },
 { label: 'Leave Pay', value: data.leave_pay },
 { label: 'Overtime Pay', value: data.overtime_pay },
 { label: 'Night Differential', value: data.night_diff_pay },
 { label: 'Holiday Premium', value: data.holiday_pay },
 ].filter((r) => Number(r.value) > 0);
 const grossReductionRows = [
  { label: 'Tardiness deduction', value: data.tardiness_deduction },
  { label: 'Undertime deduction', value: data.undertime_deduction },
 ].filter((r) => Number(r.value) > 0);

 return (
 <div>
 <PageHeader
 title={emp ? emp.full_name : 'Employee'}
 subtitle={emp ? <>
 <span className="font-mono">{emp.employee_no}</span>
 {emp.department && <> · {emp.department}</>}
 {emp.position && <> · {emp.position}</>}
 {data.period_start && data.period_end && <> · {formatDate(data.period_start)} – {formatDate(data.period_end)}</>}
 {data.pay_type && <> · {data.pay_type.replace('_', ' ')}</>}
 </> : null}
 backTo={`/payroll/periods/${id}`} backLabel="Period"
 actions={
 <>
 {data.error_message ? <Chip variant="danger">Failed</Chip> : <Chip variant="success">Computed</Chip>}
 {/* The payslip PDF is publication-gated server-side: it does not exist
 until the period is finalized. A row can now be read while the run is
 still being checked, so the button says why instead of failing on click. */}
 <Button
 variant="secondary"
 size="sm"
 icon={<LuDownload size={14} />}
 disabled={!payslipPublished}
 title={payslipPublished ? undefined : 'Available once the period is finalized'}
 onClick={() => void downloadAuthenticatedFile(payrollsApi.payslipUrl(data.id), {
 openInNewTab: true,
 errorMessage: 'Failed to generate the payslip.',
 })}
 >
 Payslip
 </Button>
 {showRecompute && (
 <div className="flex flex-col items-end gap-1">
 <Button variant="secondary" size="sm" icon={<LuRefreshCw size={14} />}
 onClick={() => recomputeMutation.mutate()}
 disabled={!canRecompute || recomputeMutation.isPending} loading={recomputeMutation.isPending}
 aria-describedby={recomputeDateBlocked ? 'payroll-row-compute-availability' : undefined}>
 Recompute
 </Button>
 {recomputeDateBlocked && (
 <span id="payroll-row-compute-availability" className="text-xs text-muted">
 Available on {formatDate(data.compute_available_on)}
 </span>
 )}
 </div>
 )}
 {can('payroll.adjustments.create') && (
 <Link to="/payroll/adjustments/create"
 state={{ original_payroll_id: data.id, employee: emp }}
 className="inline-flex items-center gap-1 px-3 h-7 text-xs rounded-md bg-accent text-accent-fg hover:bg-accent-hover">
 <LuFilePenLine size={14} /> Raise adjustment
 </Link>
 )}
 </>
 }
 />

 <div className="px-5 py-4 space-y-5">
 {data.error_message && (
 <div className="px-3 py-2 bg-danger-bg text-danger-fg rounded-md text-xs">
 <strong className="block mb-1">Computation error</strong>
 {data.error_message}
 </div>
 )}

 <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
 <StatCard label="Gross Pay" value={formatPeso(data.gross_pay)} />
 <StatCard label="Total Deductions" value={formatPeso(data.total_deductions)} />
 <StatCard label="Net Pay" value={formatPeso(data.net_pay)} />
 </div>

 <Panel title="Attendance for this period" noPadding>
 {attendance ? (
 <>
 <dl className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-x-5 gap-y-4 px-4 py-4">
 {attendanceMetrics.map((metric) => (
 <div key={metric.label}>
 <dt className="text-xs text-muted">{metric.label}</dt>
 <dd className="mt-1 text-lg font-medium tabular-nums text-primary">{metric.value}</dd>
 </div>
 ))}
 </dl>
 <p className="px-4 pb-3 text-xs text-muted">
 Days counted in payroll are the snapshot saved at computation. The other totals use current DTR records. Present includes late and half-day records. Only explicit Absent DTR entries count as absences; dates without a DTR entry are not counted as absent.
 </p>
 {attendance.records.length > 0 ? (
 <div className="w-full overflow-x-auto border-t border-default">
 <table className={tableCls}>
 <thead>
 <tr>
 <Th>Date</Th>
 <Th>Status</Th>
 <Th align="right">Regular hrs</Th>
 <Th align="right">OT hrs</Th>
 <Th align="right">Night hrs</Th>
 <Th align="right">Late min</Th>
 <Th align="right">Undertime min</Th>
 </tr>
 </thead>
 <tbody>
 {attendance.records.map((record) => (
 <tr key={record.date} className={trCls}>
 <Td>{formatDate(record.date)}</Td>
 <Td>{record.status_label}</Td>
 <Td align="right" mono>{Number(record.regular_hours).toFixed(2)}</Td>
 <Td align="right" mono>{Number(record.overtime_hours).toFixed(2)}</Td>
 <Td align="right" mono>{Number(record.night_diff_hours).toFixed(2)}</Td>
 <Td align="right" mono>{record.tardiness_minutes}</Td>
 <Td align="right" mono>{record.undertime_minutes}</Td>
 </tr>
 ))}
 </tbody>
 </table>
 </div>
 ) : (
 <p className="border-t border-default px-4 py-3 text-sm text-muted">
 No DTR entries are recorded for this cutoff.
 </p>
 )}
 </>
 ) : (
 <p className="px-4 py-3 text-sm text-muted">Attendance details are unavailable for this payroll row.</p>
 )}
 </Panel>

 <div className="grid grid-cols-2 gap-4">
 <Panel title="Gross pay calculation" noPadding>
 <table className={tableCls}>
 <tbody>
 {earningRows.length === 0 && (
 <tr><Td className="text-muted text-xs">No earnings on file.</Td></tr>
 )}
 {earningRows.map((r) => (
 <tr key={r.label} className={trCls}>
 <Td>{r.label}</Td>
 <Td align="right" mono>{formatPeso(r.value)}</Td>
 </tr>
 ))}
 {grossReductionRows.map((r) => (
 <tr key={r.label} className={trCls}>
 <Td>{r.label}</Td>
 <Td align="right" mono>−{formatPeso(r.value)}</Td>
 </tr>
 ))}
 <tr className={trCls}>
 <Td className="font-medium">Gross Pay</Td>
 <Td align="right" mono className="font-medium">{formatPeso(data.gross_pay)}</Td>
 </tr>
 </tbody>
 </table>
 </Panel>

 <Panel title="Deductions" noPadding>
 <table className={tableCls}>
 <tbody>
 {(!data.deduction_details || data.deduction_details.length === 0) && (
 <tr><Td className="text-muted text-xs">No deductions for this period.</Td></tr>
 )}
 {(data.deduction_details ?? []).map((d, i) => (
 <tr key={i} className={trCls}>
 <Td>{d.description ?? d.deduction_type_label}</Td>
 <Td align="right" mono>{formatPeso(d.amount)}</Td>
 </tr>
 ))}
 <tr className={trCls}>
 <Td className="font-medium">Total Deductions</Td>
 <Td align="right" mono className="font-medium">{formatPeso(data.total_deductions)}</Td>
 </tr>
 {Number(data.adjustment_amount) !== 0 && (
 <tr className={trCls}>
 <Td className="text-xs text-muted">Adjustment carry-over</Td>
 <Td align="right" mono>{formatPeso(data.adjustment_amount)}</Td>
 </tr>
 )}
 </tbody>
 </table>
 </Panel>
 </div>
 </div>
 </div>
 );
}
