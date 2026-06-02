/**
 * ADV11 — Demand & Sales Forecasting page.
 *
 *   - Pick a product (and optionally a customer)
 *   - Pick a method (moving_avg / weighted_avg)
 *   - Recompute → server writes 3 months of forecast rows
 *   - Chart shows last 12 months of actual demand + 3 months of forecast
 *   - Table lists all forecast rows with manual-override button
 */
import { useEffect, useMemo, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { PageHeader } from '@/components/layout/PageHeader';
import { Panel } from '@/components/ui/Panel';
import { Button } from '@/components/ui/Button';
import { Select } from '@/components/ui/Select';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { EmptyState } from '@/components/ui/EmptyState';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { Badge } from '@/components/ui/Badge';
import { productsApi } from '@/api/crm/products';
import { customersApi } from '@/api/accounting/customers';
import { forecastingApi } from '@/api/forecasting';
import type { ForecastMethod, DemandForecast } from '@/types/forecasting';

const METHOD_LABELS: Record<ForecastMethod, string> = {
  moving_avg: 'Simple moving average',
  weighted_avg: 'Weighted (recency-biased)',
  manual: 'Manual',
};

const MONTH_NAMES = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

export default function DemandForecastingPage() {
  const qc = useQueryClient();
  const [productId, setProductId] = useState<string>('');
  const [customerId, setCustomerId] = useState<string>('');
  const [method, setMethod] = useState<ForecastMethod>('weighted_avg');
  const [horizon, setHorizon] = useState<number>(3);
  const [lookback, setLookback] = useState<number>(6);
  const [manualOpen, setManualOpen] = useState(false);
  const [manualRow, setManualRow] = useState<DemandForecast | null>(null);
  const [manualQty, setManualQty] = useState<string>('');
  const [manualConf, setManualConf] = useState<string>('');

  const productsQ = useQuery({
    queryKey: ['products', { is_active: true, per_page: 200 }],
    queryFn: () => productsApi.list({ is_active: true, per_page: 200 }),
  });
  const customersQ = useQuery({
    queryKey: ['customers', { per_page: 200 }],
    queryFn: () => customersApi.list({ per_page: 200 }),
  });

  // Default to first active product once loaded.
  useEffect(() => {
    if (!productId && productsQ.data?.data?.[0]) {
      setProductId(productsQ.data.data[0].id);
    }
  }, [productsQ.data, productId]);

  const historicalQ = useQuery({
    queryKey: ['forecasting/historical', productId, customerId],
    queryFn: () => forecastingApi.historical({
      product_id: productId,
      customer_id: customerId || undefined,
      months_back: 12,
    }),
    enabled: !!productId,
  });

  const forecastsQ = useQuery({
    queryKey: ['forecasting/list', productId, customerId],
    queryFn: () => forecastingApi.list({
      product_id: productId,
      customer_id: customerId || undefined,
    }),
    enabled: !!productId,
  });

  const recomputeM = useMutation({
    mutationFn: () => forecastingApi.recompute({
      product_id: productId,
      customer_id: customerId || undefined,
      method: method === 'manual' ? 'weighted_avg' : method,
      horizon_months: horizon,
      lookback_months: lookback,
    }),
    onSuccess: (res) => {
      toast.success(res.message ?? 'Forecasts recomputed.');
      qc.invalidateQueries({ queryKey: ['forecasting/list'] });
    },
  });

  const manualM = useMutation({
    mutationFn: () => {
      if (!manualRow) throw new Error('No row selected');
      const qty = parseFloat(manualQty);
      const conf = manualConf.trim() === '' ? undefined : parseFloat(manualConf);
      return forecastingApi.storeManual({
        product_id: productId,
        customer_id: customerId || undefined,
        forecast_year: manualRow.forecast_year,
        forecast_month: manualRow.forecast_month,
        forecasted_quantity: qty,
        confidence_level: conf,
      });
    },
    onSuccess: (res) => {
      toast.success(res.message ?? 'Manual forecast saved.');
      setManualOpen(false);
      setManualRow(null);
      qc.invalidateQueries({ queryKey: ['forecasting/list'] });
    },
  });

  // Combine historical + forecast for the bar chart, sorted chronologically.
  const chartData = useMemo(() => {
    const hist = (historicalQ.data ?? []).map((p) => ({
      key: `${p.year}-${p.month}`,
      label: `${MONTH_NAMES[p.month - 1]} ${String(p.year).slice(2)}`,
      actual: p.qty,
      forecast: 0,
    }));
    const byKey = new Map(hist.map((r) => [r.key, r]));
    (forecastsQ.data ?? []).forEach((f) => {
      const key = `${f.forecast_year}-${f.forecast_month}`;
      const label = `${MONTH_NAMES[f.forecast_month - 1]} ${String(f.forecast_year).slice(2)}`;
      const existing = byKey.get(key);
      if (existing) {
        existing.forecast = f.forecasted_quantity;
      } else {
        byKey.set(key, { key, label, actual: 0, forecast: f.forecasted_quantity });
      }
    });
    return Array.from(byKey.values()).sort((a, b) => a.key.localeCompare(b.key));
  }, [historicalQ.data, forecastsQ.data]);

  const maxBar = useMemo(() => {
    const vals = chartData.flatMap((r) => [r.actual, r.forecast]);
    return Math.max(1, ...vals);
  }, [chartData]);

  const openManual = (row: DemandForecast) => {
    setManualRow(row);
    setManualQty(String(row.forecasted_quantity ?? ''));
    setManualConf(row.confidence_level !== null ? String(row.confidence_level) : '');
    setManualOpen(true);
  };

  return (
    <>
      <PageHeader
        title="Demand & Sales Forecasting"
        subtitle="Project future demand using sales history. Recompute monthly or override per period."
      />

      <div className="p-5 space-y-4">
        {/* Filters + recompute */}
        <Panel title="Forecast scope" noPadding>
          <div className="p-4 grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
            <div className="md:col-span-2">
              <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">Product</label>
              <Select value={productId} onChange={(e) => setProductId(e.target.value)}>
                <option value="">— Select a product —</option>
                {(productsQ.data?.data ?? []).map((p) => (
                  <option key={p.id} value={p.id}>{p.part_number} — {p.name}</option>
                ))}
              </Select>
            </div>
            <div className="md:col-span-2">
              <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">Customer (optional)</label>
              <Select value={customerId} onChange={(e) => setCustomerId(e.target.value)}>
                <option value="">All customers (total)</option>
                {(customersQ.data?.data ?? []).map((c) => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </Select>
            </div>
            <div>
              <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">Method</label>
              <Select value={method} onChange={(e) => setMethod(e.target.value as ForecastMethod)}>
                <option value="moving_avg">{METHOD_LABELS.moving_avg}</option>
                <option value="weighted_avg">{METHOD_LABELS.weighted_avg}</option>
              </Select>
            </div>
            <div className="grid grid-cols-2 gap-2">
              <div>
                <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">Horizon (mo)</label>
                <Input
                  type="number" min={1} max={12} value={horizon}
                  onChange={(e) => setHorizon(Math.max(1, Math.min(12, parseInt(e.target.value) || 3)))}
                />
              </div>
              <div>
                <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">Lookback (mo)</label>
                <Input
                  type="number" min={3} max={24} value={lookback}
                  onChange={(e) => setLookback(Math.max(3, Math.min(24, parseInt(e.target.value) || 6)))}
                />
              </div>
            </div>
            <div className="md:col-span-6 flex justify-end">
              <Button
                onClick={() => recomputeM.mutate()}
                disabled={!productId || recomputeM.isPending}
              >
                {recomputeM.isPending ? 'Recomputing…' : 'Recompute forecast'}
              </Button>
            </div>
          </div>
        </Panel>

        {/* Chart */}
        <Panel title="Demand history vs. forecast" noPadding>
          <div className="p-4">
            {!productId ? (
              <EmptyState title="Select a product" description="Pick a product from the dropdown to view its demand history." />
            ) : historicalQ.isLoading || forecastsQ.isLoading ? (
              <SkeletonBlock className="h-48" />
            ) : chartData.length === 0 ? (
              <EmptyState title="No demand history" description="No confirmed sales orders match the selected scope." />
            ) : (
              <div className="space-y-3">
                <div className="flex items-center gap-4 text-2xs text-muted">
                  <span className="flex items-center gap-1.5">
                    <span className="inline-block w-3 h-3 rounded-sm bg-accent/70" />
                    Actual demand
                  </span>
                  <span className="flex items-center gap-1.5">
                    <span className="inline-block w-3 h-3 rounded-sm bg-warning/70 border border-warning" />
                    Forecast
                  </span>
                </div>
                <div className="flex items-end gap-1 h-48">
                  {chartData.map((d) => (
                    <div key={d.key} className="flex-1 flex flex-col items-center gap-1 group">
                      <div className="relative w-full h-44 flex flex-col-reverse gap-0.5">
                        {d.actual > 0 && (
                          <div
                            title={`Actual: ${d.actual.toFixed(2)}`}
                            className="w-full bg-accent/70 rounded-t-sm transition-all group-hover:bg-accent"
                            style={{ height: `${Math.max(2, (d.actual / maxBar) * 100)}%` }}
                          />
                        )}
                        {d.forecast > 0 && (
                          <div
                            title={`Forecast: ${d.forecast.toFixed(2)}`}
                            className="w-full bg-warning/70 border border-warning rounded-t-sm transition-all group-hover:bg-warning"
                            style={{ height: `${Math.max(2, (d.forecast / maxBar) * 100)}%` }}
                          />
                        )}
                      </div>
                      <span className="text-[9px] text-muted -rotate-45 origin-top-left whitespace-nowrap pt-1">
                        {d.label}
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        </Panel>

        {/* Forecast rows */}
        <Panel title="Forecast rows" noPadding>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-2xs uppercase tracking-wide text-muted bg-elevated/50 border-b border-default">
                  <th className="px-4 py-2">Period</th>
                  <th className="px-4 py-2">Method</th>
                  <th className="px-4 py-2 text-right">Forecast qty</th>
                  <th className="px-4 py-2 text-right">Confidence</th>
                  <th className="px-4 py-2 text-right">Actual</th>
                  <th className="px-4 py-2 text-right">Variance</th>
                  <th className="px-4 py-2 text-right" />
                </tr>
              </thead>
              <tbody>
                {!productId ? (
                  <tr><td colSpan={7} className="px-4 py-6 text-center text-muted">Select a product.</td></tr>
                ) : forecastsQ.isLoading ? (
                  <tr><td colSpan={7} className="px-4 py-6"><SkeletonBlock className="h-6" /></td></tr>
                ) : (forecastsQ.data ?? []).length === 0 ? (
                  <tr><td colSpan={7} className="px-4 py-6 text-center text-muted">No forecasts yet — click <em>Recompute</em>.</td></tr>
                ) : (forecastsQ.data ?? []).map((f) => (
                  <tr key={f.id} className="border-b border-default/50">
                    <td className="px-4 py-2">{MONTH_NAMES[f.forecast_month - 1]} {f.forecast_year}</td>
                    <td className="px-4 py-2">
                      <Badge variant="neutral">{METHOD_LABELS[f.method]}</Badge>
                    </td>
                    <td className="px-4 py-2 text-right tabular-nums">{f.forecasted_quantity.toFixed(2)}</td>
                    <td className="px-4 py-2 text-right tabular-nums">
                      {f.confidence_level !== null ? `${f.confidence_level.toFixed(0)}%` : '—'}
                    </td>
                    <td className="px-4 py-2 text-right tabular-nums text-muted">
                      {f.actual_quantity !== null ? f.actual_quantity.toFixed(2) : '—'}
                    </td>
                    <td className="px-4 py-2 text-right tabular-nums">
                      {f.variance !== null ? (
                        <span className={f.variance < 0 ? 'text-danger' : 'text-success'}>
                          {f.variance > 0 ? '+' : ''}{f.variance.toFixed(2)}
                        </span>
                      ) : '—'}
                    </td>
                    <td className="px-4 py-2 text-right">
                      <Button size="sm" variant="ghost" onClick={() => openManual(f)}>Override</Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Panel>
      </div>

      {/* Manual override modal */}
      <Modal
        isOpen={manualOpen}
        onClose={() => setManualOpen(false)}
        title={
          manualRow
            ? `Manual override — ${MONTH_NAMES[manualRow.forecast_month - 1]} ${manualRow.forecast_year}`
            : 'Manual override'
        }
      >
        <div className="py-3">
        <div className="space-y-3">
          <div>
            <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">Forecast quantity</label>
            <Input
              type="number" min={0} step="0.01"
              value={manualQty} onChange={(e) => setManualQty(e.target.value)}
            />
          </div>
          <div>
            <label className="text-2xs uppercase tracking-wide text-muted mb-1 block">Confidence (0–100, optional)</label>
            <Input
              type="number" min={0} max={100} step="1"
              value={manualConf} onChange={(e) => setManualConf(e.target.value)}
            />
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="ghost" onClick={() => setManualOpen(false)}>Cancel</Button>
            <Button
              variant="primary"
              onClick={() => manualM.mutate()}
              disabled={manualM.isPending || manualQty.trim() === ''}
            >
              {manualM.isPending ? 'Saving…' : 'Save override'}
            </Button>
          </div>
        </div>
        </div>
      </Modal>
    </>
  );
}
