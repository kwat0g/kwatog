import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ShipmentDetailPage from './detail';
import { shipmentContainersApi, shipmentsApi } from '@/api/supply-chain';
import type { Shipment } from '@/types/supplyChain';

vi.mock('@/api/supply-chain', () => ({
  shipmentsApi: {
    show: vi.fn(), options: vi.fn(), updateStatus: vi.fn(), updateMeta: vi.fn(),
    calculateLandedCost: vi.fn(), uploadDocument: vi.fn(), destroyDocument: vi.fn(), restoreDocument: vi.fn(),
  },
  shipmentContainersApi: { list: vi.fn(), create: vi.fn(), update: vi.fn(), archive: vi.fn(), restore: vi.fn() },
}));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeRecord: vi.fn() }));

const legacyShipment = {
  id: 'shipment-1', shipment_number: 'SHP-2026-0001', status: 'ordered', version: '2026-11-03T00:00:00Z',
  carrier: null, vessel: null, container_number: null, bl_number: null, incoterm: null,
  etd: null, atd: null, eta: null, ata: null, customs_clearance_date: null,
  freight_cost: '0.00', insurance_cost: '0.00', duties_amount: '0.00', brokerage_fee: '0.00', other_charges: '0.00',
  landed_cost_total: null, allocation_method: null, landed_cost_calculated_at: null, landed_costs: [],
  notes: null, purchase_order: null, goods_receipt_note: null, documents: [],
  created_at: '2026-11-03T00:00:00Z', updated_at: '2026-11-03T00:00:00Z',
} as unknown as Shipment;

describe('ShipmentDetailPage server action projection', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(shipmentsApi.show).mockResolvedValue(legacyShipment);
    vi.mocked(shipmentsApi.options).mockResolvedValue({ statuses: [], document_types: [], allocation_methods: [], container_sizes: [], container_types: [] } as never);
    vi.mocked(shipmentContainersApi.list).mockResolvedValue({
      data: [], links: { first: '', last: '', prev: null, next: null },
      meta: { current_page: 1, last_page: 1, per_page: 100, total: 0, from: null, to: null },
    } as never);
  });

  it('does not infer container or document controls from local permission when actions are missing', async () => {
    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <MemoryRouter initialEntries={['/supply-chain/shipments/shipment-1']}>
          <Routes><Route path="/supply-chain/shipments/:id" element={<ShipmentDetailPage />} /></Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    expect(await screen.findByText('SHP-2026-0001')).toBeVisible();
    expect(screen.queryByRole('button', { name: 'Add container' })).not.toBeInTheDocument();
    expect(screen.queryByText('Upload document')).not.toBeInTheDocument();
  });
});
