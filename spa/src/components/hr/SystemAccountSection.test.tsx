import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { employeeAccountsApi } from '@/api/hr/employee-accounts';
import { SystemAccountSection } from './SystemAccountSection';

const access = vi.hoisted(() => ({ manageUsers: false }));
vi.mock('@/hooks/usePermission', () => ({ usePermission: () => ({
  can: (slug: string) => slug === 'admin.users.manage' ? access.manageUsers : true,
}) }));
vi.mock('@/api/hr/employee-accounts', () => ({ employeeAccountsApi: { status: vi.fn(), resetPassword: vi.fn(), deactivate: vi.fn() } }));
vi.mock('./CreateAccountModal', () => ({ CreateAccountModal: () => null }));

beforeEach(() => {
  access.manageUsers = false;
  vi.mocked(employeeAccountsApi.status).mockResolvedValue({
    account_exists: true, is_active: true, is_locked: false, email: 'jamie@test.com',
    user_id: 'u1', role: { id: 'r1', name: 'Employee', slug: 'employee' },
    last_login_at: null, must_change_password: true,
  });
});

function show() {
  render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
    <SystemAccountSection employeeId="e1" />
  </QueryClientProvider>);
}

it('shows account status but hides account changes without user management permission', async () => {
  show();
  await screen.findByText('jamie@test.com');
  expect(screen.queryByRole('button', { name: 'Reset Password' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Deactivate Account' })).not.toBeInTheDocument();
});

it('offers account changes to authorized user managers', async () => {
  access.manageUsers = true;
  show();
  expect(await screen.findByRole('button', { name: 'Reset Password' })).toBeEnabled();
  expect(screen.getByRole('button', { name: 'Deactivate Account' })).toBeEnabled();
});
