import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { adjustmentsApi } from '@/api/payroll/adjustments';
import { payrollsApi } from '@/api/payroll/payrolls';
import type { PayrollAdjustment } from '@/types/payroll';
import CreatePayrollAdjustmentPage from './create';

vi.mock('@/api/payroll/adjustments', () => ({
  adjustmentsApi: { options: vi.fn(), create: vi.fn() },
}));
vi.mock('@/api/payroll/payrolls', () => ({ payrollsApi: { list: vi.fn() } }));
vi.mock('@/hooks/useFormSafety', () => ({
  useFormSafety: () => ({ allowNavigationAfterSave: vi.fn() }),
}));
vi.mock('@/components/ui/FormDraftBanner', () => ({ FormDraftBanner: () => null }));
vi.mock('react-hot-toast', () => ({ default: { success: vi.fn(), error: vi.fn() } }));

const createdAdjustment: PayrollAdjustment = {
  id: 'adjustment-new',
  type: 'underpayment',
  type_label: 'Underpayment',
  amount: '1234.56',
  reason: 'Correct missed overtime',
  status: 'pending',
  status_label: 'Pending',
  permissions: { view: true, approve: false, reject: false },
  available_actions: { approve: false, reject: false },
  approver: null,
  applied_at: null,
  created_at: '2026-11-03T00:00:00.000Z',
  updated_at: '2026-11-03T00:00:00.000Z',
};

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(adjustmentsApi.options).mockResolvedValue({
    statuses: [],
    types: [{ value: 'underpayment', label: 'Underpayment' }],
  });
  vi.mocked(payrollsApi.list).mockResolvedValue({
    data: [{
      id: 'payroll-source',
      employee: { id: 'employee-1', employee_no: 'EMP-0001', full_name: 'Ada Molding' },
      period_start: '2026-10-01',
      period_end: '2026-10-15',
    }],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
  } as never);
  vi.mocked(adjustmentsApi.create).mockResolvedValue(createdAdjustment);
});

it('reconciles the successful create response before returning to the adjustment list', async () => {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/payroll/adjustments/create']}>
        <Routes>
          <Route path="/payroll/adjustments/create" element={<CreatePayrollAdjustmentPage />} />
          <Route path="/payroll/adjustments" element={<span>Adjustment list route</span>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  await waitFor(() => expect(payrollsApi.list).toHaveBeenCalled());
  fireEvent.change(screen.getByRole('combobox', { name: 'Source payroll' }), {
    target: { value: 'EMP-0001' },
  });
  fireEvent.click(await screen.findByRole('option', { name: /Ada Molding/ }));
  fireEvent.change(document.querySelector<HTMLSelectElement>('select[name="type"]')!, {
    target: { value: 'underpayment' },
  });
  fireEvent.change(document.querySelector<HTMLInputElement>('input[name="amount"]')!, {
    target: { value: '1234.56' },
  });
  fireEvent.change(document.querySelector<HTMLTextAreaElement>('textarea[name="reason"]')!, {
    target: { value: 'Correct missed overtime' },
  });
  fireEvent.click(screen.getByRole('button', { name: 'Submit adjustment' }));

  await waitFor(() => expect(adjustmentsApi.create).toHaveBeenCalledOnce());
  expect(await screen.findByText('Adjustment list route')).toBeVisible();
  expect(queryClient.getQueryData(['payroll-adjustment', createdAdjustment.id])).toEqual(createdAdjustment);
  queryClient.clear();
});
