/**
 * Per-dashboard KPI scorecard code lists for the shared compact `<KpiStrip>`.
 *
 * Every code must exist in `kpi_definitions` (see `KpiDefinitionSeeder`); the
 * server strips any code the viewer may not read
 * (`KpiSnapshotService::MODULE_PERMISSIONS`), which the strip renders as an
 * empty message — never a crash, never a role-name branch.
 *
 * `admin` intentionally passes `[]`: its Compute panel arrives in Task 3.
 * `default` intentionally passes `[]`: the widget home shows its own widgets.
 */
export const PRODUCTION_MANAGER_KPIS = ['oee', 'wo_completion_rate', 'first_pass_yield'];
// `oee` and `wo_completion_rate` are readable because the production KPI gate
// includes the production DATA read — `production.dashboard.view` is a page grant
// ppc_head does not hold, and gating the KPIs on it stripped two of the four
// codes this strip used to list from the role that owns the schedule
// (G-P2-2 / G-P2-3) — it drew 1 of 4. All three below now render.
//
// `first_pass_yield` is gone rather than granted: it is a QUALITY reading, and
// ppc_head holds no `quality.*` grant. The strip renders an empty message for a
// code the viewer cannot read, so leaving it here would have promised a tile
// that could never draw. Reading it on this dashboard is a grant decision, not a
// list edit.
export const PPC_KPIS = ['oee', 'wo_completion_rate', 'on_time_delivery'];
export const FINANCE_KPIS = ['ar_aging_60d', 'budget_utilization'];
export const HR_KPIS = ['attendance_rate'];
// `on_time_delivery` is a SUPPLY-CHAIN reading (gated by the PPC dashboard read),
// which purchasing_officer does not hold — so this strip draws 1 of its 2 codes.
// Left in the list deliberately: unlike PPC's quality code, supplier on-time
// performance is squarely purchasing's concern, so the fix is the grant, and
// that is recorded as a decision rather than silently dropped here.
export const PURCHASING_KPIS = ['supplier_quality', 'on_time_delivery'];
export const QC_KPIS = ['dppm', 'first_pass_yield', 'ncr_closure_days'];
// Same shape as purchasing: `supplier_quality` is a purchasing reading the floor
// has no grant for, so this strip draws 1 of 2.
export const WAREHOUSE_KPIS = ['inventory_turnover', 'supplier_quality'];
export const ADMIN_KPIS: string[] = [];
export const DEFAULT_KPIS: string[] = [];

/**
 * The company-overview health strip (01-matrix §B), in reading order.
 *
 * The six codes span four modules the executive holds no dashboard for. That
 * works because the server's KPI gate is a set that includes
 * `dashboard.company.view` (KpiSnapshotService::moduleGates), so this strip is
 * the one place those readings are readable without four module grants — before
 * that, every code here was stripped and the strip rendered empty rather than
 * "no data".
 */
export const COMPANY_KPIS = [
  'oee',
  'on_time_delivery',
  'first_pass_yield',
  'dppm',
  'ar_aging_60d',
  'budget_utilization',
];

/**
 * Owning role dashboard per KPI module. A KPI tile's default drill-down is
 * the dashboard that renders that KPI's strip, scrolled to the card —
 * there is no standalone scorecard page anymore.
 */
export const KPI_DASHBOARD_PATH: Record<string, string> = {
  production: '/dashboard/plant-manager',
  quality: '/dashboard/quality',
  supply_chain: '/dashboard/ppc',
  purchasing: '/dashboard/purchasing',
  attendance: '/dashboard/hr',
  accounting: '/dashboard/finance',
  inventory: '/dashboard/warehouse',
};

/** In-dashboard anchor link for a KPI code, e.g. `/dashboard/quality#kpi-dppm`. */
export function kpiDashboardLink(code: string, module?: string): string {
  const byCode: Record<string, string> = {
    oee: '/dashboard/plant-manager',
    wo_completion_rate: '/dashboard/plant-manager',
    dppm: '/dashboard/quality',
    first_pass_yield: '/dashboard/quality',
    ncr_closure_days: '/dashboard/quality',
    on_time_delivery: '/dashboard/ppc',
    supplier_quality: '/dashboard/purchasing',
    attendance_rate: '/dashboard/hr',
    ar_aging_60d: '/dashboard/finance',
    budget_utilization: '/dashboard/finance',
    inventory_turnover: '/dashboard/warehouse',
  };
  const base = byCode[code] ?? (module ? KPI_DASHBOARD_PATH[module] : undefined) ?? '/dashboard';
  return `${base}#kpi-${code}`;
}
