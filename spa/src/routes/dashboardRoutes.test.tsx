import { Children, isValidElement, type ReactElement } from 'react';
import { describe, expect, it } from 'vitest';
import { Navigate } from 'react-router-dom';
import { dashboardRoutes } from './dashboardRoutes';

/**
 * Task 3 — the standalone KPI scorecard page is gone. Every KPI renders on
 * its owning role dashboard strip; `/dashboard/scorecard` survives only as a
 * bookmark shim onto the permission-derived role router.
 */
describe('dashboardRoutes scorecard shim', () => {
  const routes = Children.toArray(dashboardRoutes.props.children)
    .filter(isValidElement)
    .map((r) => r as ReactElement<{ path?: string; element?: ReactElement }>);

  it('keeps a /dashboard/scorecard route that redirects to the role router', () => {
    const shim = routes.find((r) => r.props.path === '/dashboard/scorecard');
    expect(shim).toBeDefined();
    expect(shim?.props.element?.type).toBe(Navigate);
    expect(shim?.props.element?.props.to).toBe('/dashboard');
    expect(shim?.props.element?.props.replace).toBe(true);
  });

  it('mounts no scorecard page component anymore', () => {
    const paths = routes.map((r) => r.props.path);
    expect(paths.filter((p) => p === '/dashboard/scorecard')).toHaveLength(1);
  });
});
