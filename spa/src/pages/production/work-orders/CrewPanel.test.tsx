import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, within, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AssignmentReview, CrewPanel, SlotForm, WorkerScheduleStatus } from './CrewPanel';
import { workOrdersApi } from '@/api/production/workOrders';
import { crewValidation } from '@/lib/productionCrewValidation';
import type { EmployeeOption } from '@/types/production-crew';
import {
  offsetScheduleDate,
  productionInputDateTime,
  scheduleDateKey,
} from '@/components/production/scheduleDates';
const members: EmployeeOption[] = [
  { id: 'a', employee_number: '1', name: 'Worker', has_active_account: true, shift: null },
];
it('shows selected shift hours and provisional attendance without claiming future presence', () => {
  render(
    <WorkerScheduleStatus
      worker={{
        ...members[0],
        shift: {
          id: 'shift',
          name: 'Afternoon',
          scheduled_start: '2026-10-06T14:00:00+08:00',
          scheduled_end: '2026-10-06T22:00:00+08:00',
        },
        schedule_attendance: { state: 'upcoming', reason: null, work_date: '2026-10-06' },
      }}
    />,
  );
  expect(screen.getByText('Upcoming shift; attendance checked at Start')).toBeDefined();
  expect(screen.getByText(/Afternoon.*2:00 PM.*10:00 PM/)).toBeDefined();
  expect(screen.queryByText('Attendance verified')).toBeNull();
});
describe('crew assignment validation', () => {
  it('requires limits, member count and an active member recorder', () => {
    expect(crewValidation(members, 'a', '1', '', 'Initial crew')).toBeUndefined();
    expect(crewValidation(members, 'a', '1.5', '', 'Reason')).toBeDefined();
    expect(crewValidation(members, 'a', '2', '1', 'Reason')).toBeDefined();
    expect(crewValidation(members, 'a', '2', '', 'Reason')).toBeDefined();
    expect(crewValidation(members, 'other', '1', '', 'Reason')).toBeDefined();
    expect(
      crewValidation([{ ...members[0], has_active_account: false }], 'a', '1', '', 'Reason'),
    ).toBeDefined();
    expect(crewValidation(members, 'a', '1', '', ' ')).toBeDefined();
  });
});

vi.mock('@/api/production/workOrders', () => ({
  workOrdersApi: {
    crew: vi.fn(),
    formOptions: vi.fn(),
    reviewSlot: vi.fn(),
    crewOptions: vi.fn(),
    assignmentSuggestion: vi.fn(),
    generateAssignmentSuggestion: vi.fn(),
    applyAssignment: vi.fn(),
    assignCrew: vi.fn(),
  },
}));
afterEach(() => {
  cleanup();
  vi.resetAllMocks();
});
const context = {
  current: {
    id: 'crew-1',
    members: [...members, { ...members[0], id: 'b', name: 'Support' }],
    recorder_employee_id: 'a',
    reason: 'Initial',
    assigned_at: '2026-10-05T08:00:00Z',
    ended_at: null,
    rework_ncr_id: null,
  },
  history: [],
  minimum_workers: 1,
  maximum_workers: 1,
  can_assign: true,
};
function showManualTime(options: EmployeeOption[] = context.current.members) {
  vi.mocked(workOrdersApi.crewOptions).mockResolvedValue(options);
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <SlotForm
        woId="wo-1"
        context={context}
        onSaved={vi.fn()}
        compact
        initialTiming={{
          scheduled_start: '2099-10-10T09:00:00+08:00',
          scheduled_end: '2099-10-10T10:40:00+08:00',
        }}
      />
    </QueryClientProvider>,
  );
}

it('blocks manual timing past the deadline even when workers fit', async () => {
  vi.mocked(workOrdersApi.crewOptions).mockResolvedValue(context.current.members);
  render(
    <QueryClientProvider client={new QueryClient()}>
      <SlotForm
        woId="wo-1"
        context={{
          ...context,
          production_deadline: '2099-10-10T10:00:00+08:00',
          can_approve_late_production: false,
        }}
        onSaved={vi.fn()}
        compact
        initialTiming={{
          scheduled_start: '2099-10-10T09:00:00+08:00',
          scheduled_end: '2099-10-10T10:40:00+08:00',
        }}
      />
    </QueryClientProvider>,
  );
  await screen.findByText('Crew fits this schedule. Attendance checked at Start.');
  expect(screen.getByText(/Production due:.*10:00 AM/)).toBeVisible();
  expect(screen.getByText(/Ask Production Management/)).toBeVisible();
  expect(screen.getByRole('button', { name: 'Approve new schedule' })).toBeDisabled();
  expect(workOrdersApi.reviewSlot).not.toHaveBeenCalled();
});

