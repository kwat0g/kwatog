import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
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
  vi.mocked(profileUpdateRequestsApi.options).mockResolvedValue({ statuses: [
    { value: 'pending', label: 'Awaiting HR review' },
    { value: 'pending_finance', label: 'Awaiting Finance review' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Rejected' },
  ] });
  vi.mocked(profileUpdateRequestsApi.list).mockResolvedValue({
    data: [{
      id: 'request', status: 'pending_finance', requires_finance: true,
      permissions: { review_hr: false, review_finance: true },
      available_actions: { can_review_hr: false, can_approve_finance: true, can_reject_finance: true },
      changes: { bank_name: 'Bank' }, note: null,
      employee: { id: 'employee', employee_no: '001', full_name: 'Employee Name', department: null },
      requester: null, reviewer: null, reviewed_at: null, review_remarks: null,
      finance_reviewer: null, finance_reviewed_at: null, finance_remarks: null, created_at: null,
    }],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
    links: { first: '/requests?page=1', last: '/requests?page=1', prev: null, next: null },
  });
});

function renderPage(initialEntry = '/hr/profile-update-requests') {
  return render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
    <MemoryRouter initialEntries={[initialEntry]}><ProfileUpdateRequestsPage /></MemoryRouter>
  </QueryClientProvider>);
}

it('shows all Finance-visible statuses without linking to employee records or offering HR approval', async () => {
  renderPage();
  await screen.findAllByText('Employee Name');
  expect(profileUpdateRequestsApi.list).toHaveBeenCalledWith({ status: 'all' });
  expect(screen.getByRole('heading', { name: 'Bank Account Changes' })).toBeVisible();
  expect(document.querySelector('select[aria-label="Status"] option[value="pending"]')).toBeNull();
  expect(screen.queryByRole('link', { name: 'Employee Name' })).not.toBeInTheDocument();
  expect(screen.queryByText('Bank', { exact: true })).not.toBeInTheDocument();
  fireEvent.click(screen.getByRole('button', { name: 'Expand row' }));
  expect(await screen.findAllByText('Bank', { exact: true, selector: 'dd' })).not.toHaveLength(0);
  expect(screen.queryByRole('button', { name: 'HR Approve' })).not.toBeInTheDocument();
  fireEvent.click(screen.getAllByRole('button', { name: 'Finance Approve' })[0]);
  expect(await screen.findByRole('heading', { name: 'Approve Employee Name?' })).toBeVisible();
});

it('shows all HR-visible requests and employee links for employee reviewers', async () => {
  access.permissions = new Set(['hr.employees.view', 'hr.employees.edit']);
  renderPage();
  expect((await screen.findAllByRole('link', { name: 'Employee Name' }))[0]).toHaveAttribute('href', '/hr/employees/employee');
  expect(profileUpdateRequestsApi.list).toHaveBeenCalledWith({ status: 'all' });
  expect(screen.queryAllByRole('button', { name: 'Finance Approve' })).toHaveLength(0);
  fireEvent.change(document.querySelector('select[aria-label="Status"]')!, { target: { value: 'approved' } });
  expect(await screen.findAllByText('Approved')).not.toHaveLength(0);
  expect(profileUpdateRequestsApi.list).toHaveBeenCalledWith({ status: 'approved' });
});

it('loads the exact actionable queue when opened from the sidebar badge', async () => {
  renderPage('/hr/profile-update-requests?signal=awaiting_my_action');
  await screen.findAllByText('Employee Name');
  expect(profileUpdateRequestsApi.list).toHaveBeenCalledWith({ signal: 'awaiting_my_action' });
  expect(screen.getByRole('heading', { name: 'Profile Changes Awaiting My Action' })).toBeVisible();
  fireEvent.click(screen.getByRole('button', { name: 'Expand row' }));
  expect(screen.getAllByRole('button', { name: 'Finance Approve' }).length).toBeGreaterThan(0);
});

