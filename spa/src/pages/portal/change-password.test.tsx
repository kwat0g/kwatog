import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { beforeEach, expect, it, vi } from 'vitest';
import { authApi } from '@/api/auth';
import PortalChangePasswordPage from './change-password';

vi.mock('@/api/auth', () => ({ authApi: { passwordPolicy: vi.fn() } }));
vi.mock('@/api/b2b/customer', () => ({ customerPortalApi: { changePassword: vi.fn() } }));
vi.mock('@/api/b2b/supplier', () => ({ supplierPortalApi: { changePassword: vi.fn() } }));

beforeEach(() => {
  vi.mocked(authApi.passwordPolicy).mockResolvedValue({
    minimum_length: 10,
    requires_uppercase: true,
    requires_lowercase: true,
    requires_digit: true,
    requires_special: true,
  });
});

it('explains password changes for an already signed-in portal user', async () => {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/portal/customer/change-password']}>
        <Routes>
          <Route path="/portal/:type/change-password" element={<PortalChangePasswordPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  expect(await screen.findByRole('heading', { name: 'Change portal password' })).toBeVisible();
  expect(screen.getByLabelText('Current password')).toBeVisible();
  expect(screen.getByText(/temporary password from your invitation on first sign-in/i)).toBeVisible();
  expect(screen.getByText(/you will be signed out after saving/i)).toBeVisible();
});