it('requires explicit late approval and reason, clearing them when manual timing changes', async () => {
  vi.mocked(workOrdersApi.crewOptions).mockResolvedValue(context.current.members);
  render(
    <QueryClientProvider client={new QueryClient()}>
      <SlotForm
        woId="wo-1"
        context={{
          ...context,
          production_deadline: '2099-10-10T10:00:00+08:00',
          can_approve_late_production: true,
        }}
        onSaved={vi.fn()}
        compact
        initialTiming={{
          scheduled_start: '2099-10-10T09:00:00+08:00',
          scheduled_end: '2099-10-10T10:40:00+08:00',
        }}
      />
    </QueryClientProvider>,
  );
  await screen.findByText('Crew fits this schedule. Attendance checked at Start.');
  const approve = screen.getByRole('button', { name: 'Approve new schedule' });
  expect(approve).toBeDisabled();
  fireEvent.click(screen.getByLabelText('I approve production past the deadline.'));
  expect(approve).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Delivery impact / reason'), {
    target: { value: 'Outgoing QC will delay the shipment.' },
  });
  expect(approve).toBeEnabled();
  fireEvent.click(approve);
  await waitFor(() =>
    expect(workOrdersApi.reviewSlot).toHaveBeenCalledWith(
      'wo-1',
      expect.objectContaining({
        late_production_authorized: true,
        late_production_reason: 'Outgoing QC will delay the shipment.',
      }),
      undefined,
    ),
  );
  fireEvent.change(screen.getByLabelText('Start time'), { target: { value: '2099-10-11T09:00' } });
  expect(screen.getByLabelText('I approve production past the deadline.')).not.toBeChecked();
  expect(screen.getByLabelText('Delivery impact / reason')).toHaveValue('');
  expect(approve).toBeDisabled();
});

it('uses the current minute without a forced wait and checks the crew before approval', async () => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date('2026-10-08T12:00:30Z'));
  try {
    showManualTime();
    fireEvent.click(screen.getByRole('button', { name: 'Use current time' }));
    expect(screen.getByLabelText('Start time')).toHaveValue(
      productionInputDateTime(new Date().toISOString()),
    );
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Approve new schedule' })).toBeEnabled(),
    );
    expect(workOrdersApi.crewOptions).toHaveBeenCalled();
    expect(workOrdersApi.reviewSlot).not.toHaveBeenCalled();
  } finally {
    vi.useRealTimers();
  }
});
it('prefills manual timing, calculates finish when start changes and saves a selected reason', async () => {
  showManualTime();
  expect(screen.getByLabelText('Start time')).toHaveValue('2099-10-10T09:00');
  expect(screen.queryByLabelText('Scheduling reason')).toBeNull();
  expect(screen.queryByLabelText('Finish time')).toBeNull();
  fireEvent.change(screen.getByLabelText('Start time'), { target: { value: '2099-10-11T10:00' } });
  await screen.findByText('Crew fits this schedule. Attendance checked at Start.');
  fireEvent.change(screen.getByLabelText('Reason for change'), {
    target: { value: 'Materials delayed' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Approve new schedule' }));
  await waitFor(() =>
    expect(workOrdersApi.reviewSlot).toHaveBeenCalledWith(
      'wo-1',
      expect.objectContaining({
        scheduled_start: '2099-10-11T02:00:00.000Z',
        scheduled_end: '2099-10-11T04:00:00.000Z',
        reason: 'Materials delayed',
      }),
      undefined,
    ),
  );
});
it('blocks manual approval when retained crew cannot cover the selected interval', async () => {
  showManualTime(
    context.current.members.map((member) => ({
      ...member,
      can_assign: false,
      blocked_reason: 'Outside HR shift',
    })),
  );
  await screen.findByText(/Outside HR shift/);
  expect(screen.getByRole('button', { name: 'Approve new schedule' })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Change workers' }));
  expect(screen.getByRole('combobox', { name: 'Add worker' })).toBeDefined();
});
it('requires explanation only for Other and blocks finish shorter than the calculated duration', async () => {
  showManualTime();
  await screen.findByText('Crew fits this schedule. Attendance checked at Start.');
  fireEvent.change(screen.getByLabelText('Reason for change'), { target: { value: 'other' } });
  expect(screen.getByRole('button', { name: 'Approve new schedule' })).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Explain the change'), {
    target: { value: 'Coordinate the trial run.' },
  });
  const details = screen.getByText('Adjust finish time').closest('details')!;
  details.open = true;
  fireEvent(details, new Event('toggle'));
  await screen.findByLabelText('Finish time');
  fireEvent.change(screen.getByLabelText('Finish time'), { target: { value: '2099-10-10T09:10' } });
  expect(screen.getByRole('button', { name: 'Approve new schedule' })).toBeDisabled();
  expect(screen.getByText(/Allow at least 120 minutes/)).toBeDefined();
});
function show(readOnly = false) {
  vi.mocked(workOrdersApi.crew).mockResolvedValue(context);
  vi.mocked(workOrdersApi.crewOptions).mockResolvedValue(members);
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <CrewPanel woId="wo-1" readOnly={readOnly} />
    </QueryClientProvider>,
  );
}
it('reviews paused production timing separately and submits offset timestamps', async () => {
  vi.mocked(workOrdersApi.crew).mockResolvedValue({ ...context, can_review_slot: true });
  vi.mocked(workOrdersApi.crewOptions).mockResolvedValue(members);
  vi.mocked(workOrdersApi.reviewSlot).mockResolvedValue({} as never);
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <CrewPanel woId="wo-1" status="paused" />
    </QueryClientProvider>,
  );
  fireEvent.click(await screen.findByRole('button', { name: 'Review production slot' }));
  const dialog = screen.getByRole('dialog');
  fireEvent.change(within(dialog).getByLabelText('Reviewed production start'), {
    target: { value: '2026-10-10T09:00' },
  });
  fireEvent.change(within(dialog).getByLabelText('Reviewed production end'), {
    target: { value: '2026-10-10T11:00' },
  });
  fireEvent.change(within(dialog).getByLabelText('Scheduling reason'), {
    target: { value: 'Reviewed remaining work' },
  });
  fireEvent.click(within(dialog).getByRole('button', { name: 'Approve production slot' }));
  await waitFor(() =>
    expect(workOrdersApi.reviewSlot).toHaveBeenCalledWith(
      'wo-1',
      {
        preferred_production_date: '2026-10-10',
        scheduled_start: '2026-10-10T01:00:00.000Z',
        scheduled_end: '2026-10-10T03:00:00.000Z',
        reason: 'Reviewed remaining work',
        expected_schedule_id: null,
        expected_schedule_updated_at: null,
      },
      undefined,
    ),
  );
});

