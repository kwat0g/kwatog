import { useState, useEffect } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Play, CheckCircle2, Lock, Download, AlertCircle } from 'lucide-react';
import toast from 'react-hot-toast';
import { periodsApi } from '@/api/payroll/periods';
import { payrollsApi, type PayrollListParams } from '@/api/payroll/payrolls';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { DataTable, NumCell, StackedCell, type Column } from '@/components/ui/DataTable';
import { EmptyState } from '@/components/ui/EmptyState';
import { Panel } from '@/components/ui/Panel';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { StatCard } from '@/components/ui/StatCard';
import { Spinner } from '@/components/ui/Spinner';
import { PageHeader } from '@/components/layout/PageHeader';
import { ChainHeader } from '@/components/chain/ChainHeader';
import { LinkedRecords } from '@/components/chain/LinkedRecords';
import type { LinkedGroup } from '@/types/chain';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate, formatRelative } from '@/lib/formatDate';
import type { Payroll, PayrollPeriod } from '@/types/payroll';

const periodStatusVariant = (status: string | null | undefined): ChipVariant => {
  switch (status) {
    case 'finalized': return 'success';
    case 'approved':  return 'info';
    case 'processing': return 'info';
    case 'draft':     return 'warning';
    default:          return 'neutral';
  }
};

