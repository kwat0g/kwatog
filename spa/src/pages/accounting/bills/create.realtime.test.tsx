import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { accountsApi } from '@/api/accounting/accounts';
import { billsApi } from '@/api/accounting/bills';
import { vendorsApi } from '@/api/accounting/vendors';
import { purchaseOrdersApi } from '@/api/purchasing/purchase-orders';
import { businessPoliciesApi } from '@/api/businessPolicies';
import { uomsApi } from '@/api/inventory/uoms';
import type { Bill } from '@/types/accounting';
import CreateBillPage from './create';

vi.mock('@/api/accounting/accounts', () => ({ accountsApi: { list: vi.fn() } }));
vi.mock('@/api/accounting/bills', () => ({ billsApi: { create: vi.fn() } }));
vi.mock('@/api/accounting/vendors', () => ({ vendorsApi: { list: vi.fn() } }));
vi.mock('@/api/purchasing/purchase-orders', () => ({ purchaseOrdersApi: { list: vi.fn() } }));
vi.mock('@/api/businessPolicies', () => ({ businessPoliciesApi: { get: vi.fn() } }));
vi.mock('@/api/inventory/uoms', () => ({ uomsApi: { list: vi.fn() } }));
vi.mock('@/hooks/useFormSafety', () => ({
  useFormSafety: () => ({
    allowNavigationAfterSave: vi.fn(),
    draftState: { hasDraft: false, draftAge: 0, restore: vi.fn(), discard: vi.fn() },
  }),
}));
vi.mock('@/components/ui/FormDraftBanner', () => ({ FormDraftBanner: () => null }));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const createdBill = {
  id: 'bill-new',
  bill_number: 'VB-2026-0001',
  status: 'draft',
  vendor_id: 'vendor-1',
  version: 1,
  permissions: { manage: true },
  available_actions: { can_post: false, can_cancel: true },
  items: [],
} as unknown as Bill;

describe('Bill create freshness', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(vendorsApi.list).mockResolvedValue({
      data: [{ id: 'vendor-1', name: 'Test vendor', payment_terms_days: 30 }],
    } as never);
    vi.mocked(accountsApi.list).mockResolvedValue({
      data: [{ id: 'expense-1', code: '6000', name: 'Utilities' }],
    } as never);
    vi.mocked(purchaseOrdersApi.list).mockResolvedValue({ data: [] } as never);
    vi.mocked(businessPoliciesApi.get).mockResolvedValue({
      vat_status: 'Not VAT Registered', vat_rate: null,
    } as never);
    vi.mocked(uomsApi.list).mockResolvedValue([]);
    vi.mocked(billsApi.create).mockResolvedValue(createdBill);
  });

  it('seeds the detail cache with the create response before navigating', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/accounting/bills/create']}>
          <Routes>
            <Route path="/accounting/bills/create" element={<CreateBillPage />} />
            <Route path="/accounting/bills/:id" element={<span>Bill detail route</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.change(screen.getByRole('combobox', { name: 'Bill provenance' }), {
      target: { value: 'service' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Show Vendor' }));
    fireEvent.click(await screen.findByRole('option', { name: 'Test vendor' }));
    fireEvent.change(screen.getByRole('textbox', { name: 'Vendor bill no.' }), {
      target: { value: 'VB-2026-0001' },
    });
    fireEvent.change(screen.getByRole('textbox', { name: 'Service evidence' }), {
      target: { value: 'Approved service completion report SR-1' },
    });
    fireEvent.click(screen.getByRole('checkbox', { name: /Approve exception/ }));
    fireEvent.change(document.querySelector<HTMLInputElement>('input[name="items.0.description"]')!, {
      target: { value: 'Machine maintenance service' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Show Expense account for line 1' }));
    fireEvent.click(await screen.findByRole('option', { name: /6000.*Utilities/ }));
    fireEvent.change(document.querySelector<HTMLInputElement>('input[name="items.0.quantity"]')!, {
      target: { value: '1' },
    });
    fireEvent.change(document.querySelector<HTMLInputElement>('input[name="items.0.unit_price"]')!, {
      target: { value: '250' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Create bill' }));

    await waitFor(() => expect(billsApi.create).toHaveBeenCalledOnce());
    expect(await screen.findByText('Bill detail route')).toBeVisible();
    await waitFor(() => expect(queryClient.getQueryData(['accounting', 'bills', 'bill-new']))
      .toEqual(createdBill));
    queryClient.clear();
  });
});
