import { RoleDashboardShell } from '@/components/dashboard/RoleDashboardShell';

/**
 * Sales dashboard (DSS §D3, M-p1 R6).
 *
 * `sales_officer` landed on `/dashboard/default` with **no role default rows at
 * all**, so the generic grid resolved to empty — the role had no dashboard in
 * either sense. `/chains` now reconciles page access with both read grants
 * (F-040); sales-order detail remains gated by `crm.sales_orders.view`.
 *
 * The order book and price-request queue now show status breakdowns, and order
 * intake shows a 12-month trend. The dashboard uses its sales widgets directly;
 * it does not add a page-local permission to the chain-stage widget.
 */
export default function SalesDashboardPage() {
  return (
    <RoleDashboardShell
      title="Sales"
      subtitle="Track order intake, the live order pipeline, and price requests."
      kpiCodes={[]}
      emptyTitle="No sales blocks configured"
      emptyDescription="Order intake, order stages, and price-request status appear here."
    />
  );
}
