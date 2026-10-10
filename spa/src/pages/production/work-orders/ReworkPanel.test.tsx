import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { RepairScheduleForm, ReworkPanel } from './ReworkPanel';
import { workOrdersApi } from '@/api/production/workOrders';
import type { WorkOrderRework, WorkOrderStatus } from '@/types/production';

vi.mock('@/api/production/workOrders', () => ({
  workOrdersApi: {
    crew: vi.fn().mockResolvedValue({
      current: { id: 'crew-1', members: [], recorder_employee_id: 'worker-1' },
      schedule: {
        id: 'slot-1',
        scheduled_start: '2026-10-06T08:00:00Z',
        scheduled_end: '2099-10-06T12:00:00Z',
        updated_at: '2026-10-06T08:00:00Z',
        machine_id: null,
        mold_id: null,
      },
      history: [],
      minimum_workers: 1,
      maximum_workers: null,
      can_assign: false,
    }),
    startRework: vi.fn().mockResolvedValue({}),
    recordRework: vi.fn().mockResolvedValue({}),
    myOutputReceipt: vi.fn(),
    finishRework: vi.fn().mockResolvedValue({}),
    repairSuggestion: vi.fn(),
    reviewSlot: vi.fn().mockResolvedValue({}),
    formOptions: vi.fn().mockResolvedValue({
      machines: [
        { id: 'machine-1', machine_code: 'M-01', name: 'Press 1', tonnage: 100, status: 'idle' },
      ],
      molds: [
        {
          id: 'mold-1',
          mold_code: 'MD-01',
          name: 'Mold 1',
          product: null,
          cavity_count: 1,
          status: 'available',
        },
      ],
      products: [],
      shifts: [],
    }),
  },
}));

vi.mock('@/api/client', () => ({
  client: {
    get: vi.fn().mockResolvedValue({
      data: {
        data: [{ id: 'defect-1', code: 'CRK', name: 'Crack', description: null, is_active: true }],
      },
    }),
  },
}));

const rework: WorkOrderRework = {
  id: 'ncr-1',
  ncr_number: 'NCR-001',
  defect_description: 'One cracked piece.',
  status: 'in_progress',
  quantity: 1,
  recorded_quantity: 0,
  remaining_quantity: 1,
  started_at: null,
  started_by: null,
  finished_at: null,
  finished_by: null,
  inspections: [],
};