describe('compact crew assignment', () => {
  it('hides Change workers before assignment and retains initial assignment for confirmed work', async () => {
    vi.mocked(workOrdersApi.crew).mockResolvedValue({ ...context, current: null });
    vi.mocked(workOrdersApi.crewOptions).mockResolvedValue(members);
    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <CrewPanel woId="wo-1" status="confirmed" actionOnly />
      </QueryClientProvider>,
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Assign workers' }));
    expect(screen.queryByRole('button', { name: 'Change workers' })).toBeNull();
    expect(screen.getByRole('heading', { name: 'Assign workers' })).toBeInTheDocument();
    expect(screen.getByRole('combobox', { name: 'Add worker' })).toBeInTheDocument();
  });
  it('keeps all names and recorder visible; mounts editing only inside modal', async () => {
    show();
    await screen.findByText(/Support/);
    expect(screen.getByText(/Worker.*Output recorder/)).toBeTruthy();
    expect(screen.queryByLabelText('Number of workers')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Change workers' }));
    expect(
      within(screen.getByRole('dialog')).getByRole('combobox', { name: 'Add worker' }),
    ).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Auto assign' })).toBeNull();
    expect(screen.queryByLabelText('Reviewed production start')).toBeNull();
    expect(screen.queryByLabelText('Number of workers')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Close modal' }));
    expect(screen.queryByLabelText('Number of workers')).toBeNull();
    expect(screen.getByText(/Support/)).toBeTruthy();
  });
  it('shows read-only names without editing controls', async () => {
    show(true);
    await screen.findByText(/Support/);
    expect(screen.queryByRole('button', { name: 'Change workers' })).toBeNull();
  });
  it('disables apply when suggestion fails to load', async () => {
    vi.mocked(workOrdersApi.assignmentSuggestion).mockRejectedValue(new Error('Unavailable'));
    show();
    fireEvent.click(await screen.findByRole('button', { name: 'Suggest assignment' }));
    await screen.findByText(/Assignment suggestion failed/);
    expect(screen.getByRole('button', { name: 'Apply assignment' }).hasAttribute('disabled')).toBe(
      true,
    );
  });
  it('disables stale proposals despite valid crew and reason', async () => {
    vi.mocked(workOrdersApi.assignmentSuggestion).mockResolvedValue({
      schedule: {
        id: 'slot-1',
        scheduled_start: '2026-10-05T08:00:00Z',
        scheduled_end: '2026-10-05T09:00:00Z',
        updated_at: '2026-10-05T07:00:00Z',
        machine_id: 'machine-1',
        mold_id: 'mold-1',
      },
      members: context.current.members,
      recorder_employee_id: 'a',
      minimum_workers: 2,
      maximum_workers: 2,
      expected_crew_id: 'old-crew',
      blockers: [],
    });
    show();
    fireEvent.click(await screen.findByRole('button', { name: 'Suggest assignment' }));
    await screen.findByText(/Crew changed since/);
    fireEvent.change(screen.getByLabelText('Handover reason'), {
      target: { value: 'Approved plan' },
    });
    expect(screen.getByRole('button', { name: 'Apply assignment' }).hasAttribute('disabled')).toBe(
      true,
    );
  });
  it('applies exact reviewed slot version and refreshes the summary', async () => {
    vi.mocked(workOrdersApi.assignmentSuggestion).mockResolvedValue({
      schedule: {
        id: 'slot-1',
        scheduled_start: '2026-10-05T08:00:00Z',
        scheduled_end: '2026-10-05T09:00:00Z',
        updated_at: '2026-10-05T07:00:00Z',
        machine_id: 'machine-1',
        mold_id: 'mold-1',
      },
      members: context.current.members,
      recorder_employee_id: 'a',
      minimum_workers: 2,
      maximum_workers: 2,
      expected_crew_id: 'crew-1',
      blockers: [],
    });
    vi.mocked(workOrdersApi.applyAssignment).mockResolvedValue(context);
    show();
    fireEvent.click(await screen.findByRole('button', { name: 'Suggest assignment' }));
    await screen.findByText('machine-1');
    fireEvent.change(screen.getByLabelText('Handover reason'), {
      target: { value: 'Approved plan' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Apply assignment' }));
    await waitFor(() =>
      expect(workOrdersApi.applyAssignment).toHaveBeenCalledWith(
        'wo-1',
        expect.objectContaining({
          schedule_id: 'slot-1',
          schedule_updated_at: '2026-10-05T07:00:00Z',
          expected_crew_id: 'crew-1',
          recorder_employee_id: 'a',
        }),
      ),
    );
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(screen.getByText(/Support/)).toBeTruthy();
  });
});

it('saves worker changes before production without extra staffing or reason fields', async () => {
  vi.mocked(workOrdersApi.crew).mockResolvedValue({ ...context, can_review_slot: true });
  vi.mocked(workOrdersApi.crewOptions).mockResolvedValue(context.current.members);
  vi.mocked(workOrdersApi.assignCrew).mockResolvedValue(context);
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <CrewPanel woId="wo-1" status="confirmed" />
    </QueryClientProvider>,
  );
  fireEvent.click(await screen.findByRole('button', { name: 'Change workers' }));
  await waitFor(() => expect(workOrdersApi.crewOptions).toHaveBeenCalled());
  expect(screen.queryByLabelText('Number of workers')).toBeNull();
  expect(screen.queryByLabelText('Handover reason')).toBeNull();
  expect(screen.queryByLabelText('Reviewed production start')).toBeNull();
  expect(screen.getByRole('button', { name: 'Save workers' })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Remove Worker' }));
  await waitFor(() => expect(screen.getByLabelText('Output recorder')).toHaveValue('b'));
  fireEvent.click(screen.getByRole('button', { name: 'Save workers' }));
  await waitFor(() =>
    expect(workOrdersApi.assignCrew).toHaveBeenCalledWith(
      'wo-1',
      expect.objectContaining({
        employee_ids: ['b'],
        recorder_employee_id: 'b',
        minimum_workers: 1,
        maximum_workers: 1,
        expected_crew_id: 'crew-1',
        reason: 'Crew updated before production',
      }),
    ),
  );
});

it('requires a handover reason when changing the recorder during production', async () => {
  vi.mocked(workOrdersApi.crew).mockResolvedValue(context);
  vi.mocked(workOrdersApi.crewOptions).mockResolvedValue(context.current.members);
  vi.mocked(workOrdersApi.assignCrew).mockResolvedValue(context);
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <CrewPanel woId="wo-1" status="in_progress" />
    </QueryClientProvider>,
  );
  fireEvent.click(await screen.findByRole('button', { name: 'Change workers' }));
  await waitFor(() => expect(workOrdersApi.crewOptions).toHaveBeenCalled());
  fireEvent.change(screen.getByLabelText('Output recorder'), { target: { value: 'b' } });
  expect(screen.getByRole('button', { name: 'Save workers' })).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Handover reason'), {
    target: { value: 'Recorder replacement' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Save workers' }));
  await waitFor(() =>
    expect(workOrdersApi.assignCrew).toHaveBeenCalledWith(
      'wo-1',
      expect.objectContaining({
        recorder_employee_id: 'b',
        expected_crew_id: 'crew-1',
        reason: 'Recorder replacement',
      }),
    ),
  );
});

it('automatically suggests the chosen count and requires replacing a worker before adding another', async () => {
  const extra = { ...members[0], id: 'b', employee_number: '2', name: 'Extra worker' };
  const support = { ...members[0], id: 'c', employee_number: '3', name: 'Third worker' };
  const proposal = {
    schedule: {
      id: 'slot-1',
      scheduled_start: new Date(Date.now() + 3600000).toISOString(),
      scheduled_end: new Date(Date.now() + 10800000).toISOString(),
      updated_at: '2026-10-05T07:00:00Z',
      machine_id: 'machine-1',
      mold_id: 'mold-1',
    },
    members,
    eligible_workers: [...members, extra, support],
    recorder_employee_id: 'a',
    minimum_workers: 1,
    maximum_workers: 1,
    expected_crew_id: 'crew-1',
    blockers: [],
    equipment_blockers: [],
    staffing_blockers: [],
  };
  vi.mocked(workOrdersApi.assignmentSuggestion)
    .mockResolvedValueOnce(proposal)
    .mockResolvedValue({
      ...proposal,
      minimum_workers: 2,
      maximum_workers: 2,
      members: [...members, extra],
    });
  vi.mocked(workOrdersApi.applyAssignment).mockResolvedValue(context);
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <AssignmentReview
        woId="wo-1"
        context={{ ...context, current: { ...context.current, members } }}
        confirm
        onClose={() => undefined}
      />
    </QueryClientProvider>,
  );
  await screen.findByText('machine-1');
  fireEvent.change(screen.getByLabelText('Handover reason'), {
    target: { value: 'PPC reviewed staffing' },
  });
  fireEvent.change(screen.getByLabelText('Number of workers'), { target: { value: '2' } });
  expect(screen.getByRole('button', { name: 'Confirm work order' })).toBeDisabled();
  await waitFor(() =>
    expect(workOrdersApi.assignmentSuggestion).toHaveBeenLastCalledWith('wo-1', undefined, {
      minimum_workers: 2,
      maximum_workers: 2,
    }),
  );
  await screen.findByText(/Selected: 2/);
  expect(screen.queryByRole('button', { name: 'Refresh suggestion' })).toBeNull();
  expect(screen.getByLabelText('Handover reason')).toHaveValue('PPC reviewed staffing');
  expect(screen.queryByRole('combobox', { name: 'Add worker' })).toBeNull();
  fireEvent.click(screen.getByRole('button', { name: 'Manual assign' }));
  expect(screen.queryByLabelText('Number of workers')).toBeNull();
  fireEvent.click(screen.getByRole('button', { name: 'Remove Worker' }));
  fireEvent.focus(screen.getByRole('combobox', { name: 'Add worker' }));
  fireEvent.click(await screen.findByRole('option', { name: /3 · Third worker/ }));
  fireEvent.change(screen.getByLabelText('Handover reason'), {
    target: { value: 'PPC reviewed staffing' },
  });
  expect(screen.getByRole('button', { name: 'Confirm work order' })).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Output recorder'), { target: { value: 'b' } });
  fireEvent.click(screen.getByRole('button', { name: 'Confirm work order' }));
  await waitFor(() =>
    expect(workOrdersApi.applyAssignment).toHaveBeenCalledWith(
      'wo-1',
      expect.objectContaining({
        employee_ids: ['b', 'c'],
        minimum_workers: 2,
        maximum_workers: 2,
        recorder_employee_id: 'b',
        schedule_id: 'slot-1',
      }),
    ),
  );
});

it('waits for the number of workers and has no maximum field', async () => {
  const proposal = {
    schedule: {
      id: 'slot-1',
      scheduled_start: new Date(Date.now() + 3600000).toISOString(),
      scheduled_end: new Date(Date.now() + 10800000).toISOString(),
      updated_at: '2026-10-05T07:00:00Z',
      machine_id: 'machine-1',
      mold_id: 'mold-1',
    },
    members,
    recorder_employee_id: 'a',
    minimum_workers: 1,
    maximum_workers: 1,
    expected_crew_id: null,
    blockers: [],
  };
  vi.mocked(workOrdersApi.assignmentSuggestion).mockResolvedValue(proposal);
  vi.mocked(workOrdersApi.applyAssignment).mockResolvedValue(context);
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <AssignmentReview
        woId="wo-1"
        context={{ ...context, current: null }}
        confirm
        onClose={() => undefined}
      />
    </QueryClientProvider>,
  );
  expect(screen.getByRole('combobox', { name: 'Number of workers' })).toHaveValue('');
  expect(screen.queryByLabelText('Maximum workers')).toBeNull();
  expect(workOrdersApi.assignmentSuggestion).not.toHaveBeenCalled();
  fireEvent.change(screen.getByLabelText('Number of workers'), { target: { value: '1' } });
  await screen.findByText('machine-1');
  expect(workOrdersApi.assignmentSuggestion).toHaveBeenLastCalledWith('wo-1', undefined, {
    minimum_workers: 1,
    maximum_workers: 1,
  });
  expect(screen.queryByLabelText('Handover reason')).toBeNull();
  fireEvent.click(screen.getByRole('button', { name: 'Manual assign' }));
  expect(screen.getByText('Remove Worker')).toHaveClass('text-danger-fg');
  fireEvent.click(screen.getByRole('button', { name: 'Remove Worker' }));
  expect(screen.getByText(/Selected: 0/)).toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Auto assign' }));
  await screen.findByText(/Selected: 1/);
  expect(screen.queryByRole('combobox', { name: 'Add worker' })).toBeNull();
  fireEvent.click(screen.getByRole('button', { name: 'Confirm work order' }));
  await waitFor(() =>
    expect(workOrdersApi.applyAssignment).toHaveBeenCalledWith(
      'wo-1',
      expect.objectContaining({
        reason: 'Initial automatic crew assignment',
        employee_ids: ['a'],
        minimum_workers: 1,
        maximum_workers: 1,
      }),
    ),
  );
});

it('shows unavailable Production workers with reasons and keeps them disabled', async () => {
  const unavailable = {
    ...members[0],
    id: 'off-shift',
    employee_number: '9',
    name: 'Night worker',
    can_assign: false,
    blocked_reason: 'HR shift does not cover this production slot.',
  };
  vi.mocked(workOrdersApi.assignmentSuggestion).mockResolvedValue({
    schedule: {
      id: 'slot-1',
      scheduled_start: '2026-10-05T08:00:00Z',
      scheduled_end: '2026-10-05T10:00:00Z',
      updated_at: '2026-10-05T07:00:00Z',
      machine_id: 'machine-1',
      mold_id: 'mold-1',
    },
    members,
    recorder_employee_id: 'a',
    minimum_workers: 1,
    maximum_workers: 1,
    expected_crew_id: 'crew-1',
    blockers: [],
    equipment_blockers: [],
    worker_options: [...members, unavailable],
  });
  show();
  fireEvent.click(await screen.findByRole('button', { name: 'Suggest assignment' }));
  await screen.findByText('machine-1');
  fireEvent.click(screen.getByRole('button', { name: 'Manual assign' }));
  fireEvent.focus(screen.getByRole('combobox', { name: 'Add worker' }));
  expect(
    await screen.findByRole('option', { name: /Night worker.*HR shift does not cover/ }),
  ).toBeDisabled();
});

it('explains a day after production due without generating a misleading equipment failure', async () => {
  const today = scheduleDateKey(new Date().toISOString(), 'Asia/Manila');
  const tomorrow = offsetScheduleDate(today, 1);
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <AssignmentReview
        woId="wo-1"
        context={{
          ...context,
          minimum_workers: 1,
          preferred_production_date: tomorrow,
          production_deadline: `${today}T23:59:00+08:00`,
        }}
        confirm
        generate
        onClose={vi.fn()}
      />
    </QueryClientProvider>,
  );
  expect(await screen.findByText(/Choose a day before production due/)).toBeVisible();
  expect(screen.queryByText(/No equipment slot available/)).toBeNull();
  expect(workOrdersApi.generateAssignmentSuggestion).not.toHaveBeenCalled();
  expect(screen.getByRole('button', { name: 'Confirm work order' })).toBeDisabled();
});

it('shows one date warning and hides cached assignment messages until the date is valid', async () => {
  const today = scheduleDateKey(new Date().toISOString(), 'Asia/Manila');
  const day = offsetScheduleDate(today, 1);
  const deadline = `${offsetScheduleDate(today, 3)}T00:00:00+08:00`;
  const warning = 'Choose a day before production due.';
  vi.mocked(workOrdersApi.generateAssignmentSuggestion).mockResolvedValue({
    schedule: null,
    members: [],
    recorder_employee_id: null,
    minimum_workers: 1,
    maximum_workers: 1,
    expected_crew_id: null,
    blockers: [warning],
    equipment_blockers: [warning],
    readiness_blockers: [
      {
        code: 'quality',
        message: 'QC: add a PASS requirement for Surface Finish.',
        owner: 'Quality',
      },
    ],
  });
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <AssignmentReview
        woId="wo-cached"
        context={{
          ...context,
          current: null,
          preferred_production_date: day,
          production_deadline: deadline,
        }}
        confirm
        generate
        onClose={vi.fn()}
      />
    </QueryClientProvider>,
  );
  fireEvent.change(screen.getByLabelText('Number of workers'), { target: { value: '1' } });
  await screen.findByText(warning);
  const calls = vi.mocked(workOrdersApi.generateAssignmentSuggestion).mock.calls.length;
  fireEvent.change(screen.getByLabelText('Production day'), {
    target: { value: offsetScheduleDate(today, 5) },
  });
  expect(screen.getAllByText(/Choose a day before production due/)).toHaveLength(1);
  expect(screen.queryByText(/QC: add a PASS requirement/)).toBeNull();
  expect(screen.queryByText(/Confirms equipment and workers/)).toBeNull();
  expect(screen.queryByLabelText('Number of workers')).toBeNull();
  expect(screen.getByRole('button', { name: 'Confirm work order' })).toBeDisabled();
  await new Promise((resolve) => setTimeout(resolve, 400));
  expect(workOrdersApi.generateAssignmentSuggestion).toHaveBeenCalledTimes(calls);
  fireEvent.change(screen.getByLabelText('Production day'), { target: { value: day } });
  expect(screen.getByLabelText('Number of workers')).toHaveValue('1');
});

it('chooses production day before confirmation and regenerates on day changes', async () => {
  /* Derived from the Manila clock: the review accepts today or later only, so a
     hardcoded day goes red the day after it is written. */
  const today = scheduleDateKey(new Date().toISOString(), 'Asia/Manila');
  const day = offsetScheduleDate(today, 1);
  const nextDay = offsetScheduleDate(today, 2);
  const proposal = {
    schedule: {
      id: 'slot-day',
      scheduled_start: '2026-10-10T08:00:00+08:00',
      scheduled_end: '2026-10-10T10:00:00+08:00',
      updated_at: '2026-10-08T08:00:00Z',
      machine_id: 'machine',
      mold_id: 'mold',
    },
    members,
    recorder_employee_id: 'a',
    minimum_workers: 1,
    maximum_workers: 1,
    expected_crew_id: null,
    blockers: [],
    equipment_blockers: [],
  };
  vi.mocked(workOrdersApi.generateAssignmentSuggestion).mockResolvedValue(proposal);
  vi.mocked(workOrdersApi.assignmentSuggestion).mockResolvedValue(proposal);
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <AssignmentReview
        woId="wo-day"
        context={{ ...context, current: null, preferred_production_date: day }}
        generate
        confirm
        onClose={vi.fn()}
      />
    </QueryClientProvider>,
  );
  fireEvent.change(screen.getByLabelText('Number of workers'), { target: { value: '1' } });
  fireEvent.change(screen.getByLabelText('Production day'), { target: { value: nextDay } });
  await waitFor(() =>
    expect(workOrdersApi.generateAssignmentSuggestion).toHaveBeenCalledWith(
      'wo-day',
      expect.objectContaining({ preferred_production_date: nextDay, minimum_workers: 1 }),
    ),
  );
  const count = vi.mocked(workOrdersApi.generateAssignmentSuggestion).mock.calls.length;
  fireEvent(window, new Event('focus'));
  expect(workOrdersApi.generateAssignmentSuggestion).toHaveBeenCalledTimes(count);
});

