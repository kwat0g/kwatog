import { RoleDashboardShell } from '@/components/dashboard/RoleDashboardShell';

/**
 * Customer service dashboard (DSS §D3, M-p1 R5).
 *
 * `customer_service_officer` also landed on `/dashboard/default` with no role
 * default rows, so its two real queues — customer complaints and return cases —
 * were reachable only by navigating to each module. This page is those two
 * queues, plus the complaints breakdown that already existed as a widget.
 *
 * Return handling is where this role is load-bearing: it holds
 * `return_management.manage`/`dispose`/`complete`, so the RMA cards here are
 * worklists, not displays. Acting still happens on the entity pages
 * (`/return-management/:id`) — the dashboard stays read-only and links out, per
 * the mission's standing rule that act lives on entity controllers only.
 */
export default function CustomerServiceDashboardPage() {
  return (
    <RoleDashboardShell
      title="Customer Service"
      subtitle="Complaints and return cases: what is open, what is waiting on us."
      kpiCodes={[]}
      emptyTitle="No customer-service blocks configured"
      emptyDescription="Complaints and return cases appear here as you hold those module reads."
    />
  );
}
