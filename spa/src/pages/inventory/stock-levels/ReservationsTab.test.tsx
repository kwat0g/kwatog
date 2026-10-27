import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { stockLevelsApi } from '@/api/inventory/stock';
import { ReservationsTab } from './ReservationsTab';

const permissions = vi.hoisted(() => new Set<string>());
vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({ can: (permission: string) => permissions.has(permission) }),
}));
vi.mock('@/api/inventory/stock', () => ({ stockLevelsApi: { reservations: vi.fn() } }));

beforeEach(() => {
  vi.clearAllMocks();
  permissions.clear();
  vi.mocked(stockLevelsApi.reservations).mockResolvedValue({
    data: [
      {
        id: 'wo-14',
        work_order: { id: 'wo-14', wo_number: 'WO-202610-0014' },
        materials: [
          {
            id: 'line-1',
            work_order: { id: 'wo-14', wo_number: 'WO-202610-0014' },
            item: { id: 'resin', code: 'RM-001', name: 'Resin', unit_of_measure: 'kg' },
            location: { id: 'loc-1', full_code: 'MW-A-A-01' },
            quantity: '1.890',
            status: 'reserved',
            reserved_at: '2026-10-06T17:18:00Z',
            ended_at: null,
          },
          {
            id: 'line-2',
            work_order: { id: 'wo-14', wo_number: 'WO-202610-0014' },
            item: { id: 'bags', code: 'PKG-001', name: 'Poly Bag', unit_of_measure: 'pcs' },
            location: { id: 'loc-2', full_code: 'MW-A-A-11' },
            quantity: '100.000',
            status: 'issued',
            reserved_at: '2026-10-06T17:18:00Z',
            ended_at: null,
          },
        ],
      },
    ],
    meta: { total: 1, current_page: 1, last_page: 1, per_page: 50, from: 1, to: 1 },
    links: { first: '', last: '', prev: null, next: null },
  });
});

function mount() {
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MemoryRouter initialEntries={['/inventory/stock-levels?view=reservations&status=']}>
        <ReservationsTab />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

it('shows one WO summary and expands exact material quantities and locations', async () => {
  mount();
  expect(await screen.findByText('WO-202610-0014')).toBeVisible();
  expect(screen.getByText('2 materials')).toBeVisible();
  expect(screen.queryByText('RM-001')).not.toBeInTheDocument();
  expect(screen.queryByRole('link', { name: 'WO-202610-0014' })).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Expand row' }));
  expect(screen.getByText('RM-001')).toBeVisible();
  expect(screen.getByText('1.89 kg')).toBeVisible();
  expect(screen.getByText('100 pcs')).toBeVisible();
  expect(screen.getByText('MW-A-A-01')).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Collapse row' }));
  expect(screen.queryByText('RM-001')).not.toBeInTheDocument();
});

it('requests WO pagination and only links orders for permitted accounts', async () => {
  permissions.add('production.view');
  permissions.add('production.work_orders.view');
  mount();
  expect(await screen.findByRole('link', { name: 'WO-202610-0014' })).toHaveAttribute(
    'href',
    '/production/work-orders/wo-14',
  );
  await waitFor(() =>
    expect(stockLevelsApi.reservations).toHaveBeenCalledWith(
      expect.objectContaining({ group_by: 'work_order', per_page: 50 }),
    ),
  );
});

it('toggles material details by clicking the WO row or activating it with the keyboard', async () => {
  mount();
  const row = (await screen.findByText('WO-202610-0014')).closest('tr')!;
  expect(row).toHaveAttribute('tabindex', '0');
  fireEvent.click(within(row).getByText('2 materials'));
  expect(screen.getByText('RM-001')).toBeVisible();
  fireEvent.click(row);
  expect(screen.queryByText('RM-001')).not.toBeInTheDocument();
  fireEvent.keyDown(row, { key: 'Enter' });
  expect(screen.getByText('RM-001')).toBeVisible();
  fireEvent.keyDown(row, { key: ' ' });
  expect(screen.queryByText('RM-001')).not.toBeInTheDocument();
  fireEvent.click(within(row).getByRole('button', { name: 'Expand row' }));
  expect(screen.getByText('RM-001')).toBeVisible();
});