it('removes a completed request from the actionable queue while its refresh is pending', async () => {
  renderPage('/hr/profile-update-requests?signal=awaiting_my_action');
  await screen.findAllByText('Employee Name');

  vi.mocked(profileUpdateRequestsApi.list).mockReturnValue(new Promise(() => {}));
  vi.mocked(profileUpdateRequestsApi.financeReview).mockResolvedValue({
    id: 'request', status: 'approved', requires_finance: true,
    permissions: { review_hr: false, review_finance: true },
    available_actions: { can_review_hr: false, can_approve_finance: false, can_reject_finance: false },
    changes: { bank_name: 'Bank' }, note: null,
    employee: { id: 'employee', employee_no: '001', full_name: 'Employee Name', department: null },
    requester: null, reviewer: null, reviewed_at: null, review_remarks: null,
    finance_reviewer: { id: 'finance-reviewer', name: 'Finance Reviewer' },
    finance_reviewed_at: '2026-11-05T10:00:00Z', finance_remarks: null, created_at: null,
  });

  fireEvent.click(screen.getByRole('button', { name: 'Expand row' }));
  fireEvent.click((await screen.findAllByRole('button', { name: 'Finance Approve' }))[0]);
  fireEvent.click(await screen.findByRole('button', { name: 'Approve' }));

  await waitFor(() => expect(profileUpdateRequestsApi.financeReview).toHaveBeenCalledWith('request', 'approve'));
  await waitFor(() => expect(screen.queryAllByText('Employee Name')).toHaveLength(0));
  expect(screen.getByText('0 requests')).toBeVisible();
});

it('hides review controls when the server revokes the action for the current viewer', async () => {
  vi.mocked(profileUpdateRequestsApi.list).mockResolvedValueOnce({
    data: [{
      id: 'request', status: 'pending_finance', requires_finance: true,
      permissions: { review_hr: false, review_finance: true },
      available_actions: { can_review_hr: false, can_approve_finance: false, can_reject_finance: false },
      changes: { bank_name: 'Bank' }, note: null,
      employee: { id: 'employee', employee_no: '001', full_name: 'Employee Name', department: null },
      requester: null, reviewer: { id: 'hr-reviewer', name: 'HR Reviewer' }, reviewed_at: '2026-11-05T10:00:00Z', review_remarks: null,
      finance_reviewer: null, finance_reviewed_at: null, finance_remarks: null, created_at: null,
    }],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
    links: { first: '/requests?page=1', last: '/requests?page=1', prev: null, next: null },
  });

  renderPage();
  await screen.findAllByText('Employee Name');
  fireEvent.click(screen.getByRole('button', { name: 'Expand row' }));

  expect(await screen.findAllByText('HR approved by')).not.toHaveLength(0);
  expect(screen.queryAllByRole('button', { name: 'Finance Approve' })).toHaveLength(0);
  expect(screen.queryAllByRole('button', { name: 'Reject' })).toHaveLength(0);
});

it('does not submit a confirmation after the row action is revoked', async () => {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(<QueryClientProvider client={queryClient}>
    <MemoryRouter initialEntries={['/hr/profile-update-requests']}><ProfileUpdateRequestsPage /></MemoryRouter>
  </QueryClientProvider>);
  await screen.findAllByText('Employee Name');
  fireEvent.click(screen.getByRole('button', { name: 'Expand row' }));
  fireEvent.click((await screen.findAllByRole('button', { name: 'Finance Approve' }))[0]);
  expect(await screen.findByRole('heading', { name: 'Approve Employee Name?' })).toBeVisible();

  queryClient.setQueryData(['hr', 'profile-update-requests', 'all', 1, false], {
    data: [{
      id: 'request', status: 'approved', requires_finance: true,
      permissions: { review_hr: false, review_finance: true },
      available_actions: { can_review_hr: false, can_approve_finance: false, can_reject_finance: false },
      changes: { bank_name: 'Bank' }, note: null,
      employee: { id: 'employee', employee_no: '001', full_name: 'Employee Name', department: null },
      requester: null, reviewer: { id: 'hr-reviewer', name: 'HR Reviewer' }, reviewed_at: '2026-11-05T10:00:00Z', review_remarks: null,
      finance_reviewer: null, finance_reviewed_at: null, finance_remarks: null, created_at: null,
    }],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1, from: 1, to: 1 },
    links: { first: '/requests?page=1', last: '/requests?page=1', prev: null, next: null },
  });

  await waitFor(() => expect(screen.getByRole('button', { name: 'Approve' })).toBeDisabled());
  fireEvent.click(screen.getByRole('button', { name: 'Approve' }));
  expect(profileUpdateRequestsApi.financeReview).not.toHaveBeenCalled();
});
