import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { shipmentsApi } from '@/api/supply-chain';
import type { Shipment } from '@/types/supplyChain';
import CreateShipmentPage from './create';

vi.mock('@/api/supply-chain', () => ({
  shipmentsApi: { create: vi.fn(), purchaseOrderOptions: vi.fn() },
}));
vi.mock('@/hooks/useFormSafety', () => ({
  useFormSafety: () => ({
    allowNavigationAfterSave: vi.fn(),
    draftState: { hasDraft: false, draftAge: 0, restore: vi.fn(), discard: vi.fn() },
  }),
}));
vi.mock('@/components/ui/FormDraftBanner', () => ({ FormDraftBanner: () => null }));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const createdShipment = {
  id: 'shipment-new',
  shipment_number: 'SHP-202611-0001',
  status: 'ordered',
  version: '1',
  permissions: { manage: true },
  available_actions: { status_transitions: [], can_manage_documents: true },
} as unknown as Shipment;

describe('Shipment create freshness', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(shipmentsApi.purchaseOrderOptions).mockResolvedValue({
      data: [{
        id: 'po-1',
        po_number: 'PO-202611-0001',
        vendor: { name: 'Test vendor' },
        incoterm: 'FOB',
      }],
      meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 },
    } as never);
    vi.mocked(shipmentsApi.create).mockResolvedValue(createdShipment);
  });

  it('seeds the detail cache with the create response before navigating', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/supply-chain/shipments/create']}>
          <Routes>
            <Route path="/supply-chain/shipments/create" element={<CreateShipmentPage />} />
            <Route path="/supply-chain/shipments/:id" element={<span>Shipment detail route</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    await screen.findByRole('combobox', { name: 'Purchase order' });
    await waitFor(() => expect(screen.getByRole('button', { name: 'Create shipment' })).toBeEnabled());
    fireEvent.click(screen.getByRole('button', { name: 'Show Purchase order' }));
    fireEvent.click(await screen.findByRole('option', { name: /PO-202611-0001/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Create shipment' }));

    await waitFor(() => expect(shipmentsApi.create).toHaveBeenCalledOnce());
    expect(await screen.findByText('Shipment detail route')).toBeVisible();
    await waitFor(() => expect(queryClient.getQueryData(['supply-chain', 'shipments', 'shipment-new']))
      .toEqual(createdShipment));
    queryClient.clear();
  });
});
