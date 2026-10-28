import { formatDateTime } from '@/lib/formatDate';
import { LuLoaderCircle } from '@/lib/icons';
import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import { workOrdersApi } from '@/api/production/workOrders';
import { Modal } from '@/components/ui/Modal';
import { useDebounce } from '@/hooks/useDebounce';
import { useElapsedSeconds } from '@/hooks/useElapsedSeconds';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SearchableSelect } from '@/components/ui/SearchableSelect';
import { Textarea } from '@/components/ui/Textarea';
import {
  productionDateTime,
  productionInputIso,
  productionInputDateTime,
  scheduleTime,
  scheduleDateKey,
  offsetScheduleDate,
} from '@/components/production/scheduleDates';
import { crewValidation } from '@/lib/productionCrewValidation';
import type { CrewContext, EmployeeOption } from '@/types/production-crew';

export function WorkerScheduleStatus({ worker }: { worker: EmployeeOption }) {
  const attendance = worker.schedule_attendance;
  const labels = {
    present: 'Attendance verified',
    unknown: 'Attendance unverified; confirm at Start',
    blocked: worker.blocked_reason || attendance?.reason || 'Unavailable',
    upcoming: 'Upcoming shift; attendance checked at Start',
  };
  return (
    <span className="mt-1 block text-xs text-muted">
      {worker.shift?.name ?? 'No HR shift'}
      {worker.shift?.scheduled_start && worker.shift.scheduled_end && (
        <>
          {' '}
          · {productionDateTime(worker.shift.scheduled_start)} –{' '}
          {scheduleTime(worker.shift.scheduled_end, 'Asia/Manila')}
        </>
      )}
      {worker.presence?.clocked_in && attendance?.state !== 'present' && (
        <span className="mt-1 block">Clock-in recorded; shift coverage still required.</span>
      )}
      {attendance && (
        <span
          className={`mt-1 block ${attendance.state === 'blocked' ? 'text-danger-fg' : attendance.state === 'unknown' ? 'text-warning-fg' : attendance.state === 'present' ? 'text-success-fg' : 'text-muted'}`}
        >
          {labels[attendance.state]}
        </span>
      )}
    </span>
  );
}

function StaffingFields({
  minimum,
  onMinimumChange,
  disabled = false,
}: {
  minimum: string;
  onMinimumChange: (value: string) => void;
  disabled?: boolean;
}) {
  return (
    <Select
      label="Number of workers"
      aria-label="Number of workers"
      value={minimum}
      helper="Choose 1–4 workers; system suggests this number"
      required
      disabled={disabled}
      onChange={(event) => onMinimumChange(event.target.value)}
    >
      <option value="">Choose number of workers</option>
      {[1, 2, 3, 4].map((count) => (
        <option key={count} value={count}>
          {count}
        </option>
      ))}
    </Select>
  );
}

function CrewFields({
  members,
  onMembersChange,
  recorder,
  onRecorderChange,
  minimum,
  onMinimumChange,
  options,
  onSearch,
  loading = false,
  disabled = false,
  limitsDisabled = disabled,
  manual,
  onModeChange,
  showModes = true,
}: {
  members: EmployeeOption[];
  onMembersChange: (members: EmployeeOption[]) => void;
  recorder: string;
  onRecorderChange: (value: string) => void;
  minimum: string;
  onMinimumChange: (value: string) => void;
  options: EmployeeOption[];
  onSearch?: (value: string) => void;
  loading?: boolean;
  disabled?: boolean;
  limitsDisabled?: boolean;
  manual: boolean;
  onModeChange?: (manual: boolean) => void;
  showModes?: boolean;
}) {
  return (
    <>
      {showModes && (
        <Button
          type="button"
          variant="secondary"
          disabled={limitsDisabled}
          onClick={() => onModeChange?.(!manual)}
        >
          {manual ? 'Auto assign' : 'Manual assign'}
        </Button>
      )}
      {!manual && (
        <StaffingFields
          minimum={minimum}
          onMinimumChange={onMinimumChange}
          disabled={limitsDisabled}
        />
      )}
      {manual && (
        <SearchableSelect
          label="Add worker"
          value=""
          onSearch={onSearch}
          loading={loading}
          disabled={disabled || members.length >= 4}
          options={options
            .filter((option) => !members.some((member) => member.id === option.id))
            .map((option) => ({
              value: option.id,
              label: `${option.employee_number} · ${option.name} · ${option.shift?.name ?? 'No HR shift'}${option.shift?.scheduled_start && option.shift.scheduled_end ? ` (${scheduleTime(option.shift.scheduled_start, 'Asia/Manila')}–${scheduleTime(option.shift.scheduled_end, 'Asia/Manila')})` : ''}${option.blocked_reason ? ` · ${option.blocked_reason}` : option.schedule_attendance?.state === 'present' ? ' · Attendance verified' : option.schedule_attendance?.state === 'upcoming' ? ' · Upcoming shift' : ' · Attendance checked at Start'}`,
              disabled: option.can_assign === false,
            }))}
          onChange={(id) => {
            const member = options.find((option) => option.id === id);
            if (member && member.can_assign !== false) onMembersChange([...members, member]);
          }}
        />
      )}
      <ul className="divide-y divide-default">
        {members.map((member) => (
          <li key={member.id} className="flex items-center justify-between gap-2 py-2 text-sm">
            <span>
              {member.name}
              {!member.has_active_account && ' · No active account'}
              <WorkerScheduleStatus
                worker={options.find((option) => option.id === member.id) ?? member}
              />
            </span>
            {manual && (
              <Button
                type="button"
                disabled={disabled}
                variant="ghost"
                className="hover:bg-danger-bg"
                size="sm"
                onClick={() => onMembersChange(members.filter((item) => item.id !== member.id))}
              >
                <span className="text-danger-fg">Remove {member.name}</span>
              </Button>
            )}
          </li>
        ))}
      </ul>
      <Select
        label="Output recorder"
        aria-label="Output recorder"
        value={recorder}
        onChange={(event) => onRecorderChange(event.target.value)}
        required
        disabled={disabled}
      >
        <option value="">Choose recorder</option>
        {members
          .filter((member) => member.has_active_account)
          .map((member) => (
            <option key={member.id} value={member.id}>
              {member.name}
            </option>
          ))}
      </Select>
      <p className="text-sm text-muted">
        {manual
          ? `Selected: ${members.length} / 4 maximum.`
          : `Selected: ${members.length} / ${minimum || '—'}.`}
      </p>
    </>
  );
}

