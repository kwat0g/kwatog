import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { AxiosError } from 'axios';
import toast from 'react-hot-toast';
import { workOrdersApi } from '@/api/production/workOrders';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { LuLoaderCircle } from '@/lib/icons';
import {
  productionDateTime,
  scheduleDateKey,
  offsetScheduleDate,
} from '@/components/production/scheduleDates';
import { Input } from '@/components/ui/Input';
import {
  AssignmentForm,
  LateProductionApproval,
  SlotForm,
  WorkerScheduleStatus,
} from './CrewPanel';

export function StartProductionDialog({
  woId,
  woNumber,
  action,
  onClose,
}: {
  woId: string;
  woNumber: string;
  action: 'start' | 'resume' | 'extend';
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const [presenceVersion, setPresenceVersion] = useState<string | null>(null);
  const [lateStartVersion, setLateStartVersion] = useState<string | null>(null);
  const [manual, setManual] = useState(false);
  const [manualPending, setManualPending] = useState(false);
  const [rescheduling, setRescheduling] = useState(false);
  const [changingWorkers, setChangingWorkers] = useState(false);
  const today = scheduleDateKey(new Date().toISOString(), 'Asia/Manila');
  const [preferredDay, setPreferredDay] = useState(today);
  const dateIsValid =
    /^\d{4}-\d{2}-\d{2}$/.test(preferredDay) &&
    preferredDay >= today &&
    preferredDay <= offsetScheduleDate(today, 90);
  const [error, setError] = useState('');
  const [lateApproval, setLateApproval] = useState({ authorized: false, reason: '' });
  const readiness = useQuery({
    queryKey: ['production', 'execution-readiness', woId],
    queryFn: () => workOrdersApi.executionReadiness(woId),
    staleTime: 0,
    refetchInterval: rescheduling || changingWorkers || action === 'extend' ? false : 30_000,
    refetchOnWindowFocus: action !== 'extend',
    refetchOnReconnect: action !== 'extend',
    retry: false,
  });
  const suggestion = useQuery({
    queryKey: ['production', 'reschedule-suggestion', woId, preferredDay],
    queryFn: () => workOrdersApi.rescheduleSuggestion(woId, preferredDay),
    enabled:
      action !== 'extend' &&
      rescheduling &&
      !!readiness.data?.can_review_slot &&
      !manual &&
      dateIsValid,
    staleTime: 0,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    retry: false,
  });
  const crew = useQuery({
    queryKey: ['production', 'crew', woId],
    queryFn: () => workOrdersApi.crew(woId),
    enabled: manual || changingWorkers,
  });
  const data = readiness.data;
  const confirmationVersion = data
    ? JSON.stringify([
        data.schedule?.id,
        data.schedule?.updated_at,
        data.expected_crew_id,
        data.presence,
        data.ready,
        data.blockers,
        data.start_mode,
        data.requires_late_production_approval,
        data.production_deadline,
      ])
    : null;
  useEffect(() => {
    setPresenceVersion(null);
    setLateStartVersion(null);
  }, [confirmationVersion]);
  const present = presenceVersion !== null && presenceVersion === confirmationVersion;
  const unknown = data?.presence.filter((member) => member.state === 'unknown') ?? [];
  const reportError = (e: AxiosError<{ message?: string }>) => {
    setError(e.response?.data?.message ?? 'Action failed. Check readiness and try again.');
    setPresenceVersion(null);
    void readiness.refetch();
    if (rescheduling && !manual) void suggestion.refetch();
  };
  const refreshed = () => {
    setManualPending(false);
    setRescheduling(false);
    setManual(false);
    setPresenceVersion(null);
    setError('');
    void qc.invalidateQueries({ queryKey: ['production', 'crew', woId] });
    void qc.invalidateQueries({ queryKey: ['production', 'work-orders'] });
    void readiness.refetch();
  };
  const approve = useMutation({
    mutationFn: () => {
      const proposed = suggestion.data!;
      return workOrdersApi.reviewSlot(woId, {
        preferred_production_date: preferredDay,
        scheduled_start: proposed.scheduled_start!,
        scheduled_end: proposed.scheduled_end!,
        expected_schedule_id: proposed.expected_schedule_id,
        expected_schedule_updated_at: proposed.expected_schedule_updated_at,
        expected_crew_id: proposed.expected_crew_id,
        employee_ids: proposed.members.map((member) => member.id),
        recorder_employee_id: proposed.recorder_employee_id!,
        reason: 'Approved suggested replacement slot and crew from production readiness review.',
        ...(proposed.requires_late_production_approval
          ? {
              late_production_authorized: lateApproval.authorized,
              late_production_reason: lateApproval.reason.trim(),
            }
          : {}),
      });
    },
    onSuccess: () => {
      toast.success('Schedule approved. Checking production readiness.');
      refreshed();
    },
    onError: reportError,
  });
  const execute = useMutation({
    mutationFn: () =>
      action === 'extend'
        ? workOrdersApi.extendRun(woId, {
            expected_crew_id: data!.expected_crew_id!,
            expected_schedule_id: data!.schedule!.id,
            expected_schedule_updated_at: data!.schedule!.updated_at,
            scheduled_end: data!.execution_end!,
            reason: 'Approved remaining production time extension.',
            presence_confirmed_employee_ids: present
              ? unknown.map((member) => member.employee_id)
              : [],
            ...(data!.requires_late_production_approval
              ? {
                  late_production_authorized: lateApproval.authorized,
                  late_production_reason: lateApproval.reason.trim(),
                }
              : {}),
          })
        : workOrdersApi[action](woId, {
            expected_crew_id: data!.expected_crew_id!,
            presence_confirmed_employee_ids: present
              ? unknown.map((member) => member.employee_id)
              : [],
            ...(data!.schedule
              ? {
                  expected_schedule_id: data!.schedule.id,
                  expected_schedule_updated_at: data!.schedule.updated_at,
                }
              : {}),
            ...(data!.requires_late_start_acknowledgement
              ? {
                  late_start_authorized: lateStartVersion === confirmationVersion,
                }
              : {}),
          }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['production'] });
      toast.success(
        action === 'extend'
          ? 'Run extended. Recorder access checked.'
          : action === 'start'
            ? 'Production started.'
            : 'Production resumed.',
      );
      onClose();
    },
    onError: reportError,
  });
  const busy = approve.isPending || execute.isPending || manualPending;
  const proposed = suggestion.data;
  const extensionEnd = action === 'extend' ? data?.execution_end : null;
  useEffect(() => {
    setLateApproval({ authorized: false, reason: '' });
  }, [
    preferredDay,
    proposed?.scheduled_start,
    proposed?.scheduled_end,
    rescheduling,
    data?.schedule?.id,
    extensionEnd,
    data?.production_deadline,
  ]);
  const lateApprovalMissing =
    proposed?.requires_late_production_approval &&
    (!data?.can_approve_late_production || !lateApproval.authorized || !lateApproval.reason.trim());
  const canApprove =
    dateIsValid &&
    proposed &&
    proposed.blockers.length === 0 &&
    proposed.scheduled_start &&
    proposed.scheduled_end &&
    proposed.members.length > 0 &&
    proposed.recorder_employee_id;
  const schedule = data?.schedule;
  return (
    <Modal
      isOpen
      onClose={busy ? () => undefined : onClose}
      title={
        changingWorkers
          ? `Change workers for ${woNumber}`
          : rescheduling
            ? `Reschedule ${woNumber}`
            : `${action === 'extend' ? 'Extend run for' : action === 'start' ? 'Start' : 'Resume'} ${woNumber}`
      }
      size="lg"
      closeOnOverlayClick={!busy}
    >
      <div className="space-y-4">
        {error && (
          <p role="alert" className="text-danger-fg">
            {error}
          </p>
        )}
        {readiness.isFetching && (
          <p role="status" className="flex items-center gap-2 text-muted">
            <LuLoaderCircle
              size={16}
              className="shrink-0 animate-spin motion-reduce:animate-none"
              aria-hidden
            />
            <span>Checking schedule, workers, materials, equipment and quality…</span>
          </p>
        )}
        {readiness.isError && (
          <div role="alert" className="space-y-2 text-danger-fg">
            <p>
              {action === 'extend'
                ? 'Extension checks failed. Current reservation is unchanged.'
                : 'Readiness could not be checked. Production has not started.'}
            </p>
            <Button
              size="touch"
              variant="secondary"
              onClick={() => {
                setPresenceVersion(null);
                void readiness.refetch();
              }}
            >
              Retry checks
            </Button>
          </div>
        )}
        {changingWorkers && (
          <div className="space-y-4">
            <Button size="touch" variant="secondary" onClick={() => setChangingWorkers(false)}>
              Back to start checks
            </Button>
            {crew.isFetching && <p role="status">Loading assigned workers…</p>}
            {crew.isError && (
              <p role="alert">
                Workers could not be loaded.{' '}
                <Button onClick={() => crew.refetch()}>Retry workers</Button>
              </p>
            )}
            {crew.data?.can_assign && (
              <AssignmentForm
                woId={woId}
                context={crew.data}
                status={action === 'start' ? 'confirmed' : 'paused'}
                onSaved={() => {
                  setChangingWorkers(false);
                  setPresenceVersion(null);
                  void readiness.refetch();
                }}
              />
            )}
          </div>
        )}
        {data && !readiness.isError && !changingWorkers && (
          <>
            {schedule && (
              <div className="rounded-md border border-default bg-subtle p-4">
                <p className="text-xs text-muted">Approved schedule · Asia/Manila</p>
                <p className="mt-1 font-medium">
                  {productionDateTime(schedule.scheduled_start)} –{' '}
                  {productionDateTime(schedule.scheduled_end)}
                </p>
              </div>
            )}
            {data.production_deadline && (
              <p className="text-xs text-muted">
                Production due: {productionDateTime(data.production_deadline)} (Asia/Manila)
              </p>
            )}
            {!data.ready &&
              data.blockers.some((blocker) => ['crew', 'presence'].includes(blocker.code)) && (
                <div className="flex flex-wrap items-center gap-2">
                  {data.can_assign_crew && (
                    <Button
                      size="touch"
                      variant="secondary"
                      disabled={busy}
                      onClick={() => {
                        setChangingWorkers(true);
                        setRescheduling(false);
                        setManual(false);
                      }}
                    >
                      Change workers
                    </Button>
                  )}
                  <Button
                    size="touch"
                    variant="secondary"
                    loading={readiness.isFetching}
                    disabled={busy}
                    onClick={() => {
                      setPresenceVersion(null);
                      void readiness.refetch();
                      void qc.invalidateQueries({ queryKey: ['production', 'crew', woId] });
                      void qc.invalidateQueries({ queryKey: ['production', 'crew-options', woId] });
                    }}
                  >
                    Refresh workers
                  </Button>
                </div>
              )}
            {data.ready && !rescheduling ? (
              <>
                <p>
                  {action === 'extend'
                    ? 'Approve more time for this running WO. Original timing stays in history.'
                    : unknown.length > 0
                      ? 'Confirm worker presence before starting. Reserved materials will be issued.'
                      : 'Production checks passed. Starting records actual time and issues reserved materials.'}
                </p>
                {data.estimated_finish && (
                  <p className="text-sm text-muted">
                    Estimated finish: {productionDateTime(data.estimated_finish)}
                  </p>
                )}
                {action === 'extend' && data.requires_late_production_approval && (
                  <LateProductionApproval
                    canApprove={!!data.can_approve_late_production}
                    value={lateApproval}
                    onChange={setLateApproval}
                    disabled={busy || readiness.isFetching}
                  />
                )}
                {data.start_mode === 'early' && (
                  <p className="text-sm text-muted">
                    Early start available. Worker attendance verified.
                  </p>
                )}
                {data.start_mode && data.start_mode !== 'scheduled' && data.execution_end && (
                  <p className="text-sm text-muted">
                    {action === 'extend'
                      ? 'Extension reserves resources until '
                      : 'Starting now reserves resources until '}
                    {productionDateTime(data.execution_end)}. Original schedule stays in history.
                  </p>
                )}
                {data.requires_late_start_acknowledgement && (
                  <label className="flex items-start gap-2 rounded-md border border-default p-3 text-warning-fg">
                    <input
                      type="checkbox"
                      className="mt-1"
                      checked={lateStartVersion === confirmationVersion}
                      disabled={busy || readiness.isFetching}
                      onChange={(event) =>
                        setLateStartVersion(event.target.checked ? confirmationVersion : null)
                      }
                    />
                    <span>
                      I approve this late start and acknowledge possible production delays.
                    </span>
                  </label>
                )}
                {unknown.length > 0 && (
                  <label className="flex items-start gap-2 rounded-md border border-default p-3">
                    <input
                      type="checkbox"
                      checked={present}
                      onChange={(event) =>
                        setPresenceVersion(event.target.checked ? confirmationVersion : null)
                      }
                      className="mt-1"
                    />
                    <span>
                      Confirm physically present: {unknown.map((member) => member.name).join(', ')}.
                      No verified attendance. Confirm physical presence.
                    </span>
                  </label>
                )}
              </>
            ) : (
              <div role="status" className="space-y-2 text-warning-fg">
                {data.blockers
                  .filter((blocker) => !rescheduling || blocker.code !== 'schedule')
                  .map((blocker, index) => (
                    <p key={`${blocker.code}-${index}`}>{blocker.message}</p>
                  ))}
              </div>
            )}
            {data.can_review_slot && rescheduling && (
              <>
                {manual ? (
                  <>
                    <Button
                      size="touch"
                      variant="secondary"
                      disabled={busy}
                      onClick={() => setManual(false)}
                    >
                      Use suggested time
                    </Button>
                    {crew.isLoading && (
                      <p role="status" className="flex items-center gap-2">
                        <LuLoaderCircle
                          size={16}
                          className="shrink-0 animate-spin motion-reduce:animate-none"
                          aria-hidden
                        />
                        <span>Loading workers…</span>
                      </p>
                    )}
                    {crew.isError && (
                      <Button size="touch" variant="secondary" onClick={() => crew.refetch()}>
                        Retry workers
                      </Button>
                    )}
                    {crew.data && !crew.isError && (
                      <SlotForm
                        woId={woId}
                        context={crew.data}
                        onSaved={refreshed}
                        compact
                        onPendingChange={setManualPending}
                        initialTiming={
                          proposed?.scheduled_start && proposed.scheduled_end
                            ? {
                                scheduled_start: proposed.scheduled_start,
                                scheduled_end: proposed.scheduled_end,
                              }
                            : undefined
                        }
                      />
                    )}
                  </>
                ) : (
                  <>
                    <Input
                      label="Preferred production day"
                      aria-label="Preferred production day"
                      type="date"
                      value={preferredDay}
                      min={today}
                      max={offsetScheduleDate(today, 90)}
                      required
                      disabled={busy}
                      onChange={(event) => setPreferredDay(event.target.value)}
                      helper="Times use Asia/Manila. The system checks equipment capacity and worker shifts."
                      error={
                        !dateIsValid ? 'Choose today or a day within the next 90 days.' : undefined
                      }
                    />
                    {suggestion.isFetching && (
                      <p role="status" className="flex items-center gap-2">
                        <LuLoaderCircle
                          size={16}
                          className="shrink-0 animate-spin motion-reduce:animate-none"
                          aria-hidden
                        />
                        <span>Finding available time and eligible workers…</span>
                      </p>
                    )}
                    {suggestion.isError && (
                      <div role="alert" className="text-danger-fg">
                        <p>Schedule suggestion could not load.</p>
                        <Button
                          size="touch"
                          variant="secondary"
                          onClick={() => suggestion.refetch()}
                        >
                          Retry suggestion
                        </Button>
                      </div>
                    )}
                    {proposed && !suggestion.isError && dateIsValid && !suggestion.isFetching && (
                      <>
                        {canApprove ? (
                          <div className="space-y-3 rounded-md border border-default p-4">
                            {proposed.preferred_day_message && (
                              <p role="status" className="text-sm text-warning-fg">
                                {proposed.preferred_day_message} The alternative below requires your
                                approval.
                              </p>
                            )}
                            <p className="text-xs text-muted">
                              {proposed.preferred_day_message
                                ? 'Next available schedule'
                                : 'Suggested schedule'}{' '}
                              · Asia/Manila
                            </p>
                            <p className="font-medium">
                              {productionDateTime(proposed.scheduled_start!)} –{' '}
                              {productionDateTime(proposed.scheduled_end!)}
                            </p>
                            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                              <dt className="text-muted">Equipment</dt>
                              <dd>
                                {proposed.machine?.machine_code} / {proposed.mold?.mold_code}
                              </dd>
                              <dt className="text-muted">Workers</dt>
                              <dd>
                                <ul className="space-y-2">
                                  {proposed.members.map((member) => (
                                    <li key={member.id}>
                                      {member.name}
                                      {member.id === proposed.recorder_employee_id
                                        ? ' (Output recorder)'
                                        : ''}
                                      <WorkerScheduleStatus worker={member} />
                                    </li>
                                  ))}
                                </ul>
                              </dd>
                            </dl>
                            {proposed.requires_late_production_approval && (
                              <LateProductionApproval
                                canApprove={!!data.can_approve_late_production}
                                value={lateApproval}
                                onChange={setLateApproval}
                                disabled={busy || suggestion.isFetching || readiness.isFetching}
                              />
                            )}
                            <Button
                              size="touch"
                              variant="primary"
                              disabled={
                                busy ||
                                suggestion.isFetching ||
                                readiness.isFetching ||
                                !!lateApprovalMissing
                              }
                              loading={approve.isPending}
                              onClick={() => approve.mutate()}
                            >
                              Approve new schedule
                            </Button>
                          </div>
                        ) : (
                          <div role="alert" className="space-y-2 text-warning-fg">
                            {proposed.blockers.map((message) => (
                              <p key={message}>{message}</p>
                            ))}
                          </div>
                        )}
                      </>
                    )}
                    <Button
                      size="touch"
                      variant="secondary"
                      onClick={() => setManual(true)}
                      disabled={busy || suggestion.isFetching || !dateIsValid}
                    >
                      Choose another time
                    </Button>
                  </>
                )}
              </>
            )}
            {data.needs_reschedule && !data.can_review_slot && (
              <p className="text-muted">
                Ask PPC or production management to reschedule this work order.
              </p>
            )}
          </>
        )}
        {!manual && !changingWorkers && (
          <div className="flex flex-wrap justify-end gap-2 border-t border-default pt-4">
            <Button size="touch" variant="secondary" onClick={onClose} disabled={busy}>
              Close
            </Button>
            {action !== 'extend' &&
              data?.can_review_slot &&
              !readiness.isError &&
              (!rescheduling ||
                (!!schedule &&
                  !data.needs_reschedule &&
                  Date.parse(schedule.scheduled_end) > Date.now())) && (
                <Button
                  size="touch"
                  variant="secondary"
                  disabled={busy || readiness.isFetching}
                  onClick={() => {
                    if (!rescheduling)
                      void qc.invalidateQueries({
                        queryKey: ['production', 'reschedule-suggestion', woId],
                      });
                    setRescheduling(!rescheduling);
                    setManual(false);
                    setError('');
                    setPresenceVersion(null);
                  }}
                >
                  {rescheduling ? 'Keep approved schedule' : 'Reschedule'}
                </Button>
              )}
            {data?.ready && !readiness.isError && !rescheduling && (
              <Button
                size="touch"
                variant="primary"
                onClick={() => execute.mutate()}
                loading={execute.isPending}
                disabled={
                  busy ||
                  readiness.isFetching ||
                  (unknown.length > 0 && !present) ||
                  (!!data.requires_late_start_acknowledgement &&
                    lateStartVersion !== confirmationVersion) ||
                  (action === 'extend' &&
                    !!data.requires_late_production_approval &&
                    (!lateApproval.authorized ||
                      !lateApproval.reason.trim() ||
                      !data.can_approve_late_production))
                }
              >
                {action === 'extend'
                  ? 'Approve extension'
                  : action === 'start'
                    ? 'Start production'
                    : 'Resume production'}
              </Button>
            )}
          </div>
        )}
      </div>
    </Modal>
  );
}
