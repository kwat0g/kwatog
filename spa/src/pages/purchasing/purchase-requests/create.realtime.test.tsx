import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { departmentsApi } from '@/api/hr/departments';
import { itemsApi } from '@/api/inventory/items';
import { purchaseRequestsApi } from '@/api/purchasing/purchase-requests';
import { useAuthStore } from '@/stores/authStore';
import PurchaseRequestCreatePage from './create';

vi.mock('@/api/hr/departments', () => ({ departmentsApi: { list: vi.fn() } }));
vi.mock('@/api/inventory/items', () => ({ itemsApi: { list: vi.fn() } }));
vi.mock('@/api/purchasing/purchase-requests', () => ({ purchaseRequestsApi: {
  create: vi.fn(),
  options: vi.fn(),
  submit: vi.fn(),
} }));
vi.mock('@/components/ui/FormDraftBanner', () => ({ FormDraftBanner: () => null }));
vi.mock('@/hooks/useFormSafety', () => ({
  useFormSafety: () => ({ allowNavigationAfterSave: vi.fn(), draftState: {} }),
}));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: () => false }) }));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const createdDraft = {
  id: 'pr-new',
  pr_number: 'PR-202611-0001',
  status: 'draft',
} as never;
const submittedRequest = {
  id: 'pr-new',
  pr_number: 'PR-202611-0001',
  status: 'pending',
  status_label: 'Pending',
  version: 2,
  actions: { can_submit: false, can_approve: false },
} as never;

describe('Purchase Request create freshness', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    useAuthStore.setState({
      user: {
        id: 'requester-1',
        role: { id: 'role-1', slug: 'department_head' },
        employee: { id: 'employee-1', department_id: 'production-1' },
      } as never,
      permissions: new Set(),
      features: new Set(),
      isAuthenticated: true,
      isLoading: false,
    });
    vi.mocked(itemsApi.list).mockResolvedValue({ data: [] } as never);
    vi.mocked(departmentsApi.list).mockResolvedValue({ data: [{ id: 'production-1', name: 'Production' }] } as never);
    vi.mocked(purchaseRequestsApi.options).mockResolvedValue({
      priorities: [{ value: 'normal', label: 'Normal' }],
      statuses: [],
      default_priority: 'normal',
    } as never);
    vi.mocked(purchaseRequestsApi.create).mockResolvedValue(createdDraft);
    vi.mocked(purchaseRequestsApi.submit).mockResolvedValue(submittedRequest);
  });

  it('seeds the detail cache with the post-submit response before navigating', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/purchasing/purchase-requests/create']}>
          <Routes>
            <Route path="/purchasing/purchase-requests/create" element={<PurchaseRequestCreatePage />} />
            <Route path="/purchasing/purchase-requests/:id" element={<span>PR detail route</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    await screen.findByRole('heading', { name: 'New purchase request' });
    await screen.findByRole('option', { name: 'Normal' });
    fireEvent.change(screen.getByLabelText(/Priority/), { target: { value: 'normal' } });
    fireEvent.change(screen.getByLabelText(/Reason/), { target: { value: 'Realtime freshness regression' } });
    fireEvent.change(screen.getByLabelText('Description'), { target: { value: 'Disposable regression item' } });
    fireEvent.change(screen.getByLabelText('Quantity'), { target: { value: '1' } });
    fireEvent.change(screen.getByLabelText('Unit'), { target: { value: 'lot' } });
    fireEvent.change(screen.getByLabelText('Estimated unit price'), { target: { value: '60000' } });
    fireEvent.click(screen.getByRole('button', { name: 'Submit for approval' }));
    fireEvent.click(await screen.findByRole('button', { name: /^Submit$/ }));

    await waitFor(() => expect(purchaseRequestsApi.submit).toHaveBeenCalledWith('pr-new'));
    await waitFor(() => expect(queryClient.getQueryData(['purchasing', 'purchase-requests', 'pr-new']))
      .toMatchObject({ status: 'pending', version: 2 }));
    expect(await screen.findByText('PR detail route')).toBeVisible();
    queryClient.clear();
  });
});
