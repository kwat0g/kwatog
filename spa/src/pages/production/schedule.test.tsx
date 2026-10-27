import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ProductionSchedulePage from './schedule';
import { schedulerApi } from '@/api/mrp/scheduler';
import type { GanttSnapshot } from '@/types/mrp';

const permissions = vi.hoisted(() => new Set(['production.schedule.confirm', 'mrp.schedule']));
vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({ can: (slug: string) => permissions.has(slug) }),
}));
vi.mock('@/api/mrp/scheduler', () => ({
  schedulerApi: { options: vi.fn(), snapshot: vi.fn(), run: vi.fn(), confirm: vi.fn() },
}));
vi.mock('@/api/mrp/machines', () => ({
  machinesApi: { list: vi.fn().mockResolvedValue({ data: [] }) },
}));

const snapshot: GanttSnapshot = {
  from: '2026-10-05T00:00:00+08:00',
  to: '2026-10-19T00:00:00+08:00',
  rows: [
    {
      machine_id: 'machine-1',
      machine_code: 'IMM-01',
      name: 'Injection machine',
      tonnage: 100,
      status: 'idle',
      bars: [0, 1, 2].map((index) => ({
        id: `schedule-${index}`,
        updated_at: '2026-10-05T10:00:00.000000Z',
        staffing: {
          worker_names: ['Worker'],
          worker_count: 1,
          minimum_workers: 1,
          recorder_name: 'Worker',
          blockers: [],
          approval_blockers: [],
          expected_crew_id: `crew-${index}`,
        },
        wo_id: `wo-${index}`,
        wo_number: `WO-${index}`,
        product_name: 'Wiper bushing',
        mold_code: 'MOLD-1',
        start: `2026-10-11T0${index}:00:00+08:00`,
        end: `2026-10-11T0${index + 1}:00:00+08:00`,
        status: index === 2 ? 'confirmed' : 'pending',
        wo_status: index === 2 ? 'confirmed' : 'planned',
      })),
    },
  ],
};

function mount() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter>
        <ProductionSchedulePage />
      </MemoryRouter>
    </QueryClientProvider>,
  );
  return client;
}

beforeEach(() => {
  vi.clearAllMocks();
  permissions.clear();
  permissions.add('production.schedule.confirm');
  permissions.add('mrp.schedule');
  vi.mocked(schedulerApi.options).mockResolvedValue({ default_horizon_days: 14 });
  vi.mocked(schedulerApi.snapshot).mockResolvedValue(snapshot);
  vi.mocked(schedulerApi.confirm).mockResolvedValue({
    confirmed_count: 1,
    schedule_ids: ['schedule-0'],
  });
});

describe('ProductionSchedulePage', () => {
  it.each(['production.schedule.confirm', 'production.wo.confirm'])(
    'hands confirmation to WO records even with %s permission',
    async (permission) => {
      permissions.clear();
      permissions.add(permission);
      permissions.add('production.wo.assign');
      const client = mount();
      fireEvent.click(await screen.findByRole('tab', { name: /Schedule list/ }));
      expect(await screen.findByRole('link', { name: 'WO-0' })).toHaveAttribute(
        'href',
        '/production/work-orders/wo-0',
      );
      expect(screen.getByText(/confirm each proposal from its work-order record/)).toBeVisible();
      expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
      expect(
        screen.queryByRole('button', { name: /Confirm selected|Review and assign/ }),
      ).not.toBeInTheDocument();
      expect(schedulerApi.confirm).not.toHaveBeenCalled();
      client.clear();
    },
  );

  it('runs scheduling after review without confirming a work order', async () => {
    vi.mocked(schedulerApi.run).mockResolvedValue({ scheduled: [], conflicts: [] });
    const client = mount();
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Run scheduler' })).toBeEnabled(),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Run scheduler' }));
    const dialog = screen.getByRole('dialog');
    expect(dialog).toHaveTextContent('all planned work orders');
    expect(dialog).toHaveTextContent('work-order records');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Run scheduler' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(schedulerApi.run).toHaveBeenCalledOnce();
    expect(schedulerApi.confirm).not.toHaveBeenCalled();
    client.clear();
  });

  it('offers retry after a snapshot read fails', async () => {
    vi.mocked(schedulerApi.snapshot).mockRejectedValueOnce(new Error('Unavailable'));
    const client = mount();
    expect(await screen.findByRole('alert')).toHaveTextContent('Schedule could not be loaded');
    fireEvent.click(screen.getByRole('button', { name: 'Retry schedule' }));
    await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
    client.clear();
  });
  it('preserves crew readiness and distinct failed-run diagnostics after reload', async () => {
    vi.mocked(schedulerApi.snapshot).mockResolvedValue({
      ...snapshot,
      conflicts: [
        {
          work_order_id: 'wo-failed',
          wo_number: 'WO-FAILED',
          reasons: ['No covered shift', 'No covered shift'],
          checked_at: '2026-10-05T10:00:00Z',
        },
      ],
      rows: snapshot.rows.map((row) => ({
        ...row,
        bars: row.bars.map((bar) => ({
          ...bar,
          staffing: {
            ...bar.staffing!,
            worker_count: 0,
            worker_names: [],
            expected_crew_id: null,
            approval_blockers: ['Assign a crew'],
          },
        })),
      })),
    });
    const client = mount();
    fireEvent.click(await screen.findByRole('tab', { name: /Schedule list/ }));
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    expect(await screen.findAllByText('Crew not assigned')).toHaveLength(3);
    expect(screen.getByText('No covered shift')).toBeVisible();
    expect(screen.getByText(/Last checked:/)).toBeVisible();
    expect(schedulerApi.confirm).not.toHaveBeenCalled();
    client.clear();
  });

  it('formats schedule quantities and exposes saved table density controls', async () => {
    vi.mocked(schedulerApi.snapshot).mockResolvedValue({
      ...snapshot,
      rows: snapshot.rows.map((row) => ({
        ...row,
        bars: row.bars.map((bar, index) => ({
          ...bar,
          quantity_target: index === 0 ? 1234567.125 : bar.quantity_target,
        })),
      })),
    });
    const client = mount();
    fireEvent.click(await screen.findByRole('tab', { name: /Schedule list/ }));
    expect(await screen.findByText('1,234,567.125')).toBeVisible();
    expect(screen.getByRole('combobox', { name: 'Density' })).toHaveValue('compact');
    expect(screen.getByRole('button', { name: 'Customize columns' })).toBeVisible();
    client.clear();
  });
});
