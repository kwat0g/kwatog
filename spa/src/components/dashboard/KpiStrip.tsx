import { useQuery } from '@tanstack/react-query';
import { LuTrendingUp, LuTrendingDown, LuMinus } from '@/lib/icons';
import { Link } from 'react-router-dom';
import { kpiApi } from '@/api/kpi';
import { cn } from '@/lib/cn';
import { SparkLine } from '@/components/charts/SparkLine';
import { kpiDashboardLink } from '@/lib/dashboardKpis';
import { kpiGridCols } from './DashboardShell';
import type { KpiScorecardItem } from '@/types/dashboard/kpi';

const UNIT_SUFFIX: Record<string, string> = {
  percentage: '%',
  days: ' days',
  currency: '',
  count: '',
  ratio: 'x',
};

const STATUS_DOT: Record<string, string> = {
  on_target: 'bg-success-bg',
  warning: 'bg-warning-bg',
  off_target: 'bg-danger-bg',
};

const SPARK_COLOR: Record<string, string> = {
  on_target: 'var(--success)',
  warning: 'var(--warning)',
  off_target: 'var(--danger)',
};

const TREND_COLORS: Record<string, Record<string, string>> = {
  on_target: { up: 'text-success-fg', down: 'text-success-fg', flat: 'text-muted' },
  warning: { up: 'text-warning-fg', down: 'text-warning-fg', flat: 'text-muted' },
  off_target: { up: 'text-danger-fg', down: 'text-danger-fg', flat: 'text-muted' },
};

const TrendIcon = ({ trend, status }: { trend: string; status: string }) => {
  const color = TREND_COLORS[status]?.[trend] ?? 'text-muted';
  if (trend === 'up') return <LuTrendingUp size={12} className={color} />;
  if (trend === 'down') return <LuTrendingDown size={12} className={color} />;
  return <LuMinus size={12} className="text-muted" />;
};

/** Month-over-month delta text (`+2.50%`), or null when there is no previous value. */
function momDeltaText(actual: string, previous: string | null, unit: string): string | null {
  if (previous == null) return null;
  const delta = parseFloat(actual) - parseFloat(previous);
  if (!Number.isFinite(delta)) return null;
  if (delta === 0) return `0${UNIT_SUFFIX[unit] ?? ''}`;
  const sign = delta > 0 ? '+' : '-';
  return `${sign}${Math.abs(delta).toFixed(2)}${UNIT_SUFFIX[unit] ?? ''}`;
}

/** Direction-aware delta tone: a rising `lower_is_better` KPI is bad news. */
function momDeltaTone(
  trend: string,
  direction: string,
): 'text-success-fg' | 'text-danger-fg' | 'text-muted' {
  if (trend === 'flat') return 'text-muted';
  const good =
    (trend === 'up' && direction === 'higher_is_better') ||
    (trend === 'down' && direction === 'lower_is_better');
  return good ? 'text-success-fg' : 'text-danger-fg';
}

interface KpiStripProps {
  codes: string[];
  className?: string;
  /** Per-code drill-down overrides. Any code missing from this map links to the KPI's owning dashboard anchor (`#kpi-{code}`). */
  linkByCode?: Record<string, string>;
  /** Compact density used by every role dashboard: tighter cards + 6-pt sparkline. */
  compact?: boolean;
}