export function AssignmentForm({
  woId,
  ncrId,
  context,
  status,
  onSaved,
}: {
  woId: string;
  ncrId?: string;
  context: CrewContext;
  status?: string;
  onSaved: () => void;
}) {
  const qc = useQueryClient();
  const [members, setMembers] = useState(context.current?.members ?? []);
  const [recorder, setRecorder] = useState(context.current?.recorder_employee_id ?? '');
  useEffect(() => {
    if (!members.some((member) => member.id === recorder && member.has_active_account)) {
      setRecorder(members.find((member) => member.has_active_account)?.id ?? '');
    }
  }, [members, recorder]);
  const [reason, setReason] = useState('');
  const [search, setSearch] = useState('');
  const [presenceConfirmed, setPresenceConfirmed] = useState(false);
  useEffect(() => setPresenceConfirmed(false), [members, recorder]);
  const debouncedSearch = useDebounce(search, 300);
  const options = useQuery({
    queryKey: ['production', 'crew-options', woId, ncrId, debouncedSearch],
    queryFn: () => workOrdersApi.crewOptions(woId, debouncedSearch, ncrId),
  });
  const selectedCount = String(members.length);
  const needsReason =
    !!context.current && (!!ncrId || !['planned', 'confirmed'].includes(status ?? ''));
  const assignmentReason = needsReason
    ? reason
    : context.current
      ? 'Crew updated before production'
      : 'Initial crew assignment';
  const unchanged =
    !!context.current &&
    recorder === context.current.recorder_employee_id &&
    members.length === context.current.members.length &&
    members.every((member) => context.current!.members.some((current) => current.id === member.id));
  const invalid =
    members.length === 0
      ? 'Add at least one worker before saving.'
      : crewValidation(members, recorder, selectedCount, selectedCount, assignmentReason);
  const assign = useMutation({
    mutationFn: () =>
      workOrdersApi.assignCrew(woId, {
        employee_ids: members.map((member) => member.id),
        recorder_employee_id: recorder,
        minimum_workers: Number(selectedCount),
        maximum_workers: Number(selectedCount),
        expected_crew_id: context.current?.id ?? null,
        reason: assignmentReason.trim(),
        rework_ncr_id: ncrId,
        presence_confirmed_employee_ids: presenceConfirmed
          ? members
              .filter(
                (member) =>
                  (options.data?.find((option) => option.id === member.id)?.presence?.state ??
                    context.presence?.find((worker) => worker.employee_id === member.id)?.state) ===
                  'unknown',
              )
              .map((member) => member.id)
          : [],
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['production'] });
      onSaved();
    },
  });
  return (
    <form
      className="space-y-4"
      onSubmit={(event) => {
        event.preventDefault();
        if (!invalid && !unchanged && !options.isError && !options.isFetching && options.data)
          assign.mutate();
      }}
    >
      <CrewFields
        members={members}
        onMembersChange={setMembers}
        recorder={recorder}
        onRecorderChange={setRecorder}
        minimum={selectedCount}
        onMinimumChange={() => undefined}
        options={options.data ?? []}
        onSearch={setSearch}
        loading={options.isFetching}
        disabled={assign.isPending}
        manual
        showModes={false}
      />
      {options.isError && (
        <p role="alert" className="text-sm text-danger-fg">
          Workers failed to load.{' '}
          <Button type="button" variant="secondary" onClick={() => options.refetch()}>
            Retry workers
          </Button>
        </p>
      )}
      {needsReason &&
        members.some(
          (member) =>
            (options.data?.find((option) => option.id === member.id)?.presence?.state ??
              context.presence?.find((worker) => worker.employee_id === member.id)?.state) ===
            'unknown',
        ) && (
          <label className="flex items-start gap-2 text-sm">
            <input
              type="checkbox"
              checked={presenceConfirmed}
              onChange={(event) => setPresenceConfirmed(event.target.checked)}
            />
            Confirm selected workers are physically present
          </label>
        )}
      {needsReason && (
        <Textarea
          label="Handover reason"
          aria-label="Handover reason"
          helper="Explain why the workers or output recorder are changing"
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          required
        />
      )}
      {invalid && <p className="text-sm text-danger-fg">{invalid}</p>}
      {assign.isError && (
        <p role="alert" className="text-sm text-danger-fg">
          {assign.error instanceof AxiosError
            ? (assign.error.response?.data?.message ??
              'Assignment failed. Reload crew and try again.')
            : 'Assignment failed. Reload crew and try again.'}
        </p>
      )}
      <div className="flex justify-end gap-2">
        <Button type="button" variant="secondary" disabled={assign.isPending} onClick={onSaved}>
          Cancel
        </Button>
        <Button
          type="submit"
          variant="primary"
          disabled={
            !!invalid ||
            unchanged ||
            assign.isPending ||
            !options.data ||
            options.isError ||
            options.isFetching
          }
          loading={assign.isPending}
        >
          Save workers
        </Button>
      </div>
    </form>
  );
}
export function LateProductionApproval({
  canApprove,
  value,
  onChange,
  disabled = false,
}: {
  canApprove: boolean;
  value: { authorized: boolean; reason: string };
  onChange: (value: { authorized: boolean; reason: string }) => void;
  disabled?: boolean;
}) {
  return (
    <div className="space-y-3 text-sm">
      <p role="alert" className="text-warning-fg">
        {canApprove
          ? 'Finishes past production deadline. Approval and delivery-impact reason required.'
          : 'Finishes past production deadline. Ask Production Management to review the deadline and delivery impact.'}
      </p>
      {canApprove && (
        <>
          <label className="flex items-start gap-2">
            <input
              type="checkbox"
              checked={value.authorized}
              disabled={disabled}
              onChange={(event) => onChange({ ...value, authorized: event.target.checked })}
            />
            I approve production past the deadline.
          </label>
          <Textarea
            label="Delivery impact / reason"
            aria-label="Delivery impact / reason"
            required
            maxLength={500}
            disabled={disabled}
            value={value.reason}
            onChange={(event) => onChange({ ...value, reason: event.target.value })}
          />
        </>
      )}
    </div>
  );
}

