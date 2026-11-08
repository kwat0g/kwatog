import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { stockCountApi } from '@/api/inventory/warehouseWms';
import { warehouseApi } from '@/api/inventory/warehouse';
import type { StockCountSession } from '@/types/warehouse';
import { StockCountManager } from './stock-count';

vi.mock('@/api/inventory/warehouseWms', () => ({ stockCountApi: {
  page: vi.fn(),
  get: vi.fn(),
  options: vi.fn(),
  create: vi.fn(),
  start: vi.fn(),
  recordCount: vi.fn(),
  approveVariance: vi.fn(),
  complete: vi.fn(),
  cancel: vi.fn(),
} }));
vi.mock('@/api/inventory/warehouse', () => ({ warehouseApi: { tree: vi.fn() } }));
vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({ can: () => true, canApprove: () => true }),
}));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeList: vi.fn(), useRealtimeRecord: vi.fn() }));
vi.mock('@/stores/authStore', () => ({
  useAuthStore: (selector: (state: { user: { id: string } }) => unknown) => selector({ user: { id: 'approver-1' } }),
}));
vi.mock('react-hot-toast', () => ({ default: { error: vi.fn(), success: vi.fn() } }));

const session: StockCountSession = {
  id: 'session-1',
  session_number: 'SC-2026-0001',
  title: 'Monthly warehouse count',
  scope: 'warehouse',
  warehouse: { id: 'warehouse-1', name: 'Main', code: 'MAIN' },
  zone: null,
  status: 'in_progress',
  available_actions: ['cancel', 'complete'],
  total_locations: 1,
  counted_locations: 1,
  variance_count: 1,
  variance_value: '2.00',
  created_by: { id: 'creator-1', name: 'Count owner' },
  approved_by: null,
  frozen_at: null,
  completed_at: null,
  notes: null,
  items: [{
    id: 'count-item-1',
    session_id: 'session-1',
    location: { id: 'location-1', code: 'A-01', full_code: 'MAIN/A-01' },
    item: { id: 'resin-1', code: 'RES-01', name: 'Resin', unit_of_measure: 'kg' },
    system_quantity: '10',
    counted_quantity: '12',
    variance: '2',
    variance_percent: '20',
    lot_number: null,
    status: 'counted',
    status_label: 'Counted',
    available_actions: ['record_count', 'approve_variance'],
    counted_by: { id: 'counter-1', name: 'Counter' },
    counted_at: '2026-11-03T00:00:00Z',
    verified_by: null,
    notes: null,
  }],
  created_at: '2026-11-03T00:00:00Z',
  updated_at: '2026-11-03T00:00:00Z',
};

it('disables variance approval after the active session says the line is no longer counted', async () => {
  vi.mocked(stockCountApi.page).mockResolvedValue({
    data: [session],
    links: { first: '', last: '', prev: null, next: null },
    meta: { current_page: 1, last_page: 1, per_page: 50, total: 1, from: 1, to: 1 },
  });
  vi.mocked(stockCountApi.get).mockResolvedValue(session);
  vi.mocked(stockCountApi.options).mockResolvedValue({ scopes: [], variance_tolerance_pct: 5, default_scope: 'warehouse' });
  vi.mocked(warehouseApi.tree).mockResolvedValue([]);

  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const { unmount } = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/warehouse?session_id=session-1']}>
        <StockCountManager />
      </MemoryRouter>
    </QueryClientProvider>,
  );

  fireEvent.click(await screen.findByRole('button', { name: 'Approve' }));
  const dialog = screen.getByRole('dialog', { name: 'Approve variance?' });
  const completed = {
    ...session,
    status: 'completed' as const,
    available_actions: [],
    items: session.items?.map((item) => ({ ...item, status: 'adjusted' as const, available_actions: [] })),
  };
  act(() => queryClient.setQueryData(['warehouse', 'stock-count', 'session-1'], completed));

  expect(queryClient.getQueryData<StockCountSession>(['warehouse', 'stock-count', 'session-1'])?.status).toBe('completed');
  expect(await within(dialog).findByText(/variance is no longer available for approval/i)).toBeInTheDocument();
  expect(within(dialog).getByRole('button', { name: 'Approve' })).toBeDisabled();
  expect(stockCountApi.approveVariance).not.toHaveBeenCalled();

  unmount();
  queryClient.clear();
});

it('sends a submitted count while a realtime refetch is in flight for server revalidation', async () => {
  vi.clearAllMocks();
  vi.mocked(stockCountApi.page).mockResolvedValue({
    data: [session],
    links: { first: '', last: '', prev: null, next: null },
    meta: { current_page: 1, last_page: 1, per_page: 50, total: 1, from: 1, to: 1 },
  });
  vi.mocked(stockCountApi.get).mockResolvedValue(session);
  vi.mocked(stockCountApi.options).mockResolvedValue({ scopes: [], variance_tolerance_pct: 5, default_scope: 'warehouse' });
  vi.mocked(warehouseApi.tree).mockResolvedValue([]);
  vi.mocked(stockCountApi.recordCount).mockResolvedValue({
    ...session.items![0],
    counted_quantity: '13',
    variance: '3',
    status: 'counted',
  });

  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const { unmount } = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/warehouse?session_id=session-1']}>
        <StockCountManager />
      </MemoryRouter>
    </QueryClientProvider>,
  );

  fireEvent.click(await screen.findByRole('button', { name: 'Recount' }));
  const dialog = screen.getByRole('dialog', { name: 'Count — MAIN/A-01' });
  const input = within(dialog).getByRole('spinbutton', { name: /Counted quantity/ });
  fireEvent.change(input, { target: { value: '13' } });
  const form = input.closest('form');
  expect(form).not.toBeNull();

  vi.mocked(stockCountApi.get).mockImplementationOnce(() => new Promise(() => {}));
  void queryClient.refetchQueries({ queryKey: ['warehouse', 'stock-count', 'session-1'], exact: true });
  await waitFor(() => expect(queryClient.isFetching({ queryKey: ['warehouse', 'stock-count', 'session-1'] })).toBe(1));

  fireEvent.submit(form!);
  await waitFor(() => expect(stockCountApi.recordCount).toHaveBeenCalled());
  await waitFor(() => expect(dialog).not.toBeInTheDocument());
  const row = screen.getByText('RES-01').closest('tr');
  expect(row).not.toBeNull();
  expect(within(row!).getByText('13.000')).toBeVisible();
  expect(within(row!).getByRole('button', { name: 'Recount' })).toBeVisible();

  unmount();
  queryClient.clear();
});
