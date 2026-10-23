import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { manilaIsoDate } from '@/lib/formatDate';
import { returnManagementApi } from '@/api/returnManagement';
import ReturnRequestDetailPage from './detail';

vi.mock('@/api/returnManagement', () => ({ returnManagementApi: { get: vi.fn(), splitItem: vi.fn(), submit: vi.fn(), options: vi.fn().mockResolvedValue({ reasons: [], resolutions: [], conditions: [], dispositions: [] }) } }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => true }) }));
vi.mock('@/stores/authStore', () => ({ useAuthStore: (selector: (state: { user: { role: { slug: string } } }) => unknown) => selector({ user: { role: { slug: 'purchasing_officer' } } }) }));
vi.mock('react-router-dom', async original => ({ ...(await original<typeof import('react-router-dom')>()), useParams: () => ({ id: 'rma-1' }) }));

it('splits whole received pieces using the exact return line', async () => {
  const record = { id: 'rma-1', rma_number: 'RMA-1', type: 'customer_return' as const, type_label: 'Customer return', status: 'received', status_label: 'Received', is_editable: false, item_count: 1, created_at: '', updated_at: '', items: [{ id: 'line-1', item_id: 'item-1', quantity: '100', returned_quantity: '100', received_total: '100', unit_price: '10', total: '1000', item: { id: 'item-1', code: 'PART-1', name: 'Part', unit_of_measure: 'PCS' } }] };
  vi.mocked(returnManagementApi.get).mockResolvedValue(record);
  vi.mocked(returnManagementApi.splitItem).mockResolvedValue(record);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><ReturnRequestDetailPage /></MemoryRouter></QueryClientProvider>);
  fireEvent.click(await screen.findByRole('button', { name: 'Split quantity' }));
  const quantity = screen.getByRole('spinbutton', { name: 'Quantity for the new line' });
  fireEvent.change(quantity, { target: { value: '1.5' } });
  expect(screen.getByRole('button', { name: 'Split line' })).toBeDisabled();
  fireEvent.change(quantity, { target: { value: '1' } });
  fireEvent.click(screen.getByRole('button', { name: 'Split line' }));
  await waitFor(() => expect(returnManagementApi.splitItem).toHaveBeenCalledWith('rma-1', 'line-1', '1'));
  client.clear();
});

it('requires the delivery date when Purchasing sends the QC return', async () => {
  const record = { id: 'rma-1', rma_number: 'RMA-1', type: 'supplier_return' as const, type_label: 'Supplier return', status: 'draft', status_label: 'Draft', is_editable: false, requires_defect_evidence: true, reason_description: 'QC-1: Two torn bags.', item_count: 1, created_at: '', updated_at: '', items: [{ id: 'line-1', item_id: 'item-1', quantity: '2', returned_quantity: '2', receipt_recorded: true, reused_incoming_inspection_id: 'qc-1', moved_quantity: '2', unit_price: '0.50', total: '1.00' }] };
  vi.mocked(returnManagementApi.get).mockResolvedValue(record);
  vi.mocked(returnManagementApi.submit).mockResolvedValue({ ...record, status: 'completed', status_label: 'Completed' });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><ReturnRequestDetailPage /></MemoryRouter></QueryClientProvider>);
  fireEvent.click(await screen.findByRole('button', { name: 'Send to supplier' }));
  expect(screen.getByLabelText(/Required delivery date/)).toHaveAttribute('min', manilaIsoDate());
  const send = screen.getAllByRole('button', { name: 'Send to supplier' }).at(-1)!;
  expect(send).toBeDisabled();
  fireEvent.change(screen.getByLabelText(/Photo of defective items/), { target: { files: [new File(['photo'], 'defect.png', { type: 'image/png' })] } });
  fireEvent.change(screen.getByLabelText(/Required delivery date/), { target: { value: '2099-01-01' } });
  expect(send).toBeEnabled();
  fireEvent.submit(send.closest('form')!);
  await waitFor(() => expect(returnManagementApi.submit).toHaveBeenCalledWith('rma-1', expect.any(FormData)));
  expect(vi.mocked(returnManagementApi.submit).mock.calls[0][1]?.get('required_delivery_date')).toBe('2099-01-01');
  expect(screen.queryByRole('button', { name: 'Record Receipt' })).not.toBeInTheDocument();
  client.clear();
});
