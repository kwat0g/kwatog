import { useState, useEffect, useRef } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Play, CheckCircle2, Lock, LockOpen, Download, AlertCircle, Upload, Eye, Trash2, Banknote } from 'lucide-react';
import toast from 'react-hot-toast';
import { periodsApi } from '@/api/payroll/periods';
import { payrollsApi, type PayrollListParams } from '@/api/payroll/payrolls';
import type { PayrollVarianceReport } from '@/types/payroll';
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
import { AnomalyReviewPanel } from '@/components/payroll/AnomalyReviewPanel';
import { Modal } from '@/components/ui/Modal';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { usePermission } from '@/hooks/usePermission';
import { formatPeso } from '@/lib/formatNumber';
import { formatDate, formatRelative } from '@/lib/formatDate';
import type { DisbursementProof, Payroll, PayrollPeriod } from '@/types/payroll';

const periodStatusVariant = (status: string | null | undefined): ChipVariant => {
  switch (status) {
    case 'disbursed': return 'success';
    case 'finalized': return 'info';
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
  const [activeTab, setActiveTab] = useState<'employees' | 'failures' | 'anomalies' | 'summary' | 'variance'>('employees');
  const [compareToId, setCompareToId] = useState<string>('');

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

  const varianceQ = useQuery({
    queryKey: ['payroll-period-variance', id, compareToId],
    queryFn: () => periodsApi.variance(id!, compareToId),
    enabled: !!id && compareToId !== '',
  });

  const periodsListQ = useQuery({
    queryKey: ['payroll-periods-list-for-compare'],
    queryFn: () => periodsApi.list({ per_page: 50, sort: 'period_start', direction: 'desc' }),
    enabled: activeTab === 'variance',
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

  // ADV1 — Disbursement proof
  const [showUploadModal, setShowUploadModal] = useState(false);

  const markDisbursedMutation = useMutation({
    mutationFn: () => periodsApi.markDisbursed(id!),
    onSuccess: () => {
      toast.success('Period marked as disbursed.');
      qc.invalidateQueries({ queryKey: ['payroll-period', id] });
    },
    onError: (err: { response?: { data?: { message?: string } } }) =>
      toast.error(err.response?.data?.message ?? 'Failed to mark period as disbursed.'),
  });

  // H-8 — Force-unlock for periods stuck at Processing because the worker crashed.
  const forceUnlockMutation = useMutation({
    mutationFn: (reason: string) => periodsApi.forceUnlock(id!, reason),
    onSuccess: () => {
      toast.success('Period unlocked — you can re-run compute.');
      qc.invalidateQueries({ queryKey: ['payroll-period', id] });
    },
    onError: (err: { response?: { data?: { message?: string } } }) =>
      toast.error(err.response?.data?.message ?? 'Failed to unlock period.'),
  });

  // Force refetch when computation finishes (status flips back to draft).
  useEffect(() => {
    if (period && period.status !== 'processing') qc.invalidateQueries({ queryKey: ['payrolls', payrollFilters] });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [period?.status]);

  if (periodLoading && !period) {
    return (
      <div>
        <PageHeader title="Loading…" backTo="/payroll/periods" backLabel="Payroll" breadcrumbs={[{ label: 'Payroll', href: '/payroll/periods' }, { label: 'Period' }]} />
        <div className="px-5 py-4"><SkeletonTable columns={6} rows={6} /></div>
      </div>
    );
  }

  if (periodError || !period) {
    return (
      <div>
        <PageHeader title="Payroll Period" backTo="/payroll/periods" backLabel="Payroll" breadcrumbs={[{ label: 'Payroll', href: '/payroll/periods' }, { label: 'Period' }]} />
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
    { key: 'approved',   label: 'Approved',   state: (['approved','finalized','disbursed'].includes(period.status) ? 'done' : (period.status === 'processing' ? 'active' : 'pending')) as 'pending' | 'done' | 'active' },
    { key: 'finalized',  label: 'Finalized',  state: (['finalized','disbursed'].includes(period.status) ? 'done' : 'pending') as 'pending' | 'done' | 'active' },
    { key: 'disbursed',  label: 'Disbursed',  state: (period.status === 'disbursed' ? 'done' : 'pending') as 'pending' | 'done' | 'active' },
  ];

  const canCompute    = can('payroll.periods.compute')  && period.status === 'draft' && !period.is_thirteenth_month;
  const canApprove    = can('payroll.periods.approve')  && period.status === 'draft';
  const canFinalize   = can('payroll.periods.finalize') && period.status === 'approved';
  const canBankFile   = can('payroll.periods.finalize') && (period.status === 'finalized' || period.status === 'disbursed');
  const canDisburse   = can('payroll.periods.finalize') && period.status === 'finalized';
  const canUploadProof = can('payroll.periods.finalize') && (period.status === 'finalized' || period.status === 'disbursed');
  // H-8 — Force-unlock only surfaces when the period is stuck at Processing.
  const canForceUnlock = can('payroll.periods.force_unlock') && period.status === 'processing';
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
        breadcrumbs={[
          { label: 'Payroll', href: "/payroll/periods" },
          { label: 'Periods', href: '/payroll/periods' },
          { label: period.label },
        ]}
        actions={
          <>
            <Chip variant={periodStatusVariant(period.status)} className="mr-2">{period.status_label}</Chip>
            {period.is_auto_created && (
              <span title={period.auto_created_at ? `Auto-scheduled ${period.auto_created_at}` : 'Auto-scheduled by system'} className="mr-2">
                <Chip variant="info">Auto</Chip>
              </span>
            )}
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
            {canForceUnlock && (
              <Button
                variant="secondary"
                size="sm"
                icon={<LockOpen size={14} />}
                onClick={() => {
                  const reason = window.prompt('Reason for force-unlock (audit trail):', 'Worker crashed; rerunning compute.');
                  if (reason === null) return;
                  forceUnlockMutation.mutate(reason);
                }}
                disabled={forceUnlockMutation.isPending}
                loading={forceUnlockMutation.isPending}
              >
                Force unlock
              </Button>
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
            {canUploadProof && (
              <Button variant="secondary" size="sm" icon={<Upload size={14} />}
                onClick={() => setShowUploadModal(true)}>
                Upload Proof
              </Button>
            )}
            {canDisburse && (
              <Button variant="primary" size="sm" icon={<Banknote size={14} />}
                onClick={() => markDisbursedMutation.mutate()}
                disabled={markDisbursedMutation.isPending} loading={markDisbursedMutation.isPending}>
                Mark as Disbursed
              </Button>
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
              { key: 'anomalies', label: 'Anomaly review' },
              { key: 'summary',   label: 'Deduction summary' },
              { key: 'variance',  label: 'Period variance' },
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

          {activeTab === 'anomalies' && (
            <AnomalyReviewPanel periodId={period.id} />
          )}

          {activeTab === 'summary' && (
            <Panel title="Deduction summary">
              <div className="text-xs text-muted">
                Aggregated deduction breakdown is available per-employee in their detail view. Future enhancement: show period totals by deduction type here.
              </div>
            </Panel>
          )}

          {activeTab === 'variance' && (
            <VariancePanel
              currentId={id!}
              compareToId={compareToId}
              onCompareToChange={setCompareToId}
              periods={(periodsListQ.data?.data ?? []).filter((p) => p.id !== id)}
              varianceData={varianceQ.data ?? null}
              isLoading={varianceQ.isLoading}
            />
          )}
        </div>

        {/* ADV1 — Disbursement Proof Section */}
        <div className="col-span-1 lg:col-span-2">
          <Panel title="Disbursement Proof">
            {(!period.disbursement_proofs || period.disbursement_proofs.length === 0) ? (
              <div className="space-y-3">
                <div className="flex items-center gap-2">
                  <span className="inline-block h-2 w-2 rounded-full bg-warning" aria-hidden />
                  <span className="text-xs font-medium text-muted">Status: Pending disbursement</span>
                </div>
                <p className="text-xs text-muted">
                  No proof uploaded yet. After transferring salaries, upload the bank deposit slip or transaction confirmation here.
                </p>
                {canUploadProof && (
                  <Button variant="secondary" size="sm" icon={<Upload size={14} />}
                    onClick={() => setShowUploadModal(true)}>
                    Upload Deposit Slip / Bank Confirmation
                  </Button>
                )}
              </div>
            ) : (
              <div className="space-y-3">
                <div className="flex items-center gap-2">
                  <span className="inline-block h-2 w-2 rounded-full bg-success" aria-hidden />
                  <span className="text-xs font-medium text-muted">
                    Status: {' '}
                    {period.status === 'disbursed'
                      ? `Disbursed on ${formatDate(period.disbursed_at ?? '')}`
                      : 'Proof uploaded — mark as disbursed to complete'}
                  </span>
                </div>

                {period.disbursement_proofs.map((proof) => (
                  <DisbursementProofCard key={proof.id} proof={proof} periodId={period.id} />
                ))}

                {canUploadProof && (
                  <Button variant="secondary" size="sm" icon={<Upload size={14} />}
                    onClick={() => setShowUploadModal(true)}>
                    Upload Another
                  </Button>
                )}
              </div>
            )}
          </Panel>
        </div>
      </div>

      {/* ADV1 — Upload Proof Modal */}
      <UploadProofModal
        open={showUploadModal}
        onClose={() => setShowUploadModal(false)}
        periodId={period.id}
        onSuccess={() => {
          qc.invalidateQueries({ queryKey: ['payroll-period', id] });
          setShowUploadModal(false);
        }}
      />
    </div>
  );
}

/** Task 9 — Period-over-period variance panel. */
function VariancePanel({
  compareToId,
  onCompareToChange,
  periods,
  varianceData,
  isLoading,
}: {
  currentId: string;
  compareToId: string;
  onCompareToChange: (id: string) => void;
  periods: Array<{ id: string; label: string; status: string }>;
  varianceData: PayrollVarianceReport | null;
  isLoading: boolean;
}) {
  const fmt = (n: number | string) => formatPeso(n);
  const deltaColor = (n: number) => n > 0 ? 'text-success' : n < 0 ? 'text-danger' : 'text-muted';
  const pctFmt = (n: number | null) => n === null ? '—' : `${n > 0 ? '+' : ''}${n.toFixed(1)}%`;

  return (
    <Panel title="Period-over-period variance">
      <div className="space-y-4">
        <div className="max-w-sm">
          <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">
            Compare to period
          </label>
          <Select
            value={compareToId}
            onChange={(e) => onCompareToChange(e.target.value)}
          >
            <option value="">— Select a period to compare —</option>
            {periods.map((p) => (
              <option key={p.id} value={p.id}>
                {p.label} ({p.status})
              </option>
            ))}
          </Select>
        </div>

        {!compareToId && (
          <EmptyState
            icon="bar-chart-2"
            title="No period selected"
            description="Pick a previous payroll period from the dropdown to compare."
          />
        )}

        {compareToId && isLoading && (
          <SkeletonTable columns={4} rows={4} />
        )}

        {varianceData && !isLoading && (
          <div className="space-y-3">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-default text-left text-2xs uppercase tracking-wide text-muted">
                  <th className="py-2 pr-4">Metric</th>
                  <th className="py-2 pr-4 text-right">Previous<br /><span className="font-mono text-[10px] normal-case">{varianceData.previous.period_label}</span></th>
                  <th className="py-2 pr-4 text-right">Current<br /><span className="font-mono text-[10px] normal-case">{varianceData.current.period_label}</span></th>
                  <th className="py-2 pr-4 text-right">Delta</th>
                  <th className="py-2 text-right">Change %</th>
                </tr>
              </thead>
              <tbody>
                {[
                  {
                    label: 'Gross Pay',
                    prev: fmt(varianceData.previous.total_gross),
                    curr: fmt(varianceData.current.total_gross),
                    delta: varianceData.delta.gross,
                    pct: varianceData.pct_change.gross,
                    isMoney: true,
                  },
                  {
                    label: 'Total Deductions',
                    prev: fmt(varianceData.previous.total_deductions),
                    curr: fmt(varianceData.current.total_deductions),
                    delta: varianceData.delta.deductions,
                    pct: varianceData.pct_change.deductions,
                    isMoney: true,
                  },
                  {
                    label: 'Net Pay',
                    prev: fmt(varianceData.previous.total_net),
                    curr: fmt(varianceData.current.total_net),
                    delta: varianceData.delta.net,
                    pct: varianceData.pct_change.net,
                    isMoney: true,
                  },
                  {
                    label: 'Headcount',
                    prev: String(varianceData.previous.employee_count),
                    curr: String(varianceData.current.employee_count),
                    delta: varianceData.delta.headcount,
                    pct: varianceData.pct_change.headcount,
                    isMoney: false,
                  },
                ].map((row) => (
                  <tr key={row.label} className="border-b border-default/50 h-9">
                    <td className="pr-4 font-medium">{row.label}</td>
                    <td className="pr-4 text-right font-mono tabular-nums text-muted">{row.prev}</td>
                    <td className="pr-4 text-right font-mono tabular-nums">{row.curr}</td>
                    <td className={`pr-4 text-right font-mono tabular-nums ${deltaColor(row.delta)}`}>
                      {row.delta > 0 ? '+' : ''}{row.isMoney ? fmt(row.delta) : row.delta}
                    </td>
                    <td className={`text-right font-mono tabular-nums text-xs ${deltaColor(row.pct ?? 0)}`}>
                      {pctFmt(row.pct)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </Panel>
  );
}

/** ADV1 — A single disbursement proof card showing file info & actions. */
function DisbursementProofCard({ proof, periodId }: { proof: DisbursementProof; periodId: string }) {
  const qc = useQueryClient();

  const deleteMutation = useMutation({
    mutationFn: () => periodsApi.deleteProof(periodId, proof.id),
    onSuccess: () => {
      toast.success('Proof deleted.');
      qc.invalidateQueries({ queryKey: ['payroll-period', periodId] });
    },
    onError: () => toast.error('Failed to delete proof.'),
  });

  const proofTypeLabel: Record<string, string> = {
    deposit_slip: 'Deposit Slip',
    bank_confirmation: 'Bank Confirmation',
    transfer_receipt: 'Transfer Receipt',
    other: 'Other',
  };

  return (
    <div className="flex items-start gap-3 rounded-md border border-default bg-canvas p-3">
      <div className="shrink-0 flex h-10 w-10 items-center justify-center rounded border border-default bg-elevated">
        <Download size={16} className="text-muted" />
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-2">
          <span className="text-sm font-medium truncate">{proof.file_name}</span>
          <Chip variant="neutral">{proofTypeLabel[proof.proof_type] ?? proof.proof_type}</Chip>
        </div>
        <div className="mt-1 text-xs text-muted">
          {proof.bank_name && <span>Bank: {proof.bank_name} · </span>}
          {proof.transaction_reference && <span>Ref: {proof.transaction_reference} · </span>}
          {proof.disbursed_amount && <span>Amount: {formatPeso(proof.disbursed_amount)} · </span>}
          <span>Date: {formatDate(proof.disbursement_date)}</span>
        </div>
        <div className="mt-0.5 text-xs text-muted">
          Uploaded by {proof.uploader?.name ?? '—'} · {proof.created_at ? formatRelative(proof.created_at) : ''}
        </div>
      </div>
      <div className="shrink-0 flex items-center gap-1">
        <a
          href={periodsApi.downloadProof(periodId, proof.id)}
          target="_blank"
          rel="noopener"
          className="inline-flex items-center justify-center h-8 w-8 rounded hover:bg-elevated text-muted hover:text-primary transition-colors"
          title="View proof"
        >
          <Eye size={14} />
        </a>
        <button
          onClick={() => { if (confirm('Delete this proof file?')) deleteMutation.mutate(); }}
          disabled={deleteMutation.isPending}
          className="inline-flex items-center justify-center h-8 w-8 rounded hover:bg-danger-bg text-muted hover:text-danger-fg transition-colors"
          title="Delete proof"
        >
          <Trash2 size={14} />
        </button>
      </div>
    </div>
  );
}

/** ADV1 — Modal form to upload a new disbursement proof file. */
function UploadProofModal({
  open, onClose, periodId, onSuccess,
}: {
  open: boolean; onClose: () => void; periodId: string; onSuccess: () => void;
}) {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [proofType, setProofType] = useState('deposit_slip');
  const [file, setFile] = useState<File | null>(null);
  const [bankName, setBankName] = useState('');
  const [transactionReference, setTransactionReference] = useState('');
  const [disbursedAmount, setDisbursedAmount] = useState('');
  const [disbursementDate, setDisbursementDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [notes, setNotes] = useState('');

  const mutation = useMutation({
    mutationFn: () => {
      const fd = new FormData();
      fd.append('proof_type', proofType);
      if (file) fd.append('file', file);
      if (bankName) fd.append('bank_name', bankName);
      if (transactionReference) fd.append('transaction_reference', transactionReference);
      if (disbursedAmount) fd.append('disbursed_amount', disbursedAmount);
      fd.append('disbursement_date', disbursementDate);
      if (notes) fd.append('notes', notes);
      return periodsApi.uploadProof(periodId, fd);
    },
    onSuccess: () => {
      toast.success('Proof uploaded.');
      onSuccess();
    },
    onError: (err: { response?: { data?: { message?: string } } }) =>
      toast.error(err.response?.data?.message ?? 'Failed to upload proof.'),
  });

  return (
    <Modal isOpen={open} onClose={onClose} size="md" title="Upload Disbursement Proof">
      <div className="space-y-3 py-3">
        <Select label="Proof type" value={proofType} onChange={(e) => setProofType(e.target.value)}>
          <option value="deposit_slip">Deposit Slip</option>
          <option value="bank_confirmation">Bank Confirmation</option>
          <option value="transfer_receipt">Transfer Receipt</option>
          <option value="other">Other</option>
        </Select>

        <div>
          <label className="block text-xs font-medium text-primary mb-1">File (PDF, JPG, PNG — max 10MB)</label>
          <input
            ref={fileInputRef}
            type="file"
            accept=".pdf,.jpg,.jpeg,.png"
            onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            className="block w-full text-xs text-muted file:mr-3 file:py-1 file:px-3 file:rounded file:border file:border-default file:text-xs file:bg-elevated file:text-primary hover:file:bg-strong"
          />
        </div>

        <div className="grid grid-cols-2 gap-3">
          <Input label="Bank name" value={bankName} onChange={(e) => setBankName(e.target.value)} placeholder="e.g. BDO Unibank" />
          <Input label="Transaction ref." value={transactionReference} onChange={(e) => setTransactionReference(e.target.value)} placeholder="e.g. TXN20260415001" />
        </div>

        <div className="grid grid-cols-2 gap-3">
          <Input label="Disbursed amount" value={disbursedAmount} onChange={(e) => setDisbursedAmount(e.target.value)} placeholder="e.g. 2847500.00" />
          <Input label="Disbursement date" type="date" value={disbursementDate} onChange={(e) => setDisbursementDate(e.target.value)} />
        </div>

        <Input label="Notes (optional)" value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Any additional notes…" />
      </div>

      <div className="flex justify-end gap-2 pt-3 border-t border-default">
        <Button variant="secondary" onClick={onClose} disabled={mutation.isPending}>Cancel</Button>
        <Button variant="primary" onClick={() => mutation.mutate()}
          disabled={!file || mutation.isPending} loading={mutation.isPending}>
          {mutation.isPending ? 'Uploading…' : 'Upload'}
        </Button>
      </div>
    </Modal>
  );
}


