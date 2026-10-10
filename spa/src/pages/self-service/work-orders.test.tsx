import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { workOrdersApi } from '@/api/production/workOrders';
import MyWorkOrdersPage, { MyWorkOrderDetailPage } from './work-orders';
import { workerCountError } from '@/lib/productionCrewValidation';
import { useAuthStore } from '@/stores/authStore';
vi.mock('@/api/production/workOrders', () => ({
  workOrdersApi: {
    myWorkOrder: vi.fn(),
    myWorkOrders: vi.fn(),
    myWorkOrderHistory: vi.fn(),
    myOutputOptions: vi.fn(),
    myOutputs: vi.fn(),
    recordMyOutput: vi.fn(),
    recordMyTrial: vi.fn(),
    myOutputReceipt: vi.fn(),
    myLateProductionReports: vi.fn(),
  },
}));
beforeEach(() => {
  vi.clearAllMocks();
  sessionStorage.clear();
  localStorage.clear();
});
describe('worker output', () => {
  it.each([true, false])(
    'keeps earlier-production entry hidden during a current run (handover pending: %s)',
    async (handoverPending) => {
      vi.mocked(workOrdersApi.myWorkOrder).mockResolvedValue({
        id: 'wo',
        wo_number: 'WO-Current',
        status: 'in_progress',
        qc_workflow: 'finish_first_v1',
        product: { part_number: 'P', name: 'Part', unit_of_measure: 'pcs' },
        machine: null,
        mold: null,
        quantity_target: 200,
        quantity_good: 0,
        quantity_rejected: 0,
        remaining_good: 200,
        crew: {
          id: 'crew',
          members: [],
          recorder_employee_id: 'worker',
          reason: '',
          assigned_at: '',
          ended_at: null,
          rework_ncr_id: null,
        },
        can_record: !handoverPending,
        can_record_trial: false,
        can_report_late_production: true,
        blocker: handoverPending
          ? {
              code: 'material_handover_pending',
              message:
                'Warehouse handover is pending. Ask Warehouse to confirm before recording output.',
              owner: 'Warehouse',
            }
          : null,
        repair: null,
        warehouse_handover: {
          total: 3,
          handed_over: handoverPending ? 0 : 3,
          pending: handoverPending ? 3 : 0,
        },
        report_interval: {
          started_at: new Date(Date.now() - 60_000).toISOString(),
          finished_at: new Date(Date.now() + 3_600_000).toISOString(),
          blocker: 'Scheduled finish has not passed. Use Record production for current output.',
        },
      });
      vi.mocked(workOrdersApi.myOutputs).mockResolvedValue([]);
      vi.mocked(workOrdersApi.myLateProductionReports).mockResolvedValue({
        data: [],
        meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 },
      });
      render(
        <QueryClientProvider
          client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
        >
          <MemoryRouter initialEntries={['/self-service/work-orders/wo']}>
            <Routes>
              <Route path="/self-service/work-orders/:id" element={<MyWorkOrderDetailPage />} />
            </Routes>
          </MemoryRouter>
        </QueryClientProvider>,
      );
      const quantity = await screen.findByLabelText('Quantity made');
      if (handoverPending) expect(quantity).toBeDisabled();
      else expect(quantity).toBeEnabled();
      expect(screen.queryByText(/Report earlier production/)).not.toBeInTheDocument();
      expect(screen.queryByLabelText(/Production started/)).not.toBeInTheDocument();
      expect(screen.queryByText(/Live recording unavailable/)).not.toBeInTheDocument();
      if (handoverPending)
        expect(screen.getByRole('alert')).toHaveTextContent('Warehouse handover is pending');
    },
  );

  it('loads repair output options for finish-first work orders and lets the recorder enter repaired pieces', async () => {
    vi.mocked(workOrdersApi.myWorkOrder).mockResolvedValue({
      id: 'wo',
      wo_number: 'WO-Repair',
      status: 'completed',
      qc_workflow: 'finish_first_v1',
      product: { part_number: 'P', name: 'Part', unit_of_measure: 'pcs' },
      machine: null,
      mold: null,
      quantity_target: 500,
      quantity_good: 500,
      quantity_rejected: 0,
      remaining_good: 0,
      crew: {
        id: 'crew-repair',
        members: [],
        recorder_employee_id: 'worker',
        reason: '',
        assigned_at: '',
        ended_at: null,
        rework_ncr_id: 'ncr',
      },
      can_record: true,
      blocker: null,
      repair: { ncr_id: 'ncr', remaining_quantity: 2, started: true },
      startup_trial: null,
      warehouse_handover: { total: 3, handed_over: 3, pending: 0 },
    });
    vi.mocked(workOrdersApi.myOutputOptions).mockResolvedValue({ defect_types: [] });
    vi.mocked(workOrdersApi.myOutputs).mockResolvedValue([]);
    vi.mocked(workOrdersApi.recordMyOutput).mockResolvedValue({
      id: 'receipt',
      batch_code: 'REPAIR-1',
      good_count: 2,
      reject_count: 0,
      recorded_at: '',
      receipt_status: 'posted',
    });
    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <MemoryRouter initialEntries={['/self-service/work-orders/wo?rework_ncr_id=ncr']}>
          <Routes>
            <Route path="/self-service/work-orders/:id" element={<MyWorkOrderDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );
    const repaired = await screen.findByLabelText('Repaired pieces');
    await waitFor(() => expect(repaired).not.toBeDisabled());
    expect(workOrdersApi.myOutputOptions).toHaveBeenCalledWith('wo', 'ncr');
    const unrepairable = screen.getByLabelText('Unrepairable pieces');
    expect(unrepairable).toHaveAttribute('readonly');
    expect(repaired).toHaveAttribute('max', '2');
    fireEvent.change(repaired, { target: { value: '23123' } });
    expect(screen.getByText('Count exceeds remaining quantity.')).toHaveClass('text-danger-fg');
    expect(screen.getByRole('button', { name: 'Record output' })).toBeDisabled();
    expect(unrepairable).toHaveValue(0);
    fireEvent.change(repaired, { target: { value: '1.5' } });
    expect(screen.getByText('Enter whole, non-negative counts.')).toHaveClass('text-danger-fg');
    expect(screen.getByRole('button', { name: 'Record output' })).toBeDisabled();
    fireEvent.change(repaired, { target: { value: '1' } });
    expect(unrepairable).toHaveValue(1);
    expect(screen.getByText('Choose a defect type for rejected pieces.')).toHaveClass(
      'text-danger-fg',
    );
    expect(screen.getByRole('button', { name: 'Record output' })).toBeDisabled();
    fireEvent.change(repaired, { target: { value: '2' } });
    expect(repaired).toHaveValue(2);
    expect(unrepairable).toHaveValue(0);
    fireEvent.click(screen.getByRole('button', { name: 'Record output' }));
    await waitFor(() =>
      expect(workOrdersApi.recordMyOutput).toHaveBeenCalledWith(
        'wo',
        { crew_id: 'crew-repair', good_count: 2, reject_count: 0, defects: [], remarks: undefined },
        expect.any(String),
        'ncr',
      ),
    );
  });

  it('rejects decimals and over-target output, requires defect classification', () => {
    expect(workerCountError('1.5', '0', '', 5, false)).toBeDefined();
    expect(workerCountError('6', '0', '', 5, false)).toBeDefined();
    expect(workerCountError('4', '2', 'defect', 5, true)).toBeDefined();
    expect(workerCountError('4', '2', 'defect', 5, false)).toBeUndefined();
    expect(workerCountError('0', '1', '', 5, false)).toBeDefined();
  });
  it('lets the assigned trial recorder capture the pinned sample while normal output is blocked', async () => {
    vi.mocked(workOrdersApi.myWorkOrder).mockResolvedValue({
      id: 'wo',
      wo_number: 'WO-1',
      status: 'in_progress',
      product: { part_number: 'P', name: 'Part', unit_of_measure: 'pcs' },
      machine: null,
      mold: null,
      quantity_target: 50,
      quantity_good: 0,
      quantity_rejected: 0,
      remaining_good: 50,
      crew: {
        id: 'crew-1',
        members: [],
        recorder_employee_id: 'worker',
        reason: '',
        assigned_at: '',
        ended_at: null,
        rework_ncr_id: null,
      },
      can_record: false,
      can_record_trial: true,
      blocker: { code: 'QC_PENDING', message: 'QC pending', owner: 'Quality' },
      repair: null,
      startup_trial: {
        inspection_id: 'qc-1',
        sample_size: 12,
        recorded_quantity: 0,
        settled: false,
        state: 'awaiting_samples',
        captured_at: null,
        output_id: null,
      },
    });
    vi.mocked(workOrdersApi.myOutputOptions).mockResolvedValue({ defect_types: [] });
    vi.mocked(workOrdersApi.myOutputs).mockResolvedValue([
      {
        id: 'trial-output',
        batch_code: 'TRIAL-1',
        trial_quantity: 12,
        trial_settled_at: null,
        good_count: 0,
        reject_count: 0,
        recorded_at: '',
        receipt_status: 'not_required',
      },
    ]);
    vi.mocked(workOrdersApi.recordMyTrial).mockRejectedValue(new Error('Network failed'));
    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <MemoryRouter initialEntries={['/self-service/work-orders/wo']}>
          <Routes>
            <Route path="/self-service/work-orders/:id" element={<MyWorkOrderDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );
    expect(
      await screen.findByRole('checkbox', {
        name: 'Samples ready for QC: 12 pieces physically produced',
      }),
    ).not.toBeChecked();
    expect(await screen.findByText(/QC pending · Contact Quality/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Record output' })).not.toBeInTheDocument();
    expect(await screen.findByText(/12 trial pieces · Awaiting QC/)).toBeInTheDocument();
    fireEvent.click(
      screen.getByRole('checkbox', { name: 'Samples ready for QC: 12 pieces physically produced' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Samples ready for QC' }));
    await screen.findByText(/Trial recording outcome unknown/);
    expect(vi.mocked(workOrdersApi.recordMyTrial).mock.calls[0][1]).toEqual({
      trial_quantity: 12,
      crew_id: 'crew-1',
    });
  });

  it('hides expired trial capture and opens the locked earlier-production form', async () => {
    vi.mocked(workOrdersApi.myWorkOrder).mockResolvedValue({
      id: 'wo',
      wo_number: 'WO-Expired',
      batch_number: 'BATCH-WO-Expired',
      status: 'in_progress',
      product: { part_number: 'P', name: 'Part', unit_of_measure: 'pcs' },
      machine: null,
      mold: null,
      quantity_target: 200,
      quantity_good: 0,
      quantity_rejected: 0,
      remaining_good: 200,
      crew: {
        id: 'crew',
        members: [],
        recorder_employee_id: 'worker',
        reason: '',
        assigned_at: '',
        ended_at: null,
        rework_ncr_id: null,
      },
      can_record: false,
      can_record_trial: false,
      can_report_late_production: true,
      blocker: {
        code: 'production_blocked',
        message: 'Recording access expired',
        owner: 'Production management',
      },
      repair: null,
      report_interval: {
        started_at: '2026-10-07T00:00:00Z',
        finished_at: '2026-10-07T02:00:00Z',
        blocker: null,
      },
      startup_trial: {
        inspection_id: 'qc',
        sample_size: 5,
        recorded_quantity: 0,
        settled: false,
        state: 'awaiting_samples',
        captured_at: null,
        output_id: null,
      },
    });
    vi.mocked(workOrdersApi.myOutputOptions).mockResolvedValue({ defect_types: [] });
    vi.mocked(workOrdersApi.myOutputs).mockResolvedValue([]);
    vi.mocked(workOrdersApi.myLateProductionReports).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 },
    });
    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <MemoryRouter initialEntries={['/self-service/work-orders/wo']}>
          <Routes>
            <Route path="/self-service/work-orders/:id" element={<MyWorkOrderDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );
    expect(await screen.findByLabelText(/Production started/)).toHaveAttribute('readonly');
    expect(screen.getByLabelText(/Original batch or source reference/)).toHaveValue(
      'BATCH-WO-Expired',
    );
    expect(screen.queryByText('Startup production')).not.toBeInTheDocument();
    expect(screen.queryByText(/Live recording unavailable/)).not.toBeInTheDocument();
    expect(screen.getByText(/Recording access expired/)).toBeInTheDocument();
  });

  it('retries unknown outcomes with exactly the original payload and key', async () => {
    vi.mocked(workOrdersApi.myWorkOrder).mockResolvedValue({
      id: 'wo',
      wo_number: 'WO-1',
      status: 'in_progress',
      product: { part_number: 'P', name: 'Part', unit_of_measure: 'pcs' },
      machine: null,
      mold: null,
      quantity_target: 5,
      quantity_good: 0,
      quantity_rejected: 0,
      remaining_good: 5,
      crew: {
        id: 'crew',
        members: [],
        recorder_employee_id: 'worker',
        reason: 'Assignment',
        assigned_at: '',
        ended_at: null,
        rework_ncr_id: null,
      },
      can_record: true,
      blocker: null,
      repair: null,
    });
    vi.mocked(workOrdersApi.myOutputOptions).mockResolvedValue({ defect_types: [] });
    vi.mocked(workOrdersApi.myOutputs).mockResolvedValue([]);
    vi.mocked(workOrdersApi.recordMyOutput)
      .mockRejectedValueOnce(new Error('Network failed'))
      .mockResolvedValueOnce({
        id: 'receipt',
        batch_code: 'B-1',
        good_count: 2,
        reject_count: 0,
        recorded_at: '',
        receipt_status: 'pending',
      });
    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <MemoryRouter initialEntries={['/self-service/work-orders/wo']}>
          <Routes>
            <Route path="/self-service/work-orders/:id" element={<MyWorkOrderDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );
    await screen.findByText('Production assignment');
    await waitFor(() => expect(screen.getByLabelText('Good count')).not.toBeDisabled());
    fireEvent.change(screen.getByLabelText('Good count'), { target: { value: '2' } });
    fireEvent.click(screen.getByRole('button', { name: 'Record output' }));
    const retry = await screen.findByRole('button', { name: 'Retry same recording' });
    expect(screen.getByLabelText('Good count')).toBeDisabled();
    fireEvent.click(retry);
    await waitFor(() => expect(workOrdersApi.recordMyOutput).toHaveBeenCalledTimes(2));
    expect(vi.mocked(workOrdersApi.recordMyOutput).mock.calls[1]).toEqual(
      vi.mocked(workOrdersApi.recordMyOutput).mock.calls[0],
    );
    expect(await screen.findByText('Output B-1 recorded.')).toBeInTheDocument();
  });

  it('lets a finish-first recorder report physical quantity without startup sampling or QC classification', async () => {
    vi.mocked(workOrdersApi.myWorkOrder).mockResolvedValue({
      id: 'wo',
      wo_number: 'WO-1',
      qc_workflow: 'finish_first_v1',
      status: 'in_progress',
      product: { part_number: 'P', name: 'Part', unit_of_measure: 'pcs' },
      machine: null,
      mold: null,
      quantity_target: 50,
      quantity_good: 0,
      quantity_rejected: 0,
      produced_quantity: 0,
      remaining_good: 50,
      crew: {
        id: 'crew-1',
        members: [],
        recorder_employee_id: 'worker',
        reason: '',
        assigned_at: '',
        ended_at: null,
        rework_ncr_id: null,
      },
      can_record: true,
      can_record_trial: false,
      blocker: null,
      repair: null,
      startup_trial: {
        inspection_id: 'legacy-qc',
        sample_size: 12,
        recorded_quantity: 0,
        settled: false,
        state: 'awaiting_samples',
        captured_at: null,
        output_id: null,
      },
    } as never);
    vi.mocked(workOrdersApi.myOutputOptions).mockResolvedValue({ defect_types: [] });
    vi.mocked(workOrdersApi.myOutputs).mockResolvedValue([]);
    vi.mocked(workOrdersApi.recordMyOutput).mockResolvedValue({
      id: 'receipt',
      batch_code: 'B-1',
      good_count: 12,
      reject_count: 0,
      recorded_at: '',
      receipt_status: 'pending',
    });

    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <MemoryRouter initialEntries={['/self-service/work-orders/wo']}>
          <Routes>
            <Route path="/self-service/work-orders/:id" element={<MyWorkOrderDetailPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByLabelText('Quantity made')).toBeEnabled();
    expect(screen.queryByRole('checkbox', { name: /Samples ready for QC/ })).toBeNull();
    expect(screen.queryByLabelText('Reject count')).toBeNull();
    fireEvent.change(screen.getByLabelText('Quantity made'), { target: { value: '12' } });
    fireEvent.click(screen.getByRole('button', { name: 'Record production' }));

    await waitFor(() => expect(workOrdersApi.recordMyOutput).toHaveBeenCalledTimes(1));
    expect(vi.mocked(workOrdersApi.recordMyOutput).mock.calls[0][1]).toEqual({
      crew_id: 'crew-1',
      produced_count: 12,
    });
  });
});

describe('recorder recovery', () => {
  it('recovers an unknown recording after remount and checks its saved receipt', async () => {
    vi.mocked(workOrdersApi.myWorkOrder).mockResolvedValue({
      id: 'wo',
      wo_number: 'WO-1',
      status: 'in_progress',
      product: { part_number: 'P', name: 'Part', unit_of_measure: 'pcs' },
      machine: null,
      mold: null,
      quantity_target: 5,
      quantity_good: 0,
      quantity_rejected: 0,
      remaining_good: 5,
      crew: {
        id: 'crew',
        members: [],
        recorder_employee_id: 'worker',
        reason: '',
        assigned_at: '',
        ended_at: null,
        rework_ncr_id: null,
      },
      can_record: true,
      blocker: null,
      repair: null,
    });
    vi.mocked(workOrdersApi.myOutputOptions).mockResolvedValue({ defect_types: [] });
    vi.mocked(workOrdersApi.myOutputs).mockResolvedValue([]);
    vi.mocked(workOrdersApi.recordMyOutput)
      .mockReset()
      .mockRejectedValue(new Error('Network failed'));
    vi.mocked(workOrdersApi.myOutputReceipt).mockResolvedValue({
      id: 'receipt',
      batch_code: 'B-1',
      good_count: 2,
      reject_count: 0,
      recorded_at: '',
      receipt_status: 'pending',
    });
    const mount = () =>
      render(
        <QueryClientProvider
          client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
        >
          <MemoryRouter initialEntries={['/self-service/work-orders/wo']}>
            <Routes>
              <Route path="/self-service/work-orders/:id" element={<MyWorkOrderDetailPage />} />
            </Routes>
          </MemoryRouter>
        </QueryClientProvider>,
      );
    const first = mount();
    await waitFor(() => expect(screen.getByLabelText('Good count')).toBeEnabled());
    fireEvent.change(screen.getByLabelText('Good count'), { target: { value: '2' } });
    fireEvent.click(screen.getByRole('button', { name: 'Record output' }));
    await screen.findByRole('button', { name: 'Retry same recording' });
    const key = vi.mocked(workOrdersApi.recordMyOutput).mock.calls[0][2];
    first.unmount();
    mount();
    expect(await screen.findByText(/Unconfirmed recording: 2 good/)).toBeInTheDocument();
    expect(workOrdersApi.recordMyOutput).toHaveBeenCalledTimes(1);
    await waitFor(() => expect(screen.getByLabelText('Good count')).toBeDisabled());
    fireEvent.click(screen.getByRole('button', { name: 'Check receipt' }));
    expect(await screen.findByText('Output B-1 recorded.')).toBeInTheDocument();
    expect(workOrdersApi.myOutputReceipt).toHaveBeenCalledWith('wo', key);
  });
});

describe('recorder work history', () => {
  it('pages work history independently of assigned work', async () => {
    vi.mocked(workOrdersApi.myWorkOrders).mockResolvedValue({
      links: { first: '', last: '', prev: null, next: null },
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 0, from: null, to: null },
    });
    vi.mocked(workOrdersApi.myWorkOrderHistory).mockImplementation(async (page = 1) => ({
      data: [],
      meta: { current_page: page, last_page: 2, per_page: 15, total: 16 },
    }));
    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <MemoryRouter>
          <MyWorkOrdersPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Next history' }));
    await waitFor(() => expect(workOrdersApi.myWorkOrderHistory).toHaveBeenCalledWith(2));
    expect(workOrdersApi.myWorkOrders).not.toHaveBeenCalledWith(2);
  });
});

