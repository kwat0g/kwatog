/**
 * Warehouse Staff Dashboard — Task D7.
 *
 * Data source: GET /api/v1/dashboards/warehouse (via dashboardsApi.warehouse)
 * Backend:     RoleDashboardService::warehouse()
 * Cache:       30s Redis per user
 */
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { dashboardsApi } from '@/api/dashboards';
import { kpiLink } from '@/lib/dashboardLinks';
import { StatCard } from '@/components/ui/StatCard';
import { Panel } from '@/components/ui/Panel';
import { EmptyState } from '@/components/ui/EmptyState';
import { Th, Td, tableCls, theadTrCls, trCls } from '@/components/ui/table-cells';
import { StockOutPanel } from '@/components/dashboard/StockOutPanel';
import { DashboardShell, KpiGrid, PanelRow } from '@/components/dashboard/DashboardShell';
import { DonutBreakdown } from '@/components/charts';
import { usePermission } from '@/hooks/usePermission';
import { KpiStrip } from '@/components/dashboard/KpiStrip';

/* ───────────────────────── Typed interface ───────────────────────── */

interface IncomingItem {
  id: string;
  po_number: string;
  vendor: string;
  items_count: number;
  expected_date: string | null;
}

interface OutgoingItem {
  id: string;
  so_number: string;
  customer: string;
  scheduled_date: string | null;
}

interface LowStockItem {
  item_id: string;
  item_code: string;
  item_name: string;
  current_stock: string;
  reorder_point: string;
  shortage: string;
  supplier_id: string | null;
  supplier_name: string | null;
}

interface ZoneItem {
  zone: string;
  name: string;
  percent: number;
}

interface WarehouseDashboardData {
  kpis: Array<{ label: string; value: string; unit: string }>;
  panels: {
    incoming_queue: IncomingItem[];
    outgoing_queue: OutgoingItem[];
    low_stock_alerts: LowStockItem[];
    zone_utilization: ZoneItem[];
  };
}

/* ───────────────────────── Sub-panel components ───────────────────────── */

function IncomingQueuePanel({ items }: { items: IncomingItem[] }) {
  if (items.length === 0) {
    return (
      <Panel title="Incoming (Next 7 Days)">
        <EmptyState icon="truck" title="No incoming deliveries" description="No deliveries expected in the next 7 days." />
      </Panel>
    );
  }

  return (
    <Panel title="Incoming (Next 7 Days)" meta={items.length.toString()}>
      <ul className="divide-y divide-subtle">
        {items.map((d) => (
          <li key={d.id} className="flex items-center justify-between py-2 text-sm">
            <div className="min-w-0 flex-1">
              <Link
                to={`/inventory/grn/create?po=${d.id}`}
                className="font-mono text-xs text-link hover:underline truncate block"
                aria-label={`Process GRN for PO ${d.po_number}`}
              >
                {d.po_number}
              </Link>
              <span className="text-muted text-xs block truncate">{d.vendor}</span>
            </div>
            <div className="flex items-center gap-3 ml-2 shrink-0">
              <span className="text-xs text-muted font-mono tabular-nums">{d.items_count} items</span>
              <span className="font-mono tabular-nums text-xs text-muted">{d.expected_date ?? '—'}</span>
            </div>
          </li>
        ))}
      </ul>
    </Panel>
  );
}

function OutgoingQueuePanel({ items }: { items: OutgoingItem[] }) {
  if (items.length === 0) {
    return (
      <Panel title="Outgoing (Scheduled)">
        <EmptyState icon="package" title="No outgoing shipments" description="No deliveries scheduled for dispatch." />
      </Panel>
    );
  }

  return (
    <Panel title="Outgoing (Scheduled)" meta={items.length.toString()}>
      <ul className="divide-y divide-subtle">
        {items.map((d) => (
          <li key={d.id} className="flex items-center justify-between py-2 text-sm">
            <div className="min-w-0 flex-1">
              <Link
                to={`/supply-chain/deliveries/${d.id}`}
                className="font-mono text-xs text-link hover:underline truncate block"
                aria-label={`View delivery for SO ${d.so_number}`}
              >
                {d.so_number}
              </Link>
              <span className="text-muted text-xs block truncate">{d.customer}</span>
            </div>
            <span className="font-mono tabular-nums text-xs text-muted ml-2">{d.scheduled_date ?? '—'}</span>
          </li>
        ))}
      </ul>
    </Panel>
  );
}

