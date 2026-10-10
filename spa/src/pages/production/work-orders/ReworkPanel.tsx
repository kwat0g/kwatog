import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { client } from '@/api/client';
import { workOrdersApi } from '@/api/production/workOrders';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Modal, ModalFooter } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { CrewPanel } from './CrewPanel';
import { Textarea } from '@/components/ui/Textarea';
import { formatDateTime } from '@/lib/formatDate';
import { useElapsedSeconds } from '@/hooks/useElapsedSeconds';
import {
  productionDateTime,
  scheduleDateKey,
  offsetScheduleDate,
} from '@/components/production/scheduleDates';
import { WorkerScheduleStatus, LateProductionApproval } from './CrewPanel';
import type { CrewContext } from '@/types/production-crew';
import type {
  DefectType,
  RecordOutputData,
  WorkOrderRework,
  WorkOrderStatus,
} from '@/types/production';

export function ReworkPanel({
  woId,
  woNumber,
  isOpen,
  onClose,
  workOrderStatus,
  rework,
  canLifecycle,
  canRecord,
  canViewQc,
  canViewNcr,
}: {
  woId: string;
  woNumber: string;
  isOpen: boolean;
  onClose: () => void;
  workOrderStatus: WorkOrderStatus;
  rework: WorkOrderRework;
  canLifecycle: boolean;
  canRecord: boolean;
  canViewQc: boolean;
  canViewNcr: boolean;
}) {
  const queryClient = useQueryClient();
  const [presenceConfirmed, setPresenceConfirmed] = useState(false);
  const [finishOpen, setFinishOpen] = useState(false);
  const [autoScheduleOpen, setAutoScheduleOpen] = useState(false);
  const [schedulePending, setSchedulePending] = useState(false);
  const [good, setGood] = useState(String(rework.remaining_quantity));
  const [reject, setReject] = useState('0');
  const [defect, setDefect] = useState('');
  const [reason, setReason] = useState('');
  const [recordingOpen, setRecordingOpen] = useState(false);
  const crew = useQuery({
    queryKey: ['production', 'crew', woId, rework.id],
    queryFn: () => workOrdersApi.crew(woId, rework.id),
    enabled: isOpen,
  });
  useElapsedSeconds(isOpen && !rework.started_at);
  const now = crew.data?.server_time
    ? Date.parse(crew.data.server_time) + (Date.now() - crew.dataUpdatedAt)
    : Date.now();
  const slotStart = Date.parse(crew.data?.schedule?.scheduled_start ?? '');
  const slotEnd = Date.parse(crew.data?.schedule?.scheduled_end ?? '');
  const slotReady = slotStart <= now && now < slotEnd;
  useEffect(
    () => setPresenceConfirmed(false),
    [isOpen, crew.data?.current?.id, crew.data?.schedule?.id],
  );
  const [submission, setSubmission] = useState<{ data: RecordOutputData; key: string } | null>(
    null,
  );
  const [uncertain, setUncertain] = useState(false);
  useEffect(() => {
    if (!isOpen && !submission) {
      setRecordingOpen(false);
      setReason('');
    }
  }, [isOpen, submission]);
  const defects = useQuery({
    queryKey: ['production', 'defect-types'],
    queryFn: () =>
      client.get<{ data: DefectType[] }>('/production/defect-types').then((r) => r.data.data),
    enabled: Number(reject) > 0,
  });
  const mutation = useMutation({
    mutationFn: async (action: 'start' | 'record' | 'finish') => {
      if (action === 'start')
        return workOrdersApi.startRework(woId, rework.id, undefined, undefined, {
          expected_crew_id: crew.data!.current!.id,
          presence_confirmed_employee_ids: presenceConfirmed
            ? crew.data?.presence
                ?.filter((member) => member.state === 'unknown')
                .map((member) => member.employee_id)
            : [],
        });
      if (action === 'finish') return workOrdersApi.finishRework(woId, rework.id);
      if (submission)
        return workOrdersApi.recordRework(woId, rework.id, submission.data, submission.key);
      const data = {
        crew_id: crew.data!.current!.id,
        recording_reason: reason.trim(),
        good_count: Number(good),
        reject_count: Number(reject),
        defects: Number(reject) > 0 ? [{ defect_type_id: defect, count: Number(reject) }] : [],
      };
      const pending = { data, key: crypto.randomUUID() };
      setSubmission(pending);
      return workOrdersApi.recordRework(woId, rework.id, pending.data, pending.key);
    },
    onSuccess: (_, action) => {
      queryClient.invalidateQueries({ queryKey: ['production', 'work-orders'] });
      setFinishOpen(false);
      if (action === 'record') {
        setSubmission(null);
        setUncertain(false);
        setRecordingOpen(false);
        setReason('');
        setGood('0');
        setReject('0');
        setDefect('');
      }
      toast.success(
        action === 'finish'
          ? 'Repair finished. QC approval required.'
          : action === 'start'
            ? 'Rework started.'
            : 'Rework output recorded.',
      );
    },
    onError: (error, action) => {
      if (action === 'record') {
        if (!(error instanceof AxiosError) || !error.response || error.response.status >= 500)
          setUncertain(true);
        else if (!uncertain) setSubmission(null);
      }
      toast.error(
        error instanceof AxiosError
          ? (error.response?.data?.message ?? 'Rework action failed. Try again.')
          : error.message,
      );
    },
  });
  const check = useMutation({
    mutationFn: () => workOrdersApi.myOutputReceipt(woId, submission!.key),
    onSuccess: () => {
      setSubmission(null);
      setUncertain(false);
      setGood('0');
      setReject('0');
      setDefect('');
      queryClient.invalidateQueries({ queryKey: ['production', 'work-orders'] });
      toast.success('Output receipt found. Repair recording saved.');
    },
    onError: (error) =>
      toast.error(
        error instanceof AxiosError && error.response?.status === 404
          ? 'No receipt found yet. Retry the same recording to resolve it.'
          : 'Receipt check failed. Try again.',
      ),
  });
  const terminal = ['closed', 'cancelled'].includes(rework.status);
  const active = !!rework.started_at && !rework.finished_at && !terminal;
  const message =
    mutation.error instanceof AxiosError
      ? mutation.error.response?.data?.message
      : mutation.error?.message;
  const totalCount = Number(good) + Number(reject);
  const countError =
    !/^\d+$/.test(good) || !/^\d+$/.test(reject)
      ? 'Use whole numbers for repaired and unrepairable pieces.'
      : totalCount < 1
        ? 'Enter at least one repaired or unrepairable piece.'
        : totalCount > rework.remaining_quantity
          ? `Total cannot exceed ${rework.remaining_quantity} remaining piece${rework.remaining_quantity === 1 ? '' : 's'}.`
          : undefined;
  const defectError =
    Number(reject) > 0 && !defect ? 'Select a defect type for rejected pieces.' : undefined;
  const invalidCount =
    !!countError ||
    !!defectError ||
    !crew.data?.current ||
    crew.isError ||
    !reason.trim() ||
    (Number(reject) > 0 && (!defects.data || defects.isError));
  const canUseLifecycle = canLifecycle && workOrderStatus === 'completed';
  const canRecordOutput = canRecord && workOrderStatus === 'completed';
  const recorder = crew.data?.current?.members.find(
    (member) => member.id === crew.data?.current?.recorder_employee_id,
  );
  const presenceUnknown = !!crew.data?.presence?.some((member) => member.state === 'unknown');
  const presenceBlocked = !!crew.data?.presence?.some((member) => member.state === 'blocked');
  const closeBlocked = schedulePending || mutation.isPending || check.isPending || !!submission;
  const close = () => {
    if (!closeBlocked) onClose();
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={close}
      closeOnOverlayClick={false}
      size="xl"
      title={`${terminal || rework.finished_at || (active ? !canUseLifecycle && !canRecordOutput : !canUseLifecycle) ? 'View Rework' : active ? 'Continue Rework' : 'Start Rework'} · ${woNumber}`}
    >
      <div className="mb-5 border-b border-default pb-4 space-y-2 text-sm">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="font-medium">
            {rework.quantity} pieces ·{' '}
            {terminal
              ? 'Resolved'
              : rework.finished_at
                ? 'Awaiting QC'
                : active
                  ? 'In progress'
                  : slotEnd > now
                    ? 'Schedule approved'
                    : 'Ready for scheduling'}
          </p>
          {canViewNcr && (
            <Link className="text-accent hover:underline" to={`/quality/ncrs/${rework.id}`}>
              {rework.ncr_number}
            </Link>
          )}
        </div>
        <p className="break-words">{rework.defect_description}</p>
        <p className="text-xs text-muted">
          Repair existing pieces. Original production quantity stays unchanged.
        </p>
      </div>
      {!rework.started_at && !terminal && canUseLifecycle && crew.data?.can_review_slot && (
        <div className="mb-4">
          <Button
            disabled={schedulePending}
            variant="secondary"
            onClick={() => setAutoScheduleOpen(!autoScheduleOpen)}
          >
            {autoScheduleOpen ? 'Back to repair details' : 'Auto schedule and suggest resources'}
          </Button>
        </div>
      )}
      {autoScheduleOpen && crew.data ? (
        <RepairScheduleForm
          woId={woId}
          ncrId={rework.id}
          context={crew.data}
          onSaved={() => setAutoScheduleOpen(false)}
          onPendingChange={setSchedulePending}
        />
      ) : (
        <>
          <section className="space-y-3" aria-label="Repair crew">
            <h3 className="text-sm font-medium">Repair crew</h3>
            <CrewPanel
              woId={woId}
              ncrId={rework.id}
              readOnly={terminal || !!rework.finished_at || workOrderStatus !== 'completed'}
              allowSlotReview={false}
              unboxed
            />
          </section>
          {!rework.started_at && !terminal && slotStart > now && (
            <p role="status" className="mt-4 text-sm text-muted">
              Rework starts at {productionDateTime(crew.data!.schedule!.scheduled_start)}{' '}
              (Asia/Manila).
            </p>
          )}
          {!rework.started_at && !terminal && slotEnd <= now && (
            <p role="alert" className="mt-4 text-sm text-warning-fg">
              Repair slot expired. Auto schedule a new slot.
            </p>
          )}
          {!rework.started_at && !terminal && presenceUnknown && (
            <label className="mt-5 flex items-start gap-2 border-t border-default pt-4 text-sm">
              <input
                type="checkbox"
                className="mt-0.5 shrink-0"
                checked={presenceConfirmed}
                disabled={closeBlocked}
                onChange={(event) => setPresenceConfirmed(event.target.checked)}
              />
              Confirm repair workers are physically present
            </label>
          )}
          {rework.started_at && (
            <p className="mt-1 text-xs text-muted">
              Started by {rework.started_by?.name ?? 'Unknown user'} ·{' '}
              {formatDateTime(rework.started_at)}
            </p>
          )}
          {rework.finished_at && (
            <p className="text-xs text-muted">
              Finished by {rework.finished_by?.name ?? 'Unknown user'} ·{' '}
              {formatDateTime(rework.finished_at)}
            </p>
          )}
          {active && workOrderStatus === 'completed' && (
            <div className="mt-4 space-y-3">
              <p className="text-sm">
                {rework.recorded_quantity} of {rework.quantity} pieces processed ·{' '}
                {rework.remaining_quantity} remaining
              </p>
              {rework.remaining_quantity > 0 && (
                <p className="text-sm">
                  {recorder?.name ?? 'The assigned recorder'} records repaired output in My Work
                  Orders.
                </p>
              )}
              {canRecordOutput && rework.remaining_quantity > 0 && !recordingOpen && (
                <Button
                  variant="secondary"
                  disabled={crew.isLoading || crew.isError || !crew.data?.current}
                  onClick={() => setRecordingOpen(true)}
                >
                  Record on behalf
                </Button>
              )}
              {canRecordOutput && rework.remaining_quantity > 0 && recordingOpen && (
                <form
                  onSubmit={(e) => {
                    e.preventDefault();
                    if (!invalidCount && !submission) mutation.mutate('record');
                  }}
                  className="space-y-3"
                >
                  <p className="text-xs text-muted">
                    Use only when recording for the assigned recorder. Your name and reason are
                    saved.
                  </p>
                  <fieldset disabled={!!submission} className="space-y-3 min-w-0">
                    <div className="grid gap-3 sm:grid-cols-2">
                      <Input
                        label="Repaired pieces"
                        aria-label="Repaired pieces"
                        type="number"
                        min="0"
                        max={rework.remaining_quantity}
                        step="1"
                        value={good}
                        onChange={(e) => setGood(e.target.value)}
                        required
                      />
                      <Input
                        label="Unrepairable pieces"
                        aria-label="Unrepairable pieces"
                        type="number"
                        min="0"
                        max={rework.remaining_quantity}
                        step="1"
                        value={reject}
                        onChange={(e) => setReject(e.target.value)}
                        required
                      />
                    </div>
                    {Number(reject) > 0 && (
                      <Select
                        label="Defect type"
                        aria-label="Defect type"
                        required
                        value={defect}
                        onChange={(e) => setDefect(e.target.value)}
                      >
                        <option value="">Select defect</option>
                        {defects.data?.map((d) => (
                          <option key={d.id} value={d.id}>
                            {d.name}
                          </option>
                        ))}
                      </Select>
                    )}
                    {defects.isError && Number(reject) > 0 && (
                      <p role="alert" className="text-sm text-danger-fg">
                        Defect types failed to load.{' '}
                        <button
                          type="button"
                          className="underline"
                          onClick={() => defects.refetch()}
                        >
                          Retry
                        </button>
                      </p>
                    )}
                    <Textarea
                      label="Reason for recording on behalf"
                      aria-label="Reason for recording on behalf"
                      required
                      value={reason}
                      onChange={(event) => setReason(event.target.value)}
                    />
                    {!crew.data?.current && (
                      <p role="alert">Assign a repair crew before recording output.</p>
                    )}
                    {countError && (
                      <p role="alert" className="text-sm text-danger-fg">
                        {countError}
                      </p>
                    )}
                    {defectError && (
                      <p role="alert" className="text-sm text-danger-fg">
                        {defectError}
                      </p>
                    )}
                  </fieldset>
                  <div className="flex flex-wrap gap-2">
                    <Button
                      type="submit"
                      disabled={!!submission || mutation.isPending || invalidCount}
                    >
                      Record rework output
                    </Button>
                    <Button
                      type="button"
                      variant="secondary"
                      disabled={!!submission || mutation.isPending}
                      onClick={() => {
                        setRecordingOpen(false);
                        setReason('');
                      }}
                    >
                      Cancel recording
                    </Button>
                  </div>
                </form>
              )}
              {canUseLifecycle && rework.remaining_quantity === 0 && (
                <Button
                  disabled={!!submission || mutation.isPending}
                  onClick={() => setFinishOpen(true)}
                >
                  Finish rework
                </Button>
              )}
            </div>
          )}
          {uncertain && submission && (
            <div role="alert" className="mt-4 space-y-2 text-sm">
              <p>
                Recording outcome unknown. Check the receipt or retry this same recording before
                entering new counts.
              </p>
              <Button
                type="button"
                variant="secondary"
                disabled={mutation.isPending || check.isPending}
                onClick={() => check.mutate()}
              >
                Check receipt
              </Button>
              <Button
                type="button"
                disabled={mutation.isPending || check.isPending}
                onClick={() => mutation.mutate('record')}
              >
                Retry same recording
              </Button>
            </div>
          )}
          {rework.inspections.length > 0 && (
            <div className="mt-4 border-t border-default pt-3 space-y-2">
              <p className="text-xs text-muted">Fresh inspection for repaired pieces</p>
              {rework.inspections.map((i) => (
                <div key={i.id} className="text-sm">
                  {canViewQc ? (
                    <Link
                      className="text-accent hover:underline"
                      to={`/quality/inspections/${i.id}`}
                    >
                      {i.inspection_number}
                    </Link>
                  ) : (
                    <span>{i.inspection_number}</span>
                  )}{' '}
                  · {i.status.replaceAll('_', ' ')}
                </div>
              ))}
            </div>
          )}
          {active && workOrderStatus !== 'completed' && (
            <p role="note" className="mt-3 text-sm text-muted">
              Repair actions are blocked because this work order is no longer awaiting quality.
            </p>
          )}
          {active &&
            rework.remaining_quantity > 0 &&
            workOrderStatus === 'completed' &&
            !canRecord && (
              <p role="note" className="mt-3 text-sm text-muted">
                Production output permission is required to record repaired quantities.
              </p>
            )}
          {active &&
            rework.remaining_quantity === 0 &&
            workOrderStatus === 'completed' &&
            !canLifecycle && (
              <p role="note" className="mt-3 text-sm text-muted">
                Production lifecycle permission is required to finish repair.
              </p>
            )}
          {!rework.started_at && !terminal && workOrderStatus !== 'completed' && (
            <p role="note" className="mt-3 text-sm text-muted">
              Finish original production before starting repair.
            </p>
          )}
          {!rework.started_at && !terminal && workOrderStatus === 'completed' && !canLifecycle && (
            <p role="note" className="mt-3 text-sm text-muted">
              Repair actions require the production lifecycle permission.
            </p>
          )}
          <ConfirmDialog
            isOpen={finishOpen}
            onClose={() => setFinishOpen(false)}
            onConfirm={() =>
              mutation
                .mutateAsync('finish')
                .then(() => undefined)
                .catch(() => undefined)
            }
            title="Finish rework?"
            description="Recorded repair cannot be reopened. Equipment will be released; repaired pieces still need QC approval."
            confirmLabel="Finish rework"
            pending={mutation.isPending}
          />
          {mutation.isError && (
            <p role="alert" className="mt-3 text-sm text-danger-fg">
              {message ?? 'Rework action failed. Try again.'}
            </p>
          )}
        </>
      )}
      <ModalFooter>
        <Button variant="secondary" onClick={close} disabled={closeBlocked}>
          Close
        </Button>
        {!autoScheduleOpen && !rework.started_at && !terminal && canUseLifecycle && (
          <Button
            variant="primary"
            loading={mutation.isPending}
            disabled={
              closeBlocked ||
              crew.isError ||
              crew.isFetching ||
              !crew.data?.schedule ||
              !crew.data.current ||
              !slotReady ||
              presenceBlocked ||
              (presenceUnknown && !presenceConfirmed)
            }
            onClick={() => mutation.mutate('start')}
          >
            Start rework
          </Button>
        )}
      </ModalFooter>
    </Modal>
  );
}

