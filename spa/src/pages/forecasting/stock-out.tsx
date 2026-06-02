/**
 * ADV11 — Stock-out Projection page.
 *
 * Lists every active item projected to fall below safety stock within the
 * chosen horizon, sorted by risk. Operators can jump to "Create PR" for the
 * worst items.
 */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Select } from '@/components/ui/Select';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonTable } from '@/components/ui/Skeleton';
import { Badge } from '@/components/ui/Badge';
import { forecastingApi } from '@/api/forecasting';
import type { StockOutRisk } from '@/types/forecasting';

const RISK_VARIANT: Record<StockOutRisk, 'danger' | 'warning' | 'accent' | 'neutral'> = {
  critical: 'danger',
  high:     'warning',
  medium:   'warning',
  low:      'accent',
  ok:       'neutral',
};

const RISK_LABEL: Record<StockOutRisk, string> = {
  critical: 'Critical',
  high:     'High',
  medium:   'Medium',
  low:      'Low',
  ok:       'OK',
};

const SOURCE_LABEL: Record<string, string> = {
  forecast:   'Forecast',
  historical: 'Last 30d avg',
  none:       'No demand',
};

export default function StockOutProjectionPage() {
  const [horizon, setHorizon] = useState<number>(60);

  const q = useQuery({
    queryKey: ['forecasting/stock-out', horizon],
    queryFn: () => forecastingApi.stockOut({ horizon_days: horizon }),
  });

  const rows = q.data?.data ?? [];
  const generatedAt = q.data?.meta.generated_at;

  // Counts per risk for the summary strip.
  const counts = rows.reduce<Record<StockOutRisk, number>>(
    (acc, r) => ({ ...acc, [r.risk]: (acc[r.risk] ?? 0) + 1 }),
    { critical: 0, high: 0, medium: 0, low: 0, ok: 0 },
  );

  return (
    <>
      <PageHeader
        title="Stock-Out Projection"
        subtitle="Project days-until-stockout per item, using next-month forecast or last 30 days of consumption."
        actions={
          <div className="flex items-center gap-2">
            <label className="text-2xs uppercase tracking-wide text-muted">Horizon</label>
            <Select
              value={horizon}
              onChange={(e) => setHorizon(parseInt(e.target.value) || 60)}
              className="w-32"
            >
              <option value={30}>30 days</option>
              <option value={60}>60 days</option>
              <option value={90}>90 days</option>
              <option value={180}>180 days</option>
            </Select>
          </div>
        }
      />

      <div className="p-5 space-y-4">
        {/* Risk summary strip */}
        <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
          {(['critical', 'high', 'medium', 'low', 'ok'] as StockOutRisk[]).map((risk) => (
            <Panel key={risk} noPadding>
              <div className="p-3">
                <div className="flex items-center justify-between">
                  <span className="text-2xs uppercase tracking-wide text-muted">{RISK_LABEL[risk]}</span>
                  <Badge variant={RISK_VARIANT[risk]}>{counts[risk]}</Badge>
                </div>
                <div className="text-xl font-medium text-primary mt-1 tabular-nums">
                  {counts[risk]}
                </div>
              </div>
            </Panel>
          ))}
        </div>

        <Panel
          title="Items at risk"
          meta={generatedAt ? <span className="text-2xs text-muted">Generated {new Date(generatedAt).toLocaleString()}</span> : null}
          noPadding
        >
          {q.isLoading ? (
            <div className="p-4"><SkeletonTable columns={9} rows={8} /></div>
          ) : rows.length === 0 ? (
            <EmptyState
              icon="package"
              title="All items healthy"
              description="No items are projected to stock out within the selected horizon."
            />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-2xs uppercase tracking-wide text-muted bg-elevated/50 border-b border-default">
                    <th className="px-4 py-2">Item</th>
                    <th className="px-4 py-2 text-right">On hand</th>
                    <th className="px-4 py-2 text-right">Safety</th>
                    <th className="px-4 py-2 text-right">Daily demand</th>
                    <th className="px-4 py-2">Source</th>
                    <th className="px-4 py-2 text-right">Days until stock-out</th>
                    <th className="px-4 py-2">Order by</th>
                    <th className="px-4 py-2 text-right">Suggested qty</th>
                    <th className="px-4 py-2">Risk</th>
                    <th className="px-4 py-2 text-right" />
                  </tr>
                </thead>
                <tbody>
                  {rows.map((r) => (
                    <tr key={r.item_id} className="border-b border-default/50 hover:bg-elevated/30">
                      <td className="px-4 py-2">
                        <div className="font-medium text-primary">{r.code}</div>
                        <div className="text-2xs text-muted truncate max-w-[260px]">{r.name}</div>
                      </td>
                      <td className="px-4 py-2 text-right tabular-nums">
                        {r.available.toFixed(2)} <span className="text-2xs text-muted">{r.unit_of_measure}</span>
                      </td>
                      <td className="px-4 py-2 text-right tabular-nums text-muted">{r.safety_stock.toFixed(2)}</td>
                      <td className="px-4 py-2 text-right tabular-nums">{r.daily_demand.toFixed(2)}</td>
                      <td className="px-4 py-2 text-2xs text-muted">{SOURCE_LABEL[r.demand_source] ?? r.demand_source}</td>
                      <td className="px-4 py-2 text-right tabular-nums">
                        {r.days_until_stockout === null ? '—' : (
                          <span className={r.days_until_stockout <= r.lead_time_days ? 'text-danger font-medium' : ''}>
                            {r.days_until_stockout}d
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-2 text-2xs">
                        {r.reorder_date ? new Date(r.reorder_date).toLocaleDateString() : '—'}
                      </td>
                      <td className="px-4 py-2 text-right tabular-nums">
                        {r.suggested_qty !== null ? r.suggested_qty.toFixed(2) : '—'}
                      </td>
                      <td className="px-4 py-2">
                        <Badge variant={RISK_VARIANT[r.risk]}>{RISK_LABEL[r.risk]}</Badge>
                      </td>
                      <td className="px-4 py-2 text-right">
                        <Link to="/purchasing/purchase-requests/create">
                          <Button size="sm" variant="ghost">Create PR</Button>
                        </Link>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>
      </div>
    </>
  );
}
