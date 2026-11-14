/* eslint-disable react-refresh/only-export-components -- this module exports the route tree helpers alongside its redirect element. */
import { Children, Fragment, cloneElement, isValidElement, type ReactElement, type ReactNode } from 'react';
import { Navigate, Route, useLocation } from 'react-router-dom';
import { APP_PATH_ALIASES, shortenAppPath } from '@/lib/appPaths';

export { APP_PATH_ALIASES, expandAppPath, shortenAppPath } from '@/lib/appPaths';

/** Clone a declared route tree with compact paths while retaining its guards. */
export function compactRouteTree(children: ReactNode): ReactNode {
  return Children.map(children, (child) => {
    if (!isValidElement(child)) return child;

    if (child.type === Fragment) {
      return cloneElement(child, undefined, compactRouteTree(child.props.children));
    }

    if (child.type === Route) {
      const route = child as ReactElement<{ path?: string; children?: ReactNode }>;
      const path = route.props.path;
      return cloneElement(
        route,
        path ? { path: shortenAppPath(path) } : undefined,
        compactRouteTree(route.props.children),
      );
    }

    return child;
  });
}

function LegacyPathRedirect() {
  const { pathname, search, hash } = useLocation();
  const shortPath = shortenAppPath(pathname);
  return <Navigate to={`${shortPath}${search}${hash}`} replace />;
}

/** Route elements that preserve every legacy path as a redirect. */
export const legacyRouteAliases = [...new Set(APP_PATH_ALIASES.map(([from]) => from))].flatMap(
  (prefix) => [
    <Route key={`${prefix}-legacy-exact`} path={prefix} element={<LegacyPathRedirect />} />,
    <Route key={`${prefix}-legacy-descendants`} path={`${prefix}/*`} element={<LegacyPathRedirect />} />,
  ],
);
