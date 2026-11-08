/* eslint-disable react-refresh/only-export-components -- dashboard layout helpers are intentionally colocated with the shared shell. */
/**
 * Shared dashboard shell — one layout for every role dashboard.
 *
 * Before this existed each role dashboard hand-rolled its own header, loading
 * skeleton, error branch and grid classes, so they drifted: some used
 * non-responsive `grid-cols-4`, some `gap-2`, some `gap-4`, and the PageHeader
 * was duplicated three times per file (loading / error / data).
 *
 * DESIGN-SYSTEM.md → "Dashboard" page pattern:
 * PageHeader (title + actions)
 * KPI cards row (4 across)
 * Main grid (2 columns)
 *
 * Spacing per spec: page padding 16–20px, section gap 12–16px.
 */
import { type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { cn } from '@/lib/cn';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonBlock, SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Chip, type ChipVariant } from '@/components/ui/Chip';
import { TimeClockQuickAction } from '@/components/dashboard/TimeClockQuickAction';
import { isStaleTimestamp, WIDGET_STALE_AFTER_MS } from '@/api/dashboard-layout';
import { formatDateTime } from '@/lib/formatDate';
import { actionCenterApi } from '@/api/actionCenter';
import { usePermission } from '@/hooks/usePermission';
import { LuArrowRight } from '@/lib/icons';

import { WidgetErrorBoundary } from '@/components/ui/WidgetErrorBoundary';
import { useProductionDashboardRealtime } from '@/hooks/useProductionDashboardRealtime';
import { usePpcDashboardRealtime } from '@/hooks/usePpcDashboardRealtime';
import { useFinanceDashboardRealtime } from '@/hooks/useFinanceDashboardRealtime';
import { usePurchasingDashboardRealtime } from '@/hooks/usePurchasingDashboardRealtime';
import { useFeature } from '@/hooks/useFeature';
import { useRealtimeSignal } from '@/hooks/useRealtimeSignal';

const ACTION_PRIORITY_VARIANT: Record<'critical' | 'high' | 'medium' | 'low', ChipVariant> = {
  critical: 'danger',
  high: 'warning',
  medium: 'info',
  low: 'neutral',
};

