import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { workOrdersApi } from '@/api/production/workOrders';
import type { LateProductionReport } from '@/types/production';
import { MemoryRouter } from 'react-router-dom';
import { LateProductionEntry, LateProductionReviewQueue } from './LateProductionReports';

vi.mock('@/api/production/workOrders', () => ({
  workOrdersApi: {
    myLateProductionReports: vi.fn(),
    myLateProductionReportOptions: vi.fn(),
    proposeLateProduction: vi.fn(),
    lateProductionReports: vi.fn(),
    approveLateProductionReport: vi.fn(),
    rejectLateProductionReport: vi.fn(),
  },
}));

const report = (overrides: Partial<LateProductionReport> = {}): LateProductionReport => ({
  id: 'report-1',
  work_order_id: 'wo-1',
  wo_number: 'WO-1',
  work_order_status: 'completed',
  crew_id: 'crew-1',
  source_reference: 'BATCH-17',
  production_started_at: '2026-10-07T00:00:00Z',
  production_finished_at: '2026-10-07T02:00:00Z',
  good_count: 6,
  reject_count: 0,
  quantity_scope: 'additional_unreported_physical_pieces',
  quantity_warning: 'Only additional pieces.',
  defects: [],
  reason: 'Tablet was offline.',
  crew_snapshot: {
    crew_id: 'crew-1',
    recorder_employee_id: 'employee-1',
    assigned_at: '2026-10-07T00:00:00Z',
    ended_at: '2026-10-07T03:00:00Z',
    members: [{ employee_id: 'employee-1', employee_number: 'E-1', name: 'Pat Operator' }],
  },
  evidence_summary: {
    crew: {
      state: 'verified',
      assigned_at: '2026-10-07T00:00:00Z',
      ended_at: '2026-10-07T03:00:00Z',
    },
    attendance: { state: 'verified', members: [{ name: 'Pat Operator', state: 'verified' }] },
    execution: { state: 'verified', source: 'work_order_run_history' },
    resources: { state: 'verified', source: 'production_schedule_history' },
  },
  status: 'pending',
  recorded_by: 'user-worker',
  recorded_by_name: 'Pat Operator',
  recorded_at: '2026-10-08T00:00:00Z',
  reviewed_by: null,
  reviewer_name: null,
  reviewed_at: null,
  review_reason: null,
  work_order_output_id: null,
  output_summary: null,
  can_approve: true,
  owner: 'Production management',
  ...overrides,
});

