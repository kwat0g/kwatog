import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { profileUpdateRequestsApi } from '@/api/hr/profile-update-requests';
import ProfileUpdateRequestsPage from './index';

const access = vi.hoisted(() => ({ permissions: new Set<string>() }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({ can: (permission: string) => access.permissions.has(permission) }) }));
vi.mock('@/api/hr/profile-update-requests', () => ({ profileUpdateRequestsApi: { list: vi.fn(), options: vi.fn(), review: vi.fn(), financeReview: vi.fn() } }));

beforeEach(() => {
  vi.clearAllMocks();
  access.permissions = new Set(['hr.profile_updates.finance_review']);
  vi.mocked(profileUpdateRequestsApi.options).mockResolvedValue({ statuses: [] });
  vi.mocked(profileUpdateRequestsApi.list).mockResolvedValue({
    data: [{
      id: 'request', status: 'pending_finance', requires_finance: true,
      changes: { bank_name: 'Bank' }, note: null,
      employee: { id: 'employee', employee_no: '001', full_name: 'Employee Name', department: null },
      requester: null, reviewer: null, reviewed_at: null, review_remarks: null,
      finance_reviewer: null, finance_reviewed_at: null, finance_remarks: null, created_at: null,
    }],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
    links: { first: '/requests?page=1', last: '/requests?page=1', prev: null, next: null },
  });
});

function renderPage() {
  return render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
    <MemoryRouter><ProfileUpdateRequestsPage /></MemoryRouter>
  </QueryClientProvider>);
}

it('opens the Finance queue without linking to employee records or offering HR approval', async () => {
  renderPage();
  await screen.findByText('Employee Name');
  expect(profileUpdateRequestsApi.list).toHaveBeenCalledWith({ status: 'pending_finance' });
  expect(screen.getByRole('heading', { name: 'Bank Account Changes' })).toBeVisible();
  expect(screen.queryByRole('link', { name: 'Employee Name' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'HR Approve' })).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Finance Approve' })).toBeVisible();
});

it('keeps the HR queue default and employee links for employee reviewers', async () => {
  access.permissions = new Set(['hr.employees.view', 'hr.employees.edit']);
  renderPage();
  expect(await screen.findByRole('link', { name: 'Employee Name' })).toHaveAttribute('href', '/hr/employees/employee');
  expect(profileUpdateRequestsApi.list).toHaveBeenCalledWith({ status: 'pending' });
  expect(screen.queryByRole('button', { name: 'Finance Approve' })).not.toBeInTheDocument();
});
