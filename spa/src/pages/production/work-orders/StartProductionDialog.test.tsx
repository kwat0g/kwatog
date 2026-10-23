import { afterEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { StartProductionDialog } from './StartProductionDialog';
import { workOrdersApi } from '@/api/production/workOrders';
import { offsetScheduleDate, scheduleDateKey } from '@/components/production/scheduleDates';

vi.mock('@/api/production/workOrders', () => ({
  workOrdersApi: {
    executionReadiness: vi.fn(),
    rescheduleSuggestion: vi.fn(),
    reviewSlot: vi.fn(),
    crew: vi.fn(),
    crewOptions: vi.fn(),
    assignCrew: vi.fn(),
    start: vi.fn(),
    resume: vi.fn(),
    extendRun: vi.fn(),
  },
}));
afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.resetAllMocks();
});
const readiness = {
  ready: true,
  needs_reschedule: false,
  can_review_slot: true,
  blockers: [],
  expected_crew_id: 'crew',
  schedule: null,
  presence: [],
};
function show(action: 'start' | 'extend' = 'start') {
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <StartProductionDialog woId="wo" woNumber="WO-1" action={action} onClose={vi.fn()} />
    </QueryClientProvider>,
  );
}
it('checks readiness first and requires physical-presence confirmation before starting', async () => {
  vi.mocked(workOrdersApi.executionReadiness).mockResolvedValue({
    ...readiness,
    presence: [
      {
        state: 'unknown',
        employee_id: 'worker',
        name: 'Operator',
        reason: null,
        work_date: '2026-10-06',
      },
    ],
  });
  show();
  const start = await screen.findByRole('button', { name: 'Start production' });
  expect(workOrdersApi.start).not.toHaveBeenCalled();
  expect(start.hasAttribute('disabled')).toBe(true);
  fireEvent.click(screen.getByRole('checkbox'));
  fireEvent.click(start);
  await waitFor(() =>
    expect(workOrdersApi.start).toHaveBeenCalledWith('wo', {
      expected_crew_id: 'crew',
      presence_confirmed_employee_ids: ['worker'],
    }),
  );
});
it('refreshes unchanged readiness without clearing physical-presence confirmation', async () => {
  vi.mocked(workOrdersApi.executionReadiness).mockResolvedValue({
    ...readiness,
    presence: [
      {
        state: 'unknown',
        employee_id: 'worker',
        name: 'Operator',
        reason: null,
        work_date: '2026-10-06',
      },
    ],
  });
  vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] });
  show();
  const checkbox = await screen.findByRole('checkbox');
  fireEvent.click(checkbox);
  expect(screen.getByRole('button', { name: 'Start production' }).hasAttribute('disabled')).toBe(
    false,
  );
  await act(async () => {
    await vi.advanceTimersByTimeAsync(30_000);
  });
  await waitFor(() => expect(workOrdersApi.executionReadiness).toHaveBeenCalledTimes(2));
  await waitFor(() => expect((checkbox as HTMLInputElement).checked).toBe(true));
  expect(screen.getByRole('button', { name: 'Start production' }).hasAttribute('disabled')).toBe(
    false,
  );
  expect(workOrdersApi.start).not.toHaveBeenCalled();
});
it('approves suggested timing and crew without starting automatically, then checks again', async () => {
  vi.mocked(workOrdersApi.executionReadiness)
    .mockResolvedValueOnce({
      ...readiness,
      ready: false,
      needs_reschedule: true,
      blockers: [{ code: 'schedule', message: 'Outside approved slot.' }],
    })
    .mockResolvedValue(readiness);
  vi.mocked(workOrdersApi.rescheduleSuggestion).mockResolvedValue({
    scheduled_start: '2026-10-06T09:05:00+08:00',
    scheduled_end: '2026-10-06T10:05:00+08:00',
    expected_schedule_id: 'slot',
    expected_schedule_updated_at: 'version',
    expected_crew_id: 'crew',
    recorder_employee_id: 'worker',
    members: [
      {
        id: 'worker',
        name: 'Operator',
        employee_number: '1',
        has_active_account: true,
        shift: null,
      },
    ],
    blockers: [],
  });
  show();
  fireEvent.click(await screen.findByRole('button', { name: 'Reschedule' }));
  fireEvent.click(await screen.findByRole('button', { name: 'Approve new schedule' }));
  await waitFor(() =>
    expect(workOrdersApi.reviewSlot).toHaveBeenCalledWith(
      'wo',
      expect.objectContaining({
        expected_schedule_id: 'slot',
        expected_schedule_updated_at: 'version',
        expected_crew_id: 'crew',
        employee_ids: ['worker'],
      }),
    ),
  );
  expect(workOrdersApi.start).not.toHaveBeenCalled();
  expect(await screen.findByRole('button', { name: 'Start production' })).toBeDefined();
  expect(workOrdersApi.executionReadiness).toHaveBeenCalledTimes(2);
});
it('fails closed and offers retry when readiness cannot load', async () => {
  vi.mocked(workOrdersApi.executionReadiness).mockRejectedValue(new Error('Network failure'));
  show();
  expect(await screen.findByRole('button', { name: 'Retry checks' })).toBeDefined();
  expect(screen.queryByRole('button', { name: 'Start production' })).toBeNull();
});

