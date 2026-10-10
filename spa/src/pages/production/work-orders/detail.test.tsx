import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import WorkOrderDetailPage from './detail';
import WorkOrdersListPage from './index';
import { workOrdersApi } from '@/api/production/workOrders';
import { woOperationsApi } from '@/api/production/routings';
import type { WorkOrder } from '@/types/production';

/**
 * O2C audit 2026-09-25 — the "Target / Produced" row rendered produced first,
 * so a WO that made 50 of its 200 read "50 / 200", as if the target were 50.
 */
vi.mock('@/api/production/workOrders', () => ({
  workOrdersApi: {
    list: vi.fn(),
    show: vi.fn(),
    complete: vi.fn(),
    cancel: vi.fn(),
    assignmentSuggestion: vi.fn(),
    crewOptions: vi.fn().mockResolvedValue([]),
    crew: vi.fn().mockResolvedValue({
      current: null,
      history: [],
      minimum_workers: 1,
      maximum_workers: null,
      can_assign: false,
    }),
    formOptions: vi.fn().mockResolvedValue({}),
    options: vi.fn().mockResolvedValue({ statuses: [] }),
    chain: vi.fn().mockResolvedValue([]),
    downtimeCategories: vi.fn().mockResolvedValue([]),
  },
}));
vi.mock('@/api/production/routings', () => ({
  woOperationsApi: { list: vi.fn().mockResolvedValue([]) },
}));
vi.mock('@/api/mrp/machines', () => ({
  machinesApi: { list: vi.fn().mockResolvedValue({ data: [] }) },
}));
vi.mock('@/api/mrp/molds', () => ({ moldsApi: { list: vi.fn().mockResolvedValue({ data: [] }) } }));
vi.mock('@/hooks/useEcho', () => ({ useEcho: () => undefined }));
vi.mock('@/hooks/useChainProgress', () => ({ useChainProgress: () => undefined }));
const permissions = vi.hoisted(() => ({ allow: false, only: null as string[] | null }));
vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({ can: (slug: string) => permissions.only ? permissions.only.includes(slug) : permissions.allow }),
}));
vi.mock('@/stores/authStore', () => ({ useAuthStore: () => ({ id: 'u-1' }) }));
vi.mock('react-router-dom', async (importOriginal) => ({
  ...(await importOriginal<typeof import('react-router-dom')>()),
  useParams: () => ({ id: 'wo-1' }),
}));

const workOrder = {
  id: 'wo-1',
  wo_number: 'WO-202609-0001',
  batch_number: 'BATCH-202609-0001',
  material_lot_references: [],
  work_order_class: 'standard',
  exception_reason: null,
  exception_authorized_by: null,
  material_plan_source: null,
  product: { id: 'p-1', part_number: 'WB-001', name: 'Wiper bushing' },
  parent: null,
  children: [],
  sales_order: { id: 'so-1', so_number: 'SO-202609-0001' },
  machine: null,
  mold: null,
  quantity_target: 200,
  quantity_produced: 50,
  quantity_good: 50,
  quantity_rejected: 0,
  progress_percentage: 25,
  scrap_rate: '0.00',
  planned_start: '2026-09-25T00:00:00Z',
  planned_end: '2026-09-26T00:00:00Z',
  actual_start: '2026-09-25T01:00:00Z',
  actual_end: null,
  status: 'in_progress',
  status_label: 'In progress',
  next_statuses: [],
  pause_reason: null,
  priority: 50,
  creator: null,
  materials: [],
  material_cost_summary: null,
  outputs: [],
  inspections: [],
  created_at: '2026-09-25T00:00:00Z',
  updated_at: '2026-09-25T00:00:00Z',
} as unknown as WorkOrder;

