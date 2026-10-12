import { RoleDashboardShell } from '@/components/dashboard/RoleDashboardShell';

/**
 * Maintenance dashboard — ONE page for two roles (DSS §D3, M-p2 R12 + M-p3 R13).
 *
 * Both `maintenance_tech` and `maintenance_supervisor` hold
 * `dashboard.maintenance.view`, and each sees the cards its own permissions
 * admit: the tech gets the queued/assigned work and its own job metrics, the
 * supervisor additionally gets unassigned-MWO and awaiting-verify queues. There
 * is no role-name branch — the difference is which widgets the layout service
 * leaves standing.
 *
 * The page gate is deliberately NOT `maintenance.work.assigned`. That slug is
 * the mobile work-list read and the technician does not hold it, so gating this
 * page on it would have hidden the page from half the roles it exists for (the
 * M6 trap the specs call out by name).
 *
 * No KPI strip: neither role holds a `kpi.*` gate, and M-p2 R12 §2 settles it
 * ("a maintenance page must carry its own cards, not rely on the KPI plane").
 * `kpiCount` is therefore 0 rather than a strip that would render an empty
 * message for every code.
 */
export default function MaintenanceDashboardPage() {
  return (
    <RoleDashboardShell
      title="Maintenance"
      subtitle="Breakdowns, preventive windows and machine condition — queued work first."
      kpiCodes={[]}
      emptyTitle="No maintenance blocks configured"
      emptyDescription="Your work orders, due schedules, machine status and asset reads appear here."
    />
  );
}
