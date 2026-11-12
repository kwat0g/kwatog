import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ShipmentsListPage from './index';

const mocks = vi.hoisted(() => ({ list: vi.fn(), options: vi.fn() }));

vi.mock('@/api/supply-chain', () => ({ shipmentsApi: { list: mocks.list, options: mocks.options } }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeList: vi.fn() }));

function renderPage() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter><ShipmentsListPage /></MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('Inbound shipment date filters', () => {
  beforeEach(() => {
    vi.setSystemTime(new Date('2026-11-10T12:00:00Z'));
    mocks.list.mockResolvedValue({
      data: [],
      meta: { current_page: 1, from: null, last_page: 1, per_page: 25, to: null, total: 0 },
      links: { first: '', last: '', prev: null, next: null },
    });
    mocks.options.mockResolvedValue({ statuses: [] });
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.clearAllMocks();
  });

  it.each([
    ['Last 7 days', '2026-11-04'],
    ['Last 30 days', '2026-10-12'],
    ['Last 90 days', '2026-08-13'],
  ])('applies the inclusive %s range to the server query', async (label, fromDate) => {
    renderPage();
    await waitFor(() => expect(mocks.list).toHaveBeenCalled());
    fireEvent.click(screen.getByRole('button', { name: label }));

    await waitFor(() => expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({
      from_date: fromDate,
      to_date: '2026-11-10',
    })));
  });

  it('applies a manually entered date range', async () => {
    renderPage();
    await waitFor(() => expect(mocks.list).toHaveBeenCalled());
    fireEvent.change(screen.getByLabelText('From date'), { target: { value: '2026-10-01' } });
    fireEvent.change(screen.getByLabelText('To date'), { target: { value: '2026-10-07' } });
    fireEvent.click(screen.getByRole('button', { name: 'Apply date range' }));

    await waitFor(() => expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({
      from_date: '2026-10-01',
      to_date: '2026-10-07',
    })));
  });
});
