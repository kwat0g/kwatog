import { RoleDashboardShell } from '@/components/dashboard/RoleDashboardShell';

/**
 * Sales dashboard (DSS §D3, M-p1 R6).
 *
 * `sales_officer` landed on `/dashboard/default` with **no role default rows at
 * all**, so the generic grid resolved to empty — the role had no dashboard in
 * either sense. It opens `/chains` but the bottleneck widget 403'd it (M10), so
 * even the one cross-module view it could reach showed it nothing.
 *
 * Two cards are new (`sales.open_orders`, `sales.price_requests`) because the
 * order book and the price-request queue had no widget over them; open customer
 * complaints already had one. The M10 divergence is NOT papered over here —
 * `chain.stage_breakdown` is gated `dashboard.view_bottlenecks`, which this role
 * does not hold, and the fix is either a grant or the API/gate reconciliation
 * F-040 names. Left as recorded rather than worked around with a page-local
 * permission change.
 */
export default function SalesDashboardPage() {
  return (
    <RoleDashboardShell
      title="Sales"
      subtitle="The open order book: what is unconfirmed, what is priced, what customers are saying."
      kpiCodes={[]}
      emptyTitle="No sales blocks configured"
      emptyDescription="Your order book, price requests and customer complaints appear here."
    />
  );
}
