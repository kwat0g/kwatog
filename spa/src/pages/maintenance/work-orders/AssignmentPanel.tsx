import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { workOrdersApi, type MaintenanceAssigneeCandidate } from '@/api/maintenance/workOrders';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { Panel } from '@/components/ui/Panel';
import { LuChevronDown, LuRefreshCw } from '@/lib/icons';
import { formatTime } from '@/lib/formatDate';
import { reportMutationError } from '@/lib/formErrors';
import { cn } from '@/lib/cn';
import type { MaintenanceWorkOrder } from '@/types/maintenance';

function TechnicianOption({
  candidate,
  selected,
  suggested,
  disabled,
  onSelect,
}: {
  candidate: MaintenanceAssigneeCandidate;
  selected: boolean;
  suggested: boolean;
  disabled: boolean;
  onSelect: () => void;
}) {
  return (
    <label className={cn(
      'flex min-h-11 items-start gap-3 px-3 py-2.5 text-sm focus-within:outline-none focus-within:ring-2 focus-within:ring-inset focus-within:ring-accent',
      selected ? 'bg-surface' : 'hover:bg-subtle',
      disabled ? 'cursor-not-allowed' : 'cursor-pointer',
    )}>
      <input
        type="radio"
        name="maintenance-assignee"
        value={candidate.id}
        checked={selected}
        disabled={disabled}
        onChange={onSelect}
        className="mt-0.5 size-4 shrink-0 accent-accent"
      />
      <span className="min-w-0 flex-1">
        <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
          <span className="font-medium text-primary">{candidate.name}</span>
          <Chip variant={suggested ? 'success' : candidate.can_assign ? 'info' : candidate.can_attest_presence ? 'warning' : 'neutral'}>
            {suggested ? 'Suggested' : candidate.can_assign ? 'Available' : candidate.can_attest_presence ? 'Presence unverified' : 'Unavailable'}
          </Chip>
        </span>
        <span className="mt-0.5 block text-xs text-muted">
          <span className="font-mono">{candidate.employee_no}</span>
          {candidate.can_assign && <>{' · '}{candidate.presence.state === 'present' ? 'Present on shift' : 'Shift covered'}</>}
          {' · '}{candidate.active_load === 0 ? 'No other active orders' : `${candidate.active_load} other active ${candidate.active_load === 1 ? 'order' : 'orders'}`}
        </span>
        {candidate.blocked_reason && <span className="mt-1 block text-xs text-secondary">{candidate.blocked_reason}</span>}
      </span>
    </label>
  );
}

