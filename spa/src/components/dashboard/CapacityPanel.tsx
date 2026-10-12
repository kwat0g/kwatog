/**
 * CapacityPanel — read-only plant capacity projection (Task 7).
 *
 * Same shell chrome as CashFlowPanel (skeleton / retry / empty, confidence,
 * Open link, no inputs) but utilisation bars: open work-order hours against
 * OEE-derated machine hours per month, with an overload chip on breached
 * months. Plant-level aggregate only — no per-machine split.
 *
 * Data shape matches {@link CapacityForecastData} from '@/types/forecasting-dashboard'.
 */
import { LuTrendingUp, LuTrendingDown, LuMinus } from '@/lib/icons';
import { Link } from 'react-router-dom';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { LinkButton } from '@/components/ui/LinkButton';
import type {
  CapacityForecastData,
  TrendDirection,
} from '@/types/forecasting-dashboard';

interface Props {
  data: CapacityForecastData | undefined;
  isLoading: boolean;
  isError: boolean;
  onRetry?: () => void;
  title?: string;
  /** Read-only link-out to the schedule surface behind the projection. */
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

export function CapacityPanel({
  data,
  isLoading,
  isError,
  onRetry,
  title = 'Capacity Forecast',
  detailsHref = '/production/schedule',
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
          title="Failed to load capacity forecast"
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
          description="No machine capacity configured to project load against."
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
  // Which month trips the server-computed breach flag is derived, not
  // re-thresholded: the peak month is necessarily the one over the line.
  const peakUtil = Math.max(...data.months.map((m) => m.util_pct));

  return (
    <Panel
      title={title}
      meta={
        <span className="flex items-center gap-1 text-xs">
          <TrendIcon size={12} className={`text-${trendColor}`} />
          <Chip variant={trendColor}>{data.trend}</Chip>
          {data.breach && <Chip variant="danger">Overload</Chip>}
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
              {data.kpi.value}
              {data.kpi.unit === 'pct' && <span className="text-xs text-muted ml-1">%</span>}
            </span>
          </div>
        )}

        {/* Utilisation bars with the load/capacity split per month. */}
        <div className="space-y-2">
          {data.months.map((m) => {
            const monthLabel = new Date(m.year, m.month - 1).toLocaleDateString('en-US', {
              month: 'short',
              ...(m.year !== new Date().getFullYear() ? { year: 'numeric' } : {}),
            });
            const breached = data.breach && m.util_pct >= peakUtil;
            return (
              <div key={`${m.year}-${m.month}`}>
                <div className="flex items-baseline justify-between text-xs mb-1">
                  <span className="text-muted">
                    {monthLabel}
                    {breached && (
                      <Chip variant="danger" className="ml-1">Overload</Chip>
                    )}
                  </span>
                  <span className="font-mono tabular-nums">
                    {m.util_pct}%
                    {m.confidence != null && (
                      <span className="ml-1 text-muted">· {Math.round(m.confidence)}%</span>
                    )}
                  </span>
                </div>
                <div
                  role="progressbar"
                  aria-valuenow={m.util_pct}
                  aria-valuemin={0}
                  aria-valuemax={100}
                  aria-label={`${monthLabel}: ${m.util_pct}% of capacity`}
                  className="h-3 rounded-sm bg-subtle overflow-hidden"
                >
                  <div
                    className={`h-full ${breached ? 'bg-danger-bg' : 'bg-info-bg'}`}
                    style={{ width: `${Math.min(100, Math.max(2, m.util_pct))}%` }}
                  />
                </div>
                <div className="flex items-baseline justify-between text-2xs text-muted mt-0.5">
                  <span className="font-mono tabular-nums">{m.load_hours}h load</span>
                  <span className="font-mono tabular-nums">{m.capacity_hours}h capacity</span>
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
            <span className="w-2.5 h-2.5 rounded-sm bg-info-bg" />
            Within capacity
          </span>
          <span className="flex items-center gap-1">
            <span className="w-2.5 h-2.5 rounded-sm bg-danger-bg" />
            Overload
          </span>
        </div>
      </div>
    </Panel>
  );
}

export default CapacityPanel;
