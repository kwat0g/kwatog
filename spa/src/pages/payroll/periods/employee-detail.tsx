import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Download, RefreshCw, FilePenLine } from 'lucide-react';
import toast from 'react-hot-toast';
import { payrollsApi } from '@/api/payroll/payrolls';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonDetail } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { PageHeader } from '@/components/layout/PageHeader';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';

export default function PayrollEmployeeDetailPage() {
  const { id, eid } = useParams<{ id: string; eid: string }>();
  const qc = useQueryClient();
  const { can } = usePermission();

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['payroll', eid],
    queryFn: () => payrollsApi.show(eid!),
    enabled: !!eid,
  });

  const recomputeMutation = useMutation({
    mutationFn: () => payrollsApi.recompute(eid!),
    onSuccess: () => {
      toast.success('Recomputed.');
      qc.invalidateQueries({ queryKey: ['payroll', eid] });
    },
    onError: (e: { response?: { data?: { message?: string } } }) =>
      toast.error(e.response?.data?.message ?? 'Failed to recompute.'),
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
  const earningRows = [
    { label: 'Basic Pay',           value: data.basic_pay },
    { label: 'Overtime Pay',        value: data.overtime_pay },
    { label: 'Night Differential',  value: data.night_diff_pay },
    { label: 'Holiday Premium',     value: data.holiday_pay },
  ].filter((r) => Number(r.value) > 0);

  return (
    <div>
      <PageHeader
        title={emp ? emp.full_name : 'Employee'}
        subtitle={emp ? <>
          <span className="font-mono">{emp.employee_no}</span>
          {emp.department && <> · {emp.department}</>}
          {emp.position && <> · {emp.position}</>}
        </> : null}
        backTo={`/payroll/periods/${id}`} backLabel="Period"
        actions={
          <>
            {data.error_message ? <Chip variant="danger">Failed</Chip> : <Chip variant="success">Computed</Chip>}
            <a
              href={payrollsApi.payslipUrl(data.id)}
              className="inline-flex items-center gap-1 px-3 h-7 text-xs rounded-md border border-default bg-canvas text-primary hover:bg-elevated"
            >
              <Download size={14} /> Payslip
            </a>
            {can('payroll.periods.compute') && (
              <Button variant="secondary" size="sm" icon={<RefreshCw size={14} />}
                onClick={() => recomputeMutation.mutate()}
                disabled={recomputeMutation.isPending} loading={recomputeMutation.isPending}>
                Recompute
              </Button>
            )}
            {can('payroll.adjustments.create') && (
              <Link to="/payroll/adjustments/create"
                state={{ original_payroll_id: data.id, employee: emp }}
                className="inline-flex items-center gap-1 px-3 h-7 text-xs rounded-md bg-accent text-accent-fg hover:bg-accent-hover">
                <FilePenLine size={14} /> Raise adjustment
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

        <div className="grid grid-cols-3 gap-3">
          <StatCard label="Gross Pay"        value={formatPeso(data.gross_pay)} />
          <StatCard label="Total Deductions" value={formatPeso(data.total_deductions)} />
          <StatCard label="Net Pay"          value={formatPeso(data.net_pay)} />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <Panel title="Earnings" noPadding>
            <table className="w-full text-sm">
              <tbody>
                {earningRows.length === 0 && (
                  <tr><td className="py-3 px-3 text-muted text-xs">No earnings on file.</td></tr>
                )}
                {earningRows.map((r) => (
                  <tr key={r.label} className="h-8 border-b border-subtle">
                    <td className="px-3">{r.label}</td>
                    <td className="px-3 text-right font-mono tabular-nums">{formatPeso(r.value)}</td>
                  </tr>
                ))}
                <tr className="h-8 border-t border-default">
                  <td className="px-3 font-medium">Gross Pay</td>
                  <td className="px-3 text-right font-mono tabular-nums font-medium">{formatPeso(data.gross_pay)}</td>
                </tr>
              </tbody>
            </table>
          </Panel>

          <Panel title="Deductions" noPadding>
            <table className="w-full text-sm">
              <tbody>
                {(!data.deduction_details || data.deduction_details.length === 0) && (
                  <tr><td className="py-3 px-3 text-muted text-xs">No deductions for this period.</td></tr>
                )}
                {(data.deduction_details ?? []).map((d, i) => (
                  <tr key={i} className="h-8 border-b border-subtle">
                    <td className="px-3">{d.description ?? d.deduction_type_label}</td>
                    <td className="px-3 text-right font-mono tabular-nums">{formatPeso(d.amount)}</td>
                  </tr>
                ))}
                <tr className="h-8 border-t border-default">
                  <td className="px-3 font-medium">Total Deductions</td>
                  <td className="px-3 text-right font-mono tabular-nums font-medium">{formatPeso(data.total_deductions)}</td>
                </tr>
                {Number(data.adjustment_amount) !== 0 && (
                  <tr className="h-8">
                    <td className="px-3 text-xs text-muted">Adjustment carry-over</td>
                    <td className="px-3 text-right font-mono tabular-nums">{formatPeso(data.adjustment_amount)}</td>
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
