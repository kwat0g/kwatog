/**
 * Sprint P8 — dashboard drill-down link map.
 *
 * Maps KPI labels (the canonical ones produced by RoleDashboardService.kpi())
 * and chain-stage keys (chain_stages panel) to the filtered list URL the
 * user expects when they click. List pages must read URL query params at
 * mount and apply them as filters (see `useUrlFilters`).
 *
 * Adding a new KPI: pick a stable label string in the backend, then add a
 * matching entry here. If no entry exists the card stays non-clickable —
 * never throw on missing mappings.
 */

/** Build an `/accounting/invoices?date_from=YYYY-MM-DD` URL for "this week". */
function startOfThisWeek(): string {
  const now = new Date();
  const d = new Date(Date.UTC(now.getFullYear(), now.getMonth(), now.getDate()));
  // Monday as week start
  const day = d.getUTCDay() || 7;
  d.setUTCDate(d.getUTCDate() - (day - 1));
  return d.toISOString().slice(0, 10);
}

function startOfThisMonth(): string {
  const now = new Date();
  return new Date(Date.UTC(now.getFullYear(), now.getMonth(), 1)).toISOString().slice(0, 10);
}

/**
 * KPI label → drill-down URL. Returns `undefined` for KPIs that don't have
 * a meaningful drill-down (so the card stays non-clickable).
 */
export function kpiLink(label: string): string | undefined {
  switch (label) {
    // ─── Plant Manager ────────────────────────────────────
    case 'Revenue · Week':
      return `/accounting/invoices?status=finalized&date_from=${startOfThisWeek()}`;
    case 'Production · Week':
      return `/production/work-orders?status=in_progress`;
    case 'OEE · Today':
      return `/production/oee`;
    case 'On-Time Delivery':
      return `/supply-chain/deliveries`;

    // ─── HR ────────────────────────────────────────────────
    case 'Active Headcount':
      return `/hr/employees?status=active`;
    case 'On Leave Today':
      return `/hr/employees?status=on_leave`;
    case 'Pending Leave':
      return `/hr/leaves?status=pending_dept`;
    case 'Open Clearances':
      return `/hr/separations?status=in_progress`;

    // ─── PPC ───────────────────────────────────────────────
    case 'Active WOs':
      return `/production/work-orders?status=in_progress`;
    case 'Material Shortages':
      return `/inventory/items?below_reorder=1`;
    case 'Active Breakdowns':
      return `/maintenance/work-orders?status=in_progress`;
    case 'Molds ≥ 80%':
      return `/mrp/molds?nearing_limit=1`;

    // ─── Accounting ────────────────────────────────────────
    case 'Cash Balance':
      return `/accounting/journal-entries`;
    case 'AR Outstanding':
      return `/accounting/invoices?status=finalized`;
    case 'AP Outstanding':
      return `/accounting/bills?status=unpaid`;
    case 'Draft JEs':
      return `/accounting/journal-entries?status=draft`;
    case 'Revenue MTD':
      return `/accounting/income-statement?date_from=${startOfThisMonth()}`;

    // ─── Self-service ──────────────────────────────────────
    case 'Attendance · Month':
      return `/self-service/dtr`;
    case 'Leave Days Remaining':
      return `/self-service/leave`;
    case 'Pending Requests':
      return `/self-service/leave`;

    // ─── Purchasing (D6) ───────────────────────────────────
    case 'PRs Pending Action':
      return `/purchasing/purchase-requests?status=pending`;
    case 'Open POs':
      return `/purchasing/purchase-orders?status=sent`;
    case 'Overdue Deliveries':
      return `/purchasing/purchase-orders?overdue=1`;
    case 'Suppliers Due Review':
      return `/purchasing/suppliers?below_score=80`;

    // ─── Warehouse (D7) ────────────────────────────────────
    case 'Pending GRNs':
      return `/inventory/grn?status=pending`;
    case 'Issues Today':
      return `/inventory/material-issues?date=today`;
    case 'Low Stock Items':
      return `/inventory/items?below_reorder=1`;
    case 'Pending Transfers':
      return `/inventory/stock-movements?type=transfer&pending=1`;

    // ─── Quality (D8) ──────────────────────────────────────
    case 'Pending Inspections':
      return `/quality/inspections?status=in_progress`;
    case 'Pass Rate Today':
      return `/quality/inspections?date=today`;
    case 'Open NCRs':
      return `/quality/ncrs?status=open`;
    case 'CoCs Gen. MTD':
      return `/quality/certificates`;

    default:
      return undefined;
  }
}

/**
 * Chain-stage key → drill-down URL for the order-to-cash StageBreakdown
 * panel on the Plant Manager dashboard. Keys come straight from
 * RoleDashboardService::stageBreakdown() (`order_entered`, `mrp_planned`, …).
 */
export function chainStageLink(key: string): string | undefined {
  switch (key) {
    case 'order_entered':
      return `/crm/sales-orders?status=confirmed`;
    case 'mrp_planned':
      return `/mrp/plans?status=active`;
    case 'in_production':
      return `/production/work-orders?status=in_progress`;
    case 'qc_pending':
      return `/quality/inspections?status=in_progress`;
    case 'ready_to_ship':
      return `/supply-chain/deliveries?status=scheduled`;
    case 'delivered_unpaid':
      return `/accounting/invoices?status=finalized`;
    default:
      return undefined;
  }
}

/**
 * Alert kind → drill-down URL for the alerts panel on every role
 * dashboard. Keys come from RoleDashboardService::alerts() — keep in sync
 * if the backend renames any.
 */
export function alertLink(kind: string): string | undefined {
  switch (kind) {
    case 'ncr_open':
      return `/quality/ncrs?status=open`;
    case 'breakdown':
      return `/maintenance/work-orders?status=in_progress`;
    case 'mold_limit':
      return `/mrp/molds?nearing_limit=1`;
    case 'urgent_pr':
      return `/purchasing/purchase-requests?is_auto_generated=1`;
    default:
      return undefined;
  }
}

/** Resolve an itemized alert row to its entity detail URL (or list fallback). */
export function alertRefLink(ref: string | null, refId: string | null, kind: string): string {
  if (ref && refId) {
    switch (ref) {
      case 'machine': return `/mrp/machines/${refId}`;
      case 'ncr':     return `/quality/ncrs/${refId}`;
      case 'mold':    return `/mrp/molds/${refId}`;
    }
  }
  // Fall back to the existing kind→list mapping.
  return alertLink(kind) ?? '/alerts';
}