it('shows missing specification instructions before presence confirmation or start', async () => {
  const message =
    'QC: add a PASS requirement for "Surface Finish" in the inspection specification.';
  vi.mocked(workOrdersApi.executionReadiness).mockResolvedValue({
    ...readiness,
    ready: false,
    blockers: [{ code: 'quality', message }],
    presence: [
      {
        state: 'unknown',
        employee_id: 'worker',
        name: 'Operator',
        reason: null,
        work_date: '2026-10-08',
      },
    ],
  });
  show();
  expect(await screen.findByText(message)).toBeVisible();
  expect(screen.queryByRole('button', { name: 'Start production' })).toBeNull();
  expect(screen.queryByRole('checkbox')).toBeNull();
  expect(workOrdersApi.start).not.toHaveBeenCalled();
});

it('shows one attendance warning and a short recovery instruction while keeping other blockers', async () => {
  const attendance = 'Early start needs verified attendance: Grace Aquino, Angelica Salazar.';
  const crew = 'Crew does not cover this time. Check HR shifts, leave, or other assignments.';
  vi.mocked(workOrdersApi.executionReadiness).mockResolvedValue({
    ...readiness,
    ready: false,
    start_mode: 'early',
    blockers: [
      { code: 'presence', message: attendance },
      { code: 'crew', message: crew },
    ],
  });
  show();
  expect(await screen.findAllByText(attendance, { exact: true })).toHaveLength(1);
  expect(screen.getByText(crew, { exact: true })).toBeVisible();
  expect(screen.getByRole('button', { name: 'Refresh workers' })).toBeVisible();
  expect(screen.queryByRole('button', { name: 'Start production' })).toBeNull();
  expect(screen.queryByRole('checkbox')).toBeNull();
});

