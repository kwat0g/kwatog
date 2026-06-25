/**
 * COPQ (Cost of Poor Quality) Dashboard Widget — Task 5.
 *
 * Shows 4 StatCards (Scrap, Rework, Warranty, Inspection costs) for the
 * current month plus a stacked bar chart showing monthly COPQ trend from
 * copq_snapshots.
 *
 * Data source: GET /api/v1/dashboards/copq-widget?months=6
 */
import { useQuery } from '@tanstack/react-query';
import { client } from '@/api/client';
import { formatPeso } from '@/lib/formatNumber';
import { StatCard } from '@/components/ui/StatCard';
import { Panel } from '@/components/ui/Panel';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { BarComparison } from '@/components/charts';

/* ─── Types ─── */

interface CopqCurrent {
  internal_failure: {
    scrap_units: number;
    rework_units: number;
    scrap_cost: number;
    rework_cost: number;
  };
  external_failure: {
    returns: number;
    complaints: number;
    return_cost: number;
  };
  total: number;
  period_label: string;
}

interface CopqTrendItem {
  month: string;
  scrap_cost: number;
  rework_cost: number;
  warranty_cost: number;
  inspection_cost: number;
  total: number;
}

interface CopqWidgetData {
  current: CopqCurrent;
  trend: CopqTrendItem[];
  period: string;
}

/* ─── API ─── */

function fetchCopqWidget(months = 6) {
  return client
    .get<{ data: CopqWidgetData }>('/dashboards/copq-widget', { params: { months } })
    .then((r) => r.data.data);
}

/* ─── Component ─── */

const TREND_BARS = [
  { dataKey: 'scrap_cost', color: 'var(--color-danger)', label: 'Scrap' },
  { dataKey: 'rework_cost', color: 'var(--color-warning)', label: 'Rework' },
  { dataKey: 'warranty_cost', color: 'var(--color-info)', label: 'Returns' },
  { dataKey: 'inspection_cost', color: 'var(--color-purple, #8b5cf6)', label: 'Complaints' },
];

export function CopqWidget() {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['copq-widget'],
    queryFn: () => fetchCopqWidget(6),
    refetchInterval: 120_000,
  });

  /* ─── Loading ─── */
  if (isLoading && !data) {
    return (
      <Panel title="Cost of Poor Quality (COPQ)">
        <div className="space-y-3">
          <div className="grid grid-cols-2 md:grid-cols-4 gap-2">
            {[1, 2, 3, 4].map((i) => (
              <SkeletonBlock key={i} className="h-20 rounded-md" />
            ))}
          </div>
          <SkeletonBlock className="h-48 rounded-md" />
        </div>
      </Panel>
    );
  }

  /* ─── Error ─── */
  if (isError || !data) {
    return (
      <Panel title="Cost of Poor Quality (COPQ)">
        <EmptyState
          icon="alert-circle"
          title="Failed to load COPQ data"
          description="Could not retrieve cost of poor quality metrics."
          action={
            <button
              onClick={() => refetch()}
              className="text-sm text-link hover:underline"
            >
              Retry
            </button>
          }
        />
      </Panel>
    );
  }

  const { current, trend, period } = data;
  const scrapCost = current.internal_failure.scrap_cost;
  const reworkCost = current.internal_failure.rework_cost;
  const returnCost = current.external_failure.return_cost;
  const totalInternal = scrapCost + reworkCost;

  return (
    <Panel title={`Cost of Poor Quality (COPQ) — ${period}`}>
      <div className="space-y-4">
        {/* ── KPI Cards ── */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-2">
          <StatCard
            label="Scrap Cost"
            value={formatPeso(scrapCost)}
            helper={`${current.internal_failure.scrap_units} units scrapped`}
          />
          <StatCard
            label="Rework Cost"
            value={formatPeso(reworkCost)}
            helper={`${current.internal_failure.rework_units} units reworked`}
          />
          <StatCard
            label="Return Cost"
            value={formatPeso(returnCost)}
            helper={`${current.external_failure.returns} returns completed`}
          />
          <StatCard
            label="Total COPQ"
            value={formatPeso(current.total)}
            helper={`Internal: ${formatPeso(totalInternal)}`}
            className={current.total > 0 ? 'border-danger/30' : undefined}
          />
        </div>

        {/* ── Trend Chart ── */}
        {trend.length > 0 ? (
          <div>
            <div className="text-xs text-muted uppercase tracking-wider font-medium mb-2">
              Monthly COPQ Trend (Last 6 Months)
            </div>
            <BarComparison
              data={trend}
              bars={TREND_BARS}
              xKey="month"
              height={220}
              stacked
              formatValue={(v: number) => formatPeso(v)}
            />
          </div>
        ) : (
          <EmptyState
            icon="bar-chart-2"
            title="No historical data"
            description="COPQ trend data will appear after the monthly snapshot runs."
          />
        )}
      </div>
    </Panel>
  );
}
