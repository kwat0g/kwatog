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
 *
 * ADV2 (Adviser feedback Task 2): the first URL segment is mapped to its
 * restructured module display name (e.g. "mrp" → "Production Planning",
 * "supply-chain" → "Supply Chain") so the breadcrumb mirrors the new
 * sidebar IA without changing any URLs.
 */

/**
 * Restructured module names — ADV2 sidebar IA. Keyed by the **first**
 * URL segment only; later segments fall through to TITLE_OVERRIDES /
 * titleize().
 */
const MODULE_LABELS: Record<string, string> = {
  dashboard: 'Dashboard',
  alerts: 'Alerts',
  calendar: 'Calendar',
  approvals: 'Approvals',
  notifications: 'Notifications',
  crm: 'Sales & CRM',
  mrp: 'Production Planning',
  production: 'Production',
  'supply-chain': 'Supply Chain',
  purchasing: 'Procurement',
  inventory: 'Warehouse',
  quality: 'Quality Control',
  accounting: 'Finance & Accounting',
  hr: 'Human Resources',
  payroll: 'Payroll & Benefits',
  maintenance: 'Maintenance',
  assets: 'Maintenance',
  admin: 'Administration',
  'self-service': 'Self-service',
};

const TITLE_OVERRIDES: Record<string, string> = {
  hr: 'HR',
  mrp: 'MRP',
  crm: 'CRM',
  qc: 'QC',
  ncr: 'NCR',
  ncrs: 'NCRs',
  po: 'PO',
  pr: 'PR',
  so: 'SO',
  wo: 'WO',
  rbac: 'RBAC',
  coa: 'Chart of Accounts',
  boms: 'BOMs',
  grn: 'GRN',
  oee: 'OEE',
  dtr: 'DTR',
  ppc: 'PPC',
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
    label: i === 0 && MODULE_LABELS[segment] ? MODULE_LABELS[segment] : titleize(segment),
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
