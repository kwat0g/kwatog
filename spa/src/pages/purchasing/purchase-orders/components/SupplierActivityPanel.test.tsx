import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { supplierActivityApi } from '@/api/b2b/supplier-activity';
import { SupplierActivityPanel } from './SupplierActivityPanel';

vi.mock('@/api/b2b/supplier-activity', () => ({ supplierActivityApi: { forPurchaseOrder: vi.fn() } }));
afterEach(() => { cleanup(); vi.clearAllMocks(); });

it('keeps retired delivery plans off the Purchasing PO page', async () => {
  vi.mocked(supplierActivityApi.forPurchaseOrder).mockResolvedValue({ shipments: [], documents: [], delivery_schedules: [{
    id: 'schedule-1', month: '2026-10', planned_delivery_date: '2026-10-13', status: 'submitted',
    status_label: 'Submitted', lines: [{ purchase_order_item_id: 'line-1', product_name: 'Poly bags', quantity: '20.00', notes: null }],
    cancel_reason: null, reject_reason: null,
  }] });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><SupplierActivityPanel purchaseOrderId="po-1" orderedLines={[]} /></QueryClientProvider>);
  expect(await screen.findByText('The supplier has not reported shipments or documents yet.')).toBeInTheDocument();
  expect(screen.queryByText('Oct 13, 2026')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Acknowledge' })).not.toBeInTheDocument();
  client.clear();
});