describe('work order rework dialog', () => {
  it('opens repair from the header without starting it or showing an inline form', async () => {
    permissions.allow = true;
    vi.mocked(workOrdersApi.show).mockResolvedValue({
      ...workOrder,
      status: 'completed',
      quality_close_blocker: 'Repair and QC pending.',
      rework: [
        {
          id: 'ncr-1',
          ncr_number: 'NCR-001',
          defect_description: 'Two cracked pieces.',
          status: 'in_progress',
          quantity: 2,
          recorded_quantity: 0,
          remaining_quantity: 2,
          started_at: null,
          started_by: null,
          finished_at: null,
          finished_by: null,
          inspections: [],
        },
      ],
    });
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const view = render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <WorkOrderDetailPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );
    const trigger = await screen.findByRole('button', { name: 'Start Rework' });
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    expect(
      screen.queryByText('No crew assigned. Output recording is blocked.'),
    ).not.toBeInTheDocument();
    fireEvent.click(trigger);
    expect(screen.getByRole('dialog', { name: /Start Rework/ })).toHaveTextContent(
      'Two cracked pieces.',
    );
    expect(await screen.findByText('No crew assigned. Output recording is blocked.')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Close modal' }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('tab', { name: 'Operations' }));
    fireEvent.click(trigger);
    expect(screen.getByRole('dialog', { name: /Start Rework/ })).toBeVisible();
    view.unmount();
    client.clear();
    permissions.allow = false;
  });
});

