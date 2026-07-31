import { useState, useEffect, useRef } from 'react';
import { useParams, Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Play, CheckCircle2, Lock, LockOpen, Download, AlertCircle, Upload, Eye, Trash2, Banknote, Ban, RefreshCw } from 'lucide-react';
import toast from 'react-hot-toast';
import { periodsApi } from '@/api/payroll/periods';
import { downloadAuthenticatedFile } from '@/api/download';
import { payrollsApi, type PayrollListParams } from '@/api/payroll/payrolls';
import type { PayrollVarianceReport } from '@/types/payroll';
import { Button } from '@/components/ui/Button';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
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
import { Td, Th, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { Textarea } from '@/components/ui/Textarea';
import { FileInput } from '@/components/ui/FileInput';
import { Tabs } from '@/components/ui/Tabs';

const periodStatusVariant = (status: string | null | undefined): ChipVariant => {
  switch (status) {
    case 'disbursed': return 'success';
    case 'finalized': return 'info';
    case 'approved':  return 'info';
    case 'computed':  return 'info';
    case 'processing': return 'info';
    case 'draft':     return 'warning';
    case 'voided':    return 'danger';
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
    onSuccess: (updated) => {
      toast.success('Computation started.');
      // The 202 already carries status=processing, so seed the cache with it.
      // The button disables and polling starts on this render rather than
      // waiting for the next refetch — the window in which the old code let a
      // user click Compute again.
      qc.setQueryData(['payroll-period', id], updated);
      qc.invalidateQueries({ queryKey: ['payroll-period', id] });
    },
    // Surface the backend's reason (already computing, wrong status, …) instead
    // of a generic failure message.
    onError: (err: { response?: { data?: { message?: string } } }) =>
      toast.error(err.response?.data?.message ?? 'Failed to start computation.'),
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
  const [showApproveDialog, setShowApproveDialog] = useState(false);
  const [showRecomputeDialog, setShowRecomputeDialog] = useState(false);
  const [showFinalizeDialog, setShowFinalizeDialog] = useState(false);
  const [showDisbursedDialog, setShowDisbursedDialog] = useState(false);

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

  // REC-01 — Void a finalized period (reverses GL posting, transitions to Voided).
  const [showVoidModal, setShowVoidModal] = useState(false);
  const voidMutation = useMutation({
    mutationFn: (reason: string) => periodsApi.void(id!, reason),
    onSuccess: () => {
      toast.success('Period voided — any GL posting was reversed.');
      setShowVoidModal(false);
      qc.invalidateQueries({ queryKey: ['payroll-period', id] });
    },
    onError: (err: { response?: { data?: { message?: string } } }) =>
      toast.error(err.response?.data?.message ?? 'Failed to void period.'),
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
    { key: 'processing', label: 'Computed',   state: (period.status === 'draft' ? 'pending' : period.status === 'processing' ? 'active' : 'done') as 'pending' | 'done' | 'active' },
    { key: 'approved',   label: 'Approved',   state: (['approved','finalized','disbursed'].includes(period.status) ? 'done' : 'pending') as 'pending' | 'done' | 'active' },
    { key: 'finalized',  label: 'Finalized',  state: (['finalized','disbursed'].includes(period.status) ? 'done' : 'pending') as 'pending' | 'done' | 'active' },
    { key: 'disbursed',  label: 'Disbursed',  state: (period.status === 'disbursed' ? 'done' : 'pending') as 'pending' | 'done' | 'active' },
  ];

  // Compute (first run) and Recompute (replace an existing run) are separate
  // affordances. Recompute is destructive — it rewrites every payroll row, so it
  // asks for confirmation. The backend rejects either one for any status outside
  // draft/computed, so this is UX only.
  const hasRows       = (summary?.employee_count ?? 0) > 0;
  const canCompute    = can('payroll.periods.compute')  && period.status === 'draft'    && !period.is_thirteenth_month;
  const canRecompute  = can('payroll.periods.compute')  && period.status === 'computed' && !period.is_thirteenth_month;
  // Approve requires a completed run with rows. Previously this was gated on
  // 'draft', so an uncomputed period could be approved into an empty ₱0 payroll.
  const canApprove    = can('payroll.periods.approve')  && period.status === 'computed' && hasRows;
  const canFinalize   = can('payroll.periods.finalize') && period.status === 'approved';
  const canBankFile   = can('payroll.periods.finalize') && (period.status === 'finalized' || period.status === 'disbursed');
  const canDisburse   = can('payroll.periods.finalize') && period.status === 'finalized';
  const canUploadProof = can('payroll.periods.finalize') && (period.status === 'finalized' || period.status === 'disbursed');
  // H-8 — Force-unlock only surfaces when the period is stuck at Processing.
  const canForceUnlock = can('payroll.periods.force_unlock') && period.status === 'processing';
  // REC-01 — Void only a finalized (not yet disbursed) period.
  const canVoid = can('payroll.periods.void') && period.status === 'finalized';
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
        subtitle={<>Payroll date <span className="font-mono">{formatDate(period.payroll_date)}</span> · created by {period.creator?.name ?? '—'}
          {/* REC-04 — maker-checker attribution trail. */}
          {period.computer?.name && <> · computed by {period.computer.name}</>}
          {period.approver?.name && <> · approved by {period.approver.name}</>}
          {period.finalizer?.name && <> · finalized by {period.finalizer.name}</>}
        </>}
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
            {canRecompute && (
              <Button variant="secondary" size="sm" icon={<RefreshCw size={14} />}
                onClick={() => setShowRecomputeDialog(true)}
                disabled={computeMutation.isPending} loading={computeMutation.isPending}>
                Recompute
              </Button>
            )}
            {isProc && (
              <span className="inline-flex items-center gap-2 text-xs text-muted">
                <Spinner /> Computing…
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
                onClick={() => setShowApproveDialog(true)}
                disabled={approveMutation.isPending} loading={approveMutation.isPending}>
                Approve
              </Button>
            )}
            {canFinalize && (
              <Button variant="primary" size="sm" icon={<Lock size={14} />}
                onClick={() => setShowFinalizeDialog(true)}
                disabled={finalizeMutation.isPending} loading={finalizeMutation.isPending}>
                Finalize
              </Button>
            )}
            {canBankFile && (
              <Button
                variant="secondary"
                size="sm"
                icon={<Download size={14} />}
                onClick={() => void downloadAuthenticatedFile(periodsApi.bankFileUrl(period.id), {
                  filename: `payroll-bank-file-${period.id}.csv`,
                  errorMessage: 'Failed to download the bank file.',
                })}
              >
                Bank file
              </Button>
            )}
            {canUploadProof && (
              <Button variant="secondary" size="sm" icon={<Upload size={14} />}
                onClick={() => setShowUploadModal(true)}>
                Upload Proof
              </Button>
            )}
            {canDisburse && (
              <Button variant="primary" size="sm" icon={<Banknote size={14} />}
                onClick={() => setShowDisbursedDialog(true)}
                disabled={markDisbursedMutation.isPending} loading={markDisbursedMutation.isPending}>
                Mark as Disbursed
              </Button>
            )}
            {canVoid && (
              <Button variant="danger" size="sm" icon={<Ban size={14} />}
                onClick={() => setShowVoidModal(true)}
                disabled={voidMutation.isPending} loading={voidMutation.isPending}>
                Void
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

          {period.status === 'voided' && (
            <div className="flex items-start gap-2 px-3 py-2 mb-4 bg-danger-bg text-danger-fg rounded-md text-xs">
              <Ban size={14} className="mt-0.5 shrink-0" />
              <div>
                <span className="font-medium">This period was voided</span>
                {period.voided_at && <> on <span className="font-mono">{formatDate(period.voided_at)}</span></>}
                {period.voider?.name && <> by {period.voider.name}</>}
                {period.gl_entry_number ? ' — its GL posting was reversed.' : '.'}
                {period.void_reason && <div className="mt-0.5 text-primary/80">Reason: {period.void_reason}</div>}
              </div>
            </div>
          )}

          {summary && summary.failed_count > 0 && (
            <div className="flex items-center gap-2 px-3 py-2 mb-4 bg-danger-bg text-danger-fg rounded-md text-xs">
              <AlertCircle size={14} />
              <span>{summary.failed_count} employee(s) failed during computation. Review the Failures tab.</span>
            </div>
          )}

          {/* Tabs */}
          <Tabs
            className="mb-3"
            label="Payroll period sections"
            value={activeTab}
            onChange={setActiveTab}
            items={[
              { key: 'employees', label: 'Employees', count: summary?.employee_count ?? 0 },
              { key: 'failures', label: 'Failures', count: summary?.failed_count ?? 0 },
              { key: 'anomalies', label: 'Anomaly review' },
              { key: 'summary', label: 'Deduction summary' },
              { key: 'variance', label: 'Period variance' },
            ]}
          />

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

      {/* Recompute rewrites every payroll row in the period, reverses the loan
          deductions and adjustments the previous run applied, then re-applies
          them. Destructive enough to confirm. */}
      <ConfirmDialog
        isOpen={showRecomputeDialog}
        onClose={() => setShowRecomputeDialog(false)}
        onConfirm={() => { computeMutation.mutate(); setShowRecomputeDialog(false); }}
        title="Recompute this payroll period?"
        description={`This replaces all ${summary?.employee_count ?? 0} existing payroll row(s). Loan deductions and applied adjustments from the previous run are reversed and re-applied. Anomaly flags are re-evaluated.`}
        variant="warning"
        confirmLabel="Recompute"
        pending={computeMutation.isPending}
      />
      <ConfirmDialog
        isOpen={showApproveDialog}
        onClose={() => setShowApproveDialog(false)}
        onConfirm={() => { approveMutation.mutate(); setShowApproveDialog(false); }}
        title="Approve payroll period?"
        description="This marks the period as reviewed and approved."
        variant="warning"
        confirmLabel="Approve"
        pending={approveMutation.isPending}
      />
      <ConfirmDialog
        isOpen={showFinalizeDialog}
        onClose={() => setShowFinalizeDialog(false)}
        onConfirm={() => { finalizeMutation.mutate(); setShowFinalizeDialog(false); }}
        title="Finalize payroll period?"
        description="This will lock the period. Payslips will be generated and no further changes can be made."
        variant="danger"
        confirmLabel="Finalize"
        pending={finalizeMutation.isPending}
      />
      <ConfirmDialog
        isOpen={showDisbursedDialog}
        onClose={() => setShowDisbursedDialog(false)}
        onConfirm={() => { markDisbursedMutation.mutate(); setShowDisbursedDialog(false); }}
        title="Mark as disbursed?"
        description="This confirms that salaries have been transferred to employee bank accounts."
        variant="warning"
        confirmLabel="Mark Disbursed"
        pending={markDisbursedMutation.isPending}
      />

      {/* REC-01 — Void modal: requires a reason (min 5 chars) for the audit trail. */}
      <VoidPeriodModal
        open={showVoidModal}
        onClose={() => setShowVoidModal(false)}
        onConfirm={(reason) => voidMutation.mutate(reason)}
        pending={voidMutation.isPending}
        hasGlPosting={!!period.gl_entry_number}
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
            icon="bar-chart"
            title="No period selected"
            description="Pick a previous payroll period from the dropdown to compare."
          />
        )}

        {compareToId && isLoading && (
          <SkeletonTable columns={4} rows={4} />
        )}

        {varianceData && !isLoading && (
          <div className="space-y-3">
            <table className={tableCls}>
              <thead>
                <tr className={theadTrCls}>
                  <Th>Metric</Th>
                  <Th align="right">Previous<br /><span className="font-mono text-2xs normal-case">{varianceData.previous.period_label}</span></Th>
                  <Th align="right">Current<br /><span className="font-mono text-2xs normal-case">{varianceData.current.period_label}</span></Th>
                  <Th align="right">Delta</Th>
                  <Th align="right">Change %</Th>
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
                  <tr key={row.label} className={trCls}>
                    <Td className="font-medium">{row.label}</Td>
                    <Td align="right" mono className="text-muted">{row.prev}</Td>
                    <Td align="right" mono>{row.curr}</Td>
                    <Td align="right" mono className={deltaColor(row.delta)}>
                      {row.delta > 0 ? '+' : ''}{row.isMoney ? fmt(row.delta) : row.delta}
                    </Td>
                    <Td align="right" mono className={` text-xs ${deltaColor(row.pct ?? 0)}`}>
                      {pctFmt(row.pct)}
                    </Td>
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
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);

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
        <Button
          type="button"
          variant="ghost"
          size="sm"
          iconOnly
          icon={<Eye size={14} />}
          aria-label="View disbursement proof"
          onClick={() => void downloadAuthenticatedFile(periodsApi.downloadProof(periodId, proof.id), { openInNewTab: true, errorMessage: 'Failed to open disbursement proof.' })}
          className="text-muted hover:text-primary"
        />
        <Button
          type="button"
          variant="ghost"
          size="sm"
          iconOnly
          icon={<Trash2 size={14} />}
          aria-label="Delete proof"
          onClick={() => setShowDeleteConfirm(true)}
          disabled={deleteMutation.isPending}
          className="text-muted hover:text-danger"
        />
      </div>
      <ConfirmDialog
        isOpen={showDeleteConfirm}
        onClose={() => setShowDeleteConfirm(false)}
        onConfirm={() => { deleteMutation.mutate(); setShowDeleteConfirm(false); }}
        title="Delete this proof file?"
        description="This will permanently remove the uploaded disbursement proof."
        variant="danger"
        confirmLabel="Delete"
        pending={deleteMutation.isPending}
      />
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

        <FileInput
          ref={fileInputRef}
          label="File"
          helper="PDF, JPG, or PNG — max 10MB"
          accept=".pdf,.jpg,.jpeg,.png"
          onChange={(e) => setFile(e.target.files?.[0] ?? null)}
        />

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

/**
 * REC-01 — Modal to void a finalized period. A substantive reason (min 5
 * chars) is mandatory and written to the audit trail; the backend reverses
 * any GL posting via a balanced mirror journal entry.
 */
function VoidPeriodModal({
  open, onClose, onConfirm, pending, hasGlPosting,
}: {
  open: boolean;
  onClose: () => void;
  onConfirm: (reason: string) => void;
  pending: boolean;
  hasGlPosting: boolean;
}) {
  const [reason, setReason] = useState('');

  // Reset the field each time the modal opens so a prior draft never leaks in.
  useEffect(() => {
    if (open) setReason('');
  }, [open]);

  const tooShort = reason.trim().length < 5;

  return (
    <Modal isOpen={open} onClose={onClose} size="md" title="Void payroll period">
      <div className="space-y-3 py-3">
        <div className="flex items-start gap-2 px-3 py-2 bg-danger-bg text-danger-fg rounded-md text-xs">
          <Ban size={14} className="mt-0.5 shrink-0" />
          <span>
            Voiding transitions this period to <strong>Voided</strong>.
            {hasGlPosting
              ? ' Its GL posting will be reversed with a balanced mirror journal entry — no ledger rows are deleted.'
              : ' No GL posting exists yet, so only the period status changes.'}
            {' '}This is recorded against your account. Use it only to correct a bad finalized run.
          </span>
        </div>
        <div>
          <Textarea
            label="Reason"
            required
            helper="Minimum 5 characters — stored in the audit log."
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            rows={3}
            placeholder="e.g. Backdated OT for E. Cruz was missing; recomputing this half."
          />
        </div>
      </div>

      <div className="flex justify-end gap-2 pt-3 border-t border-default">
        <Button variant="secondary" onClick={onClose} disabled={pending}>Cancel</Button>
        <Button variant="danger" onClick={() => onConfirm(reason.trim())}
          disabled={tooShort || pending} loading={pending}>
          {pending ? 'Voiding…' : 'Void period'}
        </Button>
      </div>
    </Modal>
  );
}
