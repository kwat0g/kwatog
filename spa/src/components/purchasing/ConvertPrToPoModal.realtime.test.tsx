import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { vendorsApi } from '@/api/accounting/vendors';
import { purchaseRequestsApi } from '@/api/purchasing/purchase-requests';
import type { PurchaseRequest, SourcingLine } from '@/types/purchasing';
import { ConvertPrToPoModal } from './ConvertPrToPoModal';

vi.mock('@/api/accounting/vendors', () => ({ vendorsApi: { list: vi.fn() } }));
vi.mock('@/api/purchasing/purchase-requests', () => ({
  purchaseRequestsApi: {
    show: vi.fn(),
    sourcing: vi.fn(),
    convert: vi.fn(),
  },
}));

const request: PurchaseRequest = {
  id: 'pr-1',
  pr_number: 'PR-202611-0001',
  date: '2026-11-03',
  required_delivery_date: '2026-11-10',
  reason: 'Production material',
  priority: 'normal',
  status: 'approved',
  po_conversion_status: 'not_started',
  sourcing_method: 'direct_po',
  po_conversion_note: null,
  po_conversion_at: null,
  is_auto_generated: false,
  auto_generated_reason: null,
  is_urgent: false,
  urgency_reason: null,
  has_overdue_approval: false,
  current_approval_step: 0,
  submitted_at: null,
  approved_at: '2026-11-03T00:00:00Z',
  total_estimated_amount: '100.00',
  actions: undefined,
  requester: null,
  department: null,
  template: null,
};

const line: SourcingLine = {
  id: 'line-1',
  item: { id: 'item-1', code: 'RES-01', name: 'Resin', unit_of_measure: 'kg' },
  description: 'Production resin',
  quantity: '10',
  unit: 'kg',
  estimated_unit_price: '10.00',
  estimated_total: '100.00',
  suggested_vendor_id: 'vendor-1',
  suggested_unit_price: '10.00',
  candidates: [{
    id: 'vendor-1',
    name: 'Supplier One',
    tier: 'approved',
    qualified: true,
    unit_price: '10.00',
    lead_time_days: 5,
    order_uom: 'kg',
    base_qty_per_order_unit: '1',
  }],
};

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(vendorsApi.list).mockResolvedValue({ data: [] } as never);
  vi.mocked(purchaseRequestsApi.sourcing).mockResolvedValue({ lines: [line] });
  vi.mocked(purchaseRequestsApi.show).mockResolvedValue({
    ...request,
    status: 'converted',
    po_conversion_status: 'converted',
    actions: { can_convert: false },
  } as PurchaseRequest);
});

it('disables conversion when the current authorized PR detail no longer permits it', async () => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <ConvertPrToPoModal purchaseRequest={request} onClose={vi.fn()} />
      </MemoryRouter>
    </QueryClientProvider>,
  );

  const convertButton = await screen.findByRole('button', { name: 'Create purchase order' });
  expect(await screen.findByRole('alert')).toHaveTextContent(/no longer available for conversion/i);
  expect(convertButton).toBeDisabled();
  expect(purchaseRequestsApi.convert).not.toHaveBeenCalled();
  queryClient.clear();
});