it('keeps manual recorder across day changes and blocks unavailable selected workers', async () => {
  const today = scheduleDateKey(new Date().toISOString(), 'Asia/Manila');
  const day = offsetScheduleDate(today, 1);
  const nextDay = offsetScheduleDate(today, 2);
  const proposal = {
    schedule: {
      id: 'manual-day',
      scheduled_start: '2026-10-10T08:00:00+08:00',
      scheduled_end: '2026-10-10T10:00:00+08:00',
      updated_at: '2026-10-08T08:00:00Z',
      machine_id: 'machine',
      mold_id: 'mold',
    },
    members,
    worker_options: members,
    recorder_employee_id: 'a',
    minimum_workers: 1,
    maximum_workers: 1,
    expected_crew_id: null,
    blockers: [],
    equipment_blockers: [],
  };
  vi.mocked(workOrdersApi.generateAssignmentSuggestion)
    .mockResolvedValueOnce(proposal)
    .mockResolvedValueOnce({
      ...proposal,
      members: [],
      recorder_employee_id: null,
      worker_options: [{ ...members[0], can_assign: false, blocked_reason: 'Absent' }],
    });
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <AssignmentReview
        woId="manual-day"
        context={{ ...context, current: null, preferred_production_date: day }}
        generate
        confirm
        onClose={vi.fn()}
      />
    </QueryClientProvider>,
  );
  fireEvent.change(screen.getByLabelText('Number of workers'), { target: { value: '1' } });
  await screen.findByText('machine');
  fireEvent.click(screen.getByRole('button', { name: 'Manual assign' }));
  fireEvent.change(screen.getByLabelText('Production day'), { target: { value: nextDay } });
  await screen.findByText('Selected workers are unavailable for this time. Replace them.');
  expect(screen.getByLabelText('Output recorder')).toHaveValue('a');
  expect(screen.getByRole('button', { name: 'Remove Worker' })).toBeDefined();
  expect(screen.getByRole('button', { name: 'Confirm work order' })).toBeDisabled();
});

