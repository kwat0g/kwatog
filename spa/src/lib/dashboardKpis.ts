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
export const PPC_KPIS = ['oee', 'wo_completion_rate', 'on_time_delivery', 'first_pass_yield'];
export const FINANCE_KPIS = ['ar_aging_60d', 'budget_utilization'];
export const HR_KPIS = ['attendance_rate'];
export const PURCHASING_KPIS = ['supplier_quality', 'on_time_delivery'];
export const QC_KPIS = ['dppm', 'first_pass_yield', 'ncr_closure_days'];
export const WAREHOUSE_KPIS = ['inventory_turnover', 'supplier_quality'];
export const ADMIN_KPIS: string[] = [];
export const DEFAULT_KPIS: string[] = [];