describe('work order schedule warnings', () => {
  it.each([
    ['required', 'Rework Required', true],
    ['in_progress', 'Rework In Progress', true],
    ['awaiting_qc', 'Rework Awaiting QC', false],
  ] as const)(
    'shows %s rework state on the table and relevant attention cards',
    async (state, label, needsAttention) => {
      vi.mocked(workOrdersApi.options).mockResolvedValue({
        statuses: [{ value: 'completed', label: 'Completed', next_statuses: [] }],
        operation_statuses: [],
      });
      vi.mocked(workOrdersApi.list).mockResolvedValue({
        data: [
          {
            ...workOrder,
            status: 'completed',
            status_label: 'Completed',
            rework_status: { state, quantity: 2 },
          },
        ],
        meta: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 },
        links: { first: '', last: '', prev: null, next: null },
      });
      const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
      const view = render(
        <QueryClientProvider client={client}>
          <MemoryRouter>
            <WorkOrdersListPage />
          </MemoryRouter>
        </QueryClientProvider>,
      );
      expect(await screen.findByText(label, { exact: true })).toBeVisible();
      expect(
        within(screen.getByRole('table')).queryByText('Completed', { exact: true }),
      ).toBeNull();
      if (needsAttention) {
        expect(
          screen.getByRole('region', { name: 'Work orders needing attention' }),
        ).toHaveTextContent('2 pieces');
      } else expect(screen.queryByText('Needs attention', { exact: true })).toBeNull();
      view.unmount();
      client.clear();
    },
  );
  it.each(['table', 'record'] as const)(
    'shows informational and expired-proposal status once on the %s',
    async (surface) => {
      const cases = [
        [
          'awaiting_confirmation',
          'Awaiting confirmation',
          'Awaiting confirmation. Open Confirm to review this proposal.',
          'planned',
        ],
        [
          'start_available',
          'Start available',
          'Start available. Click Start to check readiness.',
          'confirmed',
        ],
        [
          'proposal_expired',
          'Proposal passed',
          'Proposal passed. Open Confirm to find a new slot.',
          'planned',
        ],
      ] as const;
      for (const [kind, label, message, status] of cases) {
        const timing = {
          kind,
          message,
          scheduled_start: '2026-10-07T08:00:00+08:00',
          scheduled_end: '2026-10-07T10:00:00+08:00',
        };
        const row = {
          ...workOrder,
          status,
          mrp_generated: true,
          planned_end: '2099-10-10T00:00:00Z',
          schedule_status: timing,
          schedule_warning: kind === 'proposal_expired' ? { ...timing, kind } : null,
        };
        permissions.allow = false;
        vi.mocked(workOrdersApi.show).mockResolvedValue(row);
        vi.mocked(workOrdersApi.list).mockResolvedValue({
          data: [row],
          meta: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 },
          links: { first: '', last: '', prev: null, next: null },
        });
        const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
        const view = render(
          <QueryClientProvider client={client}>
            <MemoryRouter>
              {surface === 'table' ? <WorkOrdersListPage /> : <WorkOrderDetailPage />}
            </MemoryRouter>
          </QueryClientProvider>,
        );
        if (surface === 'table') {
          expect(await screen.findByText(label, { exact: true })).toBeVisible();
          if (kind !== 'proposal_expired')
            expect(screen.queryByText('Needs attention', { exact: true })).toBeNull();
        } else {
          expect(await screen.findByRole('status')).toHaveTextContent(message);
          expect(screen.getAllByText(message, { exact: true })).toHaveLength(1);
        }
        expect(screen.queryByText('Start overdue', { exact: true })).toBeNull();
        view.unmount();
        client.clear();
      }
    },
  );

  it('keeps real production-deadline attention alongside an informational timing status', async () => {
    const row = {
      ...workOrder,
      status: 'confirmed' as const,
      mrp_generated: true,
      schedule_status: {
        kind: 'start_available' as const,
        message: 'Start available. Click Start to check readiness.',
        scheduled_end: '2099-10-10T10:00:00+08:00',
      },
      schedule_warning: null,
    };
    vi.mocked(workOrdersApi.list).mockResolvedValue({
      data: [row],
      meta: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 },
      links: { first: '', last: '', prev: null, next: null },
    });
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <WorkOrdersListPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );
    expect(await screen.findByText('Needs attention', { exact: true })).toBeVisible();
    expect(screen.getByText('Start available', { exact: true })).toBeVisible();
    expect(screen.getByText(/Production due Sep/)).toBeVisible();
    client.clear();
  });

  it.each(['table', 'record'] as const)(
    'shows a missed start before scheduled finish on the %s',
    async (surface) => {
      const warning = {
        kind: 'start_overdue' as const,
        message: 'Start overdue. Click Start to check readiness.',
        scheduled_start: '2026-10-07T04:13:00+08:00',
        scheduled_end: '2026-10-07T05:55:00+08:00',
      };
      const row = { ...workOrder, status: 'confirmed' as const, schedule_warning: warning };
      permissions.allow = false;
      vi.mocked(workOrdersApi.show).mockResolvedValue(row);
      vi.mocked(workOrdersApi.list).mockResolvedValue({
        data: [row],
        meta: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 },
        links: { first: '', last: '', prev: null, next: null },
      });
      const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
      render(
        <QueryClientProvider client={client}>
          <MemoryRouter>
            {surface === 'table' ? <WorkOrdersListPage /> : <WorkOrderDetailPage />}
          </MemoryRouter>
        </QueryClientProvider>,
      );
      if (surface === 'table') {
        expect(await screen.findByText('Start overdue', { exact: true })).toBeVisible();
      } else {
        expect(await screen.findByRole('status')).toHaveTextContent(warning.message);
        expect(screen.getByRole('status')).toHaveTextContent(
          'Scheduled start: Oct 7, 2026 · 4:13 AM (Asia/Manila)',
        );
      }
      client.clear();
    },
  );

  it.each(['table', 'record'] as const)(
    'shows the expired schedule warning on the %s',
    async (surface) => {
      const warning = {
        message: 'Schedule passed. Reschedule before starting.',
        scheduled_end: '2026-10-07T04:38:00+08:00',
      };
      const row = {
        ...workOrder,
        status: 'confirmed' as const,
        schedule_warning: warning,
        planned_end: '2099-10-10T00:00:00Z',
      };
      permissions.allow = false;
      vi.mocked(workOrdersApi.show).mockResolvedValue(row);
      vi.mocked(workOrdersApi.list).mockResolvedValue({
        data: [row],
        meta: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 },
        links: { first: '', last: '', prev: null, next: null },
      });
      const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
      render(
        <QueryClientProvider client={client}>
          <MemoryRouter>
            {surface === 'table' ? <WorkOrdersListPage /> : <WorkOrderDetailPage />}
          </MemoryRouter>
        </QueryClientProvider>,
      );
      if (surface === 'table') {
        expect(await screen.findByText('Schedule passed', { exact: true })).toBeVisible();
        expect(screen.getByTitle(warning.message)).toBeVisible();
      } else {
        expect(await screen.findByRole('status')).toHaveTextContent(warning.message);
        expect(screen.getByRole('status')).toHaveTextContent('Oct 7, 2026 · 4:38 AM (Asia/Manila)');
      }
      client.clear();
    },
  );

  it.each(['table', 'record'] as const)(
    'hides the warning after rescheduling on the %s',
    async (surface) => {
      permissions.allow = false;
      const row = { ...workOrder, schedule_warning: null };
      vi.mocked(workOrdersApi.show).mockResolvedValue(row);
      vi.mocked(workOrdersApi.list).mockResolvedValue({
        data: [row],
        meta: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 },
        links: { first: '', last: '', prev: null, next: null },
      });
      const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
      render(
        <QueryClientProvider client={client}>
          <MemoryRouter>
            {surface === 'table' ? <WorkOrdersListPage /> : <WorkOrderDetailPage />}
          </MemoryRouter>
        </QueryClientProvider>,
      );
      await screen.findAllByText(workOrder.wo_number);
      expect(screen.queryByText('Schedule passed', { exact: true })).toBeNull();
      expect(screen.queryByText('Schedule passed. Reschedule before starting.')).toBeNull();
      client.clear();
    },
  );
});

