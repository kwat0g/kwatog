import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter, Routes } from 'react-router-dom';
import { afterEach, expect, it, vi } from 'vitest';
import { useAuthStore } from '@/stores/authStore';
import { selfServiceRoutes } from './selfServiceRoutes';

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  useAuthStore.setState({ isAuthenticated: false, permissions: new Set(), features: new Set() });
});

it('blocks notification preferences when its API permission is revoked', () => {
  useAuthStore.setState({ isAuthenticated: true, permissions: new Set(), features: new Set() });
  render(
    <MemoryRouter initialEntries={['/self-service/notification-preferences']}>
      <Routes>{selfServiceRoutes}</Routes>
    </MemoryRouter>,
  );

  expect(screen.getByText('Page not found')).toBeInTheDocument();
  expect(screen.queryByText('Notification preferences')).not.toBeInTheDocument();
});
