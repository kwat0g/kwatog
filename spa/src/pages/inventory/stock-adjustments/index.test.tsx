import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { stockAdjustmentsApi } from '@/api/inventory/stock';
import type { StockAdjustment } from '@/types/inventory';
import type { PaginatedResponse } from '@/types';
import StockAdjustmentsPage from './index';

const api = vi.hoisted(() => ({
  list: vi.fn(),
  options: vi.fn(),
  approve: vi.fn(),
  reject: vi.fn(),
  withdraw: vi.fn(),
}));
const grants = vi.hoisted(() => new Set<string>());
vi.mock('@/api/inventory/stock', () => ({ stockAdjustmentsApi: api }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: (permission: string) => grants.has(permission) }) }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeList: vi.fn() }));

const adjustment: StockAdjustment = {
  id: 'adjustment-hash', direction: 'out', quantity: '5.000', unit_cost: '10.0000', value: '50.00',
  reason_code: 'damage', reason_label: 'Damage', reason: 'Damaged goods', status: 'pending', status_label: 'Pending',
  item: { id: 'item-hash', code: 'ABC-1', name: 'Resin pellets', unit_of_measure: 'kg' },
  location: { id: 'location-hash', code: 'WH-1' }, stock_movement: null,
  requested_by: { id: 'maker-hash', name: 'Mina Santos' }, approved_by: null, approved_at: null,
  resolved_by: null, resolved_at: null, resolution_reason: null,
  can_approve: false, can_reject: false, can_withdraw: false, created_at: '2026-10-26T08:00:00+08:00',
  available_actions: { approve: false, reject: false, withdraw: false },
};

function pageData(row: StockAdjustment): PaginatedResponse<StockAdjustment> {
  return {
    data: [row],
    meta: { current_page: 1, last_page: 1, per_page: 50, total: 1, from: 1, to: 1 },
    links: { first: '', last: '', prev: null, next: null },
  };
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((complete) => { resolve = complete; });
  return { promise, resolve };
}

function renderPage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const view = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/inventory/stock-adjustments?id=adjustment-hash']}>
        <Routes><Route path="/inventory/stock-adjustments" element={<StockAdjustmentsPage />} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
  return { ...view, queryClient };
}

beforeEach(() => {
  vi.clearAllMocks();
  grants.clear();
  vi.mocked(stockAdjustmentsApi.list).mockResolvedValue(pageData(adjustment));
  vi.mocked(stockAdjustmentsApi.options).mockResolvedValue({
    approval_threshold: '0', statuses: [], directions: [], reasons: [],
  });
});

it('loads the exact adjustment from a notification link and can return to the full list', async () => {
  renderPage();

  expect(await screen.findAllByText('ABC-1')).not.toHaveLength(0);
  await waitFor(() => expect(stockAdjustmentsApi.list).toHaveBeenCalledWith(expect.objectContaining({ id: 'adjustment-hash' })));

  fireEvent.click(screen.getByRole('button', { name: 'Show all adjustments' }));

  await waitFor(() => expect(stockAdjustmentsApi.list).toHaveBeenLastCalledWith(expect.objectContaining({ id: '', page: 1 })));
  expect(screen.queryByRole('button', { name: 'Show all adjustments' })).not.toBeInTheDocument();
});

it('fails closed when a legacy can_* flag is present without server available_actions', async () => {
  grants.add('inventory.adjust.approve');
  vi.mocked(stockAdjustmentsApi.list).mockResolvedValue(pageData({
    ...adjustment,
    can_approve: true,
    can_reject: true,
    can_withdraw: true,
    available_actions: undefined,
  }));

  renderPage();

  expect(await screen.findAllByText('ABC-1')).not.toHaveLength(0);
  expect(screen.queryByRole('button', { name: /^Approve$/ })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /^Reject$/ })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /^Withdraw$/ })).not.toBeInTheDocument();
});

it('renders the approved API result while an older adjustment list read completes afterward', async () => {
  grants.add('inventory.adjust.approve');
  const pending: StockAdjustment = {
    ...adjustment,
    can_approve: true,
    can_reject: true,
    can_withdraw: false,
    available_actions: { approve: true, reject: true, withdraw: false },
  };
  const approved: StockAdjustment = {
    ...pending,
    status: 'approved',
    status_label: 'Approved',
    can_approve: false,
    can_reject: false,
    available_actions: { approve: false, reject: false, withdraw: false },
    approved_by: { id: 'finance-hash', name: 'Finance Manager' },
    approved_at: '2026-11-02T09:00:00+08:00',
    stock_movement: { id: 'movement-hash' } as StockAdjustment['stock_movement'],
  };
  vi.mocked(stockAdjustmentsApi.list).mockResolvedValue(pageData(pending));
  const { queryClient } = renderPage();

  await screen.findAllByText('ABC-1');
  const staleRead = deferred<PaginatedResponse<StockAdjustment>>();
  const freshRead = deferred<PaginatedResponse<StockAdjustment>>();
  vi.mocked(stockAdjustmentsApi.list)
    .mockImplementationOnce(() => staleRead.promise)
    .mockImplementationOnce(() => freshRead.promise);
  const approveResult = deferred<StockAdjustment>();
  vi.mocked(stockAdjustmentsApi.approve).mockImplementation(() => approveResult.promise);
  const approveButtonsBeforeConfirm = await screen.findAllByRole('button', { name: 'Approve' });
  fireEvent.click(approveButtonsBeforeConfirm.at(-1)!);
  const confirmButtons = await screen.findAllByRole('button', { name: 'Approve' });
  fireEvent.click(confirmButtons.at(-1)!);

  await waitFor(() => expect(stockAdjustmentsApi.approve).toHaveBeenCalledWith('adjustment-hash'));
  const staleRefetch = queryClient.refetchQueries({ queryKey: ['inventory', 'stock-adjustments'] });
  await waitFor(() => expect(stockAdjustmentsApi.list).toHaveBeenCalledTimes(2));
  // Resolve the action while the older list read is pending, so the actor's
  // fresh API response must win without allowing an action during that read.
  await act(async () => {
    approveResult.resolve(approved);
    await Promise.resolve();
  });
  await waitFor(() => expect(stockAdjustmentsApi.list).toHaveBeenCalledTimes(3));
  expect(screen.getAllByText('Approved').length).toBeGreaterThan(0);
  expect(screen.queryByRole('button', { name: /^Approve$/ })).not.toBeInTheDocument();

  await act(async () => {
    staleRead.resolve(pageData(pending));
    await staleRefetch;
  });
  expect(screen.getAllByText('Approved').length).toBeGreaterThan(0);

  await act(async () => {
    freshRead.resolve(pageData(approved));
    await Promise.resolve();
  });
});