export function AssignmentPanel({
  order,
  touch = false,
  onAssigned,
}: {
  order: MaintenanceWorkOrder;
  touch?: boolean;
  onAssigned?: () => void;
}) {
  const queryClient = useQueryClient();
  const currentAssigneeId = order.assignee?.id ?? null;
  const [assigneeId, setAssigneeId] = useState(currentAssigneeId ?? '');
  const [presenceConfirmed, setPresenceConfirmed] = useState(false);
  const [showUnavailable, setShowUnavailable] = useState(false);
  const [submissionBlock, setSubmissionBlock] = useState('');
  const { data: suggestions, isPending, isError, isFetching, refetch } = useQuery({
    queryKey: ['maintenance', 'assignee-suggestions', order.id],
    queryFn: () => workOrdersApi.assigneeSuggestions(order.id),
  });

  useEffect(() => {
    setAssigneeId(currentAssigneeId ?? '');
  }, [order.id, currentAssigneeId]);
  useEffect(() => {
    if (!currentAssigneeId && suggestions?.suggested_employee_id) {
      setAssigneeId((current) => current || suggestions.suggested_employee_id || '');
    }
  }, [currentAssigneeId, suggestions?.suggested_employee_id]);

  const ready = suggestions?.candidates.filter((candidate) => candidate.can_assign || candidate.can_attest_presence) ?? [];
  const unavailable = suggestions?.candidates.filter((candidate) => !candidate.can_assign && !candidate.can_attest_presence) ?? [];
  const selected = suggestions?.candidates.find((candidate) => candidate.id === assigneeId);
  const changing = assigneeId !== (currentAssigneeId ?? '');
  const needsPresenceConfirmation = selected?.can_attest_presence && !selected.can_assign;
  const canConfirm = Boolean(selected
    && (selected.can_assign || (needsPresenceConfirmation && presenceConfirmed)));
  const canSave = canConfirm && (changing || (needsPresenceConfirmation && presenceConfirmed));

  const assign = useMutation({
    mutationFn: (candidate: MaintenanceAssigneeCandidate) => workOrdersApi.assign(order.id, candidate.id, {
      expected_assignee_id: currentAssigneeId,
      presence_confirmed: candidate.can_attest_presence && !candidate.can_assign ? presenceConfirmed : undefined,
    }),
    onSuccess: (_assigned, candidate) => {
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'work-order', order.id] });
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'work-orders'] });
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'assignee-suggestions', order.id] });
      setPresenceConfirmed(false);
      toast.success(candidate.id === currentAssigneeId ? 'Physical presence confirmed.' : 'Work order assigned.');
      onAssigned?.();
    },
    onError: (error) => {
      reportMutationError(error, 'Could not assign the work order.');
      refetch();
      queryClient.invalidateQueries({ queryKey: ['maintenance', 'work-order', order.id] });
    },
  });

  const submitAssignment = async () => {
    setSubmissionBlock('');
    const { data: freshSuggestions, error } = await refetch();
    const candidate = freshSuggestions?.candidates.find((row) => row.id === assigneeId);
    if (error || !candidate) {
      setSubmissionBlock('Could not refresh technician availability. Refresh and try again.');
      return;
    }
    if (!candidate.can_assign && !(candidate.can_attest_presence && presenceConfirmed)) {
      setSubmissionBlock(`${candidate.blocked_reason ?? 'Technician availability could not be confirmed.'} Choose another technician or update attendance/shift coverage, then refresh.`);
      return;
    }
    assign.mutate(candidate);
  };

  const option = (candidate: MaintenanceAssigneeCandidate) => (
    <TechnicianOption
      key={candidate.id}
      candidate={candidate}
      selected={candidate.id === assigneeId}
      suggested={candidate.id === suggestions?.suggested_employee_id}
      disabled={assign.isPending || isFetching || Boolean(!candidate.can_assign && !candidate.can_attest_presence)}
      onSelect={() => { setAssigneeId(candidate.id); setPresenceConfirmed(false); setSubmissionBlock(''); }}
    />
  );

  return (
    <Panel
      title="Assignment"
      actions={<Button type="button" variant="secondary" size={touch ? 'touch' : 'sm'} icon={<LuRefreshCw size={14} />} onClick={() => refetch()} loading={isFetching} disabled={assign.isPending}>Refresh</Button>}
    >
      <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 text-sm">
        <span className="text-muted">Current technician</span>
        <span className="font-medium text-primary">{order.assignee ? `${order.assignee.name} · ${order.assignee.employee_no}` : 'Unassigned'}</span>
      </div>
      {suggestions && <p className="mt-2 text-xs text-muted">Checked {formatTime(suggestions.evaluated_at)} · Refresh after shift or attendance changes.</p>}

      {isPending && <p role="status" className="mt-4 text-sm text-muted">Checking technician availability…</p>}
      {isError && <p role="alert" className="mt-4 text-sm text-danger-fg">Could not check availability. Refresh to try again.</p>}
      {suggestions && (
        <fieldset className="mt-4 min-w-0">
          <legend className="mb-2 text-sm font-medium text-primary">Choose technician</legend>
          {ready.length > 0 ? (
            <div className="divide-y divide-default overflow-hidden rounded-md border border-default">{ready.map(option)}</div>
          ) : (
            <p className="text-sm text-secondary">No technician currently available. Resolve attendance, shift, or assignment blockers, then refresh suggestions.</p>
          )}
          {unavailable.length > 0 && (
            <div className="mt-3">
              <Button type="button" variant="ghost" size={touch ? 'touch' : 'sm'} className="px-0 text-secondary" icon={<LuChevronDown size={14} className={cn('transition-transform', showUnavailable && 'rotate-180')} />} onClick={() => setShowUnavailable((value) => !value)} aria-expanded={showUnavailable}>
                {showUnavailable ? 'Hide' : 'Review'} unavailable technicians ({unavailable.length})
              </Button>
              {showUnavailable && <div className="mt-2 divide-y divide-default overflow-hidden rounded-md border border-default">{unavailable.map(option)}</div>}
              <p className="mt-2 text-xs text-muted">Choose another technician or resolve the listed blocker, then refresh.</p>
            </div>
          )}
        </fieldset>
      )}

      {needsPresenceConfirmation && (
        <label className="mt-3 flex items-start gap-2 rounded-md border border-default p-3 text-sm">
          <input
            type="checkbox"
            checked={presenceConfirmed}
            onChange={(event) => setPresenceConfirmed(event.target.checked)}
            className="mt-1"
          />
          <span>I confirm {selected.name} is physically present despite no verified attendance record.</span>
        </label>
      )}
      {selected && !selected.can_assign && !selected.can_attest_presence && changing && !submissionBlock && (
        <p role="status" className="mt-3 text-sm text-warning-fg">{selected.blocked_reason} Choose another technician or update attendance/shift coverage, then refresh.</p>
      )}
      {submissionBlock && <p role="alert" className="mt-3 text-sm text-warning-fg">{submissionBlock}</p>}
      <Button type="button" variant="primary" size={touch ? 'touch' : 'md'} className="mt-4 w-full" onClick={submitAssignment} disabled={!canSave || assign.isPending || isFetching || isError} loading={assign.isPending || isFetching}>
        {order.assignee ? 'Save assignment' : 'Assign technician'}
      </Button>
      {selected && !changing && !needsPresenceConfirmation && <p className="mt-2 text-center text-xs text-muted">Select another technician to change this assignment.</p>}
    </Panel>
  );
}
