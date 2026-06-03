/**
 * DemandForecastPanel — reusable demand forecast widget for dashboards.
 *
 * Fetches demand forecasts via `forecastingApi.list()` and renders a compact
 * view of upcoming forecasted demand by product. Useful for PPC, Purchasing,
 * and Plant Manager dashboards.
 *
 * Follows the same pattern as StockOutPanel and ChainBottleneckWidget.
 */
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { forecastingApi } from '@/api/forecasting';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';

interface Props {
  /** Max rows to show. Default 8. */
  maxRows?: number;
  /** Optional title override. */
  title?: string;
  /** Focus on a specific product. */
  productId?: string;
  /** Hide when no forecasts exist. */
  hideWhenEmpty?: boolean;
  /** Year to query. Defaults to current year. */
  year?: number;
}

export function DemandForecastPanel({
  maxRows = 8,
  title = 'Demand Forecast',
  productId,
  hideWhenEmpty = false,
  year = new Date().getFullYear(),
}: Props) {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['forecasting', 'demand', year, productId],
    queryFn: () => forecastingApi.list({ product_id: productId, year }),
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
          title="Failed to load forecasts"
          action={<Button variant="secondary" onClick={() => refetch()}>Retry</Button>}
        />
      </Panel>
    );
  }

  const forecasts = data ?? [];

  if (forecasts.length === 0) {
    if (hideWhenEmpty) return null;
    return (
      <Panel title={title} meta="Refreshes every 2 min">
        <EmptyState
          icon="inbox"
          title="No forecasts yet"
          description={`No demand forecasts for ${year}. Run a forecast to see projections here.`}
          action={
            <Link to="/forecasting/demand" className="text-sm text-accent hover:underline">
              Open forecasting →
            </Link>
          }
        />
      </Panel>
    );
  }

  // Group by product for a compact view
  const byProduct = new Map<string, { product: NonNullable<typeof forecasts[0]['product']>; forecasts: typeof forecasts }>();
  for (const f of forecasts) {
    if (!f.product) continue;
    const key = f.product.id;
    if (!byProduct.has(key)) {
      byProduct.set(key, { product: f.product, forecasts: [] });
    }
    byProduct.get(key)!.forecasts.push(f);
  }

  const entries = Array.from(byProduct.values()).slice(0, maxRows);

  return (
    <Panel
      title={title}
      meta={`${forecasts.length} entries · ${year}`}
    >
      <div className="space-y-3">
        {entries.map(({ product, forecasts: prods }) => {
          const totalQty = prods.reduce((s, f) => s + f.forecasted_quantity, 0);
          const withActual = prods.filter((f) => f.actual_quantity != null);
          const avgVariance = withActual.length > 0
            ? withActual.reduce((s, f) => s + ((f.actual_quantity! - f.forecasted_quantity) / f.forecasted_quantity), 0) / withActual.length * 100
            : null;

          return (
            <div key={product.id} className="border border-default rounded-md p-3">
              <div className="flex items-center justify-between mb-2">
                <Link
                  to={`/inventory/items/${product.part_number}`}
                  className="text-sm font-medium text-accent hover:underline truncate"
                  aria-label={`View item ${product.part_number} ${product.name}`}
                >
                  {product.part_number}
                </Link>
                <div className="flex items-center gap-2 text-xs text-muted">
                  <span className="font-mono tabular-nums">{prods.length} months</span>
                  <span className="font-mono tabular-nums">{totalQty} total</span>
                </div>
              </div>

              {/* Mini bar chart of monthly forecasts */}
              <div className="flex items-end gap-0.5 h-8">
                {prods.slice(0, 12).map((f) => {
                  const maxQty = Math.max(1, ...prods.map((x) => x.forecasted_quantity));
                  const heightPct = (f.forecasted_quantity / maxQty) * 100;
                  const hasActual = f.actual_quantity != null;
                  const isOver = hasActual && f.variance != null && f.variance > 0;
                  return (
                    <div
                      key={`${f.forecast_year}-${f.forecast_month}`}
                      className="flex-1 relative group"
                      title={`${new Date(f.forecast_year, f.forecast_month - 1).toLocaleDateString('en-US', { month: 'short', year: 'numeric' })}: Forecast ${f.forecasted_quantity}${hasActual ? ` · Actual ${f.actual_quantity} (${f.variance != null ? (f.variance > 0 ? '+' : '') + f.variance.toFixed(0) : '?'})` : ''}`}
                    >
                      <div
                        className={`w-full rounded-sm transition-all duration-200 ${
                          hasActual
                            ? isOver
                              ? 'bg-warning/70'
                              : 'bg-success/70'
                            : 'bg-accent/40'
                        }`}
                        style={{ height: `${Math.max(3, heightPct)}%` }}
                      />
                    </div>
                  );
                })}
              </div>

              <div className="flex items-center justify-between mt-1.5 text-2xs text-muted">
                <span>{product.name}</span>
                {avgVariance != null && (
                  <Chip variant={Math.abs(avgVariance) > 20 ? 'warning' : 'success'}>
                    {avgVariance > 0 ? '+' : ''}{avgVariance.toFixed(1)}% avg variance
                  </Chip>
                )}
              </div>
            </div>
          );
        })}
      </div>

      {forecasts.length > byProduct.size && (
        <div className="mt-3 text-xs text-center">
          <Link to="/forecasting/demand" className="text-accent hover:underline">
            View all {forecasts.length} forecasts →
          </Link>
        </div>
      )}
    </Panel>
  );
}

export default DemandForecastPanel;
