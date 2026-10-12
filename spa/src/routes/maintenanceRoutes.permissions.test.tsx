import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { workOrdersApi } from '@/api/maintenance/workOrders';
import { useAuthStore } from '@/stores/authStore';
import { maintenanceRoutes } from './maintenanceRoutes';

function renderRoute(path: string, permissions: string[]) {
  useAuthStore.setState({
    isAuthenticated: true,
    permissions: new Set(permissions),
    features: new Set(['maintenance']),
  });
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>{maintenanceRoutes}</Routes>
    </MemoryRouter>,
  );
}

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  useAuthStore.setState({ isAuthenticated: false, permissions: new Set(), features: new Set() });
});

describe('maintenance route permissions', () => {
  it('blocks the maintenance landing redirect for roles without maintenance read access', () => {
    renderRoute('/maintenance', []);
    expect(screen.getByText('Page not found')).toBeInTheDocument();
  });

  it('keeps assigned-only employees on self-service routes, not internal detail URLs', () => {
    const show = vi.spyOn(workOrdersApi, 'show');
    renderRoute('/maintenance/work-orders/private-order', ['maintenance.work.assigned']);
    expect(screen.getByText('Page not found')).toBeInTheDocument();
    expect(show).not.toHaveBeenCalled();
  });
});
