import { AxiosError } from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { workOrdersApi } from '@/api/production/workOrders';
import { Button } from '@/components/ui/Button';
import { Chip, chipVariantForStatus } from '@/components/ui/Chip';
import { Input } from '@/components/ui/Input';
import { Panel } from '@/components/ui/Panel';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { productionDateTime } from '@/components/production/scheduleDates';
import type { LateProductionReportSubmission } from '@/types/production';
import type { AssignedWorkOrder, Crew } from '@/types/production-crew';
import { useAuthStore } from '@/stores/authStore';
import { useFormDraftAutosave } from '@/hooks/useFormDraftAutosave';
import { usePendingProductionSubmission } from '@/hooks/usePendingProductionSubmission';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';
import { DraftRestoreBanner } from '@/components/ui/DraftRestoreBanner';
import { createRequestKey } from '@/lib/requestKey';

type DefectRow = { defect_type_id: string; count: string };
type PendingSubmission = { data: LateProductionReportSubmission; key: string };
const plantTime = (value: string) => new Date(`${value}:00+08:00`).getTime();
const plantTimeIso = (value: string) => new Date(plantTime(value)).toISOString();

export function LateProductionEntry({
  workOrderId,
  crew,
  canSubmit,
  workOrderStatus,
  collapsed = false,
  onPendingChange,
  recordingPending = false,
  finishFirst = false,
  reportInterval,
  batchNumber,
}: {
  workOrderId: string;
  crew: Crew;
  canSubmit: boolean;
  workOrderStatus: string;
  recordingPending?: boolean;
  finishFirst?: boolean;
  reportInterval?: AssignedWorkOrder['report_interval'];
  batchNumber?: string | null;
  collapsed?: boolean;
  onPendingChange?: (pending: boolean) => void;
}) {
  const userId = useAuthStore((state) => state.user?.id);
  const queryClient = useQueryClient();
  const [enteredSource, setSource] = useState<string | null>(null);
  const source = enteredSource ?? batchNumber ?? '';
  const [enteredStart, setStarted] = useState('');
  const [enteredFinish, setFinished] = useState('');
  const started = reportInterval ? (reportInterval.started_at ?? '') : enteredStart;
  const finished = reportInterval ? (reportInterval.finished_at ?? '') : enteredFinish;
  const startTime = reportInterval ? Date.parse(started) : plantTime(started);
  const finishTime = reportInterval ? Date.parse(finished) : plantTime(finished);
  const [good, setGood] = useState('');
  const [reject, setReject] = useState('0');
  const [defects, setDefects] = useState<DefectRow[]>([]);
  const [reason, setReason] = useState('');
  const recovery = usePendingProductionSubmission<LateProductionReportSubmission>(
    `${userId}:${workOrderId}:late`,
  );
  const { pending } = recovery;
  useEffect(() => {
    onPendingChange?.(!!pending);
  }, [pending, onPendingChange]);
  const [uncertain, setUncertain] = useState(!!pending);
  const dirty =
    source !== (batchNumber ?? '') ||
    (!reportInterval && (!!started || !!finished)) ||
    !!good ||
    reject !== '0' ||
    defects.length > 0 ||
    !!reason;
  const values = useRef({ source, started, finished, good, reject, defects, reason });
  values.current = { source, started, finished, good, reject, defects, reason };
  const getValues = useCallback(() => values.current, []);
  const restoreValues = useCallback((value: Record<string, unknown>) => {
    if (typeof value.source === 'string') setSource(value.source.trim() ? value.source : null);
    if (typeof value.started === 'string') setStarted(value.started);
    if (typeof value.finished === 'string') setFinished(value.finished);
    if (typeof value.good === 'string') setGood(value.good);
    if (typeof value.reject === 'string') setReject(value.reject);
    if (typeof value.reason === 'string') setReason(value.reason);
    if (Array.isArray(value.defects))
      setDefects(
        value.defects.filter(
          (row) => typeof row?.defect_type_id === 'string' && typeof row?.count === 'string',
        ),
      );
  }, []);
  const draft = useFormDraftAutosave({
    formKey: `earlier-production:${workOrderId}:${crew.id}`,
    userId,
    getValues,
    setValues: restoreValues,
    enabled: canSubmit && !pending && dirty,
  });
  useUnsavedChangesGuard(dirty || !!pending);
  const [reportPage, setReportPage] = useState(1);
  const reports = useQuery({
    queryKey: ['my-late-production-reports', userId, workOrderId, reportPage],
    queryFn: () => workOrdersApi.myLateProductionReports(workOrderId, reportPage),
  });
  const options = useQuery({
    queryKey: ['late-production-report-options', userId, workOrderId, crew.id],
    queryFn: () => workOrdersApi.myLateProductionReportOptions(workOrderId, crew.id),
    enabled: canSubmit && !finishFirst && Number(reject) > 0,
  });
  const refresh = () => {
    void queryClient.invalidateQueries({
      queryKey: ['my-late-production-reports', userId, workOrderId],
    });
    void queryClient.invalidateQueries({ queryKey: ['my-work-orders'] });
    void queryClient.invalidateQueries({ queryKey: ['my-work-order-history'] });
    void queryClient.invalidateQueries({ queryKey: ['production', 'work-orders'] });
  };
  const save = useMutation({
    mutationFn: (submission: PendingSubmission) =>
      workOrdersApi.proposeLateProduction(workOrderId, submission.data, submission.key),
    onSuccess: () => {
      recovery.clear();
      draft.discard();
      setUncertain(false);
      setSource(null);
      setStarted('');
      setFinished('');
      setGood('');
      setReject('0');
      setDefects([]);
      setReason('');
      setReportPage(1);
      refresh();
    },
    onError: (error) => {
      refresh();
      const unknown =
        !(error instanceof AxiosError) || !error.response || error.response.status >= 500;
      if (unknown) setUncertain(true);
      else if (!uncertain) recovery.clear();
    },
  });
  const totalRejects = defects.reduce((sum, row) => sum + (Number(row.count) || 0), 0);
  const datesValid =
    !!started &&
    !!finished &&
    startTime < finishTime &&
    finishTime <= Date.now() &&
    !reportInterval?.blocker;
  const defectsValid =
    Number(reject) === 0
      ? defects.length === 0
      : defects.length > 0 &&
        defects.every(
          (row) =>
            row.defect_type_id &&
            /^\d+$/.test(row.count) &&
            Number(row.count) > 0 &&
            Number.isSafeInteger(Number(row.count)),
        ) &&
        totalRejects === Number(reject) &&
        new Set(defects.map((row) => row.defect_type_id)).size === defects.length;
  const valid =
    !!source.trim() &&
    source.trim().length <= 120 &&
    datesValid &&
    /^\d+$/.test(good) &&
    /^\d+$/.test(reject) &&
    Number.isSafeInteger(Number(good)) &&
    Number.isSafeInteger(Number(reject)) &&
    Number.isSafeInteger(Number(good) + Number(reject)) &&
    Number(good) + Number(reject) > 0 &&
    (finishFirst ? Number(good) > 0 : defectsValid) &&
    !!reason.trim() &&
    reason.trim().length <= 500;
  const submit = () => {
    if (!valid || pending || !canSubmit) return;
    const submission: PendingSubmission = {
      key: createRequestKey(),
      data: {
        crew_id: crew.id,
        source_reference: source.trim(),
        production_started_at: reportInterval
          ? new Date(startTime).toISOString()
          : plantTimeIso(started),
        production_finished_at: reportInterval
          ? new Date(finishTime).toISOString()
          : plantTimeIso(finished),
        ...(finishFirst
          ? { produced_count: Number(good) }
          : {
              good_count: Number(good),
              reject_count: Number(reject),
              defects: defects.map((row) => ({
                defect_type_id: row.defect_type_id,
                count: Number(row.count),
              })),
            }),
        reason: reason.trim(),
      },
    };
    if (recovery.remember(submission)) {
      setUncertain(false);
      save.mutate(submission);
    }
  };

  if (finishTime > Date.now() && !pending && !draft.hasDraft) return null;

  const panel = (
    <Panel
      title={collapsed ? undefined : 'Report earlier production'}
      className={collapsed ? 'border-0 rounded-none' : undefined}
    >
      <p className="text-sm text-muted max-w-prose">
        Report pieces made but not yet saved. Production management reviews before posting.{' '}
        {workOrderStatus === 'closed'
          ? 'Closed WO: submit a correction for controlled review.'
          : ''}
      </p>
      {draft.hasDraft && !pending && (
        <DraftRestoreBanner
          ageMs={draft.draftAge}
          onRestore={draft.restore}
          onDiscard={draft.discard}
        />
      )}
      {workOrderStatus === 'cancelled' && (
        <p role="alert" className="mt-3 text-sm text-warning-fg">
          Cancelled work orders cannot receive production reports.
        </p>
      )}
      {canSubmit && workOrderStatus !== 'cancelled' && (
        <form
          className="mt-4 space-y-4"
          onSubmit={(event) => {
            event.preventDefault();
            submit();
          }}
        >
          <fieldset disabled={!!pending || save.isPending} className="space-y-4">
            <div>
              <p className="mb-2 text-xs text-muted">
                Production times · Asia/Manila{reportInterval ? ' · From saved WO schedule' : ''}
              </p>
              <div className="grid gap-3 sm:grid-cols-2">
                <Input
                  label="Production started"
                  type={reportInterval ? 'text' : 'datetime-local'}
                  required
                  value={
                    reportInterval
                      ? started
                        ? productionDateTime(started)
                        : 'Not available'
                      : started
                  }
                  readOnly={!!reportInterval}
                  onChange={reportInterval ? undefined : (event) => setStarted(event.target.value)}
                />
                <Input
                  label="Production finished"
                  type={reportInterval ? 'text' : 'datetime-local'}
                  required
                  value={
                    reportInterval
                      ? finished
                        ? productionDateTime(finished)
                        : 'Not available'
                      : finished
                  }
                  readOnly={!!reportInterval}
                  onChange={reportInterval ? undefined : (event) => setFinished(event.target.value)}
                />
              </div>
              {reportInterval?.blocker && (
                <p role="alert" className="mt-2 text-sm text-warning-fg">
                  {reportInterval.blocker}
                </p>
              )}
              {reportInterval && !reportInterval.blocker && (
                <p className="mt-2 text-xs text-muted">
                  Times reflect the saved schedule within this crew’s recorded run. Report any
                  timing difference in your reason.
                </p>
              )}
            </div>
            <Input
              label="Original batch or source reference"
              helper={batchNumber ? 'From this WO. Change only if the batch label differs.' : 'Use the batch label or production log reference.'}
              placeholder="e.g. BATCH-003"
              required
              maxLength={120}
              value={source}
              onChange={(event) => setSource(event.target.value)}
            />
            <div className="grid gap-3 sm:grid-cols-2">
              <Input
                label={finishFirst ? 'Additional produced quantity' : 'Additional good count'}
                aria-label={finishFirst ? 'Additional produced quantity' : undefined}
                type="number"
                min={0}
                step={1}
                required
                value={good}
                onChange={(event) => setGood(event.target.value)}
              />
              {!finishFirst && (
                <Input
                  label="Additional reject count"
                  type="number"
                  min={0}
                  step={1}
                  required
                  value={reject}
                  onChange={(event) => setReject(event.target.value)}
                />
              )}
            </div>
            {!finishFirst && Number(reject) > 0 && (
              <div className="space-y-2">
                {options.isLoading && (
                  <p role="status" className="text-sm text-muted">
                    Loading defect types…
                  </p>
                )}
                {options.isError && (
                  <p role="alert" className="text-sm text-danger-fg">
                    Defect types failed to load.{' '}
                    <Button type="button" variant="secondary" onClick={() => options.refetch()}>
                      Retry
                    </Button>
                  </p>
                )}
                <Button
                  type="button"
                  variant="secondary"
                  disabled={!options.data}
                  onClick={() => setDefects([...defects, { defect_type_id: '', count: '' }])}
                >
                  Add defect type
                </Button>
                {defects.map((row, index) => (
                  <div key={index} className="grid gap-2 sm:grid-cols-[1fr_7rem_auto]">
                    <Select
                      label={`Defect type ${index + 1}`}
                      value={row.defect_type_id}
                      onChange={(event) =>
                        setDefects(
                          defects.map((item, i) =>
                            i === index ? { ...item, defect_type_id: event.target.value } : item,
                          ),
                        )
                      }
                    >
                      <option value="">Choose defect</option>
                      {options.data?.defect_types.map((type) => (
                        <option
                          key={type.id}
                          value={type.id}
                          disabled={defects.some(
                            (other, otherIndex) =>
                              otherIndex !== index && other.defect_type_id === type.id,
                          )}
                        >
                          {type.code} · {type.name}
                        </option>
                      ))}
                    </Select>
                    <Input
                      label={`Defect count ${index + 1}`}
                      type="number"
                      min={1}
                      step={1}
                      value={row.count}
                      onChange={(event) =>
                        setDefects(
                          defects.map((item, i) =>
                            i === index ? { ...item, count: event.target.value } : item,
                          ),
                        )
                      }
                    />
                    <Button
                      type="button"
                      variant="ghost"
                      onClick={() => setDefects(defects.filter((_, i) => i !== index))}
                    >
                      Remove
                    </Button>
                  </div>
                ))}
                <p className="text-sm text-muted">
                  Defect counts must classify all {reject} rejected pieces.
                </p>
              </div>
            )}
            <Textarea
              label="Why was production not recorded at the time?"
              required
              maxLength={500}
              value={reason}
              onChange={(event) => setReason(event.target.value)}
            />
          </fieldset>
          {started && finished && !datesValid && !reportInterval?.blocker && (
            <p role="alert" className="text-sm text-danger-fg">
              Finish must follow start. Both times must be in the past.
            </p>
          )}
          {Number(reject) > 0 && defects.length > 0 && !defectsValid && (
            <p role="alert" className="text-sm text-danger-fg">
              Choose each defect type once. Counts must total {reject} rejected pieces.
            </p>
          )}
          <Button
            type="submit"
            variant="primary"
            className="min-h-11 w-full sm:w-auto"
            disabled={!valid || !!pending || save.isPending}
            loading={save.isPending}
          >
            Submit for management review
          </Button>
        </form>
      )}
      {!canSubmit && workOrderStatus !== 'cancelled' && (
        <p className="mt-3 text-sm text-muted">
          {recordingPending
            ? 'Resolve the unconfirmed recording above before reporting earlier production.'
            : 'Only the recorder assigned to this crew can submit a historical report.'}
        </p>
      )}
      {recovery.storageError && (
        <p role="alert" className="mt-3 text-sm text-danger-fg">
          {recovery.storageError}
        </p>
      )}
      {uncertain && pending && (
        <p role="alert" className="mt-3 text-sm text-warning-fg">
          Unconfirmed report: {pending.data.source_reference}. Retry this same report before
          entering another.
        </p>
      )}
      {save.isError && !uncertain && (
        <p role="alert" className="mt-3 text-sm text-danger-fg">
          {save.error instanceof AxiosError
            ? (save.error.response?.data?.message ?? 'Report submission failed.')
            : 'Report submission failed.'}
        </p>
      )}
      {uncertain && pending && (
        <Button
          className="mt-3"
          variant="secondary"
          disabled={save.isPending}
          onClick={() => save.mutate(pending)}
        >
          Retry same report
        </Button>
      )}
      {save.isSuccess && (
        <p role="status" className="mt-3 text-sm">
          {workOrderStatus === 'closed'
            ? 'Correction report submitted. Contact Production management and Accounting.'
            : 'Report submitted. Production management review required.'}
        </p>
      )}
      {reports.isError && (
        <p role="alert" className="mt-3 text-sm text-danger-fg">
          Your report history failed to load.{' '}
          <Button variant="secondary" onClick={() => reports.refetch()}>
            Retry
          </Button>
        </p>
      )}
      {reports.data && (
        <div className="mt-4 border-t border-default pt-3">
          <h4 className="text-sm font-medium">
            Your submitted reports
            {reports.data.meta.total > 0 ? ` · ${reports.data.meta.total}` : ''}
          </h4>
          {reports.data.data.length === 0 ? (
            <p className="mt-2 text-sm text-muted">No historical reports.</p>
          ) : (
            <ul className="mt-2 max-h-96 overflow-y-auto divide-y divide-default">
              {reports.data.data.map((report) => (
                <li key={report.id} className="py-2 text-sm">
                  <p className="flex flex-wrap items-center gap-2 font-medium">
                    {report.source_reference}
                    <Chip variant={chipVariantForStatus(report.status)}>{report.status.replaceAll('_', ' ')}</Chip>
                  </p>
                  <p className="text-muted">
                    {finishFirst
                      ? `${report.good_count} produced`
                      : `${report.good_count} good · ${report.reject_count} rejected`}{' '}
                    · {productionDateTime(report.production_started_at)} –{' '}
                    {productionDateTime(report.production_finished_at)}
                  </p>
                  {report.output_summary && (
                    <p role="status" className="text-muted">
                      {report.output_summary.held_quantity > 0
                        ? `${report.output_summary.held_quantity} physical pieces posted on quality hold.`
                        : `Posted ${report.output_summary.good_count} good · ${report.output_summary.reject_count} rejected.`}
                    </p>
                  )}
                  {report.reviewed_at && (
                    <p className="text-muted">
                      Reviewed by {report.reviewer_name ?? 'Production management'} ·{' '}
                      {productionDateTime(report.reviewed_at)}
                    </p>
                  )}
                  {report.review_reason && (
                    <p className="text-muted">Review: {report.review_reason}</p>
                  )}
                </li>
              ))}
            </ul>
          )}
          {(reports.data?.meta.last_page ?? 1) > 1 && (
            <nav
              aria-label="Your historical report pages"
              className="mt-3 flex items-center justify-end gap-2 border-t border-default pt-3"
            >
              <Button
                variant="secondary"
                disabled={reportPage <= 1}
                onClick={() => setReportPage((current) => Math.max(1, current - 1))}
              >
                Previous reports
              </Button>
              <span className="text-xs text-muted">
                Page {reports.data?.meta.current_page ?? reportPage} of{' '}
                {reports.data?.meta.last_page}
              </span>
              <Button
                variant="secondary"
                disabled={reportPage >= (reports.data?.meta.last_page ?? 1)}
                onClick={() => setReportPage((current) => current + 1)}
              >
                Next reports
              </Button>
            </nav>
          )}
        </div>
      )}
    </Panel>
  );
  return collapsed ? (
    <details
      open={!!pending || draft.hasDraft || undefined}
      className="rounded-md border border-default bg-canvas"
    >
      <summary className="min-h-11 cursor-pointer px-4 py-3 text-sm font-medium focus-visible:outline-accent">
        Finished work not recorded on time? Report earlier production
      </summary>
      {panel}
    </details>
  ) : (
    panel
  );
}

