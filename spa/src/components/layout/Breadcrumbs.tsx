import { Link, useLocation } from 'react-router-dom';
import { LuChevronRight } from '@/lib/icons';
import { useBreadcrumbStore } from '@/stores/breadcrumbStore';
import { MODULE_LABELS } from '@/lib/moduleLabels';

/**
 * Path-derived breadcrumbs — the app's ONE trail.
 *
 * DESIGN-SYSTEM.md → "Topbar (48px)" specifies breadcrumbs here, so this is
 * the canonical location. `PageHeader` used to accept a competing
 * `breadcrumbs` array, which meant the ~20 pages that passed it rendered two
 * trails at once on desktop — and the two disagreed, because a page would
 * write `{ label: 'CRM' }` where MODULE_LABELS says "Sales & CRM".
 * PageHeader now contributes a label for the final segment instead of a second
 * trail (see `stores/breadcrumbStore`).
 *
 * Why not React Router's `useMatches`? It requires a data router
 * (`createBrowserRouter`); we use the simpler `BrowserRouter` so the
 * route table stays declarative and lazy-imports work cleanly.
 *
 * ADV2 (Adviser feedback Task 2): the first URL segment is mapped to its
 * restructured module display name (e.g. "mrp" → "Production Planning",
 * "supply-chain" → "Supply Chain") so the breadcrumb mirrors the new
 * sidebar IA without changing any URLs.
 */

// Several module roots are redirects or have no standalone index route. Point
// the global breadcrumb at the supported entry surface so every ancestor is a
// real destination instead of generating dead links such as /hr or /admin.
const MODULE_PATHS: Record<string, string> = {
 dashboard: '/dashboard',
 'action-center': '/action-center',
 exceptions: '/exceptions',
 alerts: '/alerts',
 calendar: '/calendar',
 approvals: '/approvals',
 notifications: '/notifications',
 crm: '/crm',
 mrp: '/mrp',
 production: '/production',
 'supply-chain': '/supply-chain',
 purchasing: '/purchasing',
 inventory: '/inventory',
 quality: '/quality',
 accounting: '/accounting',
 hr: '/hr/employees',
 payroll: '/payroll/periods',
 maintenance: '/maintenance',
 assets: '/assets',
 admin: '/admin/users',
 'self-service': '/self-service',
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

/**
 * Does this segment look like an opaque record identifier rather than a word?
 *
 * The old check required a digit, so an all-letter hashid ("yRkLmQ") escaped
 * it and got titleized into "Yrklmq" — a plausible-looking word that names
 * nothing. Detect on shape instead: no separators, mixed case or digit-bearing,
 * and not a known abbreviation.
 */
function looksLikeRecordId(segment: string): boolean {
 if (TITLE_OVERRIDES[segment]) return false;
 if (/[-_]/.test(segment)) return false;
 if (/^\d+$/.test(segment)) return true;
 if (segment.length < 5) return false;
 // A hashid mixes case or digits; a real word segment is all lowercase letters.
 return /[A-Z]/.test(segment) || /\d/.test(segment);
}

const titleize = (segment: string): string => {
 if (TITLE_OVERRIDES[segment]) return TITLE_OVERRIDES[segment];
 return segment
 .replace(/-/g, ' ')
 .replace(/_/g, ' ')
 .replace(/\b\w/g, (c) => c.toUpperCase());
};

export function Breadcrumbs() {
 const { pathname } = useLocation();
 // PageHeader publishes the record's human name (the same string it puts in
 // document.title). Only trust it for the route that registered it.
 const override = useBreadcrumbStore((s) => (s.path === pathname ? s.label : null));
 const segments = pathname.split('/').filter(Boolean);

 if (segments.length === 0) return null;

 const lastIndex = segments.length - 1;
 const crumbs = segments.map((segment, i) => {
 let label: string;
 // The page-supplied label is only worth preferring when the segment cannot
 // name itself. On /payroll/periods the segment titleizes to "Periods" and the
 // heading reads "Payroll Periods", so taking the override there duplicated the
 // heading into the trail — which also made every `getByText(title)` in the
 // e2e suite a strict-mode violation.
 if (i === lastIndex && override && looksLikeRecordId(segment)) {
 label = override;
 } else if (i === 0 && MODULE_LABELS[segment]) {
 label = MODULE_LABELS[segment];
 } else if (looksLikeRecordId(segment)) {
 // No page-supplied name and the segment carries none: an ellipsis reads
 // as "a record" where the raw hash read as corruption.
 label = '…';
 } else {
 label = titleize(segment);
 }
 return {
 key: `${i}-${segment}`,
 label,
 to:
 i === 0 && MODULE_PATHS[segment]
 ? MODULE_PATHS[segment]
 : '/' + segments.slice(0, i + 1).join('/'),
 };
 });

 return (
 <nav aria-label="Breadcrumb" className="flex items-center gap-1 text-sm min-w-0">
 {crumbs.map((c, i) => {
 const last = i === crumbs.length - 1;
 return (
 <span
 key={c.key}
 className={
 // Below `sm` only the current page fits beside the logo; the
 // ancestors are the first thing to give up, not the whole trail.
 last ? 'flex items-center gap-1 min-w-0' : 'hidden sm:flex items-center gap-1'
 }
 >
 {i > 0 && <LuChevronRight size={12} className="text-subtle shrink-0 hidden sm:block" />}
 {last ? (
 <span className="text-primary font-medium truncate">{c.label}</span>
 ) : (
 <Link to={c.to} className="text-muted hover:text-primary whitespace-nowrap">
 {c.label}
 </Link>
 )}
 </span>
 );
 })}
 </nav>
 );
}
