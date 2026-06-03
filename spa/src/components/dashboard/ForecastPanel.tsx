/**
 * ForecastPanel — Reusable forecast visualization widget.
 *
 * Renders a mini sparkline-style bar chart of historical + projected values
 * with a trend indicator and KPI summary. Used in HR (headcount), Finance
 * (revenue), and Quality (defect rate) dashboards.
 *
 * Data shape matches {@link ForecastPanelData} from '@/types/forecasting-dashboard'.
 */
import { TrendingUp, TrendingDown, Minus } from 'lucide-react';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import type { ForecastPanelData, TrendDirection, ForecastPoint } from '@/types/forecasting-dashboard';

interface Props {
  data: ForecastPanelData | undefined;
  isLoading: boolean;
  isError: boolean;
  onRetry?: () => void;
  title?: string;
  /** Formatter for the Y-axis values (e.g., append currency symbol). */
  formatValue?: (value: number) => string;
  /** Label suffix for the mini-KPI (e.g., "employees", "₱", "%"). */
  unitLabel?: string;
  /** Whether to hide the panel when there's no data. */
  hideWhenEmpty?: boolean;
  /** Whether to show the mini-KPI card above the chart. */
  showKpi?: boolean;
}

const TREND_ICON: Record<TrendDirection, typeof TrendingUp> = {
  up: TrendingUp,
  down: TrendingDown,
  stable: Minus,
};

const TREND_COLOR: Record<TrendDirection, 'success' | 'danger' | 'info'> = {
  up: 'success',
  down: 'danger',
  stable: 'info',
};

export function ForecastPanel({
  data,
  isLoading,
  isError,
  onRetry,
  title = 'Forecast',
  formatValue = (v) => String(v),
  unitLabel,
  hideWhenEmpty = false,
  showKpi = true,
}: Props) {
  if (isLoading) {
    return (
      <Panel title={title}>
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
          title="Failed to load forecast"
          action={
            onRetry ? (
              <button
                onClick={onRetry}
                className="text-sm text-accent hover:underline cursor-pointer"
              >
                Retry
              </button>
            ) : undefined
          }
        />
      </Panel>
    );
  }

  if (!data || data.historical.length === 0) {
    if (hideWhenEmpty) return null;
    return (
      <Panel title={title}>
        <EmptyState
          icon="inbox"
          title="No data yet"
          description="Not enough historical data to generate a forecast."
        />
      </Panel>
    );
  }

  const hasForecast = data.forecast.length > 0;
  const TrendIcon = TREND_ICON[data.trend];
  const trendColor = TREND_COLOR[data.trend];

  // Combine all points for the chart.
  const allPoints: Array<ForecastPoint & { isForecast: boolean }> = [
    ...data.historical.map((p) => ({ ...p, isForecast: false })),
    ...data.forecast.map((p) => ({ ...p, isForecast: true })),
  ];

  const maxValue = Math.max(1, ...allPoints.map((p) => p.value));
  // For defect rate (0–100), use 100 as ceiling for better visual scale.
  const chartCeiling = maxValue <= 100 && data.historical.every((p) => p.value <= 100) ? 100 : maxValue;

  return (
    <Panel
      title={title}
      meta={
        hasForecast ? (
          <span className="flex items-center gap-1 text-xs">
            <TrendIcon size={12} className={`text-${trendColor}`} />
            <Chip variant={trendColor}>{data.trend}</Chip>
          </span>
        ) : undefined
      }
    >
      <div className="space-y-3">
        {/* Mini KPI */}
        {showKpi && data.kpi && (
          <div className="flex items-center justify-between p-2 bg-elevated rounded-md">
            <span className="text-sm text-muted">{data.kpi.label}</span>
            <span className="text-lg font-semibold font-mono tabular-nums">
              {data.kpi.value}
              {unitLabel && <span className="text-xs text-muted ml-1">{unitLabel}</span>}
            </span>
          </div>
        )}

        {/* Sparkline bar chart */}
        <div className="flex items-end gap-[2px] h-24">
          {allPoints.map((p, i) => {
            const heightPct = (p.value / chartCeiling) * 100;
            const isLastHistorical = i === data.historical.length - 1;
            const monthLabel = new Date(p.year, p.month - 1).toLocaleDateString('en-US', {
              month: 'short',
              ...(p.year !== new Date().getFullYear() ? { year: 'numeric' } : {}),
            });

            return (
              <div
                key={`${p.year}-${p.month}`}
                className="flex-1 relative group flex flex-col items-center justify-end"
              >
                <div
                  className={`w-full rounded-sm transition-all duration-300 ${
                    p.isForecast
                      ? 'bg-accent/30 border-t-2 border-accent/60'
                      : 'bg-accent/60'
                  }`}
                  style={{ height: `${Math.max(4, heightPct)}%` }}
                  title={`${monthLabel}: ${formatValue(p.value)}${p.isForecast && p.confidence != null ? ` (${Math.round(p.confidence)}% confidence)` : ''}`}
                />

                {/* Divider line between historical and forecast */}
                {isLastHistorical && hasForecast && (
                  <div className="absolute right-0 top-0 bottom-0 w-px bg-border" />
                )}

                {/* Tooltip on hover */}
                <div className="absolute bottom-full left-1/2 -translate-x-1/2 mb-1 hidden group-hover:block z-10">
                  <div className="bg-popover text-popover-foreground text-xs px-2 py-1 rounded-md shadow-lg whitespace-nowrap">
                    <span className="font-medium">{monthLabel}</span>
                    <span className="ml-1 font-mono">{formatValue(p.value)}</span>
                    {p.isForecast && p.confidence != null && (
                      <span className="ml-1 text-muted">· {Math.round(p.confidence)}%</span>
                    )}
                  </div>
                </div>

                {/* X-axis label */}
                {i % 2 === 0 && (
                  <span className="text-[10px] text-muted mt-1 truncate max-w-full text-center">
                    {monthLabel}
                  </span>
                )}
              </div>
            );
          })}
        </div>

        {/* Legend */}
        <div className="flex items-center justify-end gap-3 text-[11px] text-muted">
          <span className="flex items-center gap-1">
            <span className="w-2.5 h-2.5 rounded-sm bg-accent/60" />
            Historical
          </span>
          {hasForecast && (
            <span className="flex items-center gap-1">
              <span className="w-2.5 h-2.5 rounded-sm bg-accent/30 border-t border-accent/60" />
              Forecast
            </span>
          )}
        </div>
      </div>
    </Panel>
  );
}

export default ForecastPanel;
