import { RoleDashboardShell } from '@/components/dashboard/RoleDashboardShell';

/**
 * ImpEx logistics dashboard (DSS 01-matrix §D3, M-p3 R14).
 *
 * `impex_officer` had no `dashboard.*.view` slug, so it landed on
 * `/dashboard/default` — the generic widget grid, with nothing sized to the
 * decisions this role makes: what ships today, what is stuck in transit, what
 * needs a driver.
 *
 * The strip carries OTD because the supply_chain KPI gate is now a SET that
 * includes this page's own permission. That is G-P3-2's resolution — the officer
 * who owns deliveries was being judged on a number they could not read, and the
 * fix was a second qualified gate rather than a role-name branch. The set keeps
 * the decision a grant: a new holder of `dashboard.ppc.view` still reads it too.
 *
 * Deliberately NOT built: M-p3 R14 §2's "docs expiring ≤ 30d" card.
 * `shipment_documents` has no expiry column and no expiry sweep behind it, so
 * the card would have rendered a permanent zero. Recorded as data-not-available.
 */
export default function LogisticsDashboardPage() {
  return (
    <RoleDashboardShell
      title="Logistics"
      subtitle="Shipments, dispatch and fleet: what is moving, what is late, what needs a driver."
      kpiCodes={['on_time_delivery']}
      emptyTitle="No logistics blocks configured"
      emptyDescription="Shipment, delivery and purchasing reads appear here as you hold those module permissions."
    />
  );
}
