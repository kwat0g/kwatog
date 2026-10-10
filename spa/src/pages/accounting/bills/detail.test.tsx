import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { accountingOptionsApi } from '@/api/accounting/options';
import { accountsApi } from '@/api/accounting/accounts';
import { billsApi } from '@/api/accounting/bills';
import type { Bill } from '@/types/accounting';
import BillDetailPage from './detail';

const grants = vi.hoisted(() => new Set<string>());
vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({ can: (permission: string) => grants.has(permission) }),
}));
vi.mock('@/hooks/useChainProgress', () => ({ useChainProgress: () => {} }));
vi.mock('@/api/accounting/bills', () => ({ billsApi: { show: vi.fn() } }));
vi.mock('@/api/accounting/options', () => ({ accountingOptionsApi: { list: vi.fn() } }));
vi.mock('@/api/accounting/accounts', () => ({ accountsApi: { list: vi.fn() } }));

function renderBill(status: Bill['status'] = 'unpaid') {
  const bill: Bill = {
    id: 'bill-1', bill_number: 'BILL-1', status,
    date: '2026-10-09', due_date: '2026-11-08', is_vatable: true,
    subtotal: '72500.00', vat_amount: '8700.00', total_amount: '81200.00',
    amount_paid: status === 'paid' ? '81200.00' : '0.00',
    balance: status === 'paid' ? '0.00' : '81200.00',
    is_overdue: false, aging_bucket: 'current', remarks: null,
    vendor: { id: 'vendor-1', name: 'Asia Pacific Polymers, Inc.' }, items: [], payments: [],
  };
  vi.mocked(billsApi.show).mockResolvedValue(bill);
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/accounting/bills/bill-1']}>
        <Routes><Route path="/accounting/bills/:id" element={<BillDetailPage />} /></Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
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
});
