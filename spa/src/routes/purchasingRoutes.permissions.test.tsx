import { cleanup, render, screen } from '@testing-library/react';
import { Suspense } from 'react';
import { MemoryRouter, Routes } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useAuthStore } from '@/stores/authStore';
import { purchasingRoutes } from './purchasingRoutes';

vi.mock('@/pages/purchasing/rfqs/detail', () => ({
  default: () => <p>RFQ detail</p>,
}));

function renderRoute(path: string, permissions: string[]) {
  useAuthStore.setState({
    isAuthenticated: true,
    permissions: new Set(permissions),
    features: new Set(['purchasing']),
  });
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Suspense fallback={<p>Loading</p>}>
        <Routes>{purchasingRoutes}</Routes>
      </Suspense>
    </MemoryRouter>,
  );
}

afterEach(() => {
  cleanup();
  useAuthStore.setState({ isAuthenticated: false, permissions: new Set(), features: new Set() });
});

describe('purchasing route permissions', () => {
  it('lets authenticated RFQ owners reach the row-scoped detail API without global purchasing permission', async () => {
    renderRoute('/purchasing/rfqs/owned-rfq', []);

    expect(await screen.findByText('RFQ detail')).toBeInTheDocument();
  });
});