describe('WorkOrderDetailPage', () => {
  it('labels production due separately from the customer requested and current delivery dates', async () => {
    permissions.allow = false;
    vi.mocked(workOrdersApi.show).mockResolvedValue({
      ...workOrder,
      planned_end: '2026-10-15T00:00:00+08:00',
      sales_order_line: { requested_delivery_date: '2026-10-13', delivery_date: '2026-10-16' },
    });
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <WorkOrderDetailPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );
    const due = await screen.findByText('Production due', { exact: true });
    expect(due.nextElementSibling).toHaveTextContent('Oct 15, 2026 · 12:00 AM');
    expect(screen.getByText('Requested delivery').nextElementSibling).toHaveTextContent(
      'Oct 13, 2026',
    );
    expect(screen.getByText('Current delivery').nextElementSibling).toHaveTextContent(
      'Oct 16, 2026',
    );
    client.clear();
  });

  it('keeps a failed process read visible and lets production retry', async () => {
    vi.mocked(workOrdersApi.show).mockResolvedValue(workOrder);
    vi.mocked(workOrdersApi.chain)
      .mockRejectedValueOnce(new Error('Unavailable'))
      .mockResolvedValue([]);
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <WorkOrderDetailPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Work order progress could not be loaded',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Retry work order progress' }));
    await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
    client.clear();
  });

  it('shows the target before the produced quantity, matching its label', async () => {
    vi.mocked(workOrdersApi.show).mockResolvedValue(workOrder);
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <WorkOrderDetailPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    const label = await screen.findByText('Target / Produced');
    expect(label.nextElementSibling?.textContent).toBe('200 / 50');
  });

  it('shows manual GL blockers and links permitted users to the stock movement', async () => {
    permissions.allow = true;
    const message = 'The stock movement needs Accounting to retry.';
    vi.mocked(workOrdersApi.show).mockResolvedValue({
      ...workOrder,
      outputs: [
        {
          id: 'output-1',
          recorded_at: '2026-09-25T00:00:00Z',
          good_count: 10,
          reject_count: 0,
          total_count: 10,
          shift: null,
          batch_code: 'BATCH-1',
          remarks: null,
          production_receipt_handoff: {
            status: 'generated',
            status_label: 'Generated',
            message: null,
            at: '2026-09-25T00:00:00Z',
          },
          general_ledger_handoff: {
            status: 'manual_required',
            status_label: 'Manual required',
            message,
            at: '2026-09-25T00:01:00Z',
            movement_id: 'movement-1',
          },
          material_lineage: null,
          recorder: { id: 'user-1', name: 'Production recorder' },
          defects: [],
        },
      ],
    });
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    try {
      render(
        <QueryClientProvider client={client}>
          <MemoryRouter>
            <WorkOrderDetailPage />
          </MemoryRouter>
        </QueryClientProvider>,
      );

      expect(await screen.findByText('Manual required')).toBeInTheDocument();
      expect(screen.getByText(message)).toBeInTheDocument();
      expect(screen.getByRole('link', { name: 'Open stock movement' })).toHaveAttribute(
        'href',
        '/inventory/stock-levels?view=movements&movement_id=movement-1',
      );
    } finally {
      permissions.allow = false;
      client.clear();
    }
  });

  it('allows equipment setup before startup QC while normal routing stays blocked', async () => {
    permissions.allow = true;
    vi.mocked(workOrdersApi.show).mockResolvedValue({
      ...workOrder,
      process_quality_blocker: 'Startup QC pending',
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
    vi.mocked(workOrdersApi.crew).mockResolvedValue({
      current: { id: 'crew-1', members: [], recorder_employee_id: 'worker-1' },
      history: [],
      can_assign: false,
    } as never);
    vi.mocked(woOperationsApi.list).mockResolvedValue([
      {
        id: 'op-1',
        status: 'pending',
        operation_name: 'Molding',
        sequence: 1,
        qty_completed: 0,
        qty_planned: 200,
      },
    ] as never);
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const view = render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <WorkOrderDetailPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );
    expect(await screen.findByRole('link', { name: 'Open quality inspection' })).toHaveAttribute(
      'href',
      '/quality/inspections/qc-1',
    );
    expect(screen.queryByText('Startup production')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('tab', { name: 'Operations' }));
    expect(await screen.findByRole('button', { name: 'Setup' })).toBeEnabled();
    expect(screen.getByRole('button', { name: 'Start' })).toBeDisabled();
    view.unmount();
    client.clear();
    permissions.allow = false;
    vi.mocked(woOperationsApi.list).mockResolvedValue([]);
  });

  it('explains the process QC block and lets production recheck it', async () => {
    const blocker =
      'WO WO-202609-0001 is waiting for in-process QC QC-1 to receive an approved final result.';
    vi.mocked(workOrdersApi.show).mockReset();
    vi.mocked(workOrdersApi.show)
      .mockResolvedValueOnce({ ...workOrder, process_quality_blocker: blocker })
      .mockResolvedValueOnce({ ...workOrder, process_quality_blocker: null });
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <WorkOrderDetailPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByRole('alert')).toHaveTextContent(blocker);
    fireEvent.click(screen.getByRole('button', { name: 'Recheck QC' }));

    await waitFor(() => expect(workOrdersApi.show).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(screen.queryByText(blocker)).not.toBeInTheDocument());
  });
});

