import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { salaryAdjustmentsApi, type SalaryAdjustmentItem } from '@/api/hr/salary-adjustments';
import { SalaryAdjustmentsTab } from './index';

vi.mock('@/api/hr/salary-adjustments', () => ({
  salaryAdjustmentsApi: {
    options: vi.fn(),
    list: vi.fn(),
    request: vi.fn(),
    act: vi.fn(),
  },
}));

const adjustment = (overrides: Partial<SalaryAdjustmentItem> = {}): SalaryAdjustmentItem => ({
  id: 'adjustment-1',
  status: 'pending',
  status_label: 'Pending',
  from_basic_monthly_salary: '30000.00',
  from_semi_monthly_rate: null,
  to_basic_monthly_salary: '35000.00',
  to_semi_monthly_rate: null,
  effective_date: '2026-09-01',
  reason: 'Promotion',
  employee: { id: 'employee-1', employee_no: 'E-100', full_name: 'Sample Employee' },
  requested_by: { id: 'requester-1', name: 'HR Requester' },
  applied_at: null,
  created_at: '2026-08-01T00:00:00Z',
  updated_at: '2026-08-01T00:00:00Z',
  version: '2026-08-01T00:00:00.000000+00:00',
  permissions: { view: true, act: true },
  available_actions: [],
  ...overrides,
});

function renderTab() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  const rendered = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <SalaryAdjustmentsTab />
      </MemoryRouter>
    </QueryClientProvider>,
  );

  return { queryClient, ...rendered };
}

describe('salary adjustment approval actions', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(salaryAdjustmentsApi.options).mockResolvedValue({
      statuses: [{ value: 'pending', label: 'Pending' }],
    });
    vi.mocked(salaryAdjustmentsApi.list).mockResolvedValue({
      data: [adjustment()],
      links: { first: '', last: '', prev: null, next: null },
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
    });
  });

  it('hides approval buttons when the server assigns the pending step to someone else', async () => {
    renderTab();

    expect(await screen.findByRole('status')).toHaveTextContent('assigned to another reviewer');
    expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument();
  });

  it('renders only the action returned by the API', async () => {
    vi.mocked(salaryAdjustmentsApi.list).mockResolvedValue({
      data: [adjustment({ available_actions: ['approve'] })],
      links: { first: '', last: '', prev: null, next: null },
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
    });
    renderTab();

    expect(await screen.findByRole('button', { name: 'Approve' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument();
  });

  it('closes an open confirmation when a refreshed record removes that action', async () => {
    vi.mocked(salaryAdjustmentsApi.list).mockResolvedValue({
      data: [adjustment({ available_actions: ['approve'] })],
      links: { first: '', last: '', prev: null, next: null },
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
    });
    const { queryClient } = renderTab();
    fireEvent.click(await screen.findByRole('button', { name: 'Approve' }));
    expect(screen.getByText('Approve adjustment?')).toBeInTheDocument();

    queryClient.setQueryData(['hr', 'salary-adjustments', ''], {
      data: [adjustment({ available_actions: [] })],
      links: { first: '', last: '', prev: null, next: null },
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
    });

    await waitFor(() => expect(screen.queryByText('Approve adjustment?')).not.toBeInTheDocument());
  });

  it('disables an open confirmation while the latest server actions are being fetched', async () => {
    const page = {
      data: [adjustment({ available_actions: ['approve'] })],
      links: { first: '', last: '', prev: null, next: null },
      meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
    };
    let finishRefetch!: (result: typeof page) => void;
    vi.mocked(salaryAdjustmentsApi.list)
      .mockResolvedValueOnce(page)
      .mockImplementationOnce(() => new Promise((resolve) => { finishRefetch = resolve; }));
    const { queryClient } = renderTab();
    fireEvent.click(await screen.findByRole('button', { name: 'Approve' }));
    const dialog = screen.getByRole('dialog');
    const queryKey = ['hr', 'salary-adjustments', ''];
    let refetch!: Promise<void>;
    act(() => {
      refetch = queryClient.invalidateQueries({ queryKey });
    });

    await waitFor(() => expect(queryClient.isFetching({ queryKey })).toBe(1));
    expect(within(dialog).getByRole('button', { name: 'Approve' })).toBeDisabled();

    await act(async () => {
      finishRefetch(page);
      await refetch;
    });
  });
});
