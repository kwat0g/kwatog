/**
 * Purchasing Officer Dashboard — Task D6.
 *
 * Data source: GET /api/v1/dashboards/purchasing (via dashboardsApi.purchasing)
 * Backend:     RoleDashboardService::purchasing()
 * Cache:       30s Redis per user
 */
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { dashboardsApi } from '@/api/dashboards';
import { kpiLink } from '@/lib/dashboardLinks';
import { PageHeader } from '@/components/layout/PageHeader';
import { StatCard } from '@/components/ui/StatCard';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { SkeletonBlock, SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';

/* ───────────────────────── Typed interface ───────────────────────── */

interface PrActionItem {
  id: string;
  pr_number: string;
  department: string;
  items_count: number;
  estimated_total: string;
  urgency: string;
  days_waiting: number;
}

interface PoPipelineItem {
  status: string;
  count: number;
}

interface SupplierScoreItem {
  name: string;
  overall_score: string;
}

interface UpcomingDelivery {
  id: string;
  po_number: string;
  vendor: string;
  items_count: number;
  expected_date: string | null;
  status: string;
}

interface PurchasingDashboardData {
  kpis: Array<{ label: string; value: string; unit: string }>;
  panels: {
    pr_action_queue: PrActionItem[];
    po_pipeline: PoPipelineItem[];
    supplier_performance: SupplierScoreItem[];
    upcoming_deliveries: UpcomingDelivery[];
  };
}

/* ───────────────────────── Sub-panel components ───────────────────────── */

function PrActionQueuePanel({ items }: { items: PrActionItem[] }) {
  if (items.length === 0) {
    return (
      <Panel title="PR Action Queue">
        <EmptyState icon="check-circle" title="All caught up" description="No pending purchase requests requiring action." />
      </Panel>
    );
  }

  return (
    <Panel title="PR Action Queue" meta={items.length.toString()}>
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-subtle">
            <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">PR #</th>
            <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">Dept</th>
            <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium py-1">Items</th>
            <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium py-1">Est. Total</th>
            <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">Urgency</th>
            <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium py-1">Waiting</th>
          </tr>
        </thead>
        <tbody>
          {items.map((pr) => (
            <tr key={pr.id} className="border-b border-subtle h-7 hover:bg-subtle/30 transition-colors">
              <td className="py-1">
                <Link
                  to={`/purchasing/purchase-requests/${pr.id}`}
                  className="text-link hover:underline font-mono text-xs"
                  aria-label={`View purchase request ${pr.pr_number}`}
                >
                  {pr.pr_number}
                </Link>
              </td>
              <td className="py-1 text-muted text-xs">{pr.department}</td>
              <td className="py-1 text-right font-mono tabular-nums">{pr.items_count}</td>
              <td className="py-1 text-right font-mono tabular-nums">₱{pr.estimated_total}</td>
              <td className="py-1">
                <Chip variant={pr.urgency === 'urgent' ? 'danger' : pr.urgency === 'high' ? 'warning' : 'neutral'}>
                  {pr.urgency}
                </Chip>
              </td>
              <td className="py-1 text-right font-mono tabular-nums text-muted">{pr.days_waiting}d</td>
            </tr>
          ))}
        </tbody>
      </table>
    </Panel>
  );
}

function PoPipelinePanel({ items }: { items: PoPipelineItem[] }) {
  if (items.length === 0) {
    return (
      <Panel title="PO Pipeline">
        <EmptyState icon="inbox" title="No purchase orders" description="No POs in the pipeline." />
      </Panel>
    );
  }

  const maxCount = Math.max(1, ...items.map((i) => i.count));

  return (
    <Panel title="PO Pipeline">
      <ul className="space-y-2">
        {items.map((i) => {
          const pct = Math.round((i.count / maxCount) * 100);
          return (
            <li key={i.status}>
              <div className="flex items-center justify-between text-sm mb-1">
                <span className="capitalize">{i.status.replace(/_/g, ' ')}</span>
                <span className="font-mono tabular-nums">{i.count}</span>
              </div>
              <div
                role="progressbar"
                aria-valuenow={pct}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label={`${i.status}: ${i.count} POs`}
                className="h-2 bg-subtle rounded-full overflow-hidden"
              >
                <div
                  className={poBarClass(i.status)}
                  style={{ width: `${pct}%` }}
                />
              </div>
            </li>
          );
        })}
      </ul>
    </Panel>
  );
}

function poBarClass(status: string): string {
  if (status === 'received' || status === 'closed') return 'h-full bg-success rounded-full';
  if (status === 'sent') return 'h-full bg-info rounded-full';
  if (status === 'approved') return 'h-full bg-warning rounded-full';
  return 'h-full bg-muted rounded-full';
}

function SupplierPerformancePanel({ items }: { items: SupplierScoreItem[] }) {
  if (items.length === 0) {
    return (
      <Panel title="Supplier Performance">
        <EmptyState icon="inbox" title="No data" description="No supplier performance scores available." />
      </Panel>
    );
  }

  return (
    <Panel title="Top Suppliers">
      <ul className="divide-y divide-subtle">
        {items.map((s) => (
          <li key={s.name} className="flex items-center justify-between py-2 text-sm">
            <span className="truncate">{s.name}</span>
            <Chip variant={parseFloat(s.overall_score) >= 95 ? 'success' : parseFloat(s.overall_score) >= 85 ? 'info' : parseFloat(s.overall_score) >= 80 ? 'warning' : 'danger'}>
              <span className="font-mono tabular-nums">{s.overall_score}</span>
            </Chip>
          </li>
        ))}
      </ul>
    </Panel>
  );
}

function UpcomingDeliveriesPanel({ items }: { items: UpcomingDelivery[] }) {
  if (items.length === 0) {
    return (
      <Panel title="Upcoming Deliveries (7 days)">
        <EmptyState icon="truck" title="None scheduled" description="No deliveries expected in the next 7 days." />
      </Panel>
    );
  }

  return (
    <Panel title="Upcoming Deliveries (7 days)" meta={items.length.toString()}>
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-subtle">
            <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">PO #</th>
            <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">Vendor</th>
            <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium py-1">Expected</th>
            <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium py-1">Status</th>
          </tr>
        </thead>
        <tbody>
          {items.map((d) => (
            <tr key={d.id} className="border-b border-subtle h-7 hover:bg-subtle/30 transition-colors">
              <td className="py-1">
                <Link
                  to={`/purchasing/purchase-orders/${d.id}`}
                  className="text-link hover:underline font-mono text-xs"
                  aria-label={`View purchase order ${d.po_number}`}
                >
                  {d.po_number}
                </Link>
              </td>
              <td className="py-1 text-muted text-xs truncate">{d.vendor}</td>
              <td className="py-1 text-right font-mono tabular-nums text-xs">{d.expected_date ?? '—'}</td>
              <td className="py-1">
                <Chip variant={d.status === 'sent' ? 'info' : d.status === 'approved' ? 'warning' : 'neutral'}>
                  {d.status}
                </Chip>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </Panel>
  );
}

/* ───────────────────────── Page component ───────────────────────── */

export default function PurchasingDashboard() {
  const q = useQuery({
    queryKey: ['dashboard', 'purchasing'],
    queryFn: () => dashboardsApi.purchasing(),
    refetchInterval: 60_000,
  });

  /* ─── LOADING ─── */
  if (q.isLoading && !q.data) {
    return (
      <div>
        <PageHeader title="Purchasing Dashboard" subtitle="Procurement overview" />
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
        <PageHeader title="Purchasing Dashboard" subtitle="Procurement overview" />
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

  const { kpis, panels } = q.data as unknown as PurchasingDashboardData;

  return (
    <div>
      <PageHeader title="Purchasing Dashboard" subtitle="Live · refreshes every 60s" />

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

        {/* ── Row 2: PR Action Queue + PO Pipeline ── */}
        <div className="grid grid-cols-2 gap-4">
          <PrActionQueuePanel items={panels?.pr_action_queue ?? []} />
          <PoPipelinePanel items={panels?.po_pipeline ?? []} />
        </div>

        {/* ── Row 3: Supplier Performance + Upcoming Deliveries ── */}
        <div className="grid grid-cols-2 gap-4">
          <SupplierPerformancePanel items={panels?.supplier_performance ?? []} />
          <UpcomingDeliveriesPanel items={panels?.upcoming_deliveries ?? []} />
        </div>
      </div>
    </div>
  );
}