/** A short, permission-scoped work queue shared by every internal dashboard. */
export function DashboardAttentionList() {
  const { can } = usePermission();
  const canView = can('dashboard.action_center.view');
  const query = useQuery({
    queryKey: ['action-center'],
    queryFn: actionCenterApi.get,
    enabled: canView,
    staleTime: 0,
  });

  if (!canView || query.isLoading || query.isError || !query.data) return null;

  const items = query.data.items.slice(0, 3);
  return (
    <section className="border-y border-default py-3" aria-labelledby="dashboard-attention-title">
      <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
        <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
          <h2 id="dashboard-attention-title" className="text-subsection font-semibold text-primary">
            Needs your attention
          </h2>
          {query.data.summary.total > 0 && (
            <span className="text-caption text-muted">{query.data.summary.total} open</span>
          )}
        </div>
        <Link
          to="/action-center"
          className="inline-flex min-h-8 items-center justify-center whitespace-nowrap rounded-md border border-default bg-surface px-3 text-caption font-medium text-primary transition-colors hover:bg-elevated focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2"
        >
          View action center
        </Link>
      </div>

      {items.length === 0 ? (
        <p role="status" className="mt-2 text-sm text-muted">All clear.</p>
      ) : (
        <ul className="mt-2 divide-y divide-subtle">
          {items.map((item) => (
            <li key={item.id}>
              <Link
                to={item.link}
                className="group flex min-w-0 items-center gap-3 rounded-sm py-2 transition-colors hover:bg-elevated/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
              >
                <span className="min-w-0 flex-1">
                  <span className="flex flex-wrap items-center gap-1.5">
                    <span className="min-w-0 truncate text-sm font-medium text-primary group-hover:underline">{item.title}</span>
                    <Chip variant={ACTION_PRIORITY_VARIANT[item.priority]}>
                      {item.priority_label ?? item.priority}
                    </Chip>
                    {item.is_overdue && <Chip variant="danger">overdue</Chip>}
                  </span>
                  {item.description && (
                    <span className="mt-0.5 block truncate text-caption text-muted">{item.description}</span>
                  )}
                </span>
                <LuArrowRight aria-hidden="true" size={14} className="shrink-0 text-link" />
              </Link>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

/* ───────────────────────── Grid primitives ───────────────────────── */

/**
 * Responsive column classes for a KPI row of `count` cards. Always collapses
 * to one or two columns on small screens — a dashboard that needs horizontal
 * scrolling on a tablet is a broken dashboard.
 */
export function kpiGridCols(count: number): string {
  if (count <= 1) return 'grid-cols-1';
  if (count === 2) return 'grid-cols-1 sm:grid-cols-2';
  if (count === 3) return 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3';
  if (count === 4) return 'grid-cols-1 sm:grid-cols-2 xl:grid-cols-4';
  if (count === 5) return 'grid-cols-2 sm:grid-cols-3 xl:grid-cols-5';
  if (count === 6) return 'grid-cols-2 sm:grid-cols-3 xl:grid-cols-6';
  return 'grid-cols-2 sm:grid-cols-3 xl:grid-cols-4';
}

/** KPI / StatCard row. Pass the number of children so columns stay honest. */
export function KpiGrid({
  count,
  children,
  className,
}: {
  count: number;
  children: ReactNode;
  className?: string;
}) {
  return <section className={cn('grid gap-3', kpiGridCols(count), className)}>{children}</section>;
}

/** Two-column panel row — the dashboard workhorse. Stacks below `lg`. */
export function PanelRow({
  children,
  cols = 2,
  className,
}: {
  children: ReactNode;
  cols?: 2 | 3;
  className?: string;
}) {
  return (
    <div
      className={cn(
        'grid gap-3',
        cols === 3 ? 'grid-cols-1 md:grid-cols-2 xl:grid-cols-3' : 'grid-cols-1 lg:grid-cols-2',
        className,
      )}
    >
      {children}
    </div>
  );
}

/** Dashboard body wrapper — page padding + section rhythm. */
export function DashboardBody({
  children,
  className,
}: {
  children: ReactNode;
  className?: string;
}) {
  return <div className={cn('px-5 py-4 space-y-3', className)}>{children}</div>;
}

/**
 * Hierarchy primitives live in `./hierarchy` and the severity table in
 * `./severity`; both are re-exported here so a dashboard keeps ONE import for
 * its layout vocabulary. `KpiGrid` and `PanelRow` above are unchanged and still
 * correct for genuinely peer content — the point of the new primitives is that
 * equal weight is now a CHOICE rather than the only option.
 */
export {
  DashSection,
  DashTail,
  LeadStat,
  PanelEmphasis,
  SeverityBadge,
  StatBand,
} from './hierarchy';
export {
  SEVERITY,
  machineSeverity,
  thresholdSeverity,
  toSeverity,
  worstSeverity,
  type Severity,
} from './severity';

/* ───────────────────────── Shell ───────────────────────── */

interface QueryLike<T> {
  data: T | undefined;
  isLoading: boolean;
  isError: boolean;
  refetch: () => unknown;
}

interface DashboardShellProps<T> {
  title: ReactNode;
  subtitle?: ReactNode;
  actions?: ReactNode;
  /**
   * Header slot — the shared `<KpiStrip>` lives here, above the body, so every
   * dashboard mounts it from the same place. Renders in every state (loading,
   * error, empty, data) with its own skeleton; it reads its own query.
   */
  header?: ReactNode;
  /** TanStack Query result. Drives the loading / error / empty / data branches. */
  query: QueryLike<T>;
  /** How many KPI skeleton tiles to show while loading. */
  kpiCount?: number;
  /** The default action list leads the dashboard. `after-kpis` lets a dashboard place it after its own KPI row. */
  attentionPlacement?: 'before-content' | 'after-kpis';
  refreshingQueryKey?: readonly unknown[];
  /**
   * Newest timestamp behind the displayed data (e.g. max widget-data
   * `updated_at`). Older than `staleAfterMs` draws a Stale badge, not silence.
   */
  updatedAt?: string | null;
  /** Defaults to two missed 60s polls. */
  staleAfterMs?: number;
  /**
   * Loaded but holds nothing (e.g. zero widgets). Shows the empty message, not
   * a blank grid. Success-without-data (`data == null`) is always empty.
   */
  isEmpty?: (data: T) => boolean;
  emptyTitle?: string;
  emptyDescription?: string;
  children: (data: T) => ReactNode;
}

/**
 * Renders the header once and switches the body between the states every
 * list/dashboard page owes the user: loading skeleton, retryable error, empty
 * message, data — plus a Stale badge when the data outlives its poll window.
 */
export function DashboardShell<T>({
  title,
  subtitle,
  actions,
  header,
  query,
  kpiCount = 4,
  attentionPlacement = 'before-content',
  refreshingQueryKey,
  updatedAt,
  staleAfterMs = WIDGET_STALE_AFTER_MS,
  isEmpty,
  emptyTitle = 'No data yet',
  emptyDescription = 'Nothing to show on this dashboard yet.',
  children,
}: DashboardShellProps<T>) {
  // Configurable dashboard widgets share sources with the dedicated
  // dashboards. Subscribe once in this shared shell so widget-only viewers
  // refetch their own authorized API snapshot when those sources move.
  useProductionDashboardRealtime();
  usePpcDashboardRealtime();
  useFinanceDashboardRealtime();
  usePurchasingDashboardRealtime();

  const { can } = usePermission();
  const qualityEnabled = useFeature('quality');
  const productionEnabled = useFeature('production');
  useRealtimeSignal(
    'admin_dashboard_changed',
    can('dashboard.admin.view') ? 'dashboard.admin' : '',
    '.admin.dashboard.changed',
  );
  useRealtimeSignal(
    'quality_dashboard_changed',
    qualityEnabled && productionEnabled && can('dashboard.quality.view') ? 'quality.dashboard' : '',
    '.dashboard.quality.changed',
  );

  const headerNode = (
    <PageHeader
      title={title}
      subtitle={subtitle}
      actions={
        <>
          {actions}
          <TimeClockQuickAction />
        </>
      }
      refreshingQueryKey={refreshingQueryKey}
    />
  );
  const retry = () => {
    void query.refetch();
  };
  const data = query.data;
  const serverGeneratedAt =
    data && typeof data === 'object' && 'generated_at' in data && typeof data.generated_at === 'string'
      ? data.generated_at
      : null;
  const freshnessTimestamp = updatedAt ?? serverGeneratedAt;
  const stale = isStaleTimestamp(freshnessTimestamp, staleAfterMs);
  const freshnessIndicator =
    freshnessTimestamp ? (
      <div
        role={stale ? 'status' : undefined}
        className="flex items-center gap-2 rounded-md border border-default bg-surface px-3 py-2 text-xs text-muted"
      >
        {stale && <Chip variant="warning">Stale</Chip>}
        <span>{stale ? 'Data may be out of date · ' : ''}As of {formatDateTime(freshnessTimestamp)}</span>
      </div>
    ) : null;
  // The strip reads its own query and degrades to null on its own, so it is a
  // backstop boundary only — retry re-fires the shell query.
  const headerSlot = header ? (
    <WidgetErrorBoundary onRetry={retry}>{header}</WidgetErrorBoundary>
  ) : null;

  let body: ReactNode;
  if (query.isLoading && !data) {
    body = (
      <>
        <section className={cn('grid gap-3', kpiGridCols(kpiCount))}>
          {Array.from({ length: kpiCount }, (_, i) => (
            <SkeletonBlock key={i} className="h-[76px] rounded-md" />
          ))}
        </section>
        {attentionPlacement === 'after-kpis' && <DashboardAttentionList />}
        <SkeletonDetail />
      </>
    );
  } else if (query.isError) {
    body = (
      <>
        <EmptyState
          icon="alert-circle"
          title="Can't load this dashboard"
          description="The data request failed. Check your connection and try again."
          action={
            <Button variant="secondary" onClick={retry}>
              Try again
            </Button>
          }
        />
        {attentionPlacement === 'after-kpis' && <DashboardAttentionList />}
      </>
    );
  } else if (data == null || isEmpty?.(data)) {
    body = (
      <>
        <EmptyState icon="inbox" title={emptyTitle} description={emptyDescription} />
        {attentionPlacement === 'after-kpis' && <DashboardAttentionList />}
      </>
    );
  } else {
    body = (
      /* Without this, one bad number in one panel escalated to the app-level
         boundary and took the topbar and sidebar with it. Keeping the
         failure inside the body leaves the user somewhere they can navigate
         away from. Retry re-fires the shell query; fresh data clears the
         boundary via resetKeys. */
      <WidgetErrorBoundary onRetry={retry} resetKeys={[data]}>
        {children(data)}
      </WidgetErrorBoundary>
    );
  }

  return (
    <div>
      {headerNode}
      <DashboardBody>
        {headerSlot}
        {attentionPlacement === 'before-content' && <DashboardAttentionList />}
        {freshnessIndicator}
        {body}
      </DashboardBody>
    </div>
  );
}