it('ignores an older day response and waits for the current proposal', async () => {
  const today = scheduleDateKey(new Date().toISOString(), 'Asia/Manila');
  const nextDay = offsetScheduleDate(today, 2);
  let resolveOld!: (value: import('@/types/production-crew').AssignmentSuggestion) => void;
  let resolveNew!: (value: import('@/types/production-crew').AssignmentSuggestion) => void;
  vi.mocked(workOrdersApi.generateAssignmentSuggestion)
    .mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          resolveOld = resolve;
        }),
    )
    .mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          resolveNew = resolve;
        }),
    );
  const proposal = (machine: string) => ({
    schedule: {
      id: machine,
      /* The editor marks a proposal whose end has passed as expired and disables
         Confirm, so the fixture's window must stay ahead of the real clock. */
      scheduled_start: `${nextDay}T08:00:00+08:00`,
      scheduled_end: `${nextDay}T10:00:00+08:00`,
      updated_at: '2026-10-08T08:00:00Z',
      machine_id: machine,
      mold_id: 'mold',
    },
    members,
    recorder_employee_id: 'a',
    minimum_workers: 1,
    maximum_workers: 1,
    expected_crew_id: null,
    blockers: [],
    equipment_blockers: [],
  });
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <AssignmentReview
        woId="out-of-order"
        context={{ ...context, current: null }}
        generate
        confirm
        onClose={vi.fn()}
      />
    </QueryClientProvider>,
  );
  expect(screen.queryByLabelText('Production day')).toBeNull();
  fireEvent.change(screen.getByLabelText('Number of workers'), { target: { value: '1' } });
  await waitFor(() => expect(resolveOld).toBeDefined());
  fireEvent.click(screen.getByRole('button', { name: 'Choose day' }));
  fireEvent.change(screen.getByLabelText('Production day'), { target: { value: nextDay } });
  await waitFor(() => expect(resolveNew).toBeDefined());
  expect(screen.getByRole('button', { name: 'Confirm work order' })).toBeDisabled();
  resolveNew(proposal('current-machine'));
  await screen.findByText('current-machine');
  resolveOld(proposal('old-machine'));
  await waitFor(() =>
    expect(screen.getByRole('button', { name: 'Confirm work order' })).toBeEnabled(),
  );
  expect(screen.queryByText('old-machine')).toBeNull();
  expect(workOrdersApi.generateAssignmentSuggestion).toHaveBeenCalledTimes(2);
});