it('opens stock production assignment review only from Confirm on the saved record', async () => {
  permissions.allow = true;
  vi.mocked(workOrdersApi.show).mockResolvedValue({ ...workOrder, status: 'planned', production_purpose: 'stock', sales_order: null });
  vi.mocked(workOrdersApi.crew).mockResolvedValue({
    current: null,
    history: [],
    minimum_workers: 1,
    maximum_workers: null,
    can_assign: true,
    schedule: {
      id: 'slot-1',
      machine_id: 'machine-1',
      mold_id: 'mold-1',
      scheduled_start: '2026-10-10T08:00:00Z',
      scheduled_end: '2026-10-10T10:00:00Z',
      updated_at: '2026-10-05T07:00:00Z',
    },
  });
  vi.mocked(workOrdersApi.assignmentSuggestion).mockResolvedValue({
    schedule: null,
    members: [],
    recorder_employee_id: null,
    minimum_workers: 1,
    maximum_workers: null,
    expected_crew_id: null,
    blockers: ['Review schedule'],
  });
  const before = vi.mocked(workOrdersApi.formOptions).mock.calls.length;
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <WorkOrderDetailPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
  const buttons = await screen.findAllByRole('button', { name: 'Confirm' });
  expect(screen.queryByRole('dialog')).toBeNull();
  expect(screen.queryByRole('button', { name: 'Change workers' })).toBeNull();
  expect(screen.queryByRole('button', { name: 'Assign workers' })).toBeNull();
  fireEvent.click(buttons.at(-1)!);
  await screen.findByRole('heading', { name: 'Review and confirm work order' });
  expect(screen.queryByLabelText('Machine')).toBeNull();
  expect(workOrdersApi.formOptions).toHaveBeenCalledTimes(before);
  permissions.allow = false;
  client.clear();
});