export function SlotForm({
  woId,
  ncrId,
  context,
  onSaved,
  compact = false,
  initialTiming,
  onPendingChange,
}: {
  woId: string;
  ncrId?: string;
  context: CrewContext;
  onSaved: () => void;
  compact?: boolean;
  initialTiming?: { scheduled_start: string; scheduled_end: string };
  onPendingChange?: (pending: boolean) => void;
}) {
  const qc = useQueryClient();
  const sourceTiming = initialTiming ?? context.schedule;
  const durationMinutes = sourceTiming
    ? Math.max(
        120,
        Math.ceil(
          (Date.parse(sourceTiming.scheduled_end) - Date.parse(sourceTiming.scheduled_start)) /
            60000,
        ),
      )
    : 0;
  const localInput = productionInputDateTime;
  const [start, setStart] = useState(() =>
    compact && sourceTiming
      ? localInput(
          new Date(Math.max(Date.parse(sourceTiming.scheduled_start), Date.now())).toISOString(),
        )
      : '',
  );
  const [end, setEnd] = useState('');
  const [customEnd, setCustomEnd] = useState(false);
  const calculatedEnd =
    compact && durationMinutes > 0 && start
      ? localInput(
          new Date(Date.parse(productionInputIso(start)) + durationMinutes * 60000).toISOString(),
        )
      : '';
  const finish = compact && !customEnd ? calculatedEnd : end;
  const [lateApproval, setLateApproval] = useState({ authorized: false, reason: '' });
  useEffect(() => {
    setLateApproval({ authorized: false, reason: '' });
  }, [start, finish]);
  const pastDeadline =
    !!context.production_deadline &&
    !!finish &&
    Date.parse(productionInputIso(finish)) > Date.parse(context.production_deadline);
  const [reason, setReason] = useState('');
  const [reasonChoice, setReasonChoice] = useState('Production timing changed');
  const savedReason = compact && reasonChoice !== 'other' ? reasonChoice : reason.trim();
  const [machineRepair, setMachineRepair] = useState(false);
  const [replaceWorkers, setReplaceWorkers] = useState(false);
  const [members, setMembers] = useState(context.current?.members ?? []);
  const [recorder, setRecorder] = useState(context.current?.recorder_employee_id ?? '');
  const [search, setSearch] = useState('');
  const [holidayAuthorized, setHolidayAuthorized] = useState(false);
  const debouncedSearch = useDebounce(compact ? '' : search, 300);
  const debouncedTiming = useDebounce(`${start}|${finish}`, 300);
  const timing =
    debouncedTiming === `${start}|${finish}` &&
    start &&
    finish &&
    new Date(start) < new Date(finish)
      ? { scheduled_start: productionInputIso(start), scheduled_end: productionInputIso(finish) }
      : null;
  const crewOptions = useQuery({
    queryKey: [
      'production',
      'crew-options',
      woId,
      ncrId,
      debouncedSearch,
      timing?.scheduled_start,
      timing?.scheduled_end,
      holidayAuthorized,
    ],
    queryFn: () =>
      workOrdersApi.crewOptions(
        woId,
        debouncedSearch,
        ncrId,
        timing ? { ...timing, holiday_authorized: holidayAuthorized } : undefined,
      ),
    enabled: (compact || replaceWorkers) && !!timing,
  });
  const [machine, setMachine] = useState('');
  const [mold, setMold] = useState('');
  const options = useQuery({
    queryKey: ['production', 'work-orders', 'form-options'],
    queryFn: workOrdersApi.formOptions,
    enabled: !!ncrId && machineRepair,
  });
  const review = useMutation({
    mutationFn: () =>
      workOrdersApi.reviewSlot(
        woId,
        {
          scheduled_start: productionInputIso(start),
          scheduled_end: productionInputIso(finish),
          ...(!ncrId ? { preferred_production_date: start.slice(0, 10) } : {}),
          reason: savedReason,
          ...(pastDeadline
            ? {
                late_production_authorized: lateApproval.authorized,
                late_production_reason: lateApproval.reason.trim(),
              }
            : {}),
          expected_schedule_id: context.schedule?.id ?? null,
          expected_schedule_updated_at: context.schedule?.updated_at ?? null,
          ...(ncrId && machineRepair ? { machine_id: machine, mold_id: mold } : {}),
          ...(holidayAuthorized ? { holiday_authorized: true } : {}),
          ...(replaceWorkers
            ? {
                employee_ids: members.map((member) => member.id),
                recorder_employee_id: recorder,
                expected_crew_id: context.current?.id ?? null,
              }
            : {}),
        },
        ncrId,
      ),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['production'] });
      onSaved();
    },
  });
  useEffect(() => {
    onPendingChange?.(review.isPending);
  }, [onPendingChange, review.isPending]);
  const selectedMembers = replaceWorkers ? members : (context.current?.members ?? []);
  const unavailable =
    compact && crewOptions.data
      ? selectedMembers.filter(
          (member) =>
            !crewOptions.data.some(
              (option) => option.id === member.id && option.can_assign !== false,
            ),
        )
      : [];
  const minimumFinish =
    start && durationMinutes > 0
      ? Date.parse(productionInputIso(start)) + durationMinutes * 60000
      : 0;
  const timingError =
    compact && start && finish
      ? Date.parse(productionInputIso(start)) < Math.floor(Date.now() / 60000) * 60000
        ? 'Choose the current time or a future start.'
        : Date.parse(productionInputIso(finish)) < minimumFinish
          ? `Allow at least ${durationMinutes} minutes for this work order.`
          : undefined
      : undefined;
  const invalid =
    (pastDeadline &&
      (!context.can_approve_late_production ||
        !lateApproval.authorized ||
        !lateApproval.reason.trim())) ||
    !start ||
    !finish ||
    new Date(start) >= new Date(finish) ||
    !savedReason ||
    (compact &&
      (!!timingError ||
        !timing ||
        !crewOptions.data ||
        crewOptions.isError ||
        crewOptions.isFetching ||
        unavailable.length > 0 ||
        !selectedMembers.length)) ||
    (replaceWorkers &&
      (!timing ||
        !crewOptions.data ||
        !members.length ||
        !members.some((member) => member.id === recorder) ||
        crewOptions.isError ||
        crewOptions.isFetching)) ||
    (machineRepair &&
      (!machine || !mold || !options.data || options.isFetching || options.isError));
  return (
    <form
      className="space-y-4 text-sm"
      onSubmit={(event) => {
        event.preventDefault();
        if (!invalid) review.mutate();
      }}
    >
      {context.production_deadline && (
        <p className="text-xs text-muted">
          Production due: {productionDateTime(context.production_deadline)} (Asia/Manila)
        </p>
      )}
      {compact ? (
        <div className="space-y-4">
          <div>
            <h3 className="font-medium text-primary">Choose production time</h3>
            <p className="mt-1 text-xs text-muted">
              Times use Asia/Manila. Finish time includes the production reservation buffer.
            </p>
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <Input
              label="Start time"
              aria-label="Start time"
              type="datetime-local"
              value={start}
              required
              disabled={review.isPending}
              onChange={(event) => setStart(event.target.value)}
              error={
                timingError === 'Choose the current time or a future start.'
                  ? timingError
                  : undefined
              }
            />
            <div className="space-y-1">
              <p className="text-xs font-medium text-muted">Estimated finish</p>
              <p className="flex min-h-8 items-center text-sm tabular-nums">
                {finish ? productionDateTime(productionInputIso(finish)) : 'Choose a start time'}
              </p>
              {durationMinutes > 0 && (
                <p className="text-xs text-muted">
                  {durationMinutes} minutes reserved for production.
                </p>
              )}
            </div>
          </div>
          <Button
            type="button"
            variant="secondary"
            size="sm"
            disabled={review.isPending}
            onClick={() => setStart(localInput(new Date().toISOString()))}
          >
            Use current time
          </Button>
          <details
            className="text-xs"
            onToggle={(event) => {
              if (!event.currentTarget.open) {
                setCustomEnd(false);
                return;
              }
              setEnd(finish);
              setCustomEnd(true);
            }}
          >
            <summary className="cursor-pointer text-muted">Adjust finish time</summary>
            {customEnd && (
              <Input
                containerClassName="mt-3"
                label="Finish time"
                aria-label="Finish time"
                type="datetime-local"
                value={end}
                required
                disabled={review.isPending}
                onChange={(event) => setEnd(event.target.value)}
                error={
                  timingError !== 'Choose the current time or a future start.'
                    ? timingError
                    : undefined
                }
              />
            )}
          </details>
          <div className="border-t border-default pt-4">
            <div className="flex items-center justify-between gap-3">
              <h3 className="font-medium text-primary">Workers</h3>
              <Button
                type="button"
                variant="secondary"
                size="sm"
                disabled={review.isPending}
                onClick={() => setReplaceWorkers(!replaceWorkers)}
              >
                {replaceWorkers ? 'Keep assigned workers' : 'Change workers'}
              </Button>
            </div>
            {crewOptions.isFetching || !timing ? (
              <p role="status" className="mt-2 flex items-center gap-2 text-xs text-muted">
                <LuLoaderCircle
                  size={16}
                  className="shrink-0 animate-spin motion-reduce:animate-none"
                  aria-hidden
                />
                <span>Checking worker availability…</span>
              </p>
            ) : !crewOptions.isError && unavailable.length === 0 && selectedMembers.length > 0 ? (
              <p role="status" className="mt-2 text-xs text-success-fg">
                Crew fits this schedule. Attendance checked at Start.
              </p>
            ) : null}
            {!replaceWorkers && !crewOptions.isFetching && crewOptions.data && (
              <ul className="mt-2 space-y-2">
                {selectedMembers.map((member) => {
                  const option = crewOptions.data.find((item) => item.id === member.id);
                  return option ? (
                    <li key={member.id} className="text-sm">
                      {member.name}
                      <WorkerScheduleStatus worker={option} />
                    </li>
                  ) : null;
                })}
              </ul>
            )}
            {!crewOptions.isFetching && unavailable.length > 0 && (
              <p role="alert" className="mt-2 text-xs text-warning-fg">
                {unavailable
                  .map(
                    (member) =>
                      `${member.name}: ${crewOptions.data?.find((option) => option.id === member.id)?.blocked_reason ?? 'Unavailable for this interval'}`,
                  )
                  .join('; ')}
                . Change time or workers.
              </p>
            )}
          </div>
          <Select
            label="Reason for change"
            aria-label="Reason for change"
            value={reasonChoice}
            required
            disabled={review.isPending}
            onChange={(event) => setReasonChoice(event.target.value)}
          >
            {[
              'Production timing changed',
              'Materials delayed',
              'Equipment unavailable',
              'Workers unavailable',
              'Production priority changed',
            ].map((value) => (
              <option key={value} value={value}>
                {value}
              </option>
            ))}
            <option value="other">Other reason</option>
          </Select>
          {reasonChoice === 'other' && (
            <Textarea
              label="Explain the change"
              aria-label="Explain the change"
              value={reason}
              required
              maxLength={500}
              disabled={review.isPending}
              onChange={(event) => setReason(event.target.value)}
            />
          )}
        </div>
      ) : (
        <p className="text-sm">
          Review remaining production time (Asia/Manila). Previous timing stays in history. Assigned
          crew must cover this slot.
        </p>
      )}
      {ncrId && (
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={machineRepair}
            onChange={(event) => setMachineRepair(event.target.checked)}
          />
          Use machine and mold
        </label>
      )}
      {ncrId && machineRepair && (
        <div className="grid gap-3 sm:grid-cols-2">
          <Select
            label="Repair machine"
            value={machine}
            onChange={(event) => setMachine(event.target.value)}
          >
            <option value="">Select machine</option>
            {options.data?.machines
              .filter((item) => ['idle', 'running'].includes(item.status))
              .map((item) => (
                <option key={item.id} value={item.id}>
                  {item.machine_code} · {item.name}
                </option>
              ))}
          </Select>
          <Select
            label="Repair mold"
            value={mold}
            onChange={(event) => setMold(event.target.value)}
          >
            <option value="">Select mold</option>
            {options.data?.molds
              .filter((item) => ['available', 'in_use'].includes(item.status))
              .map((item) => (
                <option key={item.id} value={item.id}>
                  {item.mold_code} · {item.name}
                </option>
              ))}
          </Select>
          {options.isError && (
            <Button type="button" variant="secondary" onClick={() => options.refetch()}>
              Retry equipment
            </Button>
          )}
        </div>
      )}
      {!ncrId && (
        <>
          {!compact && (
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={replaceWorkers}
                onChange={(event) => setReplaceWorkers(event.target.checked)}
              />
              Workers for this slot
            </label>
          )}
          {replaceWorkers && (
            <CrewFields
              members={members}
              onMembersChange={setMembers}
              recorder={recorder}
              onRecorderChange={setRecorder}
              minimum={String(members.length)}
              onMinimumChange={() => undefined}
              options={(crewOptions.data ?? []).filter(
                (option) =>
                  !compact ||
                  `${option.employee_number} ${option.name}`
                    .toLowerCase()
                    .includes(search.toLowerCase()),
              )}
              onSearch={setSearch}
              loading={crewOptions.isFetching}
              disabled={review.isPending}
              manual
              showModes={false}
            />
          )}
          {replaceWorkers && !compact && (
            <p className="text-xs text-muted">
              Workers are validated against the proposed time when saved. Current-slot availability
              does not determine the replacement.
            </p>
          )}
          {crewOptions.isError && (
            <p role="alert" className="text-danger-fg">
              Workers could not be loaded.{' '}
              <Button type="button" onClick={() => crewOptions.refetch()}>
                Retry workers
              </Button>
            </p>
          )}
        </>
      )}
      <details className="text-xs">
        <summary className="cursor-pointer text-muted">Holiday production authorization</summary>
        <label className="flex items-start gap-2 text-xs">
          <input
            type="checkbox"
            checked={holidayAuthorized}
            onChange={(event) => setHolidayAuthorized(event.target.checked)}
          />
          If this slot includes a holiday, I authorize production during this interval. Requires
          Production Management permission.
        </label>
      </details>
      {!compact && (
        <div className="grid gap-3 sm:grid-cols-2">
          <Input
            label="Reviewed production start"
            aria-label="Reviewed production start"
            type="datetime-local"
            value={start}
            onChange={(event) => setStart(event.target.value)}
            required
          />
          <Input
            label="Reviewed production end"
            aria-label="Reviewed production end"
            type="datetime-local"
            value={end}
            onChange={(event) => setEnd(event.target.value)}
            required
          />
        </div>
      )}
      {!compact && (
        <Textarea
          label="Scheduling reason"
          aria-label="Scheduling reason"
          value={reason}
          onChange={(event) => setReason(event.target.value)}
          required
        />
      )}
      {review.isError && (
        <p role="alert" className="text-sm text-danger-fg">
          {review.error instanceof AxiosError
            ? (review.error.response?.data?.message ?? 'Slot review failed.')
            : 'Slot review failed.'}
        </p>
      )}
      {pastDeadline && (
        <LateProductionApproval
          canApprove={!!context.can_approve_late_production}
          value={lateApproval}
          onChange={setLateApproval}
          disabled={review.isPending}
        />
      )}
      <div
        className={
          compact
            ? 'flex flex-col-reverse gap-3 border-t border-default pt-4 sm:flex-row sm:items-center sm:justify-between'
            : undefined
        }
      >
        {compact && (
          <p className="text-xs text-muted sm:max-w-60">
            Approval updates scheduling only. Production starts separately.
          </p>
        )}
        <Button
          type="submit"
          variant={compact ? 'primary' : 'secondary'}
          className={compact ? 'w-full whitespace-nowrap sm:w-auto' : undefined}
          disabled={invalid || review.isPending}
          loading={review.isPending}
        >
          {compact ? 'Approve new schedule' : 'Approve production slot'}
        </Button>
      </div>
    </form>
  );
}