function withQuery(children: ReactNode) {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MemoryRouter>{children}</MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('late production reports', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    sessionStorage.clear();
    localStorage.clear();
  });
  it('uses locked schedule times and excludes restored draft times from submissions', async () => {
    vi.mocked(workOrdersApi.myLateProductionReports).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 },
    });
    vi.mocked(workOrdersApi.proposeLateProduction).mockResolvedValue(report());
    withQuery(
      <LateProductionEntry
        workOrderId="wo-1"
        workOrderStatus="completed"
        canSubmit
        finishFirst
        batchNumber="BATCH-17"
        reportInterval={{
          started_at: '2026-10-07T00:00:00Z',
          finished_at: '2026-10-07T02:00:00Z',
          blocker: null,
        }}
        crew={{
          id: 'crew-1',
          members: [],
          recorder_employee_id: 'employee-1',
          reason: '',
          assigned_at: '',
          ended_at: null,
          rework_ncr_id: null,
        }}
      />,
    );
    const start = screen.getByLabelText(/Production started/);
    expect(start).toHaveAttribute('readonly');
    expect(start).toHaveValue('Oct 7, 2026 · 8:00 AM');
    expect(screen.getByLabelText(/Production finished/)).toHaveValue('Oct 7, 2026 · 10:00 AM');
    fireEvent.change(start, { target: { value: '2026-10-06T08:00' } });
    expect(screen.getByLabelText(/Original batch or source reference/)).toHaveValue('BATCH-17');
    fireEvent.change(screen.getByLabelText('Additional produced quantity'), {
      target: { value: '6' },
    });
    fireEvent.change(screen.getByLabelText(/Why was production not recorded at the time/), {
      target: { value: 'Tablet offline.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Submit for management review' }));
    await waitFor(() => expect(workOrdersApi.proposeLateProduction).toHaveBeenCalledTimes(1));
    expect(vi.mocked(workOrdersApi.proposeLateProduction).mock.calls[0][1]).toMatchObject({
      source_reference: 'BATCH-17',
      production_started_at: '2026-10-07T00:00:00.000Z',
      production_finished_at: '2026-10-07T02:00:00.000Z',
      produced_count: 6,
    });
  });

  it('blocks reports when the saved schedule cannot supply a finished interval', async () => {
    vi.mocked(workOrdersApi.myLateProductionReports).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 },
    });
    withQuery(
      <LateProductionEntry
        workOrderId="wo-1"
        workOrderStatus="in_progress"
        canSubmit
        finishFirst
        reportInterval={{
          started_at: null,
          finished_at: null,
          blocker: 'No saved production schedule. Ask Production management to review this run.',
        }}
        crew={{
          id: 'crew-1',
          members: [],
          recorder_employee_id: 'employee-1',
          reason: '',
          assigned_at: '',
          ended_at: null,
          rework_ncr_id: null,
        }}
      />,
    );
    expect(screen.getByRole('alert')).toHaveTextContent('No saved production schedule');
    expect(screen.getByLabelText(/Production started/)).toHaveValue('Not available');
    expect(screen.getByRole('button', { name: 'Submit for management review' })).toBeDisabled();
  });
  it('retries an uncertain worker submission with the same frozen payload and key', async () => {
    vi.mocked(workOrdersApi.myLateProductionReports).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 },
    });
    vi.mocked(workOrdersApi.proposeLateProduction)
      .mockRejectedValueOnce(new Error('Network failed'))
      .mockResolvedValueOnce(report());
    withQuery(
      <LateProductionEntry
        workOrderId="wo-1"
        workOrderStatus="completed"
        canSubmit
        crew={{
          id: 'crew-1',
          members: [],
          recorder_employee_id: 'employee-1',
          reason: '',
          assigned_at: '',
          ended_at: null,
          rework_ncr_id: null,
        }}
      />,
    );
    fireEvent.change(screen.getByLabelText(/Original batch or source reference/), {
      target: { value: 'BATCH-17' },
    });
    fireEvent.change(screen.getByLabelText(/Production started/), {
      target: { value: '2026-10-07T08:00' },
    });
    fireEvent.change(screen.getByLabelText(/Production finished/), {
      target: { value: '2026-10-07T10:00' },
    });
    fireEvent.change(screen.getByLabelText(/Additional good count/), { target: { value: '6' } });
    fireEvent.change(screen.getByLabelText(/Why was production not recorded at the time/), {
      target: { value: 'Tablet was offline.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Submit for management review' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Retry same report' }));
    await waitFor(() => expect(workOrdersApi.proposeLateProduction).toHaveBeenCalledTimes(2));
    expect(vi.mocked(workOrdersApi.proposeLateProduction).mock.calls[1]).toEqual(
      vi.mocked(workOrdersApi.proposeLateProduction).mock.calls[0],
    );
    expect(vi.mocked(workOrdersApi.proposeLateProduction).mock.calls[0][1]).toMatchObject({
      source_reference: 'BATCH-17',
      production_started_at: '2026-10-07T00:00:00.000Z',
      production_finished_at: '2026-10-07T02:00:00.000Z',
      good_count: 6,
      reject_count: 0,
    });
    expect(
      await screen.findByText('Report submitted. Production management review required.'),
    ).toBeInTheDocument();
  });

  it('keeps expired historical access and posts only produced quantity for finish-first WOs', async () => {
    vi.mocked(workOrdersApi.myLateProductionReports).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 },
    });
    vi.mocked(workOrdersApi.proposeLateProduction).mockResolvedValue(report());
    withQuery(
      <LateProductionEntry
        workOrderId="wo-1"
        workOrderStatus="completed"
        canSubmit
        finishFirst
        collapsed={false}
        crew={{
          id: 'crew-1',
          members: [],
          recorder_employee_id: 'employee-1',
          reason: '',
          assigned_at: '',
          ended_at: '2026-10-07T03:00:00Z',
          rework_ncr_id: null,
        }}
      />,
    );

    expect(await screen.findByLabelText('Additional produced quantity')).toBeEnabled();
    expect(screen.queryByLabelText(/Additional good count/)).toBeNull();
    expect(screen.queryByLabelText(/Additional reject count/)).toBeNull();
    fireEvent.change(screen.getByLabelText(/Original batch or source reference/), {
      target: { value: 'BATCH-LATE' },
    });
    fireEvent.change(screen.getByLabelText(/Production started/), {
      target: { value: '2026-10-07T08:00' },
    });
    fireEvent.change(screen.getByLabelText(/Production finished/), {
      target: { value: '2026-10-07T10:00' },
    });
    fireEvent.change(screen.getByLabelText('Additional produced quantity'), {
      target: { value: '6' },
    });
    fireEvent.change(screen.getByLabelText(/Why was production not recorded at the time/), {
      target: { value: 'Tablet was offline.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Submit for management review' }));

    await waitFor(() => expect(workOrdersApi.proposeLateProduction).toHaveBeenCalledTimes(1));
    expect(vi.mocked(workOrdersApi.proposeLateProduction).mock.calls[0][1]).toMatchObject({
      source_reference: 'BATCH-LATE',
      production_started_at: '2026-10-07T00:00:00.000Z',
      production_finished_at: '2026-10-07T02:00:00.000Z',
      produced_count: 6,
    });
    expect(vi.mocked(workOrdersApi.proposeLateProduction).mock.calls[0][1]).not.toHaveProperty(
      'good_count',
    );
    expect(vi.mocked(workOrdersApi.proposeLateProduction).mock.calls[0][1]).not.toHaveProperty(
      'reject_count',
    );
  });

  it('shows historical evidence and keeps closed corrections out of normal approval', async () => {
    vi.mocked(workOrdersApi.lateProductionReports).mockResolvedValue({
      data: [report({ work_order_status: 'closed', status: 'correction' })],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
    });
    withQuery(<LateProductionReviewQueue workOrderId="wo-1" workOrderStatus="closed" />);
    expect(await screen.findByText(/attendance verified/)).toBeInTheDocument();
    expect(screen.getByText(/Recorded by Pat Operator/)).toBeInTheDocument();
    expect(screen.getByText(/requires accounting correction/)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Approve and post' })).not.toBeInTheDocument();
  });

  it('requires a manager note before approving a report with missing attendance', async () => {
    vi.mocked(workOrdersApi.lateProductionReports).mockResolvedValue({
      data: [
        report({
          evidence_summary: {
            ...report().evidence_summary,
            attendance: { state: 'missing', members: [{ name: 'Pat Operator', state: 'missing' }] },
          },
        }),
      ],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
    });
    withQuery(<LateProductionReviewQueue workOrderId="wo-1" workOrderStatus="completed" />);
    const approve = await screen.findByRole('button', { name: 'Approve and post' });
    expect(approve).toBeDisabled();
    fireEvent.change(
      screen.getByLabelText(/Review note \(required for missing historical evidence\)/),
      { target: { value: 'Supervisor confirmed the shift log.' } },
    );
    expect(approve).toBeEnabled();
  });

  it('shows approved held output and reviewer details', async () => {
    vi.mocked(workOrdersApi.lateProductionReports).mockResolvedValue({
      data: [
        report({
          status: 'approved',
          owner: null,
          can_approve: false,
          reviewed_by: 'manager-1',
          reviewer_name: 'Morgan Manager',
          reviewed_at: '2026-10-08T01:15:00Z',
          work_order_output_id: 'output-1',
          output_summary: { physical_count: 8, good_count: 0, reject_count: 0, held_quantity: 8 },
        }),
      ],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
    });
    withQuery(<LateProductionReviewQueue workOrderId="wo-1" workOrderStatus="completed" />);
    expect(
      await screen.findByText('8 physical pieces posted on quality hold.'),
    ).toBeInTheDocument();
    expect(screen.getByText(/Reviewed by Morgan Manager/)).toBeInTheDocument();
  });

  it('paginates older manager review reports', async () => {
    vi.mocked(workOrdersApi.lateProductionReports)
      .mockResolvedValueOnce({
        data: [report()],
        meta: { current_page: 1, last_page: 2, per_page: 50, total: 51 },
      })
      .mockResolvedValueOnce({
        data: [report({ id: 'report-old', source_reference: 'OLDER-BATCH' })],
        meta: { current_page: 2, last_page: 2, per_page: 50, total: 51 },
      });
    withQuery(<LateProductionReviewQueue workOrderId="wo-1" workOrderStatus="completed" />);
    fireEvent.click(await screen.findByRole('button', { name: 'Next reports' }));
    await waitFor(() =>
      expect(workOrdersApi.lateProductionReports).toHaveBeenLastCalledWith('wo-1', undefined, 2),
    );
    expect(await screen.findByText('OLDER-BATCH')).toBeInTheDocument();
    expect(screen.getByText('pending')).toBeInTheDocument();
    expect(screen.getByText('Page 2 of 2')).toBeInTheDocument();
  });

  it('paginates the recorder own report history', async () => {
    vi.mocked(workOrdersApi.myLateProductionReports)
      .mockResolvedValueOnce({
        data: [report()],
        meta: { current_page: 1, last_page: 2, per_page: 50, total: 51 },
      })
      .mockResolvedValueOnce({
        data: [report({ id: 'report-old', source_reference: 'OLDER-OWN-BATCH' })],
        meta: { current_page: 2, last_page: 2, per_page: 50, total: 51 },
      });
    withQuery(
      <LateProductionEntry
        workOrderId="wo-1"
        workOrderStatus="completed"
        canSubmit={false}
        crew={{
          id: 'crew-1',
          members: [],
          recorder_employee_id: 'employee-1',
          reason: '',
          assigned_at: '',
          ended_at: null,
          rework_ncr_id: null,
        }}
      />,
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Next reports' }));
    await waitFor(() =>
      expect(workOrdersApi.myLateProductionReports).toHaveBeenLastCalledWith('wo-1', 2),
    );
    expect(await screen.findByText('OLDER-OWN-BATCH')).toBeInTheDocument();
    expect(screen.getByText('pending')).toBeInTheDocument();
    expect(screen.getByText('Page 2 of 2')).toBeInTheDocument();
  });
});

