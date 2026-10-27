import { Suspense } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter, Routes } from 'react-router-dom';

const granted = vi.hoisted(() => ({ permissions: [] as string[] }));

vi.mock('@/hooks/usePermission', () => ({
  usePermission: () => ({
    can: (permission: string) => granted.permissions.includes(permission),
    canApprove: () => false,
  }),
}));
vi.mock('@/hooks/useFeature', () => ({ useFeature: () => true }));
vi.mock('@/pages/production/work-orders', () => ({ default: () => <div>Work Orders route</div> }));

import { productionRoutes } from './productionRoutes';

afterEach(() => {
  cleanup();
  granted.permissions = [];
});

function renderWorkOrdersRoute(permissions: string[]) {
  granted.permissions = permissions;
  return render(
    <MemoryRouter initialEntries={['/production/work-orders']}>
      <Suspense fallback="Loading">
        <Routes>{productionRoutes}</Routes>
      </Suspense>
    </MemoryRouter>,
  );
}

it.each([
  ['work-order permission only', ['production.work_orders.view']],
  ['module permission only', ['production.view']],
])('hides the Work Orders route with %s', async (_label, permissions) => {
  renderWorkOrdersRoute(permissions);

  expect(await screen.findByRole('heading', { name: 'Page not found' })).toBeVisible();
  expect(screen.queryByText('Work Orders route')).not.toBeInTheDocument();
});

it('opens the Work Orders route when both API route permissions exist', async () => {
  renderWorkOrdersRoute(['production.view', 'production.work_orders.view']);

  expect(await screen.findByText('Work Orders route')).toBeVisible();
});