function show({
  repair = rework,
  canLifecycle = true,
  canRecord = true,
  canViewQc = true,
  canViewNcr = true,
  workOrderStatus = 'completed',
  onClose = () => undefined,
}: {
  repair?: WorkOrderRework;
  canLifecycle?: boolean;
  canRecord?: boolean;
  canViewQc?: boolean;
  canViewNcr?: boolean;
  workOrderStatus?: WorkOrderStatus;
  onClose?: () => void;
} = {}) {
  cleanup();
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MemoryRouter>
        <ReworkPanel
          woId="wo-1"
          woNumber="WO-001"
          isOpen
          onClose={onClose}
          workOrderStatus={workOrderStatus}
          rework={repair}
          canLifecycle={canLifecycle}
          canRecord={canRecord}
          canViewQc={canViewQc}
          canViewNcr={canViewNcr}
        />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('ReworkPanel', () => {
  beforeEach(() => vi.clearAllMocks());

  it('makes the assigned recorder responsible and keeps manager recording behind an explicit action', async () => {
    const context = await workOrdersApi.crew('wo-1', 'ncr-1');
    vi.mocked(workOrdersApi.crew).mockResolvedValueOnce({
      ...context,
      current: {
        ...context.current!,
        members: [
          {
            id: 'worker-1',
            employee_number: 'EMP-001',
            name: 'Carlo Torres',
            has_active_account: true,
            shift: null,
          },
        ],
      },
    });
    show({ repair: { ...rework, started_at: '2026-10-03T12:00:00Z' } });
    expect(
      await screen.findByText('Carlo Torres records repaired output in My Work Orders.'),
    ).toBeVisible();
    expect(screen.queryByLabelText('Repaired pieces')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Record on behalf' }));
    expect(screen.getByLabelText('Repaired pieces')).toBeVisible();
    expect(screen.getByLabelText('Reason for recording on behalf')).toBeRequired();
    expect(screen.getByRole('button', { name: 'Record rework output' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Cancel recording' }));
    expect(screen.queryByLabelText('Repaired pieces')).not.toBeInTheDocument();
    expect(workOrdersApi.recordRework).not.toHaveBeenCalled();
  });

  it('does not offer manager recording without output permission', () => {
    show({ repair: { ...rework, started_at: '2026-10-03T12:00:00Z' }, canRecord: false });
    expect(screen.queryByRole('button', { name: 'Record on behalf' })).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Repaired pieces')).not.toBeInTheDocument();
  });

  it('keeps auto scheduling and worker assignment without the separate slot review button', async () => {
    const context = await workOrdersApi.crew('wo-1', 'ncr-1');
    vi.mocked(workOrdersApi.crew).mockResolvedValueOnce({
      ...context,
      can_assign: true,
      can_review_slot: true,
    });
    show();
    expect(
      await screen.findByRole('button', { name: 'Auto schedule and suggest resources' }),
    ).toBeVisible();
    expect(screen.getByRole('button', { name: 'Assign workers' })).toBeVisible();
    expect(
      screen.queryByRole('button', { name: 'Review production slot' }),
    ).not.toBeInTheDocument();
  });

  it('blocks an early start and shows the scheduled time even after presence confirmation', async () => {
    const context = await workOrdersApi.crew('wo-1', 'ncr-1');
    vi.mocked(workOrdersApi.crew).mockResolvedValueOnce({
      ...context,
      server_time: '2026-10-09T09:00:00+08:00',
      schedule: {
        ...context.schedule!,
        scheduled_start: '2026-10-09T11:14:00+08:00',
        scheduled_end: '2026-10-09T13:14:00+08:00',
      },
      presence: [
        {
          employee_id: 'worker-1',
          name: 'Recorder',
          state: 'unknown',
          reason: null,
          clocked_in: false,
          time_in: null,
          work_date: '2026-10-09',
        },
      ],
    });
    show();
    fireEvent.click(await screen.findByLabelText('Confirm repair workers are physically present'));
    expect(screen.getByRole('button', { name: 'Start rework' })).toBeDisabled();
    expect(screen.getByText(/Rework starts at .*11:14 AM/)).toBeVisible();
    expect(workOrdersApi.startRework).not.toHaveBeenCalled();
  });

  it('uses server time to block an expired slot and directs users to auto schedule', async () => {
    const context = await workOrdersApi.crew('wo-1', 'ncr-1');
    vi.mocked(workOrdersApi.crew).mockResolvedValueOnce({
      ...context,
      server_time: '2099-10-06T12:00:00Z',
    });
    show();
    expect(await screen.findByText('Repair slot expired. Auto schedule a new slot.')).toBeVisible();
    expect(screen.getByRole('button', { name: 'Start rework' })).toBeDisabled();
    expect(workOrdersApi.startRework).not.toHaveBeenCalled();
  });

  it('keeps start in the footer and requires physical presence confirmation', async () => {
    const context = await workOrdersApi.crew('wo-1', 'ncr-1');
    vi.mocked(workOrdersApi.crew).mockResolvedValueOnce({
      ...context,
      presence: [
        {
          employee_id: 'worker-1',
          name: 'Recorder',
          state: 'unknown',
          reason: 'Attendance unverified',
          clocked_in: false,
          time_in: null,
          work_date: '2026-10-06',
        },
      ],
    });
    show();
    const checkbox = await screen.findByLabelText('Confirm repair workers are physically present');
    const start = screen.getByRole('button', { name: 'Start rework' });
    expect(start).toBeDisabled();
    expect(start.parentElement).toContainElement(screen.getByRole('button', { name: /^Close$/ }));
    fireEvent.click(checkbox);
    expect(start).not.toBeDisabled();
    fireEvent.click(start);
    await waitFor(() =>
      expect(workOrdersApi.startRework).toHaveBeenCalledWith(
        'wo-1',
        'ncr-1',
        undefined,
        undefined,
        expect.objectContaining({ presence_confirmed_employee_ids: ['worker-1'] }),
      ),
    );
  });

  it('shows who started and finished repair with timestamps', () => {
    show({
      repair: {
        ...rework,
        started_at: '2026-10-03T12:00:00Z',
        started_by: { id: 'production-1', name: 'Ada Chen' },
        finished_at: '2026-10-03T12:15:00Z',
        finished_by: { id: 'production-2', name: 'Sam Diaz' },
      },
    });

    expect(screen.getByText(/Started by Ada Chen/)).toBeVisible();
    expect(screen.getByText(/Finished by Sam Diaz/)).toBeVisible();
  });

  it('starts manual repair inside the existing work order', async () => {
    show();
    fireEvent.click(screen.getByRole('button', { name: 'Start rework' }));
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Start rework' })).not.toBeDisabled(),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Start rework' }));
    await waitFor(() =>
      expect(workOrdersApi.startRework).toHaveBeenCalledWith(
        'wo-1',
        'ncr-1',
        undefined,
        undefined,
        expect.objectContaining({
          expected_crew_id: expect.any(String),
          presence_confirmed_employee_ids: [],
        }),
      ),
    );
  });

  it('blocks repair start until an approved NCR slot exists', async () => {
    vi.mocked(workOrdersApi.crew).mockResolvedValueOnce({
      current: {
        id: 'crew-1',
        members: [],
        recorder_employee_id: 'worker-1',
        reason: '',
        assigned_at: '',
        ended_at: null,
        rework_ncr_id: 'ncr-1',
      },
      history: [],
      minimum_workers: 1,
      maximum_workers: null,
      can_assign: false,
    });
    show();
    expect(screen.getByRole('button', { name: 'Start rework' })).toBeDisabled();
    expect(await screen.findByText(/Approved slot missing or expired/)).toBeVisible();
    expect(workOrdersApi.startRework).not.toHaveBeenCalled();
  });

  it('records repaired quantities through the rework endpoint', async () => {
    show({ repair: { ...rework, started_at: '2026-10-03T12:00:00Z' } });
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Record on behalf' })).not.toBeDisabled(),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Record on behalf' }));
    fireEvent.change(screen.getByLabelText('Repaired pieces'), { target: { value: '1' } });
    fireEvent.change(screen.getByLabelText('Reason for recording on behalf'), {
      target: { value: 'Manager recording' },
    });
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Record rework output' })).not.toBeDisabled(),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Record rework output' }));
    await waitFor(() =>
      expect(workOrdersApi.recordRework).toHaveBeenCalledWith(
        'wo-1',
        'ncr-1',
        {
          crew_id: 'crew-1',
          recording_reason: 'Manager recording',
          good_count: 1,
          reject_count: 0,
          defects: [],
        },
        expect.any(String),
      ),
    );
  });

  it('requires a defect type when recording rejected repair pieces', async () => {
    show({ repair: { ...rework, started_at: '2026-10-03T12:00:00Z' } });
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Record on behalf' })).not.toBeDisabled(),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Record on behalf' }));
    fireEvent.change(screen.getByLabelText('Repaired pieces'), { target: { value: '0' } });
    fireEvent.change(screen.getByLabelText('Repaired pieces'), { target: { value: '0' } });
    fireEvent.change(screen.getByLabelText('Unrepairable pieces'), { target: { value: '1' } });
    expect(screen.getByRole('button', { name: 'Record rework output' })).toBeDisabled();
    expect(screen.getByLabelText('Defect type')).toBeVisible();
    expect(workOrdersApi.recordRework).not.toHaveBeenCalled();
  });

  it('shows an inline error and does not submit more than the remaining repair quantity', async () => {
    show({ repair: { ...rework, started_at: '2026-10-03T12:00:00Z' } });
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Record on behalf' })).not.toBeDisabled(),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Record on behalf' }));
    fireEvent.change(screen.getByLabelText('Repaired pieces'), { target: { value: '2' } });
    fireEvent.click(screen.getByRole('button', { name: 'Record rework output' }));
    expect(await screen.findByText('Total cannot exceed 1 remaining piece.')).toBeVisible();
    expect(workOrdersApi.recordRework).not.toHaveBeenCalled();
  });

  it('finishes only a complete repair after explicit confirmation', async () => {
    show({
      repair: {
        ...rework,
        recorded_quantity: 1,
        remaining_quantity: 0,
        started_at: '2026-10-03T12:00:00Z',
      },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Finish rework' }));
    const dialog = screen.getByRole('dialog', { name: 'Finish rework?' });
    expect(within(dialog).getByText(/cannot be reopened/)).toBeVisible();
    fireEvent.click(within(dialog).getByRole('button', { name: 'Finish rework' }));
    await waitFor(() => expect(workOrdersApi.finishRework).toHaveBeenCalledWith('wo-1', 'ncr-1'));
  });

  it('shows defect and quantity without production actions for read-only roles', () => {
    show({ canLifecycle: false, canRecord: false });
    expect(screen.getByText('One cracked piece.')).toBeInTheDocument();
    expect(
      screen.getByText('Repair actions require the production lifecycle permission.'),
    ).toBeVisible();
    expect(screen.queryByRole('button', { name: 'Start rework' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Record rework output' })).not.toBeInTheDocument();
  });

  it('links fresh inspection records only to inspection viewers', () => {
    const repair = {
      ...rework,
      started_at: '2026-10-03T12:00:00Z',
      finished_at: '2026-10-03T13:00:00Z',
      inspections: [{ id: 'inspection-1', inspection_number: 'QC-001', status: 'awaiting_review' }],
    };
    show({ repair, canViewNcr: false });
    expect(screen.getByRole('link', { name: /QC-001/ })).toHaveAttribute(
      'href',
      '/quality/inspections/inspection-1',
    );
    expect(screen.queryByRole('link', { name: 'NCR-001' })).not.toBeInTheDocument();
    cleanup();
    show({ repair, canViewQc: false });
    expect(screen.queryByRole('link', { name: 'QC-001' })).not.toBeInTheDocument();
    expect(screen.getByText('QC-001')).toBeVisible();
  });

  it('does not start repair until original production is complete', () => {
    show({ workOrderStatus: 'in_progress' });
    expect(screen.queryByRole('button', { name: 'Start rework' })).not.toBeInTheDocument();
    expect(screen.getByText('Finish original production before starting repair.')).toBeVisible();
  });

  it('locks repair counts and retries exact payload and key after a lost response', async () => {
    vi.mocked(workOrdersApi.recordRework).mockRejectedValueOnce(new AxiosError('Network Error'));
    const close = vi.fn();
    show({ repair: { ...rework, started_at: '2026-10-03T12:00:00Z' }, onClose: close });
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Record on behalf' })).not.toBeDisabled(),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Record on behalf' }));
    fireEvent.change(screen.getByLabelText('Reason for recording on behalf'), {
      target: { value: 'Manager recording' },
    });
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Record rework output' })).not.toBeDisabled(),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Record rework output' }));
    await screen.findByText(/Recording outcome unknown/);
    expect(screen.getByLabelText('Repaired pieces')).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Close' })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Close modal' }));
    expect(close).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Retry same recording' }));
    await waitFor(() => expect(workOrdersApi.recordRework).toHaveBeenCalledTimes(2));
    expect(vi.mocked(workOrdersApi.recordRework).mock.calls[1]).toEqual(
      vi.mocked(workOrdersApi.recordRework).mock.calls[0],
    );
    await waitFor(() => expect(screen.getByRole('button', { name: 'Close' })).not.toBeDisabled());
    fireEvent.click(screen.getByRole('button', { name: 'Close modal' }));
    expect(close).toHaveBeenCalledOnce();
  });
});

