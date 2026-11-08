import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { accountsApi } from '@/api/accounting/accounts';
import { invoicesApi } from '@/api/accounting/invoices';
import type { Invoice } from '@/types/accounting';
import type { InvoicePaymentSubmission } from '@/types/invoice-payments';
import { ReviewPaymentDialog } from './ReviewPaymentDialog';
vi.mock('@/api/accounting/accounts', () => ({ accountsApi: { tree: vi.fn() } }));
vi.mock('@/api/accounting/invoices', () => ({ invoicesApi: { verifyPaymentSubmission: vi.fn(), rejectPaymentSubmission: vi.fn(), show: vi.fn() } }));
vi.mock('@/api/download', () => ({ downloadAuthenticatedFile: vi.fn() }));
const payment: InvoicePaymentSubmission = { id: 'payment-1', status: 'pending', amount: '19600.00', sending_bank: 'BDO', reference_number: 'REF-1', payment_date: '2026-10-09', proof_file_name: 'proof.pdf', proof_mime: 'application/pdf', proof_size: 100, submitted_at: '2026-10-09T10:00:00Z', reviewed_at: null, rejection_reason: null, collection_id: null, available_actions: { verify: true, reject: true } };
function open(row = payment) {
  const close = vi.fn();
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  render(<QueryClientProvider client={queryClient}><ReviewPaymentDialog invoiceId="inv-1" invoiceNumber="INV-1" payment={row} onClose={close} /></QueryClientProvider>);
  return { close, queryClient };
}
beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(accountsApi.tree).mockResolvedValue([
    { id: 'bank-1', code: '1020', name: 'Bank', type: 'asset', normal_balance: 'debit', parent_id: null, is_active: true, description: null },
    { id: 'cash-1', code: '1010', name: 'Cash on hand', type: 'asset', normal_balance: 'debit', parent_id: null, is_active: true, description: null },
  ]);
});
it('requires bank verification and prevents duplicate posts or closing', async () => {
  vi.mocked(invoicesApi.verifyPaymentSubmission).mockImplementation(() => new Promise(() => {}));
  const { close } = open();
  expect(screen.getByLabelText('Payment amount')).toHaveAttribute('readonly');
  expect(screen.getByLabelText('Payment amount')).toHaveValue('19600.00');
  expect(screen.getByRole('button', { name: 'Confirm funds received' })).toBeDisabled();
  await screen.findByRole('option', { name: '1020 · Bank' });
  expect(screen.queryByRole('option', { name: /Cash on hand/ })).not.toBeInTheDocument();
  fireEvent.change(screen.getByLabelText(/Receiving bank account/), { target: { value: 'bank-1' } });
  fireEvent.click(screen.getByRole('checkbox'));
  const confirm = screen.getByRole('button', { name: 'Confirm funds received' });
  fireEvent.click(confirm); fireEvent.click(confirm);
  await waitFor(() => expect(invoicesApi.verifyPaymentSubmission).toHaveBeenCalledTimes(1));
  expect(invoicesApi.verifyPaymentSubmission).toHaveBeenCalledWith('inv-1', 'payment-1', { cash_account_id: 'bank-1', funds_received_confirmed: true });
  fireEvent.click(screen.getByRole('button', { name: 'Close modal' }));
  expect(close).not.toHaveBeenCalled();
});
it('requires a trimmed rejection reason without verification', async () => {
  vi.mocked(invoicesApi.rejectPaymentSubmission).mockResolvedValue({ ...payment, status: 'rejected' });
  open(); fireEvent.click(screen.getByRole('button', { name: 'Reject payment' }));
  fireEvent.change(screen.getByLabelText(/Rejection reason/), { target: { value: '  ' } });
  expect(screen.getByRole('button', { name: 'Reject payment' })).toBeDisabled();
  expect(screen.getByText('Rejection reason is required.')).toHaveClass('text-danger-fg');
  fireEvent.change(screen.getByLabelText(/Rejection reason/), { target: { value: ' Wrong bank reference. ' } });
  fireEvent.click(screen.getByRole('button', { name: 'Reject payment' }));
  await waitFor(() => expect(invoicesApi.rejectPaymentSubmission).toHaveBeenCalledWith('inv-1', 'payment-1', { reason: 'Wrong bank reference.' }));
  expect(invoicesApi.verifyPaymentSubmission).not.toHaveBeenCalled();
});
it('blocks verification when bank options fail', async () => {
  vi.mocked(accountsApi.tree).mockRejectedValue(new Error('offline')); open();
  expect(await screen.findByRole('button', { name: 'Retry bank accounts' })).toBeEnabled();
  expect(screen.getByRole('button', { name: 'Confirm funds received' })).toBeDisabled();
});

it('hides review actions when the server says the payment is no longer actionable', () => {
  open({ ...payment, status: 'verified', available_actions: { verify: false, reject: false } });
  expect(screen.getByRole('status')).toHaveTextContent('no longer available for review');
  expect(screen.queryByRole('button', { name: 'Confirm funds received' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Reject payment' })).not.toBeInTheDocument();
});

it('updates the cached invoice payment submission from the rejection response', async () => {
  const { queryClient } = open();
  const invoiceKey = ['accounting', 'invoices', 'inv-1'] as const;
  const invoice = { id: 'inv-1', payment_submissions: [payment] } as Invoice;
  queryClient.setQueryData(invoiceKey, invoice);
  vi.mocked(invoicesApi.rejectPaymentSubmission).mockResolvedValue({
    ...payment,
    status: 'rejected',
    available_actions: { verify: false, reject: false },
    rejection_reason: 'Wrong bank reference.',
    reviewed_at: '2026-11-02T09:00:00Z',
  });

  fireEvent.click(screen.getByRole('button', { name: 'Reject payment' }));
  fireEvent.change(screen.getByLabelText(/Rejection reason/), { target: { value: 'Wrong bank reference.' } });
  fireEvent.click(screen.getByRole('button', { name: 'Reject payment' }));

  await waitFor(() => expect(invoicesApi.rejectPaymentSubmission).toHaveBeenCalled());
  await waitFor(() => expect(queryClient.getQueryData<Invoice>(invoiceKey)?.payment_submissions?.[0]?.status).toBe('rejected'));
  expect(queryClient.getQueryData<Invoice>(invoiceKey)?.available_actions?.review_payment).toBe(false);
});
