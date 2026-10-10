import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, fireEvent } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { customerPortalApi } from '@/api/b2b/customer';
import InvoiceDetail from './detail';

vi.mock('@/api/b2b/customer', () => ({ customerPortalApi: { getInvoice: vi.fn(), downloadInvoicePdf: vi.fn(), submitInvoicePayment: vi.fn() } }));

beforeEach(() => vi.clearAllMocks());

it('offers fixed-balance payment submission with required proof and live validation', async () => {
  vi.mocked(customerPortalApi.getInvoice).mockResolvedValue({
    id: 'inv-1', invoice_number: 'INV-1', date: '2026-10-09', due_date: '2026-11-08',
    total_amount: '19600.00', balance: '19600.00', status: 'finalized', items: [], collections: [],
    can_submit_payment: true, payment_submissions: [],
  });
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
    <MemoryRouter initialEntries={['/invoices/inv-1']}><Routes><Route path="/invoices/:id" element={<InvoiceDetail />} /></Routes></MemoryRouter>
  </QueryClientProvider>);
  fireEvent.click(await screen.findByRole('button', { name: 'Submit payment' }));
  expect(screen.getByText('Awaiting payment')).toBeInTheDocument();
  expect(screen.getByLabelText('Amount to settle')).toHaveValue('19600.00');
  expect(screen.getByLabelText('Amount to settle')).toHaveAttribute('readonly');
  expect(screen.getByRole('button', { name: 'Submit payment evidence' })).toBeDisabled();
  fireEvent.change(screen.getByLabelText(/Sending bank/), { target: { value: 'BDO' } });
  fireEvent.change(screen.getByLabelText(/Reference number/), { target: { value: '  ' } });
  expect(await screen.findByText('Reference number is required.')).toHaveClass('text-danger-fg');
  expect(customerPortalApi.submitInvoicePayment).not.toHaveBeenCalled();
});

it('shows verification status and no submission action while payment is pending', async () => {
  vi.mocked(customerPortalApi.getInvoice).mockResolvedValue({
    id: 'inv-1', invoice_number: 'INV-1', date: '2026-10-09', due_date: '2026-11-08',
    total_amount: '19600.00', balance: '19600.00', status: 'finalized', items: [], collections: [],
    can_submit_payment: false, payment_submissions: [{
      id: 'payment-1', status: 'pending', amount: '19600.00', sending_bank: 'BDO',
      reference_number: 'REF-1', payment_date: '2026-10-09', proof_file_name: 'proof.pdf',
      proof_mime: 'application/pdf', proof_size: 100, submitted_at: '2026-10-09T10:00:00Z',
      reviewed_at: null, rejection_reason: null, collection_id: null,
    }],
  });
  render(<QueryClientProvider client={new QueryClient()}><MemoryRouter initialEntries={['/invoices/inv-1']}><Routes><Route path="/invoices/:id" element={<InvoiceDetail />} /></Routes></MemoryRouter></QueryClientProvider>);
  expect(await screen.findByText('Awaiting payment verification')).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Submit payment' })).not.toBeInTheDocument();
});

it('rejects invalid proof and date before submission', async () => {
  vi.mocked(customerPortalApi.getInvoice).mockResolvedValue({ id: 'inv-1', invoice_number: 'INV-1', date: '2026-10-09', due_date: '2026-11-08', total_amount: '19600.00', balance: '19600.00', status: 'finalized', items: [], collections: [], can_submit_payment: true, payment_submissions: [] });
  render(<QueryClientProvider client={new QueryClient()}><MemoryRouter initialEntries={['/invoices/inv-1']}><Routes><Route path="/invoices/:id" element={<InvoiceDetail />} /></Routes></MemoryRouter></QueryClientProvider>);
  fireEvent.click(await screen.findByRole('button', { name: 'Submit payment' }));
  fireEvent.change(screen.getByLabelText(/Payment proof/), { target: { files: [new File(['bad'], 'proof.svg', { type: 'image/svg+xml' })] } });
  expect(await screen.findByText('Choose a PDF, JPEG, PNG, or WebP file.')).toHaveClass('text-danger-fg');
  const large = new File(['valid'], 'proof.pdf', { type: 'application/pdf' }); Object.defineProperty(large, 'size', { value: 10_485_761 });
  fireEvent.change(screen.getByLabelText(/Payment proof/), { target: { files: [large] } });
  expect(await screen.findByText('Proof must be 10 MiB or smaller.')).toHaveClass('text-danger-fg');
  fireEvent.change(screen.getByLabelText(/Payment date/), { target: { value: '2099-10-10' } });
  expect(await screen.findByText('Payment date must fall between invoice date and today.')).toHaveClass('text-danger-fg');
  expect(customerPortalApi.submitInvoicePayment).not.toHaveBeenCalled();
});