it('requires explicit approval of a late suggestion and clears approval when preferred day changes', async () => {
  /* Manila-relative days: the dialog accepts today or later, so a fixed date
     turns this red once the calendar passes it (same class as CrewPanel.test.tsx). */
  const today = scheduleDateKey(new Date().toISOString(), 'Asia/Manila');
  const nextDay = offsetScheduleDate(today, 1);
  vi.mocked(workOrdersApi.executionReadiness).mockResolvedValue({
    ...readiness,
    ready: false,
    needs_reschedule: true,
    production_deadline: '2026-10-07T00:00:00+08:00',
    can_approve_late_production: true,
  });
  vi.mocked(workOrdersApi.rescheduleSuggestion).mockResolvedValue({
    scheduled_start: '2099-10-10T09:00:00+08:00',
    scheduled_end: '2099-10-10T10:00:00+08:00',
    expected_schedule_id: 'slot',
    expected_schedule_updated_at: 'version',
    expected_crew_id: 'crew',
    members: [
      {
        id: 'worker',
        name: 'Operator',
        employee_number: '1',
        has_active_account: true,
        shift: null,
      },
    ],
    recorder_employee_id: 'worker',
    blockers: [],
    requires_late_production_approval: true,
  });
  show();
  fireEvent.click(await screen.findByRole('button', { name: 'Reschedule' }));
  const approve = await screen.findByRole('button', { name: 'Approve new schedule' });
  expect(approve).toBeDisabled();
  fireEvent.click(screen.getByLabelText('I approve production past the deadline.'));
  expect(approve).toBeDisabled();
  fireEvent.change(screen.getByLabelText('Delivery impact / reason'), {
    target: { value: 'Delivery must wait for remaining production and QC.' },
  });
  expect(approve).toBeEnabled();
  fireEvent.click(approve);
  await waitFor(() =>
    expect(workOrdersApi.reviewSlot).toHaveBeenCalledWith(
      'wo',
      expect.objectContaining({
        late_production_authorized: true,
        late_production_reason: 'Delivery must wait for remaining production and QC.',
      }),
    ),
  );
  fireEvent.click(await screen.findByRole('button', { name: 'Reschedule' }));
  await screen.findByLabelText('Delivery impact / reason');
  fireEvent.change(screen.getByLabelText('Preferred production day'), {
    target: { value: nextDay },
  });
  await waitFor(() =>
    expect(workOrdersApi.rescheduleSuggestion).toHaveBeenCalledWith('wo', nextDay),
  );
  expect(await screen.findByLabelText('I approve production past the deadline.')).not.toBeChecked();
  expect(screen.getByLabelText('Delivery impact / reason')).toHaveValue('');
  expect(screen.getByRole('button', { name: 'Approve new schedule' })).toBeDisabled();
  expect(workOrdersApi.start).not.toHaveBeenCalled();
});