it('shows workers in Details, keeps editing in the modal, and cost after Activity', async () => {
  vi.mocked(workOrdersApi.show).mockResolvedValue(workOrder);
  vi.mocked(workOrdersApi.crew).mockResolvedValue({
    current: {
      id: 'crew-details',
      members: [
        {
          id: 'worker-details',
          employee_number: '7',
          name: 'Production recorder',
          has_active_account: true,
          shift: { id: 'day', name: 'Day shift' },
        },
      ],
      recorder_employee_id: 'worker-details',
      reason: 'Initial crew',
      assigned_at: '2026-10-05T08:00:00Z',
      ended_at: null,
      rework_ncr_id: null,
    },
    history: [],
    minimum_workers: 1,
    maximum_workers: 2,
    can_assign: true,
  });
  vi.mocked(workOrdersApi.crewOptions).mockResolvedValue([]);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <WorkOrderDetailPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
  await screen.findByRole('heading', { name: 'Details' });
  expect(screen.queryByRole('heading', { name: 'Assigned workers' })).toBeNull();
  const workers = await screen.findByText('Assigned workers');
  await waitFor(() =>
    expect(workers.nextElementSibling).toHaveTextContent('Production recorder(Output recorder)'),
  );
  expect(screen.queryByLabelText('Output recorder')).toBeNull();
  const changeWorkers = screen.getByRole('button', { name: 'Change workers' });
  expect(workers.nextElementSibling).toContainElement(changeWorkers);
  expect(changeWorkers.parentElement?.parentElement).toHaveClass('ml-auto');
  fireEvent.click(changeWorkers);
  expect(await screen.findByRole('heading', { name: 'Change workers' })).toBeInTheDocument();
  expect(screen.getByLabelText('Output recorder')).toHaveValue('worker-details');
  fireEvent.click(screen.getByRole('button', { name: 'Close modal' }));
  expect(screen.queryByRole('heading', { name: 'Production crew' })).toBeNull();
  const activity = screen.getByRole('heading', { name: 'Activity' }).parentElement!.parentElement!
    .parentElement!;
  const cost = screen.getByRole('heading', { name: 'Production cost snapshot' }).parentElement!
    .parentElement!.parentElement!;
  expect(activity.nextElementSibling).toBe(cost);
  client.clear();
});

