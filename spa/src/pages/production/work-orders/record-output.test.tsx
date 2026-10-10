import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';
import { client } from '@/api/client';
import { workOrdersApi } from '@/api/production/workOrders';
import type { WorkOrder } from '@/types/production';
import RecordOutputPage from './record-output';

vi.mock('@/api/production/workOrders', () => ({
  workOrdersApi: {
    show: vi.fn(),
    crew: vi.fn().mockResolvedValue({
      current: null,
      history: [],
      minimum_workers: 1,
      maximum_workers: null,
      can_assign: false,
    }),
    formOptions: vi.fn(),
    recordOutput: vi.fn(),
    recordTrial: vi.fn(),
    myOutputReceipt: vi.fn(),
  },
}));
vi.mock('@/api/client', () => ({
  getCsrfCookie: vi.fn(),
  client: { get: vi.fn() },
}));
vi.mock('@/hooks/useEcho', () => ({ useEcho: () => undefined }));
const permissions = vi.hoisted(() => new Set<string>());
vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({ can: (permission: string) => permissions.has(permission) }),
}));

describe('RecordOutputPage', () => {
  beforeEach(() => {
    cleanup();
    sessionStorage.clear();
    vi.clearAllMocks();
    permissions.clear();
  });
  it('requires confirmation of the pinned startup sample and keeps normal output blocked', async () => {
    permissions.add('production.wo.record');
    vi.mocked(workOrdersApi.show).mockResolvedValue({
      id: 'wo-1',
      wo_number: 'WO-1',
      status: 'in_progress',
      process_quality_blocker: 'QC pending',
      startup_trial: {
        inspection_id: 'qc-1',
        sample_size: 12,
        recorded_quantity: 0,
        settled: false,
        state: 'awaiting_samples',
        captured_at: null,
        output_id: null,
      },
    } as unknown as WorkOrder);
    vi.mocked(workOrdersApi.crew).mockResolvedValue({
      current: { id: 'crew-1', members: [] },
      history: [],
    } as never);
    vi.mocked(client.get).mockResolvedValue({ data: { data: [] } } as never);
    vi.mocked(workOrdersApi.recordTrial).mockRejectedValue(new AxiosError('Network Error'));
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/production/work-orders/wo-1/record-output']}>
          <Routes>
            <Route
              path="/production/work-orders/:id/record-output"
              element={<RecordOutputPage />}
            />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );
    expect(
      await screen.findByRole('checkbox', {
        name: 'Samples ready for QC: 12 pieces physically produced',
      }),
    ).not.toBeChecked();
    expect(
      screen.getByRole('checkbox', { name: 'Samples ready for QC: 12 pieces physically produced' }),
    ).not.toBeChecked();
    expect(screen.getByRole('button', { name: 'Samples ready for QC' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Record' })).toBeDisabled();
    fireEvent.change(screen.getByRole('textbox', { name: /Reason for recording for the worker/ }), {
      target: { value: 'Supervisor capture' },
    });
    fireEvent.click(
      screen.getByRole('checkbox', { name: 'Samples ready for QC: 12 pieces physically produced' }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Samples ready for QC' }));
    await screen.findByText(/Trial recording outcome unknown/);
    expect(
      screen.getByRole('textbox', { name: /Reason for recording for the worker/ }),
    ).toBeDisabled();
    queryClient.setQueryData(['production', 'crew', 'wo-1'], {
      current: { id: 'crew-2', members: [] },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Retry same trial' }));
    await waitFor(() => expect(workOrdersApi.recordTrial).toHaveBeenCalledTimes(2));
    expect(vi.mocked(workOrdersApi.recordTrial).mock.calls[1]).toEqual(
      vi.mocked(workOrdersApi.recordTrial).mock.calls[0],
    );
    expect(vi.mocked(workOrdersApi.recordTrial).mock.calls[0][1]).toEqual({
      trial_quantity: 12,
      crew_id: 'crew-1',
      recording_reason: 'Supervisor capture',
    });
    expect(workOrdersApi.recordOutput).not.toHaveBeenCalled();
  });

  it('blocks output until QC is released and lets the user recheck', async () => {
    const blocker = 'WO WO-1 has no active in-process QC inspection.';
    vi.mocked(workOrdersApi.show).mockResolvedValue({
      id: 'wo-1',
      wo_number: 'WO-1',
      status: 'in_progress',
      status_label: 'In progress',
      process_quality_blocker: blocker,
    } as unknown as WorkOrder);
    vi.mocked(workOrdersApi.formOptions).mockResolvedValue({
      products: [],
      machines: [],
      molds: [],
      shifts: [],
    });
    vi.mocked(client.get).mockResolvedValue({ data: { data: [] } } as never);

    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/production/work-orders/wo-1/record-output']}>
          <Routes>
            <Route
              path="/production/work-orders/:id/record-output"
              element={<RecordOutputPage />}
            />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByText(blocker)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Record' })).toBeDisabled();

    fireEvent.click(screen.getByRole('button', { name: 'Recheck QC' }));
    await waitFor(() => expect(workOrdersApi.show).toHaveBeenCalledTimes(2));
  });

  it('records one produced quantity and skips legacy startup QC for finish-first work', async () => {
    vi.mocked(workOrdersApi.show).mockResolvedValue({
      id: 'wo-1',
      wo_number: 'WO-1',
      qc_workflow: 'finish_first_v1',
      status: 'in_progress',
      startup_trial: {
        inspection_id: 'legacy-qc',
        sample_size: 12,
        recorded_quantity: 0,
        settled: false,
        state: 'awaiting_samples',
        captured_at: null,
        output_id: null,
      },
      quantity_target: 50,
      quantity_produced: 0,
      quantity_good: 0,
      quantity_rejected: 0,
      process_quality_blocker: null,
    } as unknown as WorkOrder);
    vi.mocked(workOrdersApi.crew).mockResolvedValue({
      current: { id: 'crew-1', members: [] },
      history: [],
    } as never);
    vi.mocked(workOrdersApi.recordOutput).mockResolvedValue({ batch_code: 'B-1' } as never);

    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <MemoryRouter initialEntries={['/production/work-orders/wo-1/record-output']}>
          <Routes>
            <Route path="/production/work-orders/:id/record-output" element={<RecordOutputPage />} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByLabelText('Quantity made')).toBeEnabled();
    expect(screen.queryByRole('checkbox', { name: /Samples ready for QC/ })).toBeNull();
    fireEvent.change(screen.getByLabelText('Quantity made'), { target: { value: '12' } });
    fireEvent.change(screen.getByLabelText(/Recording on behalf reason/), {
      target: { value: 'Manager recording' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Record' }));

    await waitFor(() => expect(workOrdersApi.recordOutput).toHaveBeenCalledTimes(1));
    expect(vi.mocked(workOrdersApi.recordOutput).mock.calls[0][1]).toMatchObject({
      produced_count: 12,
      crew_id: 'crew-1',
    });
    expect(vi.mocked(workOrdersApi.recordOutput).mock.calls[0][1]).not.toHaveProperty('good_count');
    expect(vi.mocked(workOrdersApi.recordOutput).mock.calls[0][1]).not.toHaveProperty('reject_count');
  });

  it('freezes submitted crew and counts after an unknown save outcome', async () => {
    vi.mocked(workOrdersApi.show).mockResolvedValue({
      id: 'wo-1',
      wo_number: 'WO-1',
      status: 'in_progress',
      process_quality_blocker: null,
    } as unknown as WorkOrder);
    vi.mocked(workOrdersApi.crew).mockResolvedValue({
      current: { id: 'crew-1', members: [] },
      history: [],
    } as never);
    vi.mocked(client.get).mockResolvedValue({ data: { data: [] } } as never);
    vi.mocked(workOrdersApi.recordOutput).mockRejectedValue(new AxiosError('Network Error'));
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/production/work-orders/wo-1/record-output']}>
          <Routes>
            <Route
              path="/production/work-orders/:id/record-output"
              element={<RecordOutputPage />}
            />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );
    await waitFor(() => expect(screen.getByRole('button', { name: 'Record' })).not.toBeDisabled());
    fireEvent.change(screen.getByLabelText(/Good count/), { target: { value: '5' } });
    fireEvent.change(screen.getByLabelText(/Recording on behalf reason/), {
      target: { value: 'Manager recording' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Record' }));
    await screen.findByText(/Recording outcome unknown/);
    expect(screen.getByLabelText(/Good count/)).toBeDisabled();
    queryClient.setQueryData(['production', 'crew', 'wo-1'], {
      current: { id: 'crew-2', members: [] },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Retry same recording' }));
    await waitFor(() => expect(workOrdersApi.recordOutput).toHaveBeenCalledTimes(2));
    expect(vi.mocked(workOrdersApi.recordOutput).mock.calls[1]).toEqual(
      vi.mocked(workOrdersApi.recordOutput).mock.calls[0],
    );
  });

  it('requires explicit manager acknowledgement and reason while handover remains pending', async () => {
    permissions.add('production.wo.record');
    permissions.add('production.work_orders.lifecycle');
    vi.mocked(workOrdersApi.show).mockResolvedValue({
      id: 'wo-1',
      wo_number: 'WO-1',
      status: 'in_progress',
      process_quality_blocker: null,
      warehouse_handover_pending: 1,
    } as WorkOrder);
    vi.mocked(workOrdersApi.crew).mockResolvedValue({
      current: { id: 'crew-1', members: [] },
      history: [],
    } as never);
    vi.mocked(client.get).mockResolvedValue({ data: { data: [] } } as never);
    vi.mocked(workOrdersApi.recordOutput).mockRejectedValue(new AxiosError('Network Error'));
    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <MemoryRouter initialEntries={['/production/work-orders/wo-1/record-output']}>
          <Routes>
            <Route
              path="/production/work-orders/:id/record-output"
              element={<RecordOutputPage />}
            />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );
    const acknowledgement = await screen.findByRole('checkbox', {
      name: /Materials were received/,
    });
    expect(acknowledgement).not.toBeChecked();
    expect(screen.getByRole('button', { name: 'Record' })).toBeDisabled();
    fireEvent.click(acknowledgement);
    expect(screen.getByRole('button', { name: 'Record' })).toBeDisabled();
    fireEvent.change(
      screen.getByLabelText(/pending.*confirmation|Handover reason|Warehouse confirmation reason/i),
      { target: { value: 'Delivered to the crew; Warehouse is saving receipt.' } },
    );
    fireEvent.change(screen.getByLabelText(/Good count/), { target: { value: '5' } });
    fireEvent.change(screen.getByLabelText(/Recording on behalf reason/), {
      target: { value: 'Manager records for crew' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Record' }));
    await waitFor(() => expect(workOrdersApi.recordOutput).toHaveBeenCalled());
    expect(vi.mocked(workOrdersApi.recordOutput).mock.calls[0][1]).toMatchObject({
      material_handover_acknowledged: true,
      material_handover_reason: 'Delivered to the crew; Warehouse is saving receipt.',
    });
  });
});
