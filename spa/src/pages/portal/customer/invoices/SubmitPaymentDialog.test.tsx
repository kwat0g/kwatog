import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { customerPortalApi } from '@/api/b2b/customer';
import { SubmitPaymentDialog } from './SubmitPaymentDialog';
vi.mock('@/api/b2b/customer', () => ({ customerPortalApi: { getInvoice: vi.fn(), submitInvoicePayment: vi.fn() } }));
const invoice = { id: 'inv-1', invoice_number: 'INV-1', date: '2026-10-09', due_date: '2026-11-08', status: 'finalized', total_amount: '19600.00', balance: '19600.00', items: [], collections: [], can_submit_payment: true, payment_submissions: [] };
function open() {
 const close = vi.fn(); render(<QueryClientProvider client={new QueryClient()}><SubmitPaymentDialog invoice={invoice} onClose={close} /></QueryClientProvider>); return close;
}
async function prepare() {
 fireEvent.change(screen.getByLabelText(/Sending bank/), { target: { value: 'BDO' } });
 fireEvent.change(screen.getByLabelText(/Reference number/), { target: { value: 'REF-1' } });
 fireEvent.change(screen.getByLabelText(/Payment proof/), { target: { files: [new File(['%PDF'], 'proof.pdf', { type: 'application/pdf' })] } });
 await waitFor(() => expect(screen.getByRole('button', { name: 'Submit payment evidence' })).toBeEnabled());
}
beforeEach(() => vi.clearAllMocks());
it('sends fixed balance once and blocks closing during submission', async () => {
 vi.mocked(customerPortalApi.submitInvoicePayment).mockImplementation(() => new Promise(() => {}));
 const close = open(); await prepare(); const submit = screen.getByRole('button', { name: 'Submit payment evidence' }); fireEvent.click(submit); fireEvent.click(submit);
 await waitFor(() => expect(customerPortalApi.submitInvoicePayment).toHaveBeenCalledTimes(1));
 const form = vi.mocked(customerPortalApi.submitInvoicePayment).mock.calls[0][1]; expect(form.get('amount')).toBe('19600.00');
 fireEvent.click(screen.getByRole('button', { name: 'Close modal' })); expect(close).not.toHaveBeenCalled();
});
it('requires refresh after ambiguous failure and reuses identical submission key', async () => {
 vi.mocked(customerPortalApi.submitInvoicePayment).mockRejectedValue(new Error('network result unknown'));
 vi.mocked(customerPortalApi.getInvoice).mockResolvedValue(invoice); open(); await prepare(); fireEvent.click(screen.getByRole('button', { name: 'Submit payment evidence' }));
 await screen.findByRole('button', { name: 'Review refreshed invoice' }); expect(screen.getByRole('button', { name: 'Submit payment evidence' })).toBeDisabled();
 fireEvent.click(screen.getByRole('button', { name: 'Review refreshed invoice' })); await waitFor(() => expect(screen.getByRole('button', { name: 'Submit payment evidence' })).toBeEnabled());
 fireEvent.click(screen.getByRole('button', { name: 'Submit payment evidence' })); await waitFor(() => expect(customerPortalApi.submitInvoicePayment).toHaveBeenCalledTimes(2));
 const calls = vi.mocked(customerPortalApi.submitInvoicePayment).mock.calls; expect(calls[0][1].get('idempotency_key')).toBe(calls[1][1].get('idempotency_key'));
});
