/**
 * StockOutPanel — reusable stock-out risk widget for dashboards.
 *
 * Fetches stock-out predictions via `forecastingApi.stockOut()` and renders
 * a compact table of items at risk. Designed to be dropped into any dashboard
 * page (Purchasing, Warehouse, PPC).
 *
 * Follows the same pattern as ChainBottleneckWidget.
 */
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { forecastingApi } from '@/api/forecasting';
import type { StockOutRisk } from '@/types/forecasting';
import { Panel } from '@/components/ui/Panel';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';

interface Props {
  /** Max rows to show. Default 8. */
  maxRows?: number;
  /** Optional title override. */
  title?: string;
  /** Horizon days to query. Default 30. */
  horizonDays?: number;
  /** Hide entirely when no items are at risk. */
  hideWhenEmpty?: boolean;
}

const RISK_VARIANT: Record<StockOutRisk, ChipVariant> = {
  critical: 'danger',
  high:     'warning',
  medium:   'info',
  low:      'neutral',
  ok:       'success',
};

const RISK_LABEL: Record<StockOutRisk, string> = {
  critical: 'Critical',
  high:     'High',
  medium:   'Medium',
  low:      'Low',
  ok:       'OK',
};

export function StockOutPanel({
  maxRows = 8,
  title = 'Stock-out Risk',
  horizonDays = 30,
  hideWhenEmpty = false,
}: Props) {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['forecasting', 'stock-out', horizonDays],
    queryFn: () => forecastingApi.stockOut({ horizon_days: horizonDays }),
    refetchInterval: 120_000,
    staleTime: 60_000,
  });

  if (isLoading) {
    return (
      <Panel title={title} meta="Refreshes every 2 min">
        <div className="space-y-2">
          {[1, 2, 3].map((i) => <SkeletonBlock key={i} className="h-10 w-full rounded-md" />)}
        </div>
      </Panel>
    );
  }

  if (isError) {
    return (
      <Panel title={title}>
        <EmptyState
          icon="alert-circle"
          title="Failed to load stock-out data"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      </Panel>
    );
  }

  const atRisk = (data?.data ?? [])
    .filter((r) => r.risk !== 'ok')
    .slice(0, maxRows);

  if (atRisk.length === 0) {
    if (hideWhenEmpty) return null;
    return (
      <Panel title={title} meta="Refreshes every 2 min">
        <EmptyState
          icon="check-circle"
          title="Stock levels OK"
          description={`No items projected to run out within ${horizonDays} days.`}
        />
      </Panel>
    );
  }

  const totalAtRisk = (data?.data ?? []).filter((r) => r.risk !== 'ok').length;

  return (
    <Panel
      title={title}
      meta={`${totalAtRisk} at risk · ${horizonDays}d horizon`}
      bodyClassName="p-0"
    >
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-subtle">
              <th className="text-left text-2xs uppercase tracking-wider text-muted font-medium px-3 py-1.5">Item</th>
              <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-3 py-1.5">On Hand</th>
              <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-3 py-1.5">Safety</th>
              <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-3 py-1.5">Daily</th>
              <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-3 py-1.5">Days Left</th>
              <th className="text-right text-2xs uppercase tracking-wider text-muted font-medium px-3 py-1.5">Risk</th>
            </tr>
          </thead>
          <tbody>
            {atRisk.map((r) => (
              <tr key={r.item_id} className="border-b border-subtle last:border-b-0 hover:bg-subtle/30 transition-colors">
                <td className="px-3 py-2">
                  <Link
                    to={`/inventory/items/${r.code}`}
                    className="text-link hover:underline font-mono text-xs"
                    aria-label={`View item ${r.code} - ${r.name}`}
                  >
                    {r.code}
                  </Link>
                  <span className="text-muted ml-1 text-xs">{r.name}</span>
                </td>
                <td className="px-3 py-2 text-right font-mono tabular-nums text-xs">{r.available}</td>
                <td className="px-3 py-2 text-right font-mono tabular-nums text-xs">{r.safety_stock}</td>
                <td className="px-3 py-2 text-right font-mono tabular-nums text-xs">{r.daily_demand}</td>
                <td className="px-3 py-2 text-right font-mono tabular-nums text-xs">
                  {r.days_until_stockout != null ? (
                    <span className={r.days_until_stockout <= 3 ? 'text-danger' : r.days_until_stockout <= 7 ? 'text-warning' : ''}>
                      {r.days_until_stockout}
                    </span>
                  ) : (
                    <span className="text-muted">—</span>
                  )}
                </td>
                <td className="px-3 py-2 text-right">
                  <Chip variant={RISK_VARIANT[r.risk]}>
                    {RISK_LABEL[r.risk]}
                  </Chip>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {totalAtRisk > maxRows && (
        <div className="px-3 py-2 border-t border-subtle text-xs text-center">
          <Link to="/inventory/stock-levels" className="text-accent hover:underline">
            +{totalAtRisk - maxRows} more at risk — View all →
          </Link>
        </div>
      )}
    </Panel>
  );
}

export default StockOutPanel;