const futureSchedule = {
  id: 'slot',
  status: 'confirmed',
  updated_at: 'version',
  scheduled_start: '2099-10-07T08:00:00+08:00',
  scheduled_end: '2099-10-07T10:00:00+08:00',
  machine_id: 'machine',
  mold_id: 'mold',
};
it('keeps future approved timing on reopen and loads suggestions only after explicit rescheduling', async () => {
  vi.mocked(workOrdersApi.executionReadiness).mockResolvedValue({
    ...readiness,
    ready: false,
    schedule: futureSchedule,
    blockers: [{ code: 'schedule', message: 'Scheduled for a later time.' }],
  });
  vi.mocked(workOrdersApi.rescheduleSuggestion).mockResolvedValue({
    scheduled_start: null,
    scheduled_end: null,
    expected_schedule_id: 'slot',
    expected_schedule_updated_at: 'version',
    expected_crew_id: 'crew',
    members: [],
    recorder_employee_id: null,
    blockers: ['No capacity on this date.'],
  });
  show();
  await screen.findByRole('button', { name: 'Reschedule' });
  expect(screen.queryByRole('button', { name: 'Check again' })).toBeNull();
  expect(workOrdersApi.executionReadiness).toHaveBeenCalledTimes(1);
  expect(workOrdersApi.rescheduleSuggestion).not.toHaveBeenCalled();
  expect(screen.queryByRole('button', { name: 'Approve new schedule' })).toBeNull();
  expect(screen.queryByLabelText('Preferred production day')).toBeNull();
  expect(screen.queryByRole('button', { name: 'Start production' })).toBeNull();
  fireEvent.click(screen.getByRole('button', { name: 'Reschedule' }));
  const today = scheduleDateKey(new Date().toISOString(), 'Asia/Manila');
  const nextDay = offsetScheduleDate(today, 1);
  await waitFor(() => expect(workOrdersApi.rescheduleSuggestion).toHaveBeenCalledWith('wo', today));
  fireEvent.change(screen.getByLabelText('Preferred production day'), {
    target: { value: nextDay },
  });
  await waitFor(() =>
    expect(workOrdersApi.rescheduleSuggestion).toHaveBeenCalledWith('wo', nextDay),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Keep approved schedule' }));
  expect(screen.queryByLabelText('Preferred production day')).toBeNull();
  expect(workOrdersApi.reviewSlot).not.toHaveBeenCalled();
});
it('labels a later-day alternative and requires explicit approval', async () => {
  const today = scheduleDateKey(new Date().toISOString(), 'Asia/Manila');
  const requested = offsetScheduleDate(today, 1);
  const alternative = offsetScheduleDate(today, 2);
  vi.mocked(workOrdersApi.executionReadiness).mockResolvedValue({
    ...readiness,
    ready: false,
    schedule: futureSchedule,
    blockers: [{ code: 'schedule', message: 'Scheduled for a later time.' }],
  });
  vi.mocked(workOrdersApi.rescheduleSuggestion).mockResolvedValue({
    scheduled_start: `${alternative}T08:00:00+08:00`,
    scheduled_end: `${alternative}T09:00:00+08:00`,
    expected_schedule_id: 'slot',
    expected_schedule_updated_at: 'version',
    expected_crew_id: 'crew',
    members: [
      {
        id: 'worker',
        name: 'Operator',
        employee_number: '1',
        has_active_account: true,
        shift: null,
      },
    ],
    recorder_employee_id: 'worker',
    blockers: [],
    preferred_day_message: 'No eligible crew on the selected day.',
  });
  show();
  fireEvent.click(await screen.findByRole('button', { name: 'Reschedule' }));
  fireEvent.change(screen.getByLabelText('Preferred production day'), {
    target: { value: requested },
  });
  expect(await screen.findByText('Next available schedule · Asia/Manila')).toBeDefined();
  expect(workOrdersApi.reviewSlot).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button', { name: 'Approve new schedule' }));
  await waitFor(() => expect(workOrdersApi.reviewSlot).toHaveBeenCalledTimes(1));
  await waitFor(() => expect(screen.queryByLabelText('Preferred production day')).toBeNull());
  expect(workOrdersApi.start).not.toHaveBeenCalled();
});
it('hides rescheduling from users without scheduling permission', async () => {
  vi.mocked(workOrdersApi.executionReadiness).mockResolvedValue({
    ...readiness,
    can_review_slot: false,
  });
  show();
  await screen.findByRole('button', { name: 'Start production' });
  expect(screen.queryByRole('button', { name: 'Reschedule' })).toBeNull();
  expect(workOrdersApi.rescheduleSuggestion).not.toHaveBeenCalled();
});

it('keeps suggestions stable while reviewing without offering an unusable approved schedule', async () => {
  vi.mocked(workOrdersApi.executionReadiness).mockResolvedValue({
    ...readiness,
    ready: false,
    needs_reschedule: true,
    schedule: futureSchedule,
    blockers: [{ code: 'schedule', message: 'Not enough time remains. Reschedule.' }],
  });
  vi.mocked(workOrdersApi.rescheduleSuggestion).mockResolvedValue({
    scheduled_start: '2026-10-07T08:00:00+08:00',
    scheduled_end: '2026-10-07T10:00:00+08:00',
    expected_schedule_id: 'slot',
    expected_schedule_updated_at: 'version',
    expected_crew_id: 'crew',
    members: [
      {
        id: 'worker',
        name: 'Operator',
        employee_number: '1',
        has_active_account: true,
        shift: null,
      },
    ],
    recorder_employee_id: 'worker',
    blockers: [],
  });
  vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] });
  show();
  fireEvent.click(await screen.findByRole('button', { name: 'Reschedule' }));
  await screen.findByRole('button', { name: 'Approve new schedule' });
  expect(screen.queryByRole('button', { name: 'Keep approved schedule' })).toBeNull();
  await act(async () => {
    await vi.advanceTimersByTimeAsync(60_000);
  });
  await waitFor(() => expect(workOrdersApi.rescheduleSuggestion).toHaveBeenCalledTimes(1));
  expect(workOrdersApi.executionReadiness).toHaveBeenCalledTimes(1);
  expect(screen.getByRole('button', { name: 'Approve new schedule' })).toBeEnabled();
  const day = screen.getByLabelText('Preferred production day') as HTMLInputElement;
  const originalDay = day.value;
  fireEvent.change(day, { target: { value: offsetScheduleDate(originalDay, 1) } });
  await waitFor(() => expect(workOrdersApi.rescheduleSuggestion).toHaveBeenCalledTimes(2));
  fireEvent.change(day, { target: { value: originalDay } });
  await waitFor(() => expect(workOrdersApi.rescheduleSuggestion).toHaveBeenCalledTimes(3));
});