export function CrewPanel({
  woId,
  ncrId,
  readOnly = false,
  status,
  allowSlotReview = true,
  actionOnly = false,
  unboxed = false,
}: {
  woId: string;
  ncrId?: string;
  readOnly?: boolean;
  status?: string;
  allowSlotReview?: boolean;
  actionOnly?: boolean;
  unboxed?: boolean;
}) {
  const [manageOpen, setManageOpen] = useState(false);
  const [slotOpen, setSlotOpen] = useState(false);
  const [suggestOpen, setSuggestOpen] = useState(false);
  const crew = useQuery({
    queryKey: ncrId ? ['production', 'crew', woId, ncrId] : ['production', 'crew', woId],
    queryFn: () => workOrdersApi.crew(woId, ncrId),
  });
  const content = (
    <>
      {crew.isLoading && (
        <p role="status" className="flex items-center gap-2">
          <LuLoaderCircle
            size={16}
            className="shrink-0 animate-spin motion-reduce:animate-none"
            aria-hidden
          />
          <span>Loading crew…</span>
        </p>
      )}
      {crew.isError && (
        <p role="alert">
          Crew failed to load.{' '}
          <Button variant="secondary" onClick={() => crew.refetch()}>
            Reload crew
          </Button>
        </p>
      )}
      {crew.data && (
        <>
          {!actionOnly && crew.data.schedule && (
            <dl className="mb-3 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[auto_1fr]">
              <dt className="text-muted">Equipment</dt>
              <dd>
                {crew.data.schedule.machine_id ? (
                  <>
                    {crew.data.schedule.machine?.machine_code ?? crew.data.schedule.machine_id} /{' '}
                    {crew.data.schedule.mold?.mold_code ?? crew.data.schedule.mold_id}
                  </>
                ) : (
                  'Manual repair'
                )}
              </dd>
              <dt className="text-muted">
                {crew.data.schedule.status === 'pending' ? 'Proposed slot' : 'Approved slot'}
              </dt>
              <dd>
                {productionDateTime(crew.data.schedule.scheduled_start)} –{' '}
                {productionDateTime(crew.data.schedule.scheduled_end)} (Asia/Manila)
              </dd>
            </dl>
          )}
          {!actionOnly &&
            (!crew.data.schedule ||
              (!unboxed && new Date(crew.data.schedule.scheduled_end) <= new Date())) &&
            (status === 'confirmed' || status === 'paused' || ncrId) && (
              <p role="alert" className="mb-3 text-sm text-danger-fg">
                Approved slot missing or expired.{' '}
                {ncrId && !allowSlotReview
                  ? 'Auto schedule before starting repair.'
                  : 'Review the production slot before start or resume.'}
              </p>
            )}
          {!actionOnly && (
            <p className="mb-3 text-sm text-muted">
              Assigned: {crew.data.current?.members.length ?? 0} workers.
            </p>
          )}
          {!actionOnly &&
            (crew.data.current ? (
              <ul
                className={
                  ncrId
                    ? 'grid gap-3 text-sm sm:grid-cols-2'
                    : 'flex flex-wrap gap-x-5 gap-y-2 text-sm'
                }
              >
                {crew.data.current.members.map((member) => (
                  <li key={member.id} className="break-words">
                    {member.name}
                    {ncrId ? (
                      <span className="mt-1 block text-xs text-muted">
                        {member.shift?.name ?? 'No HR shift'} ·{' '}
                        {member.id === crew.data.current?.recorder_employee_id
                          ? 'Output recorder'
                          : 'View only'}
                      </span>
                    ) : (
                      <>
                        {' '}
                        · {member.shift?.name ?? 'No HR shift'}
                        {member.id === crew.data.current?.recorder_employee_id
                          ? ' · Output recorder'
                          : ' · View only'}
                      </>
                    )}
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-muted">No crew assigned. Output recording is blocked.</p>
            ))}
          {crew.data.can_assign && !readOnly && (
            <div
              className={
                actionOnly ? 'flex flex-wrap justify-end gap-2' : 'mt-3 flex flex-wrap gap-2'
              }
            >
              {allowSlotReview && crew.data.can_review_slot && (
                <Button variant="secondary" size="sm" onClick={() => setSlotOpen(true)}>
                  Review production slot
                </Button>
              )}
              {crew.data.current?.members.length ? (
                <Button variant="secondary" size="sm" onClick={() => setManageOpen(true)}>
                  Change workers
                </Button>
              ) : (
                (ncrId ||
                  status === 'confirmed' ||
                  status === 'in_progress' ||
                  status === 'paused') && (
                  <Button variant="secondary" size="sm" onClick={() => setManageOpen(true)}>
                    Assign workers
                  </Button>
                )
              )}
              {!actionOnly &&
                !ncrId &&
                (status === undefined || status === 'planned' || status === 'confirmed') && (
                  <Button variant="secondary" size="sm" onClick={() => setSuggestOpen(true)}>
                    Suggest assignment
                  </Button>
                )}
            </div>
          )}
          <Modal
            isOpen={manageOpen}
            onClose={() => setManageOpen(false)}
            title={
              crew.data.current?.members.length
                ? ncrId
                  ? 'Change repair workers'
                  : 'Change workers'
                : ncrId
                  ? 'Assign repair workers'
                  : 'Assign workers'
            }
            size="md"
          >
            {manageOpen && crew.data.can_assign && !readOnly && (
              <AssignmentForm
                key={crew.data.current?.id ?? 'initial'}
                woId={woId}
                ncrId={ncrId}
                context={crew.data}
                status={status}
                onSaved={() => setManageOpen(false)}
              />
            )}
            {crew.data.history.length > 0 && (
              <details className="mt-4 text-sm">
                <summary className="cursor-pointer">Crew history</summary>
                <ul className="mt-2 space-y-3">
                  {crew.data.history.map((item) => (
                    <li key={item.id}>
                      {item.members.map((member) => member.name).join(', ')} ·{' '}
                      {formatDateTime(item.assigned_at)}
                      <p className="text-muted">{item.reason}</p>
                    </li>
                  ))}
                </ul>
              </details>
            )}
          </Modal>
          <Modal
            isOpen={slotOpen}
            onClose={() => setSlotOpen(false)}
            title="Review production slot"
            size="lg"
          >
            {slotOpen && !readOnly && allowSlotReview && crew.data.can_review_slot && (
              <SlotForm
                woId={woId}
                ncrId={ncrId}
                context={crew.data}
                onSaved={() => setSlotOpen(false)}
              />
            )}
          </Modal>
          {suggestOpen && (
            <AssignmentReview
              woId={woId}
              context={crew.data}
              generate={status === 'planned' && !ncrId}
              onClose={() => setSuggestOpen(false)}
            />
          )}
        </>
      )}
    </>
  );
  return actionOnly || unboxed ? (
    content
  ) : (
    <Panel title={ncrId ? 'Repair crew' : 'Production crew'}>{content}</Panel>
  );
}

export function AssignmentReview({
  woId,
  context,
  generate = false,
  confirm = false,
  onClose,
  initialWorkers,
  commitLabel,
  onPendingChange,
  title,
}: {
  woId: string;
  context: CrewContext;
  generate?: boolean;
  confirm?: boolean;
  onClose: () => void;
  initialWorkers?: number;
  commitLabel?: string;
  onPendingChange?: (pending: boolean) => void;
  title?: string;
}) {
  const [minimum, setMinimum] = useState(
    context.current ? String(context.current.members.length) : initialWorkers ? String(initialWorkers) : '',
  );
  const [manual, setManual] = useState(false);
  const [preferredDay, setPreferredDay] = useState(context.preferred_production_date ?? '');
  const today = scheduleDateKey(new Date().toISOString(), 'Asia/Manila');
  const limitsKey = minimum || (manual ? '1' : '');
  const requestKey = `${limitsKey}|${preferredDay}`;
  const debouncedKey = useDebounce(requestKey, 300);
  const [debouncedCount, debouncedDay] = debouncedKey.split('|');
  const validLimits = /^[1-4]$/.test(limitsKey);
  const afterDeadline =
    confirm &&
    !!preferredDay &&
    !!context.production_deadline &&
    new Date(`${preferredDay}T00:00:00+08:00`).getTime() >=
      new Date(context.production_deadline).getTime();
  const validDay =
    !preferredDay ||
    (/^\d{4}-\d{2}-\d{2}$/.test(preferredDay) &&
      preferredDay >= today &&
      preferredDay <= offsetScheduleDate(today, 90) &&
      !afterDeadline);
  const stable = requestKey === debouncedKey;
  const limits = {
    minimum_workers: Number(debouncedCount),
    maximum_workers: Number(debouncedCount),
  };
  const [reason, setReason] = useState('');
  const suggestion = useQuery({
    queryKey: ['production', 'assignment-suggestion', woId, debouncedKey, generate],
    enabled: validLimits && validDay && stable,
    placeholderData: (previous) => previous,
    queryFn: async () => {
      if (generate) {
        return workOrdersApi.generateAssignmentSuggestion(woId, {
          ...limits,
          preferred_production_date: debouncedDay || null,
        });
      }
      const result = await workOrdersApi.assignmentSuggestion(woId, undefined, limits);
      if (
        context.schedule &&
        (result.schedule?.id !== context.schedule.id ||
          result.schedule.updated_at !== context.schedule.updated_at)
      )
        throw new Error('Schedule changed. Close this review and refresh the current proposal.');
      return result;
    },
    refetchOnReconnect: false,
    refetchOnWindowFocus: false,
    retry: false,
    staleTime: 0,
  });
  return (
    <Modal
      isOpen
      onClose={onClose}
      title={title ?? (confirm ? 'Review and confirm work order' : 'Review suggested assignment')}
      size="lg"
    >
      {confirm && (
        <div className="mb-5 space-y-3">
          <div className="flex flex-wrap gap-2" role="group" aria-label="Production timing">
            <Button
              variant={preferredDay ? 'secondary' : 'primary'}
              aria-pressed={!preferredDay}
              onClick={() => setPreferredDay('')}
            >
              Earliest available
            </Button>
            <Button
              variant={preferredDay ? 'primary' : 'secondary'}
              aria-pressed={!!preferredDay}
              onClick={() => setPreferredDay(preferredDay || today)}
            >
              Choose day
            </Button>
          </div>
          {preferredDay && (
            <Input
              type="date"
              label="Production day"
              aria-label="Production day"
              required
              min={today}
              max={offsetScheduleDate(today, 90)}
              value={preferredDay}
              onChange={(event) => setPreferredDay(event.target.value)}
              helper="Find the earliest available time from this day (Asia/Manila)."
            />
          )}
          {!validDay && (
            <p role="alert" className="text-danger-fg">
              {afterDeadline
                ? `Choose a day before production due: ${productionDateTime(context.production_deadline!)}.`
                : 'Choose today or a future day within 90 days.'}
            </p>
          )}
          {validDay && context.production_deadline && (
            <p className="text-xs text-muted">
              Production due: {productionDateTime(context.production_deadline)} (Asia/Manila)
            </p>
          )}
          {validDay && context.delivery_date && (
            <p className="text-xs text-muted">
              Current delivery: {formatDateTime(context.delivery_date)}
            </p>
          )}
        </div>
      )}
      {validDay && suggestion.isFetching && (
        <p role="status" className="flex items-center gap-2">
          <LuLoaderCircle
            size={16}
            className="shrink-0 animate-spin motion-reduce:animate-none"
            aria-hidden
          />
          <span>Checking equipment, HR shifts and worker availability…</span>
        </p>
      )}
      {validDay && suggestion.isError && (
        <p role="alert" className="text-danger-fg">
          Assignment suggestion failed to load.{' '}
          <Button
            variant="secondary"
            disabled={!validLimits || !validDay || !stable}
            onClick={() => suggestion.refetch()}
          >
            Retry suggestion
          </Button>
        </p>
      )}
      {suggestion.data ? (
        <AssignmentEditor
          woId={woId}
          context={context}
          proposal={suggestion.data}
          reason={reason}
          onReasonChange={setReason}
          minimum={minimum}
          onMinimumChange={setMinimum}
          manual={manual}
          onModeChange={setManual}
          busy={suggestion.isFetching || suggestion.isError || !stable || !validDay}
          preferredDay={preferredDay}
          invalidDay={!validDay}
          onRefresh={confirm && generate ? () => suggestion.refetch() : undefined}
          confirm={confirm}
          commitLabel={commitLabel}
          onPendingChange={onPendingChange}
          onClose={onClose}
        />
      ) : (
        <div className="space-y-4">
          {validDay && (
            <>
              <Button variant="secondary" onClick={() => setManual(!manual)}>
                {manual ? 'Auto assign' : 'Manual assign'}
              </Button>
              {!manual && <StaffingFields minimum={minimum} onMinimumChange={setMinimum} />}
              {!minimum && !manual && (
                <p className="text-warning-fg">
                  Choose the number of workers to suggest the crew automatically.
                </p>
              )}
            </>
          )}
          <div className="mt-4 flex justify-end gap-2">
            <Button variant="secondary" onClick={onClose}>
              Cancel
            </Button>
            <Button variant="primary" disabled>{commitLabel ?? (confirm ? 'Confirm work order' : 'Apply assignment')}</Button>
          </div>
        </div>
      )}
    </Modal>
  );
}

function AssignmentEditor({
  woId,
  context,
  proposal,
  reason,
  onReasonChange,
  minimum,
  onMinimumChange,
  manual,
  onModeChange,
  busy,
  preferredDay,
  invalidDay,
  onRefresh,
  confirm,
  commitLabel,
  onPendingChange,
  onClose,
}: {
  woId: string;
  context: CrewContext;
  proposal: import('@/types/production-crew').AssignmentSuggestion;
  reason: string;
  onReasonChange: (value: string) => void;
  minimum: string;
  onMinimumChange: (value: string) => void;
  manual: boolean;
  onModeChange: (manual: boolean) => void;
  busy: boolean;
  preferredDay: string;
  invalidDay: boolean;
  onRefresh?: () => void;
  confirm: boolean;
  commitLabel?: string;
  onPendingChange?: (pending: boolean) => void;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const [members, setMembers] = useState(manual && !minimum ? [] : proposal.members);
  const [recorder, setRecorder] = useState(
    manual && !minimum ? '' : (proposal.recorder_employee_id ?? ''),
  );
  useEffect(() => {
    if (!manual) {
      setMembers(proposal.members);
      setRecorder(proposal.recorder_employee_id ?? '');
    }
  }, [proposal, manual]);
  const selectedCount = manual ? String(members.length) : minimum;
  const validLimits = /^[1-4]$/.test(selectedCount);
  const limitsChanged =
    !manual &&
    (Number(minimum) !== proposal.minimum_workers || Number(minimum) !== proposal.maximum_workers);
  const stale = proposal.expected_crew_id !== (context.current?.id ?? null);
  const assignmentReason = context.current
    ? reason
    : manual
      ? 'Initial manual crew assignment'
      : 'Initial automatic crew assignment';
  const options = proposal.worker_options ?? proposal.eligible_workers ?? proposal.members;
  const unavailable =
    manual &&
    members.some((member) => {
      const latest = options.find((option) => option.id === member.id);
      return !latest || latest.can_assign === false;
    });
  const error = unavailable
    ? 'Selected workers are unavailable for this time. Replace them.'
    : crewValidation(members, recorder, selectedCount, selectedCount, assignmentReason);
  const equipmentBlockers = proposal.equipment_blockers ?? proposal.blockers;
  useElapsedSeconds(confirm && !!proposal.schedule);
  const expired =
    confirm &&
    !!proposal.schedule &&
    new Date(proposal.schedule.scheduled_end).getTime() <= Date.now();
  const invalid =
    expired ||
    busy ||
    !context.can_assign ||
    !proposal.schedule ||
    stale ||
    !validLimits ||
    limitsChanged ||
    equipmentBlockers.length > 0 ||
    (confirm && !!proposal.readiness_blockers?.some((blocker) => blocker.code !== 'quality')) ||
    !!error;
  const apply = useMutation({
    mutationFn: () =>
      workOrdersApi.applyAssignment(woId, {
        employee_ids: members.map((member) => member.id),
        recorder_employee_id: recorder,
        minimum_workers: Number(selectedCount),
        maximum_workers: Number(selectedCount),
        expected_crew_id: proposal.expected_crew_id,
        schedule_id: proposal.schedule!.id,
        schedule_updated_at: proposal.schedule!.updated_at,
        reason: assignmentReason.trim(),
        ...(confirm ? { preferred_production_date: preferredDay || null } : {}),
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['production', 'work-orders'] });
      qc.invalidateQueries({ queryKey: ['production', 'crew', woId] });
      qc.invalidateQueries({ queryKey: ['production', 'dashboard'] });
      qc.invalidateQueries({ queryKey: ['mrp'] });
      onClose();
    },
  });
  useEffect(() => { onPendingChange?.(apply.isPending); }, [apply.isPending, onPendingChange]);
  const applyMessage = apply.isError
    ? apply.error instanceof AxiosError
      ? (apply.error.response?.data?.message ??
        'Assignment failed. Reopen this review and try again.')
      : 'Assignment failed. Reopen this review and try again.'
    : null;
  const timingRejected = !!applyMessage && /Proposal (passed|no longer fits)/.test(applyMessage);
  const recoveryMessage = timingRejected
    ? applyMessage
    : expired
      ? 'Proposal passed. Find a new slot.'
      : null;
  return (
    <div className="space-y-4 text-sm">
      {!invalidDay && (
        <>
          {proposal.schedule ? (
            <dl className="grid gap-x-4 gap-y-2 sm:grid-cols-[auto_1fr]">
              <dt className="text-muted">Machine</dt>
              <dd>{proposal.schedule.machine?.machine_code ?? proposal.schedule.machine_id}</dd>
              <dt className="text-muted">Mold</dt>
              <dd>{proposal.schedule.mold?.mold_code ?? proposal.schedule.mold_id}</dd>
              <dt className="text-muted">Scheduled time</dt>
              <dd>
                {productionDateTime(proposal.schedule.scheduled_start)} –{' '}
                {productionDateTime(proposal.schedule.scheduled_end)} (Asia/Manila)
              </dd>
            </dl>
          ) : (
            <p role="alert" className="text-danger-fg">
              {equipmentBlockers[0] ??
                'No schedule available. Choose another day or review equipment and worker availability.'}
            </p>
          )}
          {recoveryMessage && (
            <div className="flex flex-wrap items-center gap-3">
              <p role="alert" className="text-warning-fg">
                {recoveryMessage}
              </p>
              {onRefresh ? (
                <Button
                  variant="secondary"
                  disabled={busy || apply.isPending}
                  onClick={() => {
                    apply.reset();
                    onRefresh();
                  }}
                >
                  Find next slot
                </Button>
              ) : (
                <p className="text-muted">Close this review and open Confirm or Reschedule.</p>
              )}
            </div>
          )}
          {proposal.preferred_day_message && (
            <p className="text-warning-fg">{proposal.preferred_day_message}</p>
          )}
          {confirm && (
            <p className="text-muted">
              Confirms equipment and workers, and reserves materials. Start production separately.
            </p>
          )}
          {confirm &&
            proposal.readiness_blockers?.map((blocker) => (
              <p
                key={blocker.code + blocker.message}
                className={blocker.code === 'quality' ? 'text-warning-fg' : 'text-danger-fg'}
              >
                {blocker.message}
              </p>
            ))}
          {proposal.schedule ? (
            <CrewFields
              members={members}
              onMembersChange={setMembers}
              recorder={recorder}
              onRecorderChange={setRecorder}
              minimum={minimum}
              onMinimumChange={onMinimumChange}
              options={proposal.worker_options ?? proposal.eligible_workers ?? proposal.members}
              disabled={busy || expired || apply.isPending}
              limitsDisabled={apply.isPending}
              manual={manual}
              onModeChange={onModeChange}
            />
          ) : (
            <div className="space-y-4">
              <Button
                variant="secondary"
                disabled={apply.isPending}
                onClick={() => onModeChange(!manual)}
              >
                {manual ? 'Auto assign' : 'Manual assign'}
              </Button>
              {!manual && (
                <StaffingFields
                  minimum={minimum}
                  onMinimumChange={onMinimumChange}
                  disabled={apply.isPending}
                />
              )}
            </div>
          )}
          {limitsChanged && (
            <p role="status" className="text-warning-fg">
              Worker count changed. Updating suggested workers automatically…
            </p>
          )}
          {proposal.schedule &&
            !manual &&
            proposal.staffing_blockers &&
            proposal.staffing_blockers.length > 0 && (
              <p className="text-warning-fg">{proposal.staffing_blockers.join(' ')}</p>
            )}
          {equipmentBlockers.length > (proposal.schedule ? 0 : 1) && (
            <ul role="alert" className="space-y-1 text-danger-fg">
              {equipmentBlockers.slice(proposal.schedule ? 0 : 1).map((blocker, index) => (
                <li key={index}>{blocker}</li>
              ))}
            </ul>
          )}
          {stale && (
            <p role="alert" className="text-danger-fg">
              Crew changed since this suggestion. Reopen this review before applying.
            </p>
          )}
          {context.current && (
            <Textarea
              label="Handover reason"
              aria-label="Handover reason"
              helper="Explain why the workers or output recorder are changing"
              value={reason}
              onChange={(event) => onReasonChange(event.target.value)}
              disabled={apply.isPending}
              required
            />
          )}
          {proposal.schedule && error && <p className="text-danger-fg">{error}</p>}
          {applyMessage && !timingRejected && (
            <p role="alert" className="text-danger-fg">
              {applyMessage}
            </p>
          )}
        </>
      )}
      <div className="flex flex-wrap justify-end gap-2">
        <Button variant="secondary" disabled={apply.isPending} onClick={onClose}>
          Cancel
        </Button>
        <Button
          variant="primary"
          disabled={invalid || apply.isPending || apply.isError}
          loading={apply.isPending}
          onClick={() => {
            if (!invalid) apply.mutate();
          }}
        >
          {commitLabel ?? (confirm ? 'Confirm work order' : 'Apply assignment')}
        </Button>
      </div>
    </div>
  );
}