export function RepairScheduleForm({
  woId,
  ncrId,
  context,
  onSaved,
  onPendingChange,
}: {
  woId: string;
  ncrId: string;
  context: CrewContext;
  onSaved: () => void;
  onPendingChange?: (pending: boolean) => void;
}) {
  const queryClient = useQueryClient();
  const [workers, setWorkers] = useState(
    String(context.current?.members.length || context.minimum_workers || 1),
  );
  const [manual, setManual] = useState(false);
  const [day, setDay] = useState('');
  const [lateApproval, setLateApproval] = useState({ authorized: false, reason: '' });
  const today = scheduleDateKey(new Date().toISOString(), 'Asia/Manila');
  const valid =
    /^[1-4]$/.test(workers) && (!day || (day >= today && day <= offsetScheduleDate(today, 90)));
  const suggestion = useQuery({
    queryKey: ['production', 'repair-suggestion', woId, ncrId, workers, manual, day],
    queryFn: () =>
      workOrdersApi.repairSuggestion(woId, ncrId, {
        minimum_workers: Number(workers),
        manual_repair: manual,
        preferred_production_date: day || null,
      }),
    enabled: valid,
    retry: false,
    staleTime: 0,
    refetchOnWindowFocus: false,
  });
  const proposal = suggestion.data;
  const approval = useMutation({
    mutationFn: () => {
      onPendingChange?.(true);
      return workOrdersApi.reviewSlot(
        woId,
        {
          scheduled_start: proposal!.scheduled_start!,
          scheduled_end: proposal!.scheduled_end!,
          expected_schedule_id: proposal!.expected_schedule_id,
          expected_schedule_updated_at: proposal!.expected_schedule_updated_at,
          expected_crew_id: proposal!.expected_crew_id,
          employee_ids: proposal!.members.map((member) => member.id),
          recorder_employee_id: proposal!.recorder_employee_id!,
          ...(proposal!.machine_id && proposal!.mold_id
            ? { machine_id: proposal!.machine_id, mold_id: proposal!.mold_id }
            : {}),
          reason: 'Approved automatic repair schedule and resource suggestion.',
          ...(proposal!.requires_late_production_approval
            ? {
                late_production_authorized: lateApproval.authorized,
                late_production_reason: lateApproval.reason.trim(),
              }
            : {}),
        },
        ncrId,
      );
    },
    onSettled: () => onPendingChange?.(false),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['production'] });
      queryClient.invalidateQueries({ queryKey: ['mrp'] });
      toast.success('Repair schedule and crew approved. Start rework when workers are present.');
      onSaved();
    },
  });
  const blocked =
    !valid ||
    suggestion.isFetching ||
    suggestion.isError ||
    approval.isPending ||
    !proposal?.scheduled_start ||
    !proposal.scheduled_end ||
    !proposal.recorder_employee_id ||
    proposal.blockers.length > 0 ||
    proposal.expected_crew_id !== (context.current?.id ?? null) ||
    Date.parse(proposal.scheduled_end) <= Date.now() ||
    (proposal.requires_late_production_approval &&
      (!context.can_approve_late_production ||
        !lateApproval.authorized ||
        !lateApproval.reason.trim()));
  return (
    <div className="space-y-4 text-sm">
      <div className="grid gap-4 sm:grid-cols-2">
        <Select
          label="Repair method"
          value={manual ? 'manual' : 'machine'}
          onChange={(event) => {
            setManual(event.target.value === 'manual');
            setLateApproval({ authorized: false, reason: '' });
          }}
          disabled={approval.isPending}
        >
          <option value="machine">Machine and mold</option>
          <option value="manual">Manual repair</option>
        </Select>
        <Select
          label="Number of repair workers"
          value={workers}
          onChange={(event) => {
            setWorkers(event.target.value);
            setLateApproval({ authorized: false, reason: '' });
          }}
          disabled={approval.isPending}
        >
          {[1, 2, 3, 4].map((count) => (
            <option key={count} value={count}>
              {count}
            </option>
          ))}
        </Select>
      </div>
      <Input
        label="Repair day"
        type="date"
        min={today}
        max={offsetScheduleDate(today, 90)}
        value={day}
        disabled={approval.isPending}
        onChange={(event) => {
          setDay(event.target.value);
          setLateApproval({ authorized: false, reason: '' });
        }}
        helper="Leave blank for earliest available time (Asia/Manila)."
      />
      {!valid && (
        <p role="alert" className="text-danger-fg">
          Choose today or a day within 90 days.
        </p>
      )}
      {suggestion.isFetching && (
        <p role="status">Checking equipment, HR shifts, leave, and worker reservations…</p>
      )}
      {suggestion.isError && (
        <p role="alert" className="text-danger-fg">
          {suggestion.error instanceof AxiosError
            ? (suggestion.error.response?.data?.message ?? 'Suggestion unavailable. Try again.')
            : 'Suggestion unavailable. Try again.'}
        </p>
      )}
      {proposal && !suggestion.isFetching && (
        <>
          {proposal.blockers.map((message) => (
            <p key={message} role="alert" className="text-danger-fg">
              {message}
            </p>
          ))}
          {proposal.scheduled_start && proposal.scheduled_end && (
            <div className="border-y border-default py-4 space-y-3">
              <dl className="grid gap-x-5 gap-y-2 sm:grid-cols-[auto_1fr]">
                <dt className="text-muted">Repair pieces</dt>
                <dd>{proposal.repair_quantity}</dd>
                <dt className="text-muted">Suggested time</dt>
                <dd>
                  {productionDateTime(proposal.scheduled_start)} –{' '}
                  {productionDateTime(proposal.scheduled_end)}
                </dd>
                <dt className="text-muted">Equipment</dt>
                <dd>
                  {proposal.machine
                    ? `${proposal.machine.machine_code} / ${proposal.mold?.mold_code}`
                    : 'Manual repair'}
                </dd>
              </dl>
              <ul className="space-y-3">
                {proposal.members.map((member) => (
                  <li key={member.id}>
                    {member.name}
                    {member.id === proposal.recorder_employee_id ? ' · Output recorder' : ''}
                    <WorkerScheduleStatus worker={member} />
                  </li>
                ))}
              </ul>
              <p className="text-xs text-muted">
                Suggestion only. Approval reserves equipment and crew; Start rework checks
                attendance.
              </p>
            </div>
          )}
          {proposal.requires_late_production_approval && (
            <LateProductionApproval
              canApprove={!!context.can_approve_late_production}
              disabled={approval.isPending}
              value={lateApproval}
              onChange={setLateApproval}
            />
          )}
        </>
      )}
      {approval.isError && (
        <p role="alert" className="text-danger-fg">
          {approval.error instanceof AxiosError
            ? (approval.error.response?.data?.message ?? 'Approval failed. Refresh suggestion.')
            : 'Approval failed. Refresh suggestion.'}
        </p>
      )}
      <div className="flex flex-wrap justify-end gap-2">
        <Button
          variant="secondary"
          disabled={suggestion.isFetching || approval.isPending || !valid}
          onClick={() => {
            setLateApproval({ authorized: false, reason: '' });
            suggestion.refetch();
          }}
        >
          Refresh suggestion
        </Button>
        <Button
          variant="primary"
          disabled={blocked}
          loading={approval.isPending}
          onClick={() => approval.mutate()}
        >
          Approve repair schedule
        </Button>
      </div>
    </div>
  );
}