export function LateProductionReviewQueue({
  workOrderId,
  workOrderStatus,
}: {
  workOrderId: string;
  workOrderStatus: string;
}) {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [reasons, setReasons] = useState<Record<string, string>>({});
  const reports = useQuery({
    queryKey: ['late-production-reports', workOrderId, page],
    queryFn: () => workOrdersApi.lateProductionReports(workOrderId, undefined, page),
  });
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['late-production-reports', workOrderId] });
    void queryClient.invalidateQueries({ queryKey: ['production', 'work-orders'] });
  };
  const approve = useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) =>
      workOrdersApi.approveLateProductionReport(id, reason),
    onSuccess: refresh,
  });
  const reject = useMutation({
    mutationFn: ({ id, reason }: { id: string; reason: string }) =>
      workOrdersApi.rejectLateProductionReport(id, reason),
    onSuccess: refresh,
  });

  return (
    <Panel
      title="Historical production review"
      meta={reports.data ? `${reports.data.meta.total} reports` : undefined}
    >
      {reports.isLoading && (
        <p role="status" className="text-sm text-muted">
          Loading historical reports…
        </p>
      )}
      {reports.isError && (
        <p role="alert" className="text-sm text-danger-fg">
          Historical reports failed to load.{' '}
          <Button variant="secondary" onClick={() => reports.refetch()}>
            Retry
          </Button>
        </p>
      )}
      {approve.isError && (
        <p role="alert" className="text-sm text-danger-fg">
          {approve.error instanceof AxiosError
            ? approve.error.response?.data?.message
            : 'Approval failed.'}
        </p>
      )}
      {reject.isError && (
        <p role="alert" className="text-sm text-danger-fg">
          {reject.error instanceof AxiosError
            ? reject.error.response?.data?.message
            : 'Rejection failed.'}
        </p>
      )}
      {reports.data?.data.length === 0 && (
        <p className="text-sm text-muted">No historical reports for this work order.</p>
      )}
      <ul className="max-h-96 overflow-y-auto divide-y divide-default">
        {reports.data?.data.map((report) => {
          const evidence = report.evidence_summary;
          const closed = workOrderStatus === 'closed' || report.status === 'correction';
          const missingEvidence = [
            evidence.crew,
            evidence.attendance,
            evidence.execution,
            evidence.resources,
          ].some((item) => item.state === 'missing');
          const evidenceConflicts = [
            evidence.crew,
            evidence.attendance,
            evidence.execution,
            evidence.resources,
          ].some((item) => item.state === 'conflicting');
          return (
            <li key={report.id} className="py-3 space-y-2">
              <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="flex flex-wrap items-center gap-2 font-medium">
                  {report.source_reference}
                  <Chip variant={chipVariantForStatus(report.status)}>{report.status.replaceAll('_', ' ')}</Chip>
                </p>
                <p className="text-xs text-muted">
                  {report.good_count} good · {report.reject_count} rejected ·{' '}
                  {report.owner ?? 'Reviewed'}
                </p>
              </div>
              {report.output_summary && (
                <p role="status" className="text-sm">
                  {report.output_summary.held_quantity > 0
                    ? `${report.output_summary.held_quantity} physical pieces posted on quality hold.`
                    : `Posted ${report.output_summary.good_count} good · ${report.output_summary.reject_count} rejected.`}
                </p>
              )}
              {report.reviewed_at && (
                <p className="text-sm text-muted">
                  Reviewed by {report.reviewer_name ?? 'Production management'} ·{' '}
                  {productionDateTime(report.reviewed_at)}
                </p>
              )}
              <p className="text-sm">
                {productionDateTime(report.production_started_at)} –{' '}
                {productionDateTime(report.production_finished_at)} · Recorded by{' '}
                {report.recorded_by_name ?? 'Recorder'}
              </p>
              <p className="text-sm">
                Crew:{' '}
                {report.crew_snapshot.members
                  .map((member) => member.name ?? member.employee_number ?? 'Crew member')
                  .join(', ') || 'Snapshot unavailable'}
              </p>
              <p className="text-sm">
                Evidence: crew {evidence.crew.state}, attendance {evidence.attendance.state},
                execution {evidence.execution.state}, resources {evidence.resources.state}
              </p>
              {evidence.attendance.members.length > 0 && (
                <ul className="text-xs text-muted">
                  {evidence.attendance.members.map((member, index) => (
                    <li key={`${report.id}-attendance-${index}`}>
                      {member.name}: {member.state}
                      {member.shift
                        ? ` · Shift ${productionDateTime(member.shift.starts_at)}–${productionDateTime(member.shift.ends_at)}`
                        : ''}
                    </li>
                  ))}
                </ul>
              )}
              {(evidence.resources.machine_code || evidence.resources.mold_code) && (
                <p className="text-sm text-muted">
                  Historical schedule: {evidence.resources.machine_code ?? 'Machine not recorded'} ·{' '}
                  {evidence.resources.mold_code ?? 'Mold not recorded'}
                </p>
              )}
              {evidenceConflicts && (
                <p role="alert" className="text-sm text-warning-fg">
                  Conflicting historical evidence must be reconciled before approval.
                </p>
              )}
              {report.reason && <p className="text-sm text-muted">Reason: {report.reason}</p>}
              {closed && report.status === 'correction' && (
                <p role="alert" className="text-sm text-warning-fg">
                  Closed work order requires accounting correction. This report cannot post through
                  normal approval.
                </p>
              )}
              {['pending', 'correction'].includes(report.status) && report.can_approve && (
                <div className="space-y-2">
                  <Textarea
                    label={
                      missingEvidence
                        ? 'Review note (required for missing historical evidence)'
                        : 'Review note (optional)'
                    }
                    required={missingEvidence}
                    maxLength={500}
                    value={reasons[report.id] ?? ''}
                    onChange={(event) =>
                      setReasons({ ...reasons, [report.id]: event.target.value })
                    }
                  />
                  <div className="flex gap-2">
                    {!closed && report.status === 'pending' && (
                      <Button
                        variant="primary"
                        disabled={
                          approve.isPending ||
                          reject.isPending ||
                          evidenceConflicts ||
                          (missingEvidence && !(reasons[report.id] ?? '').trim())
                        }
                        loading={approve.isPending && approve.variables?.id === report.id}
                        onClick={() =>
                          approve.mutate({ id: report.id, reason: reasons[report.id] ?? '' })
                        }
                      >
                        Approve and post
                      </Button>
                    )}
                    <Button
                      variant="secondary"
                      disabled={
                        approve.isPending || reject.isPending || !(reasons[report.id] ?? '').trim()
                      }
                      loading={reject.isPending && reject.variables?.id === report.id}
                      onClick={() =>
                        reject.mutate({ id: report.id, reason: reasons[report.id].trim() })
                      }
                    >
                      Reject
                    </Button>
                  </div>
                </div>
              )}
            </li>
          );
        })}
      </ul>
      {(reports.data?.meta.last_page ?? 1) > 1 && (
        <nav
          aria-label="Historical report pages"
          className="mt-3 flex items-center justify-end gap-2 border-t border-default pt-3"
        >
          <Button
            variant="secondary"
            disabled={page <= 1}
            onClick={() => setPage((current) => Math.max(1, current - 1))}
          >
            Previous reports
          </Button>
          <span className="text-xs text-muted">
            Page {reports.data?.meta.current_page ?? page} of {reports.data?.meta.last_page}
          </span>
          <Button
            variant="secondary"
            disabled={page >= (reports.data?.meta.last_page ?? 1)}
            onClick={() => setPage((current) => current + 1)}
          >
            Next reports
          </Button>
        </nav>
      )}
    </Panel>
  );
}