it('expires a reviewed proposal without automatic requests and refreshes only on Find next slot', async () => {
  let resolveNext!: (value: import('@/types/production-crew').AssignmentSuggestion) => void;
  const end = new Date(Date.now() + 2000).toISOString();
  const proposal = {
    schedule: {
      id: 'expiry-1',
      machine_id: 'expiry-machine',
      mold_id: 'expiry-mold',
      scheduled_start: new Date(Date.now() - 1000).toISOString(),
      scheduled_end: end,
      updated_at: new Date().toISOString(),
    },
    members,
    worker_options: members,
    recorder_employee_id: 'a',
    minimum_workers: 1,
    maximum_workers: 1,
    expected_crew_id: null,
    blockers: [],
    equipment_blockers: [],
  };
  vi.mocked(workOrdersApi.generateAssignmentSuggestion)
    .mockResolvedValueOnce(proposal)
    .mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          resolveNext = resolve;
        }),
    );
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <AssignmentReview
        woId="expiry-wo"
        context={{ ...context, current: null }}
        generate
        confirm
        onClose={vi.fn()}
      />
    </QueryClientProvider>,
  );
  fireEvent.change(screen.getByLabelText('Number of workers'), { target: { value: '1' } });
  await screen.findByText('expiry-machine');
  fireEvent.click(screen.getByRole('button', { name: 'Manual assign' }));
  expect(screen.getByLabelText('Output recorder')).toHaveValue('a');
  await screen.findByText('Proposal passed. Find a new slot.', {}, { timeout: 4000 });
  expect(workOrdersApi.generateAssignmentSuggestion).toHaveBeenCalledTimes(1);
  expect(screen.getByRole('button', { name: 'Confirm work order' })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Find next slot' }));
  await waitFor(() => expect(workOrdersApi.generateAssignmentSuggestion).toHaveBeenCalledTimes(2));
  expect(workOrdersApi.generateAssignmentSuggestion).toHaveBeenNthCalledWith(2, 'expiry-wo', {
    minimum_workers: 1,
    maximum_workers: 1,
    preferred_production_date: null,
  });
  expect(screen.getByRole('button', { name: 'Find next slot' })).toBeDisabled();
  expect(screen.getByRole('button', { name: 'Confirm work order' })).toBeDisabled();
  resolveNext({
    ...proposal,
    schedule: {
      ...proposal.schedule,
      id: 'expiry-2',
      scheduled_end: new Date(Date.now() + 3600000).toISOString(),
    },
    members: [],
    recorder_employee_id: null,
    worker_options: [{ ...members[0], can_assign: false, blocked_reason: 'Absent' }],
  });
  await screen.findByText('Selected workers are unavailable for this time. Replace them.');
  expect(screen.getByRole('button', { name: 'Confirm work order' })).toBeDisabled();
  expect(screen.queryByText('Proposal passed. Find a new slot.')).toBeNull();
  expect(screen.getByLabelText('Output recorder')).toHaveValue('a');
  expect(screen.getByRole('button', { name: 'Remove Worker' })).toBeVisible();
  expect(workOrdersApi.applyAssignment).not.toHaveBeenCalled();
});

