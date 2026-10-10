import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { loansApi } from '@/api/loans';
import { employeesApi } from '@/api/hr/employees';
import { selfServiceApi } from '@/api/self-service';
import type { SelfServiceLoan } from '@/types/self-service';
import type { LoanTypeOption } from '@/types/loans';
import type { Employee } from '@/types/hr';
import SelfServiceLoansPage from './loans';
import CreateLoanPage from '@/pages/loans/create';

vi.mock('@/api/self-service', () => ({ selfServiceApi: { loans: vi.fn(), applyLoan: vi.fn(), previewLoanAmortization: vi.fn() } }));
vi.mock('@/api/loans', () => ({ loansApi: { types: vi.fn(), limits: vi.fn(), create: vi.fn(), previewAmortization: vi.fn() } }));
vi.mock('@/api/hr/employees', () => ({ employeesApi: { list: vi.fn() } }));
vi.mock('@/hooks/useFormSafety', () => ({ useFormSafety: () => ({ draftState: { hasDraft: false } }) }));

const types: LoanTypeOption[] = [
  { value: 'company_loan', label: 'Company Loan', interest_rate: '0', approval_steps: 4 },
  { value: 'cash_advance', label: 'Cash Advance', interest_rate: '0', approval_steps: 3 },
];
const existingLoan: SelfServiceLoan = {
  id: 'loan-1', loan_type: 'company_loan', loan_type_label: 'Company Loan',
  principal: '6000.00', outstanding_balance: '6000.00', monthly_amortization: '1000.00',
  periods: 6, periods_remaining: 6, status: 'active', status_label: 'Active',
  created_at: '2026-10-10T00:00:00Z',
};

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(selfServiceApi.loans).mockResolvedValue({ active: [], history: [], loan_types: types, max_pay_periods: 12 });
  vi.mocked(loansApi.types).mockResolvedValue(types);
  vi.mocked(loansApi.limits).mockResolvedValue({ principal_max: '30000.00', has_active: true, max_pay_periods: 12 });
  vi.mocked(employeesApi.list).mockResolvedValue({ data: [
    { id: 'employee-1', full_name: 'Manuel Cruz', employee_no: 'DEMO-0009' } as Employee,
    { id: 'employee-2', full_name: 'Eligible Employee', employee_no: 'DEMO-0010' } as Employee,
  ] } as Awaited<ReturnType<typeof employeesApi.list>>);
  vi.mocked(loansApi.previewAmortization).mockResolvedValue([]);
});

function show(page: 'employee' | 'HR') {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>{page === 'employee' ? <SelfServiceLoansPage /> : <CreateLoanPage />}</MemoryRouter>
    </QueryClientProvider>,
  );
}

it.each(['pending', 'active', 'write_off_pending'])('blocks employee application for %s loans', async (status) => {
  vi.mocked(selfServiceApi.loans).mockResolvedValue({ active: [{ ...existingLoan, status }], history: [], loan_types: types, max_pay_periods: 12 });
  show('employee');
  expect(await screen.findByText(/You already have a pending or active loan or cash advance/)).toBeInTheDocument();
  const apply = screen.getByRole('button', { name: 'Apply for a loan' });
  expect(apply).toBeDisabled();
  fireEvent.click(apply);
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  expect(selfServiceApi.applyLoan).not.toHaveBeenCalled();
});

it('allows employee application with only cancelled history', async () => {
  vi.mocked(selfServiceApi.loans).mockResolvedValue({ active: [], history: [{ ...existingLoan, status: 'cancelled' }], loan_types: types, max_pay_periods: 12 });
  show('employee');
  const apply = await screen.findByRole('button', { name: 'Apply for a loan' });
  await waitFor(() => expect(apply).toBeEnabled());
  fireEvent.click(apply);
  expect(await screen.findByRole('dialog')).toBeInTheDocument();
});

it('blocks HR submission for either type and permits another eligible employee', async () => {
  show('HR');
  fireEvent.click(await screen.findByRole('button', { name: 'Show Employee' }));
  fireEvent.click(await screen.findByRole('option', { name: 'Manuel Cruz (DEMO-0009)' }));
  expect(await screen.findByText(/This employee already has a pending or active loan or cash advance/)).toBeInTheDocument();
  fireEvent.change(screen.getByLabelText(/^Principal/), { target: { value: '1000.00' } });
  fireEvent.change(screen.getByLabelText(/^Pay periods/), { target: { value: '2' } });
  const submit = screen.getByRole('button', { name: 'Submit request' });
  expect(submit).toBeDisabled();
  fireEvent.submit(submit.closest('form')!);
  await waitFor(() => expect(loansApi.limits).toHaveBeenCalledWith('employee-1', 'company_loan'));
  expect(loansApi.create).not.toHaveBeenCalled();
  fireEvent.click(screen.getByRole('radio', { name: /Cash Advance/ }));
  await waitFor(() => expect(loansApi.limits).toHaveBeenCalledWith('employee-1', 'cash_advance'));
  expect(submit).toBeDisabled();

  vi.mocked(loansApi.limits).mockResolvedValue({ principal_max: '30000.00', has_active: false, max_pay_periods: 12 });
  fireEvent.click(screen.getByRole('button', { name: 'Show Employee' }));
  fireEvent.click(await screen.findByRole('option', { name: 'Eligible Employee (DEMO-0010)' }));
  await waitFor(() => expect(submit).toBeEnabled());
});