it('closes a stale approval action and refreshes the row after a 409 conflict', async () => {
  grants.add('inventory.adjust.approve');
  const pending: StockAdjustment = {
    ...adjustment,
    can_approve: true,
    can_reject: true,
    available_actions: { approve: true, reject: true, withdraw: false },
  };
  const approved: StockAdjustment = {
    ...pending,
    status: 'approved',
    status_label: 'Approved',
    available_actions: { approve: false, reject: false, withdraw: false },
  };
  vi.mocked(stockAdjustmentsApi.list)
    .mockResolvedValueOnce(pageData(pending))
    .mockResolvedValueOnce(pageData(approved));
  vi.mocked(stockAdjustmentsApi.approve).mockRejectedValue({
    isAxiosError: true,
    response: { status: 409, data: { message: 'Adjustment is already approved. Refresh and review its current status.' } },
  });

  renderPage();
  fireEvent.click((await screen.findAllByRole('button', { name: /^Approve$/ })).at(-1)!);
  const confirmButtons = await screen.findAllByRole('button', { name: /^Approve$/ });
  fireEvent.click(confirmButtons.at(-1)!);

  await waitFor(() => expect(stockAdjustmentsApi.approve).toHaveBeenCalledWith('adjustment-hash'));
  await waitFor(() => expect(stockAdjustmentsApi.list).toHaveBeenCalledTimes(2));
  expect(await screen.findAllByText('Approved')).not.toHaveLength(0);
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /^Approve$/ })).not.toBeInTheDocument();
});

it('disables an open approval confirmation while event invalidation refreshes the row', async () => {
  grants.add('inventory.adjust.approve');
  const pending: StockAdjustment = {
    ...adjustment,
    available_actions: { approve: true, reject: true, withdraw: false },
  };
  vi.mocked(stockAdjustmentsApi.list).mockResolvedValue(pageData(pending));
  const { queryClient } = renderPage();
  fireEvent.click((await screen.findAllByRole('button', { name: /^Approve$/ })).at(-1)!);
  const dialog = screen.getByRole('dialog');
  const staleRead = deferred<PaginatedResponse<StockAdjustment>>();
  vi.mocked(stockAdjustmentsApi.list).mockImplementationOnce(() => staleRead.promise);
  const queryKey = ['inventory', 'stock-adjustments'];
  let invalidation!: Promise<void>;
  act(() => {
    invalidation = queryClient.invalidateQueries({ queryKey });
  });

  await waitFor(() => expect(queryClient.isFetching({ queryKey })).toBe(1));
  expect(within(dialog).getByRole('button', { name: /^Approve$/ })).toBeDisabled();

  await act(async () => {
    staleRead.resolve(pageData(pending));
    await invalidation;
  });
});

it('disables an open rejection dialog while event invalidation refreshes the row', async () => {
  grants.add('inventory.adjust.approve');
  const pending: StockAdjustment = {
    ...adjustment,
    available_actions: { approve: true, reject: true, withdraw: false },
  };
  vi.mocked(stockAdjustmentsApi.list).mockResolvedValue(pageData(pending));
  const { queryClient } = renderPage();
  fireEvent.click((await screen.findAllByRole('button', { name: /^Reject$/ })).at(-1)!);
  const dialog = screen.getByRole('dialog');
  fireEvent.change(within(dialog).getByLabelText(/Rejection reason/), {
    target: { value: 'The quantity evidence does not match.' },
  });
  const rejectButton = within(dialog).getByRole('button', { name: 'Reject adjustment' });
  expect(rejectButton).toBeEnabled();

  const staleRead = deferred<PaginatedResponse<StockAdjustment>>();
  vi.mocked(stockAdjustmentsApi.list).mockImplementationOnce(() => staleRead.promise);
  const queryKey = ['inventory', 'stock-adjustments'];
  let invalidation!: Promise<void>;
  act(() => {
    invalidation = queryClient.invalidateQueries({ queryKey });
  });

  await waitFor(() => expect(queryClient.isFetching({ queryKey })).toBe(1));
  expect(rejectButton).toBeDisabled();

  await act(async () => {
    staleRead.resolve(pageData(pending));
    await invalidation;
  });
});
