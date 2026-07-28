import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Download, RefreshCw, FilePenLine } from 'lucide-react';
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
import { formatPeso } from '@/lib/formatNumber';
import { Td, tableCls, trCls } from '@/components/ui/table-cells';

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
        <PageHeader title="Employee payroll" backTo={`/payroll/periods/${id}`} backLabel="Period" breadcrumbs={[{ label: 'Payroll', href: '/payroll/periods' }, { label: 'Periods', href: '/payroll/periods' }, { label: 'Employee Detail' }]} />
        <EmptyState icon="alert-circle" title="Failed to load payroll"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>} />
      </div>
    );
  }

  const emp = data.employee;
  const earningRows = [
    { label: 'Basic Pay',           value: data.basic_pay },
    { label: 'Leave Pay',           value: data.leave_pay },
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
        breadcrumbs={[
          { label: 'Payroll', href: '/payroll/periods' },
          { label: 'Periods', href: '/payroll/periods' },
          { label: emp ? emp.full_name : 'Employee' },
        ]}
        actions={
          <>
            {data.error_message ? <Chip variant="danger">Failed</Chip> : <Chip variant="success">Computed</Chip>}
            <Button
              variant="secondary"
              size="sm"
              icon={<Download size={14} />}
              onClick={() => void downloadAuthenticatedFile(payrollsApi.payslipUrl(data.id), {
                openInNewTab: true,
                errorMessage: 'Failed to generate the payslip.',
              })}
            >
              Payslip
            </Button>
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