it('requires a late-start acknowledgement and sends the reviewed schedule version', async () => {
  vi.mocked(workOrdersApi.executionReadiness).mockResolvedValue({
    ...readiness,
    start_mode: 'late',
    requires_late_start_acknowledgement: true,
    execution_end: '2026-10-07T13:00:00+08:00',
    schedule: {
      id: 'slot',
      updated_at: '2026-10-07T08:00:00+08:00',
      scheduled_start: '2026-10-07T08:00:00+08:00',
      scheduled_end: '2026-10-07T10:00:00+08:00',
      machine_id: 'machine',
      mold_id: 'mold',
    },
  });
  show();
  const start = await screen.findByRole('button', { name: 'Start production' });
  expect(start.hasAttribute('disabled')).toBe(true);
  fireEvent.click(screen.getByRole('checkbox', { name: /I approve this late start/ }));
  fireEvent.click(start);
  await waitFor(() =>
    expect(workOrdersApi.start).toHaveBeenCalledWith('wo', {
      expected_crew_id: 'crew',
      presence_confirmed_employee_ids: [],
      expected_schedule_id: 'slot',
      expected_schedule_updated_at: '2026-10-07T08:00:00+08:00',
      late_start_authorized: true,
    }),
  );
});

it('extends running production without starting it again', async () => {
  vi.mocked(workOrdersApi.executionReadiness).mockResolvedValue({
    ...readiness,
    start_mode: 'extend',
    execution_end: '2026-10-06T12:00:00+08:00',
    schedule: {
      id: 'slot',
      status: 'confirmed',
      updated_at: '2026-10-06T08:00:00+08:00',
      scheduled_start: '2026-10-06T08:00:00+08:00',
      scheduled_end: '2026-10-06T10:00:00+08:00',
      machine_id: 'machine',
      mold_id: 'mold',
    },
  });
  show('extend');
  fireEvent.click(await screen.findByRole('button', { name: 'Approve extension' }));
  await waitFor(() =>
    expect(workOrdersApi.extendRun).toHaveBeenCalledWith(
      'wo',
      expect.objectContaining({
        expected_crew_id: 'crew',
        expected_schedule_id: 'slot',
        scheduled_end: '2026-10-06T12:00:00+08:00',
        reason: 'Approved remaining production time extension.',
      }),
    ),
  );
  expect(workOrdersApi.start).not.toHaveBeenCalled();
});

