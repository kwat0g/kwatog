import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { businessPoliciesApi } from '@/api/businessPolicies';
import { portalAccessApi } from '@/api/b2b/portal-access';
import { vendorsApi } from '@/api/accounting/vendors';
import VendorFormPage from './form';

vi.mock('@/api/businessPolicies', () => ({ businessPoliciesApi: { get: vi.fn() } }));
vi.mock('@/api/accounting/vendors', () => ({ vendorsApi: { create: vi.fn(), show: vi.fn(), update: vi.fn() } }));
vi.mock('@/api/b2b/portal-access', () => ({ portalAccessApi: { inviteSupplier: vi.fn() } }));
vi.mock('@/hooks/useFormSafety', () => ({
  useFormSafety: () => ({
    allowNavigationAfterSave: vi.fn(),
    draftState: { hasDraft: false, draftAge: 0, restore: vi.fn(), discard: vi.fn() },
  }),
}));
vi.mock('@/components/ui/FormDraftBanner', () => ({ FormDraftBanner: () => null }));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const createdVendor = {
  id: 'vendor-new',
  name: 'Laguna Precision Supply',
  contact_person: 'Rey Santos',
  email: 'rey@example.test',
  is_active: true,
} as never;

function renderCreateForm() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const view = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/accounting/vendors/create']}>
        <Routes>
          <Route path="/accounting/vendors/create" element={<VendorFormPage mode="create" />} />
          <Route path="/accounting/vendors/:id" element={<span>Vendor record</span>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
  return { ...view, queryClient };
}

describe('Vendor creation portal invitation', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(businessPoliciesApi.get).mockResolvedValue({ vendor_payment_terms_days: 30 } as never);
    vi.mocked(vendorsApi.create).mockResolvedValue(createdVendor);
    vi.mocked(portalAccessApi.inviteSupplier).mockResolvedValue({} as never);
  });

  it('queues an optional supplier portal invitation only after vendor creation succeeds', async () => {
    const { queryClient } = renderCreateForm();
    fireEvent.change(await screen.findByRole('textbox', { name: 'Vendor name' }), { target: { value: 'Laguna Precision Supply' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Contact person' }), { target: { value: 'Rey Santos' } });
    fireEvent.change(screen.getByRole('textbox', { name: 'Email' }), { target: { value: 'rey@example.test' } });
    fireEvent.click(screen.getByRole('checkbox', { name: /Send portal invitation after vendor creation/ }));
    fireEvent.click(screen.getByRole('checkbox', { name: /Authorized signatory/i }));
    fireEvent.click(screen.getByRole('button', { name: 'Create vendor' }));

    await waitFor(() => expect(vendorsApi.create).toHaveBeenCalled());
    await waitFor(() => expect(portalAccessApi.inviteSupplier).toHaveBeenCalledWith('vendor-new', {
      name: 'Rey Santos', email: 'rey@example.test', is_authorized_signatory: true,
    }));
    expect(vi.mocked(portalAccessApi.inviteSupplier).mock.invocationCallOrder[0])
      .toBeGreaterThan(vi.mocked(vendorsApi.create).mock.invocationCallOrder[0]);
    queryClient.clear();
  });
});
