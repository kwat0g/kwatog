import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ncrsApi } from '@/api/quality/ncrs';
import { ncrTemplatesApi } from '@/api/quality/ncr-templates';
import { productsApi } from '@/api/crm/products';
import CreateNcrPage from './create';
import type { Ncr } from '@/types/quality';

vi.mock('@/api/quality/inspections', () => ({ inspectionsApi: { show: vi.fn() } }));
vi.mock('@/api/quality/ncrs', () => ({
  ncrsApi: { options: vi.fn(), create: vi.fn() },
}));
vi.mock('@/api/quality/ncr-templates', () => ({ ncrTemplatesApi: { active: vi.fn() } }));
vi.mock('@/api/crm/products', () => ({ productsApi: { options: vi.fn() } }));
vi.mock('@/hooks/useRealtimeRecord', () => ({ useRealtimeList: vi.fn() }));
vi.mock('@/hooks/useFormSafety', () => ({
  useFormSafety: () => ({
    allowNavigationAfterSave: vi.fn(),
    draftState: { hasDraft: false, draftAge: 0, restore: vi.fn(), discard: vi.fn() },
  }),
}));
vi.mock('@/components/ui/FormDraftBanner', () => ({ FormDraftBanner: () => null }));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const createdNcr = {
  id: 'ncr-new',
  ncr_number: 'NCR-2026-0001',
  status: 'open',
  severity: 'low',
  source: 'customer_complaint',
  defect_description: 'Short shot on part',
  affected_quantity: 5,
  version: 1,
  permissions: { manage: true },
  available_actions: { can_cancel: true, can_close: false },
} as unknown as Ncr;

describe('NCR create freshness', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(ncrsApi.options).mockResolvedValue({
      sources: [{ value: 'customer_complaint', label: 'Customer complaint' }],
      severities: [{ value: 'low', label: 'Low' }],
      statuses: [], actions: [], dispositions: [], default_disposition: '',
    } as never);
    vi.mocked(productsApi.options).mockResolvedValue({ data: [] } as never);
    vi.mocked(ncrTemplatesApi.active).mockResolvedValue([] as never);
    vi.mocked(ncrsApi.create).mockResolvedValue(createdNcr);
  });

  it('seeds the detail cache with the create response before navigating', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/quality/ncrs/create']}>
          <Routes>
            <Route path="/quality/ncrs/create" element={<CreateNcrPage />} />
            <Route path="/quality/ncrs/:id" element={<span>NCR detail route</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.change(screen.getByRole('textbox', { name: 'Defect description' }), {
      target: { value: 'Short shot on part' },
    });
    await screen.findByRole('option', { name: 'Customer complaint' });
    await screen.findByRole('option', { name: 'Low' });
    fireEvent.change(screen.getByRole('combobox', { name: 'Source' }), {
      target: { value: 'customer_complaint' },
    });
    fireEvent.change(screen.getByRole('combobox', { name: 'Severity' }), {
      target: { value: 'low' },
    });
    fireEvent.submit(screen.getByRole('button', { name: 'Open NCR' }).closest('form')!);

    await waitFor(() => expect(ncrsApi.create).toHaveBeenCalledOnce());
    expect(await screen.findByText('NCR detail route')).toBeVisible();
    await waitFor(() => expect(queryClient.getQueryData(['quality', 'ncrs', 'ncr-new']))
      .toEqual(createdNcr));
    queryClient.clear();
  });
});
