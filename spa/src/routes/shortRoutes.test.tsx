import { Children, isValidElement, type ReactNode } from 'react';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { describe, expect, it } from 'vitest';
import { portalRoutes } from './portalRoutes';
import {
  APP_PATH_ALIASES,
  compactRouteTree,
  expandAppPath,
  legacyRouteAliases,
  shortenAppPath,
} from './shortRoutes';

function LocationOutput() {
  const { pathname, search, hash } = useLocation();
  return <output data-testid="location">{`${pathname}${search}${hash}`}</output>;
}

function collectRoutePaths(children: ReactNode, paths: string[] = []): string[] {
  Children.forEach(children, (child) => {
    if (!isValidElement(child)) return;
    const props = child.props as { path?: string; children?: ReactNode };
    if (props.path) paths.push(props.path);
    if (props.children) collectRoutePaths(props.children, paths);
  });
  return paths;
}

describe('compact SPA routes', () => {
  it('shortens module and deeply nested paths without changing route parameters', () => {
    expect(shortenAppPath('/crm/inquiries/azPAPDAXxo')).toBe('/inq/azPAPDAXxo');
    expect(shortenAppPath('/hr/attendance/overtime/abc123')).toBe('/ot/abc123');
    expect(shortenAppPath('/accounting/bills/42')).toBe('/ac/bills/42');
  });

  it('round-trips every registered module prefix and nested path', () => {
    for (const [longPrefix, shortPrefix] of APP_PATH_ALIASES) {
      expect(shortenAppPath(`${longPrefix}/record-123`)).toBe(`${shortPrefix}/record-123`);
      expect(expandAppPath(`${shortPrefix}/record-123`)).toBe(`${longPrefix}/record-123`);
    }
  });

  it('redirects a legacy URL to the short route and preserves its query and hash', async () => {
    render(
      <MemoryRouter initialEntries={["/crm/inquiries/azPAPDAXxo?status=closed#history"]}>
        <Routes>
          {compactRouteTree(
            <Route path="/crm/inquiries/:id" element={<p>Inquiry detail</p>} />,
          )}
          {legacyRouteAliases}
          <Route path="*" element={<p>Not found</p>} />
        </Routes>
        <LocationOutput />
      </MemoryRouter>,
    );

    expect(await screen.findByText('Inquiry detail')).toBeInTheDocument();
    expect(screen.getByTestId('location')).toHaveTextContent(
      '/inq/azPAPDAXxo?status=closed#history',
    );
  });

  it('serves a short overtime route directly', () => {
    render(
      <MemoryRouter initialEntries={['/ot/abc123']}>
        <Routes>
          {compactRouteTree(
            <Route path="/hr/attendance/overtime/:id" element={<p>Overtime detail</p>} />,
          )}
          {legacyRouteAliases}
          <Route path="*" element={<p>Not found</p>} />
        </Routes>
      </MemoryRouter>,
    );

    expect(screen.getByText('Overtime detail')).toBeInTheDocument();
  });

  it('keeps both portal change-password routes reachable after shortening', () => {
    const paths = collectRoutePaths(compactRouteTree(portalRoutes));

    expect(paths).toContain('/pc/change-password');
    expect(paths).toContain('/ps/change-password');
  });
});