describe('automatic repair scheduling', () => {
  beforeEach(() => vi.clearAllMocks());
  const proposal = {
    repair_quantity: 2,
    scheduled_start: '2099-10-09T08:00:00+08:00',
    scheduled_end: '2099-10-09T10:00:00+08:00',
    expected_schedule_id: null,
    expected_schedule_updated_at: null,
    expected_crew_id: null,
    machine_id: 'machine-1',
    mold_id: 'mold-1',
    machine: { machine_code: 'IMM-03', name: 'Press' },
    mold: { mold_code: 'M-PC-002', name: 'Mold' },
    members: [
      {
        id: 'worker-1',
        name: 'Recorder Worker',
        employee_number: '1',
        has_active_account: true,
        shift: null,
      },
    ],
    recorder_employee_id: 'worker-1',
    blockers: [],
  };
  function showSchedule(saved = vi.fn()) {
    cleanup();
    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <RepairScheduleForm
          woId="wo-1"
          ncrId="ncr-1"
          context={{
            current: null,
            history: [],
            minimum_workers: 1,
            maximum_workers: 4,
            can_assign: true,
          }}
          onSaved={saved}
        />
      </QueryClientProvider>,
    );
    return saved;
  }
  it('previews equipment and crew, then approves both for the NCR without starting repair', async () => {
    vi.mocked(workOrdersApi.repairSuggestion).mockResolvedValue(proposal);
    const saved = showSchedule();
    expect(await screen.findByText('IMM-03 / M-PC-002')).toBeVisible();
    expect(screen.getByText(/Recorder Worker/)).toBeVisible();
    expect(workOrdersApi.reviewSlot).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Approve repair schedule' }));
    await waitFor(() =>
      expect(workOrdersApi.reviewSlot).toHaveBeenCalledWith(
        'wo-1',
        expect.objectContaining({
          machine_id: 'machine-1',
          mold_id: 'mold-1',
          employee_ids: ['worker-1'],
          expected_crew_id: null,
        }),
        'ncr-1',
      ),
    );
    expect(saved).toHaveBeenCalledOnce();
    expect(workOrdersApi.startRework).not.toHaveBeenCalled();
  });
  it('blocks unavailable proposals and keeps manual repair free of equipment', async () => {
    vi.mocked(workOrdersApi.repairSuggestion)
      .mockResolvedValueOnce({ ...proposal, blockers: ['No eligible repair crew.'] })
      .mockResolvedValue({
        ...proposal,
        machine_id: null,
        mold_id: null,
        machine: null,
        mold: null,
      });
    showSchedule();
    expect(await screen.findByText('No eligible repair crew.')).toBeVisible();
    expect(screen.getByRole('button', { name: 'Approve repair schedule' })).toBeDisabled();
    fireEvent.change(screen.getByLabelText('Repair method'), { target: { value: 'manual' } });
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Approve repair schedule' })).not.toBeDisabled(),
    );
    expect(workOrdersApi.repairSuggestion).toHaveBeenLastCalledWith(
      'wo-1',
      'ncr-1',
      expect.objectContaining({ manual_repair: true }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Approve repair schedule' }));
    await waitFor(() => expect(workOrdersApi.reviewSlot).toHaveBeenCalled());
    expect(vi.mocked(workOrdersApi.reviewSlot).mock.calls.at(-1)?.[1]).not.toHaveProperty(
      'machine_id',
    );
  });
});