it('sends an operator-entered extension reason', async () => {
  vi.mocked(workOrdersApi.executionReadiness).mockResolvedValue({
    ...readiness,
    start_mode: 'extend',
    execution_end: '2026-10-06T12:00:00+08:00',
    schedule: {
      id: 'slot',
      status: 'confirmed',
      updated_at: '2026-10-06T08:00:00+08:00',
      scheduled_start: '2026-10-06T08:00:00+08:00',
      scheduled_end: '2026-10-06T10:00:00+08:00',
      machine_id: 'machine',
      mold_id: 'mold',
    },
  });
  show('extend');
  fireEvent.change(await screen.findByLabelText(/Extension reason/), {
    target: { value: 'Machine slowed after mold change.' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Approve extension' }));
  await waitFor(() =>
    expect(workOrdersApi.extendRun).toHaveBeenCalledWith(
      'wo',
      expect.objectContaining({ reason: 'Machine slowed after mold change.' }),
    ),
  );
});

it('blocks the extension while the reason is blank', async () => {
  vi.mocked(workOrdersApi.executionReadiness).mockResolvedValue({
    ...readiness,
    start_mode: 'extend',
    execution_end: '2026-10-06T12:00:00+08:00',
    schedule: {
      id: 'slot',
      status: 'confirmed',
      updated_at: '2026-10-06T08:00:00+08:00',
      scheduled_start: '2026-10-06T08:00:00+08:00',
      scheduled_end: '2026-10-06T10:00:00+08:00',
      machine_id: 'machine',
      mold_id: 'mold',
    },
  });
  show('extend');
  fireEvent.change(await screen.findByLabelText(/Extension reason/), { target: { value: '   ' } });
  expect(screen.getByRole('button', { name: 'Approve extension' })).toBeDisabled();
});

it('keeps the reviewed extension finish stable while the manager reads it', async () => {
  vi.mocked(workOrdersApi.executionReadiness).mockResolvedValue({
    ...readiness,
    start_mode: 'extend',
    execution_end: '2026-10-06T12:00:00+08:00',
  });
  vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] });
  show('extend');
  await screen.findByRole('button', { name: 'Approve extension' });
  await act(async () => {
    await vi.advanceTimersByTimeAsync(60_000);
  });
  expect(workOrdersApi.executionReadiness).toHaveBeenCalledTimes(1);
  expect(workOrdersApi.extendRun).not.toHaveBeenCalled();
});

it('clears confirmation when attendance changes between refreshes', async () => {
  const unknown = {
    state: 'unknown' as const,
    employee_id: 'worker',
    name: 'Operator',
    reason: null,
    work_date: '2026-10-06',
  };
  vi.mocked(workOrdersApi.executionReadiness)
    .mockResolvedValueOnce({ ...readiness, presence: [unknown] })
    .mockResolvedValue({
      ...readiness,
      presence: [{ ...unknown, reason: 'Attendance record changed.' }],
    });
  vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] });
  show();
  const checkbox = await screen.findByRole('checkbox');
  fireEvent.click(checkbox);
  await act(async () => {
    await vi.advanceTimersByTimeAsync(30_000);
  });
  await waitFor(() => expect(workOrdersApi.executionReadiness).toHaveBeenCalledTimes(2));
  await waitFor(() => expect((checkbox as HTMLInputElement).checked).toBe(false));
  expect(screen.getByRole('button', { name: 'Start production' })).toBeDisabled();
});

it('offers worker recovery and refresh without rescheduling after a shift blocker', async () => {
  vi.mocked(workOrdersApi.executionReadiness).mockResolvedValue({
    ...readiness,
    ready: false,
    can_assign_crew: true,
    blockers: [
      {
        code: 'presence',
        message: 'Operator: Clocked in, but HR shift hours do not cover this time.',
      },
    ],
  });
  vi.mocked(workOrdersApi.crew).mockResolvedValue({
    current: null,
    history: [],
    can_assign: true,
    minimum_workers: 1,
    maximum_workers: 4,
  });
  vi.mocked(workOrdersApi.crewOptions).mockResolvedValue([]);
  show();
  fireEvent.click(await screen.findByRole('button', { name: 'Refresh workers' }));
  await waitFor(() => expect(workOrdersApi.executionReadiness).toHaveBeenCalledTimes(2));
  expect(workOrdersApi.rescheduleSuggestion).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('button', { name: 'Change workers' }));
  expect(await screen.findByRole('button', { name: 'Back to start checks' })).toBeTruthy();
  expect(await screen.findByLabelText('Output recorder')).toBeTruthy();
});