it('keeps an uncertain report frozen after a later validation error and reload', async () => {
  sessionStorage.clear();
  localStorage.clear();
  const { AxiosError } = await import('axios');
  vi.mocked(workOrdersApi.myLateProductionReports).mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1, per_page: 15, total: 0 },
  });
  vi.mocked(workOrdersApi.proposeLateProduction)
    .mockReset()
    .mockRejectedValueOnce(new Error('Network failed'))
    .mockRejectedValueOnce(
      new AxiosError('Rejected', undefined, undefined, undefined, { status: 422 } as never),
    );
  const entry = (
    <LateProductionEntry
      workOrderId="wo-1"
      workOrderStatus="completed"
      canSubmit
      crew={{
        id: 'crew-1',
        members: [],
        recorder_employee_id: 'employee-1',
        reason: '',
        assigned_at: '',
        ended_at: null,
        rework_ncr_id: null,
      }}
    />
  );
  const first = withQuery(entry);
  fireEvent.change(screen.getByLabelText(/Original batch or source reference/), {
    target: { value: 'BATCH-17' },
  });
  fireEvent.change(screen.getByLabelText(/Production started/), {
    target: { value: '2026-10-07T08:00' },
  });
  fireEvent.change(screen.getByLabelText(/Production finished/), {
    target: { value: '2026-10-07T10:00' },
  });
  fireEvent.change(screen.getByLabelText(/Additional good count/), { target: { value: '6' } });
  fireEvent.change(screen.getByLabelText(/Why was production not recorded at the time/), {
    target: { value: 'Tablet was offline.' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Submit for management review' }));
  fireEvent.click(await screen.findByRole('button', { name: 'Retry same report' }));
  await waitFor(() => expect(workOrdersApi.proposeLateProduction).toHaveBeenCalledTimes(2));
  expect(screen.getByLabelText(/Additional good count/)).toBeDisabled();
  first.unmount();
  withQuery(entry);
  expect(screen.getByText(/Unconfirmed report: BATCH-17/)).toBeInTheDocument();
  expect(screen.getByLabelText(/Additional good count/)).toBeDisabled();
  expect(workOrdersApi.proposeLateProduction).toHaveBeenCalledTimes(2);
});

it('keeps unresolved late reports visible even when a run is extended and the entry starts collapsed', () => {
  sessionStorage.clear();
  localStorage.clear();
  sessionStorage.setItem(
    'ogami:pending-production:undefined:wo-1:late',
    JSON.stringify({
      key: 'same-key',
      data: { source_reference: 'BATCH-PENDING', crew_id: 'crew-1' },
    }),
  );
  const mounted = withQuery(
    <LateProductionEntry
      collapsed
      workOrderId="wo-1"
      workOrderStatus="in_progress"
      reportInterval={{
        started_at: new Date(Date.now() - 60_000).toISOString(),
        finished_at: new Date(Date.now() + 3_600_000).toISOString(),
        blocker: 'Scheduled finish has not passed. Use Record production for current output.',
      }}
      canSubmit
      crew={{
        id: 'crew-1',
        members: [],
        recorder_employee_id: 'employee-1',
        reason: '',
        assigned_at: '',
        ended_at: null,
        rework_ncr_id: null,
      }}
    />,
  );
  expect(mounted.container.querySelector('details')).toHaveAttribute('open');
  expect(screen.getByText(/Unconfirmed report: BATCH-PENDING/)).toBeVisible();
});

it('explains that closed work-order reports require correction instead of output posting', () => {
  sessionStorage.clear();
  localStorage.clear();
  withQuery(
    <LateProductionEntry
      workOrderId="wo-1"
      workOrderStatus="closed"
      canSubmit
      crew={{
        id: 'crew-1',
        members: [],
        recorder_employee_id: 'employee-1',
        reason: '',
        assigned_at: '',
        ended_at: null,
        rework_ncr_id: null,
      }}
    />,
  );
  expect(
    screen.getByText(/Closed WO: submit a correction for controlled review/),
  ).toBeInTheDocument();
});
