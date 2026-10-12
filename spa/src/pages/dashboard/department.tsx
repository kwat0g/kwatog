import { RoleDashboardShell } from '@/components/dashboard/RoleDashboardShell';

/**
 * Department dashboard (DSS §D3, M-p3 R15).
 *
 * `department_head` carries six approve grants and had no surface of its own:
 * its approvals were five separate module pages plus a board that omits the
 * custom family entirely (overtime is board-blind — C §1b). This page is where
 * that queue — leave, loans, returns, overtime, clearance — sits in one place,
 * alongside department-scoped presence and headcount.
 *
 * Every card here is already department-scoped server-side
 * (`DashboardWidgetDataService` + `WidgetScope`), so the page is also the reason
 * the two `hr.team_*` tiles are documented as broad-but-harmless rather than
 * re-gated: what they return is this department's count, or "No department is
 * linked to this account".
 *
 * No KPI strip: a department attendance RATE would need `kpi.attendance_rate`,
 * whose gate is the HR planning read this role does not hold. The matrix's other
 * option — a department rollup card — has no department-scoped KPI source today,
 * and the KPI itself is a company-wide figure, so granting it would have widened
 * a company number to every department head. Left open rather than granted.
 */
export default function DepartmentDashboardPage() {
  return (
    <RoleDashboardShell
      title="Department"
      subtitle="Approvals, presence and headcount for the department you own."
      kpiCodes={[]}
      emptyTitle="No department blocks configured"
      emptyDescription="Your team's approvals, attendance and headcount appear here as you hold those reads."
    />
  );
}