it.each([
  { pending: 3, produced: 0, canRecord: false, canFinish: false },
  { pending: 1, produced: 200, canRecord: false, canFinish: false },
  { pending: 0, produced: 0, canRecord: true, canFinish: false },
  { pending: 0, produced: 50, canRecord: true, canFinish: true },
  { pending: 0, produced: 200, canRecord: false, canFinish: false },
])(
  'gates production actions for $pending pending handovers and $produced produced',
  async ({ pending, produced, canRecord, canFinish }) => {
    permissions.allow = true;
    vi.mocked(workOrdersApi.show).mockResolvedValue({
      ...workOrder,
      qc_workflow: 'finish_first_v1',
      produced_quantity: produced,
      quantity_produced: produced,
      quantity_good: 0,
      warehouse_handover: { total: 3, handed_over: 3 - pending, pending },
    });
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const view = render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <WorkOrderDetailPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );
    try {
      await screen.findByText('Target / Produced');
      expect(Boolean(screen.queryByRole('button', { name: 'Record production' }))).toBe(canRecord);
      expect(Boolean(screen.queryByRole('button', { name: 'Finish production' }))).toBe(canFinish);
    } finally {
      view.unmount();
      client.clear();
      permissions.allow = false;
    }
  },
);

it('requires and sends a short-completion reason only below the production target', async () => {
  permissions.allow = true;
  vi.mocked(workOrdersApi.show).mockResolvedValue(workOrder);
  vi.mocked(workOrdersApi.complete).mockResolvedValue({ ...workOrder, status: 'completed' });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  try {
    render(
      <QueryClientProvider client={client}>
        <MemoryRouter>
          <WorkOrderDetailPage />
        </MemoryRouter>
      </QueryClientProvider>,
    );
    fireEvent.click(await screen.findByRole('button', { name: 'Complete' }));
    expect(screen.getAllByRole('button', { name: 'Complete' }).at(-1)!).toBeDisabled();
    fireEvent.change(screen.getByRole('textbox', { name: 'Short-completion reason' }), {
      target: { value: 'Material shortage approved by production.' },
    });
    fireEvent.click(screen.getAllByRole('button', { name: 'Complete' }).at(-1)!);
    await waitFor(() =>
      expect(workOrdersApi.complete).toHaveBeenCalledWith('wo-1', {
        short_completion_reason: 'Material shortage approved by production.',
      }),
    );
  } finally {
    permissions.allow = false;
    client.clear();
  }
});

it('shows current quality obligations on a closed production record', async () => {
  vi.mocked(workOrdersApi.show).mockResolvedValue({
    ...workOrder,
    status: 'closed',
    status_label: 'Closed',
    quality_summary: {
      approved_quantity: 49,
      rejected_quantity: 1,
      open_defects: 1,
      missing_process_evidence: true,
      recovery_work_orders: [
        { id: 'repair-1', wo_number: 'WO-REPAIR-1', status: 'planned', quantity_target: 1 },
      ],
      replacement_pending: true,
    },
  });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <WorkOrderDetailPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
  expect(await screen.findByText('Current quality obligations')).toBeVisible();
  expect(screen.getByText(/Historical process evidence is missing/)).toBeVisible();
  expect(screen.getByText(/Replacement fulfillment remains pending/)).toBeVisible();
  expect(screen.getByRole('link', { name: 'WO-REPAIR-1' })).toHaveAttribute(
    'href',
    '/production/work-orders/repair-1',
  );
  client.clear();
});

it('shows saved timing approval history on the WO record', async () => {
  vi.mocked(workOrdersApi.show).mockResolvedValue({
    ...workOrder,
    execution_history: [
      {
        id: 'event',
        summary: 'Running production extended.',
        created_at: '2026-10-06T10:00:00+08:00',
        actor: { name: 'Production Manager' },
        detail: {
          approved_start: '2026-10-06T08:00:00+08:00',
          approved_end: '2026-10-06T10:00:00+08:00',
          scheduled_start: '2026-10-06T10:00:00+08:00',
          scheduled_end: '2026-10-06T11:00:00+08:00',
          reason: 'Output overrun reviewed.',
        },
      },
    ],
  });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <WorkOrderDetailPage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
  expect(await screen.findByText('Running production extended.')).toBeVisible();
  expect(screen.getByText('Approved by Production Manager')).toBeVisible();
  expect(screen.getByText('Timing details')).toBeVisible();
  client.clear();
});

