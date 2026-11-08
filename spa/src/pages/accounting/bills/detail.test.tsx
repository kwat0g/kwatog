import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AxiosError } from 'axios';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { accountingOptionsApi } from '@/api/accounting/options';
import { accountsApi } from '@/api/accounting/accounts';
import { billsApi } from '@/api/accounting/bills';
import type { Bill, BillPayment } from '@/types/accounting';
import BillDetailPage from './detail';

const grants = vi.hoisted(() => new Set<string>());
vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({ can: (permission: string) => grants.has(permission) }),
}));
vi.mock('@/hooks/useChainProgress', () => ({ useChainProgress: () => {} }));
vi.mock('@/api/accounting/bills', () => ({ billsApi: {
  show: vi.fn(),
  cancel: vi.fn(),
  postDraft: vi.fn(),
  approvePayment: vi.fn(),
  rejectPayment: vi.fn(),
  voidPayment: vi.fn(),
} }));
vi.mock('@/api/accounting/options', () => ({ accountingOptionsApi: { list: vi.fn() } }));
vi.mock('@/api/accounting/accounts', () => ({ accountsApi: { list: vi.fn() } }));

function renderBill(
  status: Bill['status'] = 'unpaid',
  staleRefresh?: Promise<Bill>,
  payments: BillPayment[] = [],
  followupRefresh?: Promise<Bill>,
) {
  const bill: Bill = {
    id: 'bill-1', bill_number: 'BILL-1', status,
    date: '2026-10-09', due_date: '2026-11-08', is_vatable: true,
    subtotal: '72500.00', vat_amount: '8700.00', total_amount: '81200.00',
    amount_paid: status === 'paid' ? '81200.00' : '0.00',
    balance: status === 'paid' ? '0.00' : '81200.00',
    is_overdue: false, aging_bucket: 'current', remarks: null,
    vendor: { id: 'vendor-1', name: 'Asia Pacific Polymers, Inc.' }, items: [], payments,
  };
  if (staleRefresh) {
    let calls = 0;
    vi.mocked(billsApi.show).mockImplementation(() => {
      calls += 1;
      if (calls === 1) return Promise.resolve(bill);
      if (calls === 2) return staleRefresh;
      return followupRefresh ?? Promise.resolve(bill);
    });
  } else {
    vi.mocked(billsApi.show).mockResolvedValue(bill);
  }
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const view = render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/accounting/bills/bill-1']}>
        <Routes><Route path="/accounting/bills/:id" element={<BillDetailPage />} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
  return { ...view, client, bill };
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((complete) => { resolve = complete; });
  return { promise, resolve };
}

