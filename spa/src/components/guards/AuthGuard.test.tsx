import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import type { AuthUser } from '@/api/auth';
import { afterEach, expect, it } from 'vitest';
import { useAuthStore } from '@/stores/authStore';
import { AuthGuard } from './AuthGuard';

afterEach(() => {
  cleanup();
  useAuthStore.setState({
    user: null,
    isAuthenticated: false,
    isLoading: false,
    bootstrapError: null,
    retryAt: 0,
  });
});
it('blocks protected content after a temporary bootstrap failure without sending the user to sign-in', () => {
  useAuthStore.setState({
    user: null,
    isAuthenticated: false,
    isLoading: false,
    bootstrapError: 'Could not verify your session.',
    retryAt: 0,
  });
  render(
    <MemoryRouter>
      <AuthGuard>
        <p>Protected payroll</p>
      </AuthGuard>
    </MemoryRouter>,
  );
  expect(screen.queryByText('Protected payroll')).not.toBeInTheDocument();
  expect(screen.getByRole('heading', { name: 'Session check unavailable' })).toBeVisible();
  expect(screen.getByRole('button', { name: 'Retry session check' })).toBeEnabled();
});

it('returns to the protected path with query and hash after sign-in', () => {
  function ShowReturnPath() {
    const location = useLocation();
    return <p>{(location.state as { from?: string } | null)?.from}</p>;
  }

  render(
    <MemoryRouter initialEntries={['/self-service/payslips/payroll-abc?source=email#download']}>
      <Routes>
        <Route path="/sign-in" element={<ShowReturnPath />} />
        <Route
          path="*"
          element={<AuthGuard><p>Protected payroll</p></AuthGuard>}
        />
      </Routes>
    </MemoryRouter>,
  );

  expect(screen.getByText('/self-service/payslips/payroll-abc?source=email#download')).toBeVisible();
});

it('preserves the intended protected path through a required password change', () => {
  const user = {
    id: 'u1', name: 'Staff User', email: 'staff@example.test', is_active: true,
    must_change_password: true, theme_mode: 'light', sidebar_collapsed: false,
    role: { id: 'r1', name: 'Employee', slug: 'employee' }, employee: null,
    permissions: [], delegated_approvals: [], features: [],
  } satisfies AuthUser;
  useAuthStore.setState({ user, isAuthenticated: true });

  function ShowReturnPath() {
    const location = useLocation();
    return <p>{(location.state as { from?: string } | null)?.from}</p>;
  }

  render(
    <MemoryRouter initialEntries={['/self-service/payslips/payroll-abc']}>
      <Routes>
        <Route path="/change-password" element={<ShowReturnPath />} />
        <Route path="*" element={<AuthGuard><p>Protected payroll</p></AuthGuard>} />
      </Routes>
    </MemoryRouter>,
  );

  expect(screen.getByText('/self-service/payslips/payroll-abc')).toBeVisible();
});
