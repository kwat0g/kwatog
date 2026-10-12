import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useAuthStore } from '@/stores/authStore';
import { inventoryRoutes } from './inventoryRoutes';

vi.mock('@/pages/inventory/warehouse', () => ({
  default: () => <div>Warehouse structure page</div>,
}));

function renderRoute(path: string, permissions: string[]) {
  useAuthStore.setState({
    isAuthenticated: true,
    permissions: new Set(permissions),
    features: new Set(['inventory']),
  });
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>{inventoryRoutes}</Routes>
    </MemoryRouter>,
  );
}

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  useAuthStore.setState({ isAuthenticated: false, permissions: new Set(), features: new Set() });
});

describe('inventory route permissions', () => {
  it('allows inventory readers to view warehouse structure without management access', async () => {
    renderRoute('/inventory/warehouse', ['inventory.view']);

    expect(await screen.findByText('Warehouse structure page')).toBeInTheDocument();
  });

  it('keeps warehouse map restricted to structure managers', () => {
    renderRoute('/inventory/warehouse-map', ['inventory.view']);

    expect(screen.getByText('Page not found')).toBeInTheDocument();
  });
});
