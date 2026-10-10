import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
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