export function KpiStrip({ codes, className, linkByCode, compact }: KpiStripProps) {
  const now = new Date();
  const prevMonth = new Date(now.getFullYear(), now.getMonth() - 1, 1);
  const year = prevMonth.getFullYear();
  const month = prevMonth.getMonth() + 1;

  const { data, isLoading, isError } = useQuery({
    queryKey: ['kpi', 'scorecard', year, month],
    queryFn: () => kpiApi.scorecard(year, month),
    // Nothing requested (admin compute panel, default widget home) — skip the
    // fetch. The early return below already renders null; without this the
    // query still fires on every mount of those pages.
    enabled: codes.length > 0,
    staleTime: 5 * 60_000,
    retry: 1,
  });

  const { data: trends } = useQuery({
    // Joined to a string: a fresh `codes` array identity per render would
    // otherwise re-key the query and refetch in a loop.
    queryKey: ['kpi', 'trends', codes.join(',')],
    queryFn: () => kpiApi.trends(codes, 6),
    // The 6-pt sparkline is a compact-strip feature — non-compact callers get
    // status dot + delta only, no trends fetch.
    enabled: (compact ?? false) && codes.length > 0,
    staleTime: 5 * 60_000,
    retry: 1,
  });

  // Nothing requested (admin compute panel, default widget home) — render nothing.
  if (codes.length === 0) return null;

  if (isError) return null;

  if (isLoading || !data) {
    return (
      <div className={cn('grid gap-3', kpiGridCols(codes.length), className)}>
        {codes.map((c) => (
          <div key={c} className="h-[72px] bg-surface border border-default rounded-md animate-pulse" />
        ))}
      </div>
    );
  }

  // The scorecard endpoint gates each KPI on the viewer's grant and omits what
  // they may not read — and a mocked/stale payload may be a bare object, so
  // normalise before matching instead of assuming an array.
  const items = Array.isArray(data)
    ? data
    : (Object.values(data ?? {}) as KpiScorecardItem[]);
  const filtered = codes
    .map((code) => items.find((item: KpiScorecardItem) => item.definition.code === code))
    .filter(Boolean) as KpiScorecardItem[];

  // Every requested code was permission-stripped: say so instead of crashing
  // or silently rendering nothing.
  if (filtered.length === 0) {
    return (
      <p className="text-xs text-muted" role="status">
        No KPI data available for this period.
      </p>
    );
  }

  return (
    <div className={cn('grid gap-3', kpiGridCols(filtered.length), className)}>
      {filtered.map((item) => {
        const snap = item.snapshot;
        const val = snap
          ? `${parseFloat(snap.actual_value).toLocaleString()}${UNIT_SUFFIX[item.definition.unit] ?? ''}`
          : '—';
        const target = item.definition.target_value
          ? `Target: ${parseFloat(item.definition.target_value).toLocaleString()}${UNIT_SUFFIX[item.definition.unit] ?? ''}`
          : undefined;
        const delta =
          snap != null ? momDeltaText(snap.actual_value, snap.previous_value, item.definition.unit) : null;
        const points = (trends?.[item.definition.code] ?? []).map((p) => parseFloat(p.value));
        const sparkPoints = points.filter((v) => Number.isFinite(v)).slice(-6);
        const statusLabel = snap?.status_label ?? snap?.status.replace(/_/g, ' ') ?? 'No data';
        return (
          <Link
            key={item.definition.code}
            id={`kpi-${item.definition.code}`}
            to={linkByCode?.[item.definition.code] ?? kpiDashboardLink(item.definition.code, item.definition.module)}
            className={cn(
              'bg-surface border border-default rounded-md hover:bg-elevated transition-colors duration-fast flex items-start justify-between gap-2',
              compact ? 'px-2.5 py-2' : 'p-3',
            )}
          >
            <div className="min-w-0">
              <div className="flex items-center gap-1.5 mb-1">
                <span
                  className={cn(
                    'w-1.5 h-1.5 rounded-full shrink-0',
                    (snap && STATUS_DOT[snap.status]) ?? 'bg-text-subtle',
                  )}
                  title={statusLabel}
                  aria-label={`Status: ${statusLabel}`}
                />
                <span className="text-2xs uppercase tracking-wider text-subtle font-medium truncate">
                  {item.definition.name}
                </span>
              </div>
              <div
                className={cn(
                  'font-medium font-mono tabular-nums text-primary leading-tight',
                  compact ? 'text-base' : 'text-lg',
                )}
              >
                {val}
              </div>
              {delta != null && snap != null && (
                <div
                  className={cn(
                    'text-2xs font-mono tabular-nums mt-0.5',
                    momDeltaTone(snap.trend, item.definition.direction),
                  )}
                  title="Month-over-month change"
                >
                  {delta}
                </div>
              )}
              {target && <div className="text-2xs text-muted mt-0.5">{target}</div>}
            </div>
            <div className="flex flex-col items-end gap-1 shrink-0 mt-0.5">
              {snap && <TrendIcon trend={snap.trend} status={snap.status} />}
              {sparkPoints.length >= 2 && (
                <SparkLine
                  data={sparkPoints}
                  label={`${item.definition.name}, monthly trend`}
                  color={SPARK_COLOR[snap?.status ?? ''] ?? 'var(--accent)'}
                  height={compact ? 28 : 36}
                  width={compact ? 64 : 72}
                />
              )}
            </div>
          </Link>
        );
      })}
    </div>
  );
}
