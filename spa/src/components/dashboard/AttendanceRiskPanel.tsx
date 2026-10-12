/**
 * AttendanceRiskPanel — read-only attendance projection (Task 8).
 *
 * Same shell chrome as CapacityPanel (skeleton / retry / empty, confidence,
 * Open link, no inputs) but expected-rate bars with the approved leave load
 * per month and a risk chip on the peak month. Plant-level aggregate only —
 * no per-department split. Colours are static maps (never `text-${var}`).
 *
 * Data shape matches {@link AttendanceForecastData} from '@/types/forecasting-dashboard'.
 */
import { LuTrendingUp, LuTrendingDown, LuMinus } from '@/lib/icons';
import { Link } from 'react-router-dom';
import { Panel } from '@/components/ui/Panel';
import { Chip } from '@/components/ui/Chip';
import { SkeletonBlock } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { LinkButton } from '@/components/ui/LinkButton';
import type {
  AttendanceForecastData,
  AttendanceRisk,
  TrendDirection,
} from '@/types/forecasting-dashboard';

interface Props {
  data: AttendanceForecastData | undefined;
  isLoading: boolean;
  isError: boolean;
  onRetry?: () => void;
  title?: string;
  /** Read-only link-out to the attendance surface behind the projection. */
  detailsHref?: string;
}

const TREND_ICON: Record<TrendDirection, typeof LuTrendingUp> = {
  up: LuTrendingUp,
  down: LuTrendingDown,
  stable: LuMinus,
};

const TREND_ICON_CLASS: Record<TrendDirection, string> = {
  up: 'text-success-fg',
  down: 'text-danger-fg',
  stable: 'text-info-fg',
};

const TREND_CHIP: Record<TrendDirection, 'success' | 'danger' | 'info'> = {
  up: 'success',
  down: 'danger',
  stable: 'info',
};

const RISK_CHIP: Record<AttendanceRisk, 'success' | 'warning' | 'danger'> = {
  low: 'success',
  medium: 'warning',
  high: 'danger',
};

const RISK_BAR: Record<AttendanceRisk, string> = {
  low: 'bg-success-bg',
  medium: 'bg-warning-bg',
  high: 'bg-danger-bg',
};

export function AttendanceRiskPanel({
  data,
  isLoading,
  isError,
  onRetry,
  title = 'Attendance Forecast',
  detailsHref = '/hr/attendance',
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
          title="Failed to load attendance forecast"
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
          description="Not enough attendance history to project coverage risk."
        />
      </Panel>
    );
  }

  const TrendIcon = TREND_ICON[data.trend];
  const avgConfidence =
    data.confidence != null && Number.isFinite(data.confidence)
      ? Math.round(data.confidence)
      : null;
  // Which month carries the server-computed horizon risk is derived, not
  // re-thresholded: strict > keeps the FIRST month on ties.
  let peakLoad = -1;
  let peakKey = '';
  for (const m of data.months) {
    if (m.leave_load > peakLoad) {
      peakLoad = m.leave_load;
      peakKey = `${m.year}-${m.month}`;
    }
  }

  return (
    <Panel
      title={title}
      meta={
        <span className="flex items-center gap-1 text-xs">
          <TrendIcon size={12} className={TREND_ICON_CLASS[data.trend]} />
          <Chip variant={TREND_CHIP[data.trend]}>{data.trend}</Chip>
          {data.risk !== 'low' && <Chip variant={RISK_CHIP[data.risk]}>{data.risk} risk</Chip>}
        </span>
      }
      actions={
        <Link to={detailsHref} className="text-xs text-link hover:underline">
          Open →
        </Link>
      }
    >
      <div className="space-y-3">
        {/* Mini KPI — the label carries the configured horizon, never hardcoded here. */}
        {data.kpi && (
          <div className="flex items-center justify-between p-2 bg-elevated rounded-md">
            <span className="text-sm text-muted">{data.kpi.label}</span>
            <span className="text-lg font-medium font-mono tabular-nums">
              {data.kpi.value}
              {data.kpi.unit === 'pct' && <span className="text-xs text-muted ml-1">%</span>}
            </span>
          </div>
        )}

        {/* Expected-rate bars with the leave load per month. */}
        <div className="space-y-2">
          {data.months.map((m) => {
            const monthLabel = new Date(m.year, m.month - 1).toLocaleDateString('en-US', {
              month: 'short',
              ...(m.year !== new Date().getFullYear() ? { year: 'numeric' } : {}),
            });
            const key = `${m.year}-${m.month}`;
            const isPeak = data.risk !== 'low' && key === peakKey;
            return (
              <div key={key}>
                <div className="flex items-baseline justify-between text-xs mb-1">
                  <span className="text-muted">
                    {monthLabel}
                    {isPeak && (
                      <Chip variant={RISK_CHIP[m.risk]} className="ml-1">{m.risk} risk</Chip>
                    )}
                  </span>
                  <span className="font-mono tabular-nums">
                    {m.expected_rate}%
                    <span className="ml-1 text-muted">· leave {m.leave_load}%</span>
                    {m.confidence != null && (
                      <span className="ml-1 text-muted">· {Math.round(m.confidence)}%</span>
                    )}
                  </span>
                </div>
                <div
                  role="progressbar"
                  aria-valuenow={m.expected_rate}
                  aria-valuemin={0}
                  aria-valuemax={100}
                  aria-label={`${monthLabel}: ${m.expected_rate}% expected attendance`}
                  className="h-3 rounded-sm bg-subtle overflow-hidden"
                >
                  <div
                    className={`h-full ${RISK_BAR[m.risk]}`}
                    style={{ width: `${Math.min(100, Math.max(2, m.expected_rate))}%` }}
                  />
                </div>
                <div className="flex items-baseline justify-between text-2xs text-muted mt-0.5">
                  <span className="font-mono tabular-nums">{m.leave_load}% leave load</span>
                  <span className="font-mono tabular-nums">{m.risk}</span>
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
            Low risk
          </span>
          <span className="flex items-center gap-1">
            <span className="w-2.5 h-2.5 rounded-sm bg-warning-bg" />
            Medium
          </span>
          <span className="flex items-center gap-1">
            <span className="w-2.5 h-2.5 rounded-sm bg-danger-bg" />
            High risk
          </span>
        </div>
      </div>
    </Panel>
  );
}

export default AttendanceRiskPanel;
