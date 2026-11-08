import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { periodsApi } from '@/api/payroll/periods';
import type { PayrollPeriod } from '@/types/payroll';
import CreatePayrollPeriodPage from './create';

vi.mock('@/api/payroll/periods', () => ({
  periodsApi: { options: vi.fn(), scopePreview: vi.fn(), create: vi.fn() },
}));
vi.mock('@/hooks/useFormSafety', () => ({
  useFormSafety: () => ({
    allowNavigationAfterSave: vi.fn(),
    draftState: { hasDraft: false, draftAge: 0, restore: vi.fn(), discard: vi.fn() },
  }),
}));
vi.mock('@/components/ui/FormDraftBanner', () => ({ FormDraftBanner: () => null }));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const createdPeriod = {
  id: 'period-new',
  period_start: '2026-11-01',
  period_end: '2026-11-15',
  payroll_date: '2026-11-20',
  is_first_half: true,
  is_thirteenth_month: false,
  status: 'draft',
  status_label: 'Draft',
  is_locked: false,
  permissions: { can_compute: true },
  available_actions: { compute: true },
} as unknown as PayrollPeriod;

describe('Payroll Period create freshness', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(periodsApi.options).mockResolvedValue({
      statuses: [], period_types: [], half_types: [], employment_types: [], pay_types: [], departments: [],
    });
    vi.mocked(periodsApi.scopePreview).mockResolvedValue({
      employee_count: 4,
      total_active: 4,
      estimated_gross: '10000.00',
      is_company_wide: true,
      already_paid_count: 0,
      already_paid_sample: [],
    });
    vi.mocked(periodsApi.create).mockResolvedValue(createdPeriod);
  });

  it('seeds the detail cache with the create response before navigating', async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/payroll/periods/create']}>
          <Routes>
            <Route path="/payroll/periods/create" element={<CreatePayrollPeriodPage />} />
            <Route path="/payroll/periods/:id" element={<span>Payroll period detail route</span>} />
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    fireEvent.change(document.querySelector<HTMLInputElement>('input[name="period_start"]')!, {
      target: { value: '2026-11-01' },
    });
    fireEvent.change(document.querySelector<HTMLInputElement>('input[name="period_end"]')!, {
      target: { value: '2026-11-15' },
    });
    fireEvent.change(document.querySelector<HTMLInputElement>('input[name="payroll_date"]')!, {
      target: { value: '2026-11-20' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Create period' }));

    await waitFor(() => expect(periodsApi.create).toHaveBeenCalledOnce());
    expect(await screen.findByText('Payroll period detail route')).toBeVisible();
    await waitFor(() => expect(queryClient.getQueryData(['payroll-period', createdPeriod.id]))
      .toEqual(createdPeriod));
    queryClient.clear();
  });
});
