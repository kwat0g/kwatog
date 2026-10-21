import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { returnManagementApi } from '@/api/returnManagement';
import { manilaIsoDate } from '@/lib/formatDate';
import DisposeDialog from './dispose';

vi.mock('@/api/returnManagement', () => ({ returnManagementApi: { options: vi.fn(), dispose: vi.fn() } }));
vi.mock('@/api/inventory/warehouse', () => ({ warehouseApi: { tree: vi.fn().mockResolvedValue([]) } }));

it('shows and retries a failed disposition lookup while blocking stock-changing submission', async () => {
  vi.mocked(returnManagementApi.options).mockRejectedValueOnce(new Error('Unavailable')).mockResolvedValue({ types: [], statuses: [], reasons: [], resolutions: [], conditions: [], dispositions: [], disposition_matrix: {} });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><DisposeDialog rma={{ id: 'rma-1', type: 'supplier_return', type_label: 'Supplier return', rma_number: 'RMA-1', status: 'inspected', status_label: 'Inspected', is_editable: false, created_at: '', updated_at: '', items: [], item_count: 0 }} isOpen onClose={() => {}} /></QueryClientProvider>);
  expect(await screen.findByRole('alert')).toHaveTextContent('Disposition options could not be loaded');
  expect(screen.getByRole('button', { name: 'Record Disposition' })).toBeDisabled();
  fireEvent.click(screen.getByRole('button', { name: 'Retry dispositions' }));
  await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
  client.clear();
});

it('arranges checked supplier defects without a second warehouse location', async () => {
  vi.mocked(returnManagementApi.options).mockResolvedValue({ types: [], statuses: [], reasons: [], resolutions: [], conditions: [], dispositions: [{ value: 'return_to_supplier', label: 'Return to Supplier' }], disposition_matrix: {} });
  vi.mocked(returnManagementApi.dispose).mockResolvedValue({ replacement_purchase_order: { id: 'po-1', po_number: 'PO-1', status: 'sent' } } as never);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const rma = { id: 'rma-2', type: 'supplier_return' as const, type_label: 'Supplier return', rma_number: 'RMA-2', status: 'approved', status_label: 'Approved', is_editable: false, requires_defect_evidence: true, created_at: '', updated_at: '', item_count: 1, items: [{ id: 'line-2', item_id: 'item-2', quantity: '2', returned_quantity: '2', receipt_recorded: true, moved_quantity: '2', unit_price: '0.50', total: '1.00' }] };
  render(<QueryClientProvider client={client}><DisposeDialog rma={rma} isOpen onClose={() => {}} /></QueryClientProvider>);
  expect(screen.getByLabelText(/Required delivery date/)).toHaveAttribute('min', manilaIsoDate());
  await screen.findByRole('option', { name: 'Return to Supplier' });
  fireEvent.change(screen.getByRole('combobox', { name: 'Disposition' }), { target: { value: 'return_to_supplier' } });
  expect(screen.queryByLabelText('Restock location')).not.toBeInTheDocument();
  expect(screen.queryByRole('checkbox', { name: /Raise a replacement purchase order/ })).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Finish earlier return' })).toBeDisabled();
  const date = manilaIsoDate(new Date(Date.now() + 4 * 24 * 60 * 60 * 1000));
  fireEvent.change(screen.getByLabelText(/Required delivery date/), { target: { value: date } });
  fireEvent.click(screen.getByRole('button', { name: 'Finish earlier return' }));
  await waitFor(() => expect(returnManagementApi.dispose).toHaveBeenCalledWith('rma-2', [{ item_id: 'line-2', disposition: 'return_to_supplier', notes: undefined }], true, undefined, date));
  client.clear();
});