it('offers explicit recovery after confirmation runs out of time and clears the error only on refresh', async () => {
  const { AxiosError, AxiosHeaders } = await import('axios');
  const proposal = {
    schedule: {
      id: 'reject-slot',
      machine_id: 'reject-machine',
      mold_id: 'reject-mold',
      scheduled_start: new Date().toISOString(),
      scheduled_end: new Date(Date.now() + 3600000).toISOString(),
      updated_at: new Date().toISOString(),
    },
    members,
    worker_options: members,
    recorder_employee_id: 'a',
    minimum_workers: 1,
    maximum_workers: 1,
    expected_crew_id: null,
    blockers: [],
    equipment_blockers: [],
  };
  vi.mocked(workOrdersApi.generateAssignmentSuggestion).mockResolvedValue(proposal);
  vi.mocked(workOrdersApi.applyAssignment).mockRejectedValue(
    new AxiosError('Failed', '422', undefined, undefined, {
      data: { message: 'Proposal no longer fits. Find a new slot before confirming.' },
      status: 422,
      statusText: 'Unprocessable',
      headers: {},
      config: { headers: new AxiosHeaders() },
    }),
  );
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <AssignmentReview
        woId="reject-wo"
        context={{ ...context, current: null }}
        generate
        confirm
        onClose={vi.fn()}
      />
    </QueryClientProvider>,
  );
  fireEvent.change(screen.getByLabelText('Number of workers'), { target: { value: '1' } });
  await screen.findByText('reject-machine');
  fireEvent.click(screen.getByRole('button', { name: 'Confirm work order' }));
  await screen.findByText('Proposal no longer fits. Find a new slot before confirming.');
  expect(workOrdersApi.generateAssignmentSuggestion).toHaveBeenCalledTimes(1);
  fireEvent.click(screen.getByRole('button', { name: 'Find next slot' }));
  await waitFor(() => expect(workOrdersApi.generateAssignmentSuggestion).toHaveBeenCalledTimes(2));
  await waitFor(() =>
    expect(screen.getByRole('button', { name: 'Confirm work order' })).not.toBeDisabled(),
  );
  expect(
    screen.queryByText('Proposal no longer fits. Find a new slot before confirming.'),
  ).toBeNull();
  expect(workOrdersApi.applyAssignment).toHaveBeenCalledTimes(1);
});

