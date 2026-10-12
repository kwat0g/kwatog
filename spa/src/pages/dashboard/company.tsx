import { RoleDashboardShell } from '@/components/dashboard/RoleDashboardShell';
import { COMPANY_KPIS } from '@/lib/dashboardKpis';

/**
 * Company overview — the cross-functional executive surface (DSS 01-matrix §B).
 *
 * Dispatched by holding `dashboard.company.view` (never by role name), its
 * health strip is the six company KPIs, and its body is the widget registry —
 * the layout work now lives in `RoleDashboardShell`, shared with the five role
 * pages added alongside it.
 *
 * The eight §B blocks are not new analytics: every one of them already exists as
 * a gated widget or KPI reading, so re-deriving them here would have created a
 * second, differently-gated copy of numbers the modules already publish. What
 * the viewer gets is permission-derived end to end — the strip admits the
 * company-wide read through each module's KPI gate set, and the body is stripped
 * against each widget's own gate.
 *
 * That is why a block can simply be absent. Money, chain health, alerts, demand
 * vs supply and the approvals worklist render for an executive; §B's quality
 * panel does not, because it draws on `quality.*` grants the executive does not
 * hold — and omitting a panel is the documented rule. Nothing here names a role,
 * so granting that slug adds the block with no code change.
 *
 * Quality is a read-only set of Pareto, NCR-aging and 8D-SLA signals. It
 * requires quality view grants, while every management and disposition action
 * remains in the owning module.
 *
 * Demand-vs-supply is the block that proved the rule rather than dodging it. It
 * was absent because its two forecasts were gated on module PAGE slugs
 * (`forecasting.view`, `accounting.dashboard.view`) that the executive does not
 * hold and has no reason to; re-gating them on the datasets' read SETS — which
 * admit `dashboard.company.view` — put the block here without granting a single
 * new authority.
 */
export default function CompanyDashboardPage() {
  return (
    <RoleDashboardShell
      title="Company Overview"
      subtitle="Company risks, quality, cash flow, chain health and approval load."
      kpiCodes={COMPANY_KPIS}
      emptyTitle="Nothing on this overview yet"
      emptyDescription="Your role has no default blocks for this page. Signals appear when your permissions include their source data."
    />
  );
}
