import { Link, useLocation } from 'react-router-dom';
import { ChevronRight } from 'lucide-react';

/**
 * Path-derived breadcrumbs. Splits the current pathname into segments
 * and renders each as a clickable crumb (last one is the current page).
 *
 * Why not React Router's `useMatches`? It requires a data router
 * (`createBrowserRouter`); we use the simpler `BrowserRouter` so the
 * route table stays declarative and lazy-imports work cleanly.
 *
 * Module pages are free to override breadcrumbs entirely via
 * <PageHeader backTo="…" /> — this component is the global default.
 */

const TITLE_OVERRIDES: Record<string, string> = {
  hr: 'HR',
  mrp: 'MRP',
  crm: 'CRM',
  qc: 'QC',
  ncr: 'NCR',
  po: 'PO',
  pr: 'PR',
  so: 'SO',
  wo: 'WO',
  rbac: 'RBAC',
};

const titleize = (segment: string): string => {
  if (TITLE_OVERRIDES[segment]) return TITLE_OVERRIDES[segment];
  if (/^[a-z0-9]{6,}$/i.test(segment) && /\d/.test(segment)) return segment; // looks like a hash id
  return segment
    .replace(/-/g, ' ')
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());
};

export function Breadcrumbs() {
  const { pathname } = useLocation();
  const segments = pathname.split('/').filter(Boolean);

  if (segments.length === 0) return null;

  const crumbs = segments.map((segment, i) => ({
    label: titleize(segment),
    to: '/' + segments.slice(0, i + 1).join('/'),
  }));

  return (
    <nav aria-label="Breadcrumb" className="flex items-center gap-1 text-sm">
      {crumbs.map((c, i) => {
        const last = i === crumbs.length - 1;
        return (
          <span key={c.to} className="flex items-center gap-1">
            {i > 0 && <ChevronRight size={12} className="text-text-subtle" />}
            {last ? (
              <span className="text-primary font-medium">{c.label}</span>
            ) : (
              <Link to={c.to} className="text-muted hover:text-primary">
                {c.label}
              </Link>
            )}
          </span>
        );
      })}
    </nav>
  );
}