describe('bill detail payment options', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    grants.clear();
    grants.add('accounting.bills.view');
    vi.mocked(accountingOptionsApi.list).mockResolvedValue({
      payment_methods: [{ value: 'bank_transfer', label: 'Bank transfer' }],
      account_types: [], normal_balances: [], credit_note_types: [], credit_note_statuses: [],
    });
    vi.mocked(accountsApi.list).mockResolvedValue({
      data: [],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 0, from: null, to: null },
      links: { first: '', last: '', prev: null, next: null },
    });
  });

  it.each(['unpaid', 'paid'] as const)('purchasing can read a %s bill without requesting restricted options', async (status) => {
    renderBill(status);
    expect(await screen.findByText('BILL-1')).toBeInTheDocument();
    expect(screen.getByText('Asia Pacific Polymers, Inc.')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Record payment' })).not.toBeInTheDocument();
    expect(accountingOptionsApi.list).not.toHaveBeenCalled();
    expect(accountsApi.list).not.toHaveBeenCalled();
  });

  it('loads payment methods when finance opens Record payment', async () => {
    grants.add('accounting.view');
    grants.add('accounting.bills.pay');
    renderBill();
    const open = await screen.findByRole('button', { name: 'Record payment' });
    expect(accountingOptionsApi.list).not.toHaveBeenCalled();
    fireEvent.click(open);
    expect(await screen.findByRole('option', { name: 'Bank transfer' })).toBeInTheDocument();
    await waitFor(() => expect(accountingOptionsApi.list).toHaveBeenCalledTimes(1));
  });

  it('keeps the returned posted bill when an older detail read completes afterward', async () => {
    grants.add('accounting.bills.create');
    const staleRead = deferred<Bill>();
    const { client } = renderBill('draft', staleRead.promise);
    const billKey = ['accounting', 'bills', 'bill-1'] as const;
    await screen.findByRole('button', { name: 'Post bill' });
    const staleRefetch = client.refetchQueries({ queryKey: billKey });
    await waitFor(() => expect(billsApi.show).toHaveBeenCalledTimes(2));
    const postedBill: Bill = {
      id: 'bill-1', bill_number: 'BILL-1', status: 'unpaid',
      date: '2026-10-09', due_date: '2026-11-08', is_vatable: true,
      subtotal: '72500.00', vat_amount: '8700.00', total_amount: '81200.00',
      amount_paid: '0.00', balance: '81200.00',
      is_overdue: false, aging_bucket: 'current', remarks: null,
      vendor: { id: 'vendor-1', name: 'Asia Pacific Polymers, Inc.' }, items: [], payments: [],
    };
    vi.mocked(billsApi.postDraft).mockResolvedValue(postedBill);
    fireEvent.click(await screen.findByRole('button', { name: 'Post bill' }));
    const confirmButtons = await screen.findAllByRole('button', { name: 'Post bill' });
    fireEvent.click(confirmButtons.at(-1)!);

    await waitFor(() => expect(billsApi.postDraft).toHaveBeenCalledWith('bill-1', {}));
    await act(async () => {
      staleRead.resolve({ ...postedBill, status: 'draft' });
      await staleRefetch;
    });
    expect(client.getQueryData<Bill>(billKey)?.status).toBe('unpaid');
    expect(screen.queryByRole('button', { name: 'Post bill' })).not.toBeInTheDocument();
  });

  it('refetches and closes the stale post confirmation after a state conflict', async () => {
    grants.add('accounting.bills.create');
    const { bill, client } = renderBill('draft');
    await screen.findByText('BILL-1');
    client.setQueryData(['accounting', 'bills', 'bill-1'], {
      ...bill,
      available_actions: {
        post_draft: true,
        override_three_way_match: false,
        record_payment: false,
        cancel: false,
      },
    });
    await screen.findByRole('button', { name: 'Post bill' });
    vi.mocked(billsApi.show).mockResolvedValue({
      ...bill,
      status: 'unpaid',
      available_actions: {
        post_draft: false,
        override_three_way_match: false,
        record_payment: false,
        cancel: false,
      },
    });
    vi.mocked(billsApi.postDraft).mockRejectedValue(new AxiosError('This bill is no longer a draft.', '409', undefined, undefined, {
      status: 409,
      data: { code: 'bill_state_conflict', message: 'This bill is no longer a draft.' },
      statusText: 'Conflict',
      headers: {},
      config: {} as never,
    }));

    fireEvent.click(screen.getByRole('button', { name: 'Post bill' }));
    const postButtons = await screen.findAllByRole('button', { name: 'Post bill' });
    fireEvent.click(postButtons.at(-1)!);

    await waitFor(() => expect(billsApi.show).toHaveBeenCalledTimes(2));
    expect(await screen.findByText('unpaid')).toBeVisible();
    expect(screen.queryByRole('button', { name: 'Post bill' })).not.toBeInTheDocument();
  });

  it('keeps the returned cancelled bill when an older detail read completes afterward', async () => {
    grants.add('accounting.bills.update');
    const staleRead = deferred<Bill>();
    const { client } = renderBill('unpaid', staleRead.promise);
    const billKey = ['accounting', 'bills', 'bill-1'] as const;
    await screen.findByRole('button', { name: /^Cancel$/ });
    const staleRefetch = client.refetchQueries({ queryKey: billKey });
    await waitFor(() => expect(billsApi.show).toHaveBeenCalledTimes(2));
    const cancelledBill: Bill = {
      id: 'bill-1', bill_number: 'BILL-1', status: 'cancelled',
      date: '2026-10-09', due_date: '2026-11-08', is_vatable: true,
      subtotal: '72500.00', vat_amount: '8700.00', total_amount: '81200.00',
      amount_paid: '0.00', balance: '81200.00',
      is_overdue: false, aging_bucket: 'current', remarks: null,
      vendor: { id: 'vendor-1', name: 'Asia Pacific Polymers, Inc.' }, items: [], payments: [],
    };
    vi.mocked(billsApi.cancel).mockResolvedValue(cancelledBill);
    fireEvent.click(await screen.findByRole('button', { name: /^Cancel$/ }));
    fireEvent.click(await screen.findByRole('button', { name: 'Cancel bill' }));

    await waitFor(() => expect(billsApi.cancel).toHaveBeenCalledWith('bill-1'));
    await act(async () => {
      staleRead.resolve({ ...cancelledBill, status: 'unpaid' });
      await staleRefetch;
    });
    expect(client.getQueryData<Bill>(billKey)?.status).toBe('cancelled');
    expect(screen.queryByRole('button', { name: /^Cancel$/ })).not.toBeInTheDocument();
  });

  it('renders the returned payment decision while an older bill read is pending', async () => {
    grants.add('accounting.bills.payment_approve');
    const pendingPayment: BillPayment = {
      id: 'payment-1', payment_date: '2026-10-20', amount: '81200.00',
      payment_method: 'bank_transfer', reference_number: 'REF-1',
      status: 'pending_approval', status_label: 'Pending approval',
      journal_entry_id: null, can_decide: true,
    };
    const staleRead = deferred<Bill>();
    const freshRead = deferred<Bill>();
    const { client, bill } = renderBill('unpaid', staleRead.promise, [pendingPayment], freshRead.promise);
    const billKey = ['accounting', 'bills', 'bill-1'] as const;
    await screen.findByRole('button', { name: 'Approve' });
    const approvalResponse = deferred<BillPayment>();
    vi.mocked(billsApi.approvePayment).mockReturnValue(approvalResponse.promise);
    fireEvent.click(screen.getByRole('button', { name: 'Approve' }));
    await waitFor(() => expect(billsApi.approvePayment).toHaveBeenCalledWith('bill-1', 'payment-1'));

    // The action starts from fresh data. A slower read begins after the click
    // but before the mutation response, exercising a real response/read race.
    const staleRefetch = client.refetchQueries({ queryKey: billKey });
    await waitFor(() => expect(billsApi.show).toHaveBeenCalledTimes(2));
    const updatedPayment: BillPayment = {
      ...pendingPayment,
      status: 'posted',
      status_label: 'Posted',
      journal_entry_id: 'journal-1',
      can_decide: false,
    };
    const updatedBill: Bill = {
      ...bill,
      status: 'paid',
      amount_paid: '81200.00',
      balance: '0.00',
      payments: [updatedPayment],
    };
    await act(async () => approvalResponse.resolve(updatedPayment));
    expect(screen.getByText('Posted')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    await waitFor(() => expect(billsApi.show).toHaveBeenCalledTimes(3));
    await act(async () => {
      staleRead.resolve(bill);
      await staleRefetch;
    });
    expect(screen.getByText('Posted')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    await act(async () => freshRead.resolve(updatedBill));
  });

  it('does not infer payment decision actions when the server decision is missing', async () => {
    grants.add('accounting.bills.payment_approve');
    const pendingPayment: BillPayment = {
      id: 'payment-2', payment_date: '2026-10-20', amount: '81200.00',
      payment_method: 'bank_transfer', reference_number: 'REF-2',
      status: 'pending_approval', status_label: 'Pending approval',
      journal_entry_id: null,
    };

    renderBill('unpaid', undefined, [pendingPayment]);

    expect(await screen.findByText('Pending approval')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument();
  });

  it('disables an open rejection when the latest bill removes the payment decision action', async () => {
    const pendingPayment: BillPayment = {
      id: 'payment-3', payment_date: '2026-10-20', amount: '81200.00',
      payment_method: 'bank_transfer', reference_number: 'REF-3',
      status: 'pending_approval', status_label: 'Pending approval',
      journal_entry_id: null, can_decide: true,
    };
    const { client, bill } = renderBill('unpaid', undefined, [pendingPayment]);
    const billKey = ['accounting', 'bills', 'bill-1'] as const;

    fireEvent.click(await screen.findByRole('button', { name: 'Reject' }));
    fireEvent.change(screen.getByLabelText(/Rejection reason/), {
      target: { value: 'Already reconciled' },
    });
    await act(async () => {
      client.setQueryData<Bill>(billKey, {
        ...bill,
        payments: [{ ...pendingPayment, status: 'posted', status_label: 'Posted', can_decide: false }],
      });
    });

    await waitFor(() => {
      expect(screen.getByText('Posted')).toBeVisible();
      expect(screen.getByRole('button', { name: 'Reject payment' })).toBeDisabled();
    });
    expect(screen.getByLabelText(/Rejection reason/)).toHaveValue('Already reconciled');
  });
});
