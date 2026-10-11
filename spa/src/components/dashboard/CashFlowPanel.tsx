/**
 * CashFlowPanel — read-only 3-month cash-flow projection (Task 6).
 *
 * Same shell chrome as ForecastPanel (skeleton / retry / empty, confidence,
 * Open link, no inputs) but grouped inflow-vs-outflow bars: a single net
 * series would hide whether a thin month is weak collections or heavy bills.
 *
 * Data shape matches {@link CashFlowForecastData} from '@/types/forecasting-dashboard'.
 */
import { LuTrendingUp, LuTrendingDown, LuMinus } from '@/lib/icons';
import { Link } from 'react-router-dom';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { LinkButton } from '@/components/ui/LinkButton';
import type {
  CashFlowForecastData,
  TrendDirection,
} from '@/types/forecasting-dashboard';

interface Props {
  data: CashFlowForecastData | undefined;
  isLoading: boolean;
  isError: boolean;
  onRetry?: () => void;
  title?: string;
  /** Formatter for peso values. */
  formatValue?: (value: number) => string;
  /** Read-only link-out to the demand surface behind the projection. */
  detailsHref?: string;
}

const TREND_ICON: Record<TrendDirection, typeof LuTrendingUp> = {
  up: LuTrendingUp,
  down: LuTrendingDown,
  stable: LuMinus,
};

const TREND_COLOR: Record<TrendDirection, 'success' | 'danger' | 'info'> = {
  up: 'success',
  down: 'danger',
  stable: 'info',
};

export function CashFlowPanel({
  data,
  isLoading,
  isError,
  onRetry,
  title = 'Cash Flow Forecast',
  formatValue = (v) => String(v),
  detailsHref = '/forecasting/demand',
}: Props) {
  if (isLoading) {
    return (
      <Panel title={title}>
        <div className="space-y-2">
          {[1, 2, 3].map((i) => (
            <SkeletonBlock key={i} className="h-10 w-full rounded-md" />
          ))}
        </div>
      </Panel>
    );
  }

  if (isError) {
    return (
      <Panel title={title}>
        <EmptyState
          icon="alert-circle"
          title="Failed to load cash-flow forecast"
          action={
            onRetry ? (
              <LinkButton onClick={onRetry} className="text-sm">
                Retry
              </LinkButton>
            ) : undefined
          }
        />
      </Panel>
    );
  }

  if (!data || data.months.length === 0) {
    return (
      <Panel title={title}>
        <EmptyState
          icon="inbox"
          title="No data yet"
          description="Not enough billing history to project cash flow."
        />
      </Panel>
    );
  }

  const TrendIcon = TREND_ICON[data.trend];
  const trendColor = TREND_COLOR[data.trend];
  const avgConfidence =
    data.confidence != null && Number.isFinite(data.confidence)
      ? Math.round(data.confidence)
      : null;
  const ceiling = Math.max(1, ...data.months.flatMap((m) => [m.inflow, m.outflow]));

  return (
    <Panel
      title={title}
      meta={
        <span className="flex items-center gap-1 text-xs">
          <TrendIcon size={12} className={`text-${trendColor}`} />
          <Chip variant={trendColor}>{data.trend}</Chip>
        </span>
      }
      actions={
        <Link to={detailsHref} className="text-xs text-link hover:underline">
          Open →
        </Link>
      }
    >
      <div className="space-y-3">
        {/* Mini KPI */}
        {data.kpi && (
          <div className="flex items-center justify-between p-2 bg-elevated rounded-md">
            <span className="text-sm text-muted">{data.kpi.label}</span>
            <span className="text-lg font-medium font-mono tabular-nums">
              {data.kpi.value !== '' && Number.isFinite(Number(data.kpi.value))
                ? formatValue(Number(data.kpi.value))
                : data.kpi.value}
              {data.kpi.unit && <span className="text-xs text-muted ml-1">{data.kpi.unit}</span>}
            </span>
          </div>
        )}

        {/* Grouped inflow / outflow bars with the net figure per month. */}
        <div className="space-y-2">
          {data.months.map((m) => {
            const monthLabel = new Date(m.year, m.month - 1).toLocaleDateString('en-US', {
              month: 'short',
              ...(m.year !== new Date().getFullYear() ? { year: 'numeric' } : {}),
            });
            return (
              <div key={`${m.year}-${m.month}`}>
                <div className="flex items-baseline justify-between text-xs mb-1">
                  <span className="text-muted">{monthLabel}</span>
                  <span className="font-mono tabular-nums">
                    Net {formatValue(m.net)}
                    {m.confidence != null && (
                      <span className="ml-1 text-muted">· {Math.round(m.confidence)}%</span>
                    )}
                  </span>
                </div>
                <div className="space-y-1">
                  <div className="flex items-center gap-2">
                    <span className="w-12 text-2xs text-muted">In</span>
                    <div className="flex-1 h-3 rounded-sm bg-subtle overflow-hidden">
                      <div
                        className="h-full bg-success-bg"
                        style={{ width: `${Math.max(2, (m.inflow / ceiling) * 100)}%` }}
                      />
                    </div>
                    <span className="w-24 text-right text-xs font-mono tabular-nums">
                      {formatValue(m.inflow)}
                    </span>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="w-12 text-2xs text-muted">Out</span>
                    <div className="flex-1 h-3 rounded-sm bg-subtle overflow-hidden">
                      <div
                        className="h-full bg-danger-bg"
                        style={{ width: `${Math.max(2, (m.outflow / ceiling) * 100)}%` }}
                      />
                    </div>
                    <span className="w-24 text-right text-xs font-mono tabular-nums">
                      {formatValue(m.outflow)}
                    </span>
                  </div>
                </div>
              </div>
            );
          })}
        </div>

        {/* Legend */}
        <div className="flex items-center justify-end gap-3 text-xs text-muted">
          {avgConfidence != null && (
            <span className="mr-auto font-mono tabular-nums">
              Avg confidence {avgConfidence}%
            </span>
          )}
          <span className="flex items-center gap-1">
            <span className="w-2.5 h-2.5 rounded-sm bg-success-bg" />
            Inflow
          </span>
          <span className="flex items-center gap-1">
            <span className="w-2.5 h-2.5 rounded-sm bg-danger-bg" />
            Outflow
          </span>
        </div>
      </div>
    </Panel>
  );
}

export default CashFlowPanel;
