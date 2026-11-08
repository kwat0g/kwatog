import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { billsApi } from '@/api/accounting/bills';
import { vendorsApi } from '@/api/accounting/vendors';
import type { Vendor } from '@/types/accounting';
import VendorDetailPage from './detail';

const realtime = vi.hoisted(() => ({ register: vi.fn() }));

vi.mock('@/hooks/useRealtimeEvent', () => ({
  useRealtimeEvent: (...args: unknown[]) => realtime.register(...args),
}));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => false }) }));
vi.mock('@/api/accounting/vendors', () => ({
  vendorsApi: {
    show: vi.fn(),
    bir2307: vi.fn(),
    bir2307PdfUrl: vi.fn(() => '/api/v1/vendors/vendor-1/bir-2307/pdf'),
  },
}));
vi.mock('@/api/accounting/bills', () => ({ billsApi: { list: vi.fn() } }));

function vendor(name: string): Vendor {
  return {
    id: 'vendor-1',
    name,
    contact_person: null,
    email: null,
    phone: null,
    address: null,
    tin: null,
    payment_terms_days: 30,
    is_active: true,
  };
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  vi.mocked(billsApi.list).mockResolvedValue({
    data: [],
    meta: { total: 0, per_page: 50, current_page: 1, last_page: 1 },
  } as never);

  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/accounting/vendors/vendor-1']}>
        <Routes>
          <Route path="/accounting/vendors/:id" element={<VendorDetailPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  return client;
}

describe('vendor detail realtime refresh', () => {
  beforeEach(() => vi.clearAllMocks());

  it('refetches the open vendor detail from its authorized record signal', async () => {
    vi.mocked(vendorsApi.show)
      .mockResolvedValueOnce(vendor('Old Vendor'))
      .mockResolvedValueOnce(vendor('Updated Vendor'));
    const client = renderPage();

    expect(await screen.findByText('Old Vendor')).toBeInTheDocument();
    expect(realtime.register).toHaveBeenCalledWith(
      'accounting.vendor.vendor-1',
      '.accounting.vendor.changed',
      expect.any(Function),
      expect.any(Function),
      expect.any(Function),
    );

    const registration = realtime.register.mock.calls.find(([channel, event]) =>
      channel === 'accounting.vendor.vendor-1' && event === '.accounting.vendor.changed');
    const onChanged = registration?.[2] as ((payload: unknown) => void) | undefined;
    await act(async () => {
      onChanged?.({ entity_type: 'vendor', hash_id: 'vendor-1', action: 'updated' });
    });

    expect(await screen.findByText('Updated Vendor')).toBeInTheDocument();
    expect(vendorsApi.show).toHaveBeenCalledTimes(2);
    client.clear();
  });
});
