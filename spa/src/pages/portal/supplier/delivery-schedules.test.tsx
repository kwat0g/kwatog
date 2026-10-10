import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { supplierPortalApi } from '@/api/b2b/supplier';
import SupplierDeliverySchedulesPage from './delivery-schedules';

vi.mock('@/api/b2b/supplier', () => ({ supplierPortalApi: { listDeliverySchedules: vi.fn() } }));
afterEach(() => { cleanup(); vi.clearAllMocks(); });

it('shows existing supplier plans as read-only history', async () => {
  vi.mocked(supplierPortalApi.listDeliverySchedules).mockResolvedValue({
    data: [{ id: 'old', month: '2026-09', planned_delivery_date: null, precision: 'month', status: 'acknowledged',
      lines: [], created_at: '2026-09-01', updated_at: '2026-09-01' }],
    meta: { total: 1, current_page: 1, last_page: 1, per_page: 25, from: 1, to: 1 }, links: { first: '', last: '', prev: null, next: null },
  } as Awaited<ReturnType<typeof supplierPortalApi.listDeliverySchedules>>);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter initialEntries={['/portal/supplier/delivery-schedules?purchase_order_id=po-1']}><SupplierDeliverySchedulesPage /></MemoryRouter></QueryClientProvider>);
  expect(await screen.findByText('2026-09 · Month-only plan')).toBeInTheDocument();
  expect(supplierPortalApi.listDeliverySchedules).toHaveBeenCalledWith({ page: 1, per_page: 25, purchase_order_id: 'po-1' });
  expect(screen.queryByRole('button', { name: /new schedule|cancel schedule|submit schedule/i })).not.toBeInTheDocument();
  client.clear();
});
