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
import { PageHeader } from '@/components/layout/PageHeader';
import { StatCard } from '@/components/ui/StatCard';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock, SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { StockOutPanel } from '@/components/dashboard/StockOutPanel';
import { DonutBreakdown } from '@/components/charts';
import { usePermission } from '@/hooks/usePermission';

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
    <Panel title="Low Stock Alerts" meta={items.length.toString()}>
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-subtle">
            <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">Item</th>
            <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium py-1">On Hand</th>
            <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium py-1">Reorder</th>
            <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium py-1">Shortage</th>
          </tr>
        </thead>
        <tbody>
          {items.map((s) => (
            <tr key={s.item_code} className="border-b border-subtle h-7">
              <td className="py-1">
                <Link
                  to={`/inventory/items/${s.item_code}`}
                  className="text-link hover:underline font-mono text-xs"
                  aria-label={`View item ${s.item_code} - ${s.item_name}`}
                >
                  {s.item_code}
                </Link>
                <span className="text-muted ml-1 text-xs">{s.item_name}</span>
              </td>
              <td className="py-1 text-right font-mono tabular-nums text-xs">{s.current_stock}</td>
              <td className="py-1 text-right font-mono tabular-nums text-xs">{s.reorder_point}</td>
              <td className="py-1 text-right font-mono tabular-nums text-xs text-danger">{s.shortage}</td>
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
    queryFn: () => dashboardsApi.warehouse(),
    refetchInterval: 60_000,
  });

  // Compute chart data
  const zoneUtilChartData = (q.data as unknown as WarehouseDashboardData)?.panels?.zone_utilization?.map(z => ({
    name: z.name,
    value: z.percent,
    color: z.percent >= 90 ? 'var(--color-danger)' : z.percent >= 75 ? 'var(--color-warning)' : 'var(--color-success)',
  })) ?? [];

  /* ─── LOADING ─── */
  if (q.isLoading && !q.data) {
    return (
      <div>
        <PageHeader title="Warehouse Dashboard" subtitle="Inventory & logistics" />
        <div className="px-5 py-6 space-y-4">
          <div className="grid grid-cols-4 gap-2">
            {[1, 2, 3, 4].map((i) => <SkeletonBlock key={i} className="h-16 rounded-md" />)}
          </div>
          <SkeletonDetail />
        </div>
      </div>
    );
  }

  /* ─── ERROR ─── */
  if (q.isError || !q.data) {
    return (
      <div>
        <PageHeader title="Warehouse Dashboard" subtitle="Inventory & logistics" />
        <div className="px-5 py-6">
          <EmptyState
            icon="alert-circle"
            title="Failed to load dashboard"
            action={<Button variant="secondary" onClick={() => q.refetch()}>Retry</Button>}
          />
        </div>
      </div>
    );
  }

  const { kpis, panels } = q.data as unknown as WarehouseDashboardData;

  return (
    <div>
      <PageHeader title="Warehouse Dashboard" subtitle="Live · refreshes every 60s" />

      <div className="px-5 py-4 space-y-4">
        {/* ── Row 1: KPIs ── */}
        <section className="grid grid-cols-4 gap-2">
          {kpis.map((k) => (
            <StatCard
              key={k.label}
              label={k.label}
              value={k.unit === 'PHP' ? `₱ ${k.value}` : k.value}
              helper={k.unit !== 'PHP' && k.unit !== 'count' ? k.unit : undefined}
              linkTo={kpiLink(k.label)}
            />
          ))}
        </section>

        {/* ── Row 2: Incoming + Outgoing queue ── */}
        <div className="grid grid-cols-2 gap-4">
          <IncomingQueuePanel items={panels?.incoming_queue ?? []} />
          <OutgoingQueuePanel items={panels?.outgoing_queue ?? []} />
        </div>

        {/* ── Row 3: Low Stock Alerts + Zone Utilisation ── */}
        <div className="grid grid-cols-2 gap-4">
          <LowStockAlertsPanel items={panels?.low_stock_alerts ?? []} />
          <ZoneUtilizationPanel items={panels?.zone_utilization ?? []} />
        </div>

        {/* ── Row 3.5: Chart visualizations ── */}
        <Panel title="Zone Capacity Distribution">
          {zoneUtilChartData.length === 0 ? (
            <EmptyState icon="inbox" title="No zones" description="No warehouse zone data available." />
          ) : (
            <DonutBreakdown
              data={zoneUtilChartData}
              centerLabel="Avg Util"
              centerValue={`${Math.round(zoneUtilChartData.reduce((sum, i) => sum + i.value, 0) / zoneUtilChartData.length)}%`}
            />
          )}
        </Panel>

        {/* ── Row 4: Stock-out forecast ── */}
        {can('forecasting.view') && <StockOutPanel horizonDays={30} hideWhenEmpty />}
      </div>
    </div>
  );
}
