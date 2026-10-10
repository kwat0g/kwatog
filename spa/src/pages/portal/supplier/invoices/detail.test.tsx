import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { expect, it, vi } from 'vitest';
import { supplierPortalApi } from '@/api/b2b/supplier';
import SupplierInvoiceDetailPage from './detail';

vi.mock('react-router-dom', async (importOriginal) => ({
  ...(await importOriginal<typeof import('react-router-dom')>()),
  useParams: () => ({ id: 'invoice-1' }),
}));
vi.mock('@/api/b2b/supplier', () => ({ supplierPortalApi: { getInvoice: vi.fn() } }));

it('shows one supplier invoice with its two GRN-level AP matches', async () => {
  vi.mocked(supplierPortalApi.getInvoice).mockResolvedValue({
    id: 'invoice-1', supplier_invoice_number: 'SUP-100', bill_number: '2 AP bills',
    supplier_invoice_date: '2026-10-08', date: '2026-10-08', total_amount: '11200.00',
    amount_paid: '6720.00', balance: '4480.00', due_date: '2026-11-08', status: 'partial',
    purchase_order: { id: 'po-1', po_number: 'PO-1' }, goods_receipt_note: null,
    invoice_attachment: null, items: [], payments: [], bills: [
      { id: 'bill-1', bill_number: 'AP-1', grn_number: 'GRN-1', total_amount: '6720.00', balance: '0.00', status: 'paid' },
      { id: 'bill-2', bill_number: 'AP-2', grn_number: 'GRN-2', total_amount: '4480.00', balance: '4480.00', status: 'unpaid' },
    ],
  });
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={client}><MemoryRouter><SupplierInvoiceDetailPage /></MemoryRouter></QueryClientProvider>);

  expect(await screen.findByText('SUP-100')).toBeInTheDocument();
  expect(screen.getByText('GRN-1')).toBeInTheDocument();
  expect(screen.getByText('GRN-2')).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Download invoice file' })).not.toBeInTheDocument();
  client.clear();
});