export default function PayrollPeriodDetailPage() {
  const { id } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const { can } = usePermission();
  const [activeTab, setActiveTab] = useState<'employees' | 'failures' | 'summary'>('employees');

  const isProcessing = (period?: PayrollPeriod | null) => period?.status === 'processing';

  const { data: period, isLoading: periodLoading, isError: periodError, refetch } = useQuery({
    queryKey: ['payroll-period', id],
    queryFn: () => periodsApi.show(id!),
    enabled: !!id,
    refetchInterval: (q) => isProcessing(q.state.data as PayrollPeriod | undefined) ? 3000 : false,
  });

  const payrollFilters: PayrollListParams = {
    period_id: id, page: 1, per_page: 100,
    failed_only: activeTab === 'failures',
  };
  const { data: payrolls, isLoading: payrollsLoading } = useQuery({
    queryKey: ['payrolls', payrollFilters],
    queryFn: () => payrollsApi.list(payrollFilters),
    enabled: !!id && (activeTab === 'employees' || activeTab === 'failures'),
    placeholderData: (prev) => prev,
  });

  const computeMutation = useMutation({
    mutationFn: () => periodsApi.compute(id!),
    onSuccess: () => {
      toast.success('Computation queued.');
      qc.invalidateQueries({ queryKey: ['payroll-period', id] });
    },
    onError: () => toast.error('Failed to start computation.'),
  });
  const approveMutation = useMutation({
    mutationFn: () => periodsApi.approve(id!),
    onSuccess: () => { toast.success('Period approved.'); qc.invalidateQueries({ queryKey: ['payroll-period', id] }); },
    onError: (err: { response?: { data?: { message?: string } } }) =>
      toast.error(err.response?.data?.message ?? 'Failed to approve period.'),
  });
  const finalizeMutation = useMutation({
    mutationFn: () => periodsApi.finalize(id!),
    onSuccess: () => { toast.success('Period finalized — GL posting queued.'); qc.invalidateQueries({ queryKey: ['payroll-period', id] }); },
    onError: (err: { response?: { data?: { message?: string } } }) =>
      toast.error(err.response?.data?.message ?? 'Failed to finalize period.'),
  });

  // Force refetch when computation finishes (status flips back to draft).
  useEffect(() => {
    if (period && period.status !== 'processing') qc.invalidateQueries({ queryKey: ['payrolls', payrollFilters] });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [period?.status]);

  if (periodLoading && !period) {
    return (
      <div>
        <PageHeader title="Loading…" backTo="/payroll/periods" backLabel="Payroll" />
        <div className="px-5 py-4"><SkeletonTable columns={6} rows={6} /></div>
      </div>
    );
  }

  if (periodError || !period) {
    return (
      <div>
        <PageHeader title="Payroll Period" backTo="/payroll/periods" backLabel="Payroll" />
        <EmptyState
          icon="alert-circle"
          title="Failed to load period"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      </div>
    );
  }

  const summary = period.summary;
  const chainSteps = [
    { key: 'draft',      label: 'Draft',      state: 'done' as const },
    { key: 'processing', label: 'Computed',   state: (period.status === 'draft' ? 'pending' : 'done') as 'pending' | 'done' | 'active' },
    { key: 'approved',   label: 'Approved',   state: (['approved','finalized'].includes(period.status) ? 'done' : (period.status === 'processing' ? 'active' : 'pending')) as 'pending' | 'done' | 'active' },
    { key: 'finalized',  label: 'Finalized',  state: (period.status === 'finalized' ? 'done' : 'pending') as 'pending' | 'done' | 'active' },
  ];

  const canCompute  = can('payroll.periods.compute')  && period.status === 'draft' && !period.is_thirteenth_month;
  const canApprove  = can('payroll.periods.approve')  && period.status === 'draft';
  const canFinalize = can('payroll.periods.finalize') && period.status === 'approved';
  const canBankFile = can('payroll.periods.finalize') && period.status === 'finalized';
  const isProc = period.status === 'processing';

  const columns: Column<Payroll>[] = [
    {
      key: 'employee',
      header: 'Employee',
      cell: (r) => r.employee
        ? <StackedCell
            primary={
              <Link to={`/payroll/periods/${period.id}/employee/${r.id}`} className="text-accent hover:underline">
                {r.employee.full_name}
              </Link>
            }
            secondary={<span className="font-mono">{r.employee.employee_no}</span>}
          />
        : '—',
    },
    { key: 'pay_type', header: 'Pay Type', cell: (r) => <span className="capitalize">{r.pay_type}</span> },
    { key: 'days_worked', header: 'Days', align: 'right', cell: (r) => <NumCell>{r.days_worked ?? '—'}</NumCell> },
    { key: 'gross_pay', header: 'Gross', align: 'right', cell: (r) => <NumCell>{formatPeso(r.gross_pay)}</NumCell> },
    { key: 'total_deductions', header: 'Deductions', align: 'right', cell: (r) => <NumCell>{formatPeso(r.total_deductions)}</NumCell> },
    { key: 'adjustment_amount', header: 'Adj.', align: 'right', cell: (r) => <NumCell>{Number(r.adjustment_amount) === 0 ? '—' : formatPeso(r.adjustment_amount)}</NumCell> },
    { key: 'net_pay', header: 'Net', align: 'right', cell: (r) => <NumCell className="font-medium">{formatPeso(r.net_pay)}</NumCell> },
    {
      key: 'status', header: 'Status',
      cell: (r) => r.error_message
        ? <Chip variant="danger">Failed</Chip>
        : r.computed_at ? <Chip variant="success">Computed</Chip> : <Chip variant="neutral">Pending</Chip>,
    },
  ];

  return (
    <div>
      <PageHeader
        title={period.label}
        subtitle={<>Payroll date <span className="font-mono">{formatDate(period.payroll_date)}</span> · created by {period.creator?.name ?? '—'}</>}
        backTo="/payroll/periods" backLabel="Payroll"
        actions={
          <>
            <Chip variant={periodStatusVariant(period.status)} className="mr-2">{period.status_label}</Chip>
            {canCompute && (
              <Button variant="primary" size="sm" icon={<Play size={14} />}
                onClick={() => computeMutation.mutate()}
                disabled={computeMutation.isPending} loading={computeMutation.isPending}>
                Compute
              </Button>
            )}
            {isProc && (
              <span className="inline-flex items-center gap-2 text-xs text-muted">
                <Spinner /> Processing…
              </span>
            )}
            {canApprove && (
              <Button variant="primary" size="sm" icon={<CheckCircle2 size={14} />}
                onClick={() => approveMutation.mutate()}
                disabled={approveMutation.isPending} loading={approveMutation.isPending}>
                Approve
              </Button>
            )}
            {canFinalize && (
              <Button variant="primary" size="sm" icon={<Lock size={14} />}
                onClick={() => finalizeMutation.mutate()}
                disabled={finalizeMutation.isPending} loading={finalizeMutation.isPending}>
                Finalize
              </Button>
            )}
            {canBankFile && (
              <a
                href={periodsApi.bankFileUrl(period.id)}
                className="inline-flex items-center gap-1 px-3 h-7 text-xs rounded-md border border-default bg-canvas text-primary hover:bg-elevated"
              >
                <Download size={14} /> Bank file
              </a>
            )}
          </>
        }
        bottom={<ChainHeader steps={chainSteps} />}
      />

      <div className="px-5 py-4 grid grid-cols-1 lg:grid-cols-[1fr_280px] gap-5">
        {/* Main content */}
        <div>
          <div className="grid grid-cols-4 gap-3 mb-5">
            <StatCard label="Employees" value={summary?.employee_count ?? 0} />
            <StatCard label="Total Gross"      value={formatPeso(summary?.total_gross ?? 0)} />
            <StatCard label="Total Deductions" value={formatPeso(summary?.total_deductions ?? 0)} />
            <StatCard label="Total Net"        value={formatPeso(summary?.total_net ?? 0)} />
          </div>

          {summary && summary.failed_count > 0 && (
            <div className="flex items-center gap-2 px-3 py-2 mb-4 bg-danger-bg text-danger-fg rounded-md text-xs">
              <AlertCircle size={14} />
              <span>{summary.failed_count} employee(s) failed during computation. Review the Failures tab.</span>
            </div>
          )}

          {/* Tabs */}
          <div className="flex items-center gap-1 mb-3 border-b border-default">
            {([
              { key: 'employees', label: `Employees (${summary?.employee_count ?? 0})` },
              { key: 'failures',  label: `Failures (${summary?.failed_count ?? 0})` },
              { key: 'summary',   label: 'Deduction summary' },
            ] as const).map((t) => (
              <button
                key={t.key}
                onClick={() => setActiveTab(t.key)}
                className={
                  'px-3 py-2 text-xs border-b-2 -mb-[1px] ' +
                  (activeTab === t.key
                    ? 'text-primary border-accent'
                    : 'text-muted border-transparent hover:text-primary')
                }
              >{t.label}</button>
            ))}
          </div>

          {(activeTab === 'employees' || activeTab === 'failures') && (
            <>
              {payrollsLoading && !payrolls && <SkeletonTable columns={8} rows={8} />}
              {payrolls && payrolls.data.length === 0 && (
                <EmptyState icon="users"
                  title={activeTab === 'failures' ? 'No failures' : 'No payroll rows yet'}
                  description={activeTab === 'failures'
                    ? 'Computation completed cleanly for every employee.'
                    : 'Run Compute to generate payroll rows.'} />
              )}
              {payrolls && payrolls.data.length > 0 && (
                <DataTable
                  columns={columns}
                  data={payrolls.data}
                  meta={payrolls.meta}
                />
              )}
            </>
          )}

          {activeTab === 'summary' && (
            <Panel title="Deduction summary">
              <div className="text-xs text-muted">
                Aggregated deduction breakdown is available per-employee in their detail view. Future enhancement: show period totals by deduction type here.
              </div>
            </Panel>
          )}
        </div>

        {/* LinkedRecords sidebar */}
        <LinkedRecordsSidebar period={period} />
      </div>
    </div>
  );
}

function LinkedRecordsSidebar({ period }: { period: PayrollPeriod }) {
  const groups: LinkedGroup[] = [];

  // GL Journal Entry — only present once posted.
  if (period.gl_entry_number) {
    groups.push({
      label: 'General Ledger',
      items: [{
        id: period.gl_entry_number,
        meta: 'Posted to GL',
        chip: { variant: 'success', text: 'Posted' },
      }],
    });
  } else if (period.status === 'finalized') {
    groups.push({
      label: 'General Ledger',
      items: [{
        id: 'Pending',
        meta: 'Posting queued — waiting for accounting feature flag or worker',
        chip: { variant: 'warning', text: 'Pending' },
      }],
    });
  }

  // Bank file disbursements.
  if (period.bank_files && period.bank_files.length > 0) {
    groups.push({
      label: 'Bank files',
      items: period.bank_files.map((f) => ({
        id: `${f.record_count} records`,
        meta: `${formatPeso(f.total_amount)} · by ${f.generator?.name ?? 'system'} ${f.generated_at ? formatRelative(f.generated_at) : ''}`,
        chip: { variant: 'success', text: 'Generated' },
      })),
    });
  }

  // Adjustments rolling into / out of this period.
  if (period.adjustment_counts) {
    const ac = period.adjustment_counts;
    const total = ac.pending + ac.approved + ac.applied + ac.rejected;
    if (total > 0) {
      groups.push({
        label: 'Adjustments',
        items: [
          ...(ac.pending  ? [{ id: `${ac.pending} pending`,  meta: 'Awaiting approval', chip: { variant: 'warning' as const, text: 'Pending' } }] : []),
          ...(ac.approved ? [{ id: `${ac.approved} approved`, meta: 'Will apply next period', chip: { variant: 'info' as const, text: 'Approved' } }] : []),
          ...(ac.applied  ? [{ id: `${ac.applied} applied`,  meta: 'Already netted', chip: { variant: 'success' as const, text: 'Applied' } }] : []),
        ],
      });
    }
  }

  // 13th-month accruals — only on the special period.
  if (period.is_thirteenth_month) {
    groups.push({
      label: '13th Month',
      items: [{
        id: `Year ${period.period_start.slice(0, 4)}`,
        meta: 'Special disbursement period — accruals locked.',
        chip: { variant: 'info', text: '13th' },
      }],
    });
  }

  if (groups.length === 0) {
    return (
      <aside className="hidden lg:block">
        <Panel title="Linked records">
          <div className="text-xs text-muted">
            Linked GL entries, bank files, and adjustments will appear here after the period is computed and finalized.
          </div>
        </Panel>
      </aside>
    );
  }

  return (
    <aside className="bg-surface border border-default rounded-md p-4">
      <LinkedRecords groups={groups} />
    </aside>
  );
}