it('refreshes crew data without regenerating the just-applied equipment proposal', async () => {
  const proposal = {
    schedule: {
      id: 'proposal-slot',
      machine_id: 'proposal-machine',
      mold_id: 'proposal-mold',
      scheduled_start: new Date(Date.now() + 3600000).toISOString(),
      scheduled_end: new Date(Date.now() + 7200000).toISOString(),
      updated_at: new Date().toISOString(),
    },
    members,
    recorder_employee_id: 'a',
    minimum_workers: 1,
    maximum_workers: 1,
    expected_crew_id: null,
    blockers: [],
    equipment_blockers: [],
  };
  vi.mocked(workOrdersApi.generateAssignmentSuggestion).mockResolvedValue(proposal);
  vi.mocked(workOrdersApi.applyAssignment).mockResolvedValue(context);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const invalidate = vi.spyOn(client, 'invalidateQueries');

  render(
    <QueryClientProvider client={client}>
      <AssignmentReview
        woId="wo-1"
        context={{ ...context, current: null }}
        generate
        confirm
        onClose={vi.fn()}
      />
    </QueryClientProvider>,
  );
  fireEvent.change(screen.getByLabelText('Number of workers'), { target: { value: '1' } });
  await screen.findByText('proposal-machine');
  fireEvent.click(screen.getByRole('button', { name: 'Confirm work order' }));

  await waitFor(() => expect(workOrdersApi.applyAssignment).toHaveBeenCalledTimes(1));
  expect(invalidate).toHaveBeenCalledWith({ queryKey: ['production', 'work-orders'] });
  expect(invalidate).toHaveBeenCalledWith({ queryKey: ['production', 'crew', 'wo-1'] });
  expect(invalidate).toHaveBeenCalledWith({ queryKey: ['production', 'dashboard'] });
  expect(invalidate).not.toHaveBeenCalledWith({ queryKey: ['production'] });
});
