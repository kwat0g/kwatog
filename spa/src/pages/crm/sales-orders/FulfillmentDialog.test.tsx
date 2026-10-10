import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { FulfillmentDialog } from './FulfillmentDialog';
import { salesOrdersApi } from '@/api/crm/salesOrders';
vi.mock('@/api/crm/salesOrders', () => ({ salesOrdersApi: {
  fulfillment: vi.fn().mockResolvedValue({ revision: 'test', editable: true, as_of: '', lines: [{ id: 'line', part_number: 'PC-001', name: 'Part', policy: 'stock_first', demand: '500.000', allocated_stock: '300.000', eligible_free_stock: '0.000', proposed_stock: '0.000', production_shortage: '200.000', production_commitments: '0.000' }] }),
  updateFulfillment: vi.fn().mockResolvedValue({}),
} }));
afterEach(cleanup);
it('separates existing planned production from additional production needed', async () => {
  vi.mocked(salesOrdersApi.fulfillment).mockResolvedValueOnce({
    revision: 'covered', editable: true, as_of: '',
    lines: [{ id: 'line', part_number: 'PC-001', name: 'Part', policy: 'stock_first', demand: '700.000', allocated_stock: '500.000', eligible_free_stock: '0.000', proposed_stock: '0.000', production_shortage: '0.000', production_commitments: '200.000' }],
  });
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><FulfillmentDialog orderId="covered-order" onClose={vi.fn()} /></QueryClientProvider>);
  expect((await screen.findByText('Planned production')).nextElementSibling).toHaveTextContent('200.000 pcs');
  const additional = screen.getByText('Additional production needed').nextElementSibling;
  expect(additional).toHaveTextContent('0.000 pcs');
  expect(screen.queryByText('Production shortage')).not.toBeInTheDocument();
  fireEvent.change(screen.getByLabelText(/Fulfillment policy/), { target: { value: 'produce_new' } });
  expect(additional).toHaveTextContent('Recalculated on save');
  expect(additional).not.toHaveTextContent('0.000 pcs');
});
it('requires planning reason and submits policy without client quantities', async () => {
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><FulfillmentDialog orderId="order" onClose={vi.fn()} /></QueryClientProvider>);
  expect(await screen.findByText('500.000 pcs')).toBeVisible();
  const commit = screen.getByRole('button', { name: 'Confirm fulfillment' });
  expect(commit).toBeDisabled();
  fireEvent.change(screen.getByLabelText(/Fulfillment policy/), { target: { value: 'produce_new' } });
  fireEvent.change(screen.getByLabelText(/Planning reason/), { target: { value: 'Fresh customer batch' } });
  fireEvent.click(commit);
  await vi.waitFor(() => expect(salesOrdersApi.updateFulfillment).toHaveBeenCalledWith('order', expect.objectContaining({ lines: [{ id: 'line', policy: 'produce_new' }], reason: 'Fresh customer batch' })));
});