describe('stock work order cancellation', () => {
  afterEach(() => { permissions.only = null; permissions.allow = false; });
  const stock = { ...workOrder, production_purpose: 'stock' as const, status: 'confirmed' as const,
    actual_start: null, quantity_produced: 0, quantity_good: 0, outputs: [], sales_order: null };
  function page(data: WorkOrder = stock) {
    permissions.only = ['production.work_orders.view', 'production.wo.cancel_stock'];
    vi.mocked(workOrdersApi.show).mockResolvedValue(data);
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    return render(<QueryClientProvider client={client}><MemoryRouter><WorkOrderDetailPage /></MemoryRouter></QueryClientProvider>);
  }
  it('requires a reason and sends it from one modal for PPC', async () => {
    vi.mocked(workOrdersApi.cancel).mockResolvedValue({ ...stock, status: 'cancelled' });
    const view = page();
    fireEvent.click(await screen.findByRole('button', { name: 'Cancel WO' }));
    const dialog = screen.getByRole('dialog');
    const submit = within(dialog).getByRole('button', { name: 'Cancel WO' });
    expect(submit).toBeDisabled();
    fireEvent.change(within(dialog).getByLabelText(/Cancellation reason/), { target: { value: '   ' } });
    expect(submit).toBeDisabled();
    fireEvent.change(within(dialog).getByLabelText(/Cancellation reason/), { target: { value: '  Stock no longer needed.  ' } });
    expect(submit).toBeEnabled();
    expect(dialog).toHaveTextContent('Unissued material holds');
    fireEvent.click(submit);
    await waitFor(() => expect(workOrdersApi.cancel).toHaveBeenCalledWith('wo-1', 'Stock no longer needed.'));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    view.unmount();
  });
  it.each([
    { ...stock, status: 'paused' as const },
    { ...stock, actual_start: '2026-10-09T01:00:00Z' },
    { ...stock, parent: { id: 'parent-1', wo_number: 'WO-PARENT' } },
    { ...stock, production_purpose: 'order' as const },
  ])('hides cancellation when state or scope disallows it', async data => {
    const view = page(data);
    await screen.findByRole('heading', { name: new RegExp(stock.wo_number) });
    expect(screen.queryByRole('button', { name: 'Cancel WO' })).not.toBeInTheDocument();
    view.unmount();
  });
  it('keeps the modal open and displays a server blocker', async () => {
    vi.mocked(workOrdersApi.cancel).mockRejectedValue({ response: { data: { message: 'Review committed purchasing PR-001.' } } });
    const view = page();
    fireEvent.click(await screen.findByRole('button', { name: 'Cancel WO' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.change(within(dialog).getByLabelText(/Cancellation reason/), { target: { value: 'No longer needed.' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel WO' }));
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('Review committed purchasing PR-001.');
    expect(dialog).toBeVisible();
    view.unmount();
  });
});


it('shows the exact completion blocker after reaching target', async () => {
  vi.mocked(workOrdersApi.show).mockResolvedValue({
    ...workOrder,
    qc_workflow: 'finish_first_v1',
    produced_quantity: 200,
    quantity_produced: 200,
    production_completion_blocker: 'Confirm material handover before completing the work order.',
  });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const view = render(
    <QueryClientProvider client={client}>
      <MemoryRouter><WorkOrderDetailPage /></MemoryRouter>
    </QueryClientProvider>,
  );
  await screen.findByText('Target produced. Completion pending.');
  expect(screen.getByText('Confirm material handover before completing the work order.')).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Record production' })).toBeNull();
  expect(screen.queryByRole('button', { name: 'Finish production' })).toBeNull();
  view.unmount();
  client.clear();
});