it('keeps recorder identity when current recording is blocked', async () => {
  const original = useAuthStore.getState().user;
  useAuthStore.setState({
    user: {
      ...original!,
      id: 'user-1',
      employee: { id: 'recorder', employee_no: 'E-1', full_name: 'Pat', department_id: null },
    },
  });
  vi.mocked(workOrdersApi.myWorkOrders).mockResolvedValue({
    links: { first: '', last: '', prev: null, next: null },
    data: [
      {
        id: 'wo',
        wo_number: 'WO-Blocked',
        status: 'confirmed',
        product: { part_number: 'P', name: 'Part', unit_of_measure: 'pcs' },
        machine: null,
        mold: null,
        quantity_target: 5,
        quantity_good: 0,
        quantity_rejected: 0,
        remaining_good: 5,
        crew: {
          id: 'crew',
          members: [],
          recorder_employee_id: 'recorder',
          reason: '',
          assigned_at: '',
          ended_at: null,
          rework_ncr_id: 'repair',
        },
        can_record: false,
        can_record_trial: false,
        blocker: { code: 'blocked', message: 'Repair not started', owner: 'Production management' },
        repair: null,
      },
    ],
    meta: { current_page: 1, last_page: 1, per_page: 15, total: 1, from: 1, to: 1 },
  });
  vi.mocked(workOrdersApi.myWorkOrderHistory).mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 },
  });
  render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter>
        <MyWorkOrdersPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
  expect(await screen.findByText(/Output recorder.*5 good pieces remaining/)).toBeInTheDocument();
  act(() => useAuthStore.setState({ user: original }));
});