function LowStockAlertsPanel({ items }: { items: LowStockItem[] }) {
  if (items.length === 0) {
    return (
      <Panel title="Low Stock Alerts">
        <EmptyState icon="check-circle" title="Stock levels OK" description="No items below reorder point." />
      </Panel>
    );
  }

  return (
    <Panel title="Low Stock Alerts" meta={items.length.toString()} noPadding bodyClassName="px-1.5 pb-2">
      <table className={tableCls}>
        <thead>
          <tr className={theadTrCls}>
            <Th>Item</Th>
            <Th align="right">On Hand</Th>
            <Th align="right">Reorder</Th>
            <Th align="right">Shortage</Th>
          </tr>
        </thead>
        <tbody>
          {items.map((s) => (
            <tr key={s.item_code} className={trCls}>
              <Td>
                <Link
                  to={`/inventory/items/${s.item_id}`}
                  className="text-link hover:underline font-mono text-xs"
                  aria-label={`View item ${s.item_code} - ${s.item_name}`}
                >
                  {s.item_code}
                </Link>
                <span className="text-muted ml-1 text-xs">{s.item_name}</span>
              </Td>
              <Td align="right" mono>{s.current_stock}</Td>
              <Td align="right" mono>{s.reorder_point}</Td>
              <Td align="right" mono className="text-danger">{s.shortage}</Td>
            </tr>
          ))}
        </tbody>
      </table>
    </Panel>
  );
}

function ZoneUtilizationPanel({ items }: { items: ZoneItem[] }) {
  if (items.length === 0) {
    return (
      <Panel title="Zone Utilisation">
        <EmptyState icon="inbox" title="No zones" description="No warehouse zones configured." />
      </Panel>
    );
  }

  return (
    <Panel title="Zone Utilisation">
      <ul className="space-y-2">
        {items.map((z) => (
          <li key={z.zone}>
            <div className="flex items-center justify-between text-sm mb-1">
              <span>{z.name}</span>
              <span className="font-mono tabular-nums">{z.percent}%</span>
            </div>
            <div
              role="progressbar"
              aria-valuenow={z.percent}
              aria-valuemin={0}
              aria-valuemax={100}
              aria-label={`${z.name}: ${z.percent}% occupied`}
              className="h-2 bg-subtle rounded-full overflow-hidden"
            >
              <div
                className={zonePctClass(z.percent)}
                style={{ width: `${z.percent}%` }}
              />
            </div>
          </li>
        ))}
      </ul>
    </Panel>
  );
}

function zonePctClass(pct: number): string {
  if (pct >= 90) return 'h-full bg-danger rounded-full';
  if (pct >= 75) return 'h-full bg-warning rounded-full';
  return 'h-full bg-success rounded-full';
}

/* ───────────────────────── Page component ───────────────────────── */

export default function WarehouseDashboard() {
  const { can } = usePermission();
  const q = useQuery({
    queryKey: ['dashboard', 'warehouse'],
    queryFn: () => dashboardsApi.warehouse<WarehouseDashboardData>(),
    refetchInterval: 60_000,
  });

  return (
    <DashboardShell<WarehouseDashboardData>
      title="Warehouse Dashboard"
      subtitle="Live · refreshes every 60s"
      query={q}
      refreshingQueryKey={['dashboard', 'warehouse']}
    >
      {({ kpis, panels }) => {
        const zoneUtilChartData =
          panels?.zone_utilization?.map((z) => ({
            name: z.name,
            value: z.percent,
            color: z.percent >= 90 ? 'var(--danger)' : z.percent >= 75 ? 'var(--warning)' : 'var(--success)',
          })) ?? [];

        return (
          <>
            {/* ── Row 1: KPIs ── */}
            <KpiGrid count={kpis.length}>
              {kpis.map((k) => (
                <StatCard
                  key={k.label}
                  label={k.label}
                  value={k.unit === 'PHP' ? `₱ ${k.value}` : k.value}
                  helper={k.unit !== 'PHP' && k.unit !== 'count' ? k.unit : undefined}
                  linkTo={kpiLink(k.label)}
                />
              ))}
            </KpiGrid>

            {/* KPI Scorecard strip */}
            <KpiStrip codes={['inventory_turnover', 'supplier_quality']} />

            {/* ── Row 2: Incoming + Outgoing queue ── */}
            <PanelRow>
              <IncomingQueuePanel items={panels?.incoming_queue ?? []} />
              <OutgoingQueuePanel items={panels?.outgoing_queue ?? []} />
            </PanelRow>

            {/* ── Row 3: Low Stock Alerts + Zone Utilisation ── */}
            <PanelRow>
              <LowStockAlertsPanel items={panels?.low_stock_alerts ?? []} />
              <ZoneUtilizationPanel items={panels?.zone_utilization ?? []} />
            </PanelRow>

            {/* ── Row 4: Zone capacity chart ── */}
            <Panel title="Zone Capacity Distribution">
              {zoneUtilChartData.length === 0 ? (
                <EmptyState icon="inbox" title="No zones" description="No warehouse zone data available." />
              ) : (
                <DonutBreakdown
                  data={zoneUtilChartData}
                  centerLabel="Avg Util"
                  centerValue={`${Math.round(
                    zoneUtilChartData.reduce((sum, i) => sum + i.value, 0) / zoneUtilChartData.length,
                  )}%`}
                />
              )}
            </Panel>

            {/* ── Row 5: Stock-out forecast ── */}
            {can('forecasting.view') && <StockOutPanel horizonDays={30} hideWhenEmpty />}
          </>
        );
      }}
    </DashboardShell>
  );
}
