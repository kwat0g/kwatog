import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { accountsApi } from '@/api/accounting/accounts';
import { customerAdvancesApi } from '@/api/accounting/customerAdvances';
import CustomerAdvancesPage from './customer-advances';

vi.mock('@/api/accounting/accounts', () => ({ accountsApi: { tree: vi.fn() } }));
vi.mock('@/api/accounting/customerAdvances', () => ({ customerAdvancesApi: {
  list: vi.fn(), verify: vi.fn(), reject: vi.fn(), waive: vi.fn(), refund: vi.fn(), proofPath: vi.fn(),
} }));
vi.mock('@/api/download', () => ({ downloadAuthenticatedFile: vi.fn() }));
afterEach(() => { cleanup(); vi.clearAllMocks(); });

it('requires a receiving account and confirmation before Finance verifies a deposit', async () => {
  vi.mocked(customerAdvancesApi.list).mockResolvedValue({
    data: [{ id: 'advance-1', sales_order_id: 'so-1', sales_order_number: 'SO-1', customer_id: 'c-1', customer_name: 'Honda',
      attempt_number: 1, order_total_snapshot: '1000.00', required_amount: '250.00', percentage: '25.00', submitted_amount: '250.00',
      refunded_amount: '0.00', unapplied_amount: '250.00', status: 'pending', sending_bank: 'BDO', reference_number: 'REF-1',
      payment_date: '2026-11-02', proof_file_name: 'receipt.pdf', review_reason: null, refund_reference: null, refund_reason: null,
      verified_at: null, reviewed_at: null, created_at: '2026-11-02T00:00:00Z' }],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
    links: { first: '', last: '', prev: null, next: null },
  });
  vi.mocked(accountsApi.tree).mockResolvedValue([{ id: 'bank-root', code: '1020', name: 'Cash in Bank', type: 'asset', is_active: true, children: [
    { id: 'bank-1', code: '1020-01', name: 'BDO Current', type: 'asset', is_active: true, children: [] },
  ] }] as never);
  vi.mocked(customerAdvancesApi.verify).mockResolvedValue({} as never);
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={queryClient}><MemoryRouter><CustomerAdvancesPage /></MemoryRouter></QueryClientProvider>);

  expect(await screen.findByText('SO-1')).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Review proof' }));
  await screen.findByRole('option', { name: '1020-01 · BDO Current' });
  const verify = screen.getByRole('button', { name: 'Confirm funds received' });
  expect(verify).toBeDisabled();
  fireEvent.change(screen.getByLabelText(/Receiving bank account/), { target: { value: 'bank-1' } });
  fireEvent.click(screen.getByRole('checkbox'));
  expect(verify).toBeEnabled();
  fireEvent.click(verify);

  await waitFor(() => expect(customerAdvancesApi.verify).toHaveBeenCalledWith('advance-1', 'bank-1'));
  queryClient.clear();
});
