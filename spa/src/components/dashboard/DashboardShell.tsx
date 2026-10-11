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
import { cn } from '@/lib/cn';
import { PageHeader } from '@/components/layout/PageHeader';
import { SkeletonBlock, SkeletonDetail } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Button } from '@/components/ui/Button';
import { Chip } from '@/components/ui/Chip';
import { TimeClockQuickAction } from '@/components/dashboard/TimeClockQuickAction';
import { isStaleTimestamp, WIDGET_STALE_AFTER_MS } from '@/api/dashboard-layout';
import { formatTime } from '@/lib/formatDate';

import { WidgetErrorBoundary } from '@/components/ui/WidgetErrorBoundary';
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
  refreshingQueryKey,
  updatedAt,
  staleAfterMs = WIDGET_STALE_AFTER_MS,
  isEmpty,
  emptyTitle = 'No data yet',
  emptyDescription = 'Nothing to show on this dashboard yet.',
  children,
}: DashboardShellProps<T>) {
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
  const stale = isStaleTimestamp(updatedAt, staleAfterMs);
  const staleBar =
    stale && updatedAt ? (
      <div
        role="status"
        className="flex items-center gap-2 rounded-md border border-default bg-surface px-3 py-2 text-xs text-muted"
      >
        <Chip variant="warning">Stale</Chip>
        <span>Updated {formatTime(updatedAt)} — data may be out of date.</span>
      </div>
    ) : null;
  // The strip reads its own query and degrades to null on its own, so it is a
  // backstop boundary only — retry re-fires the shell query.
  const headerSlot = header ? (
    <WidgetErrorBoundary onRetry={retry}>{header}</WidgetErrorBoundary>
  ) : null;

  let body: ReactNode;
  const data = query.data;
  if (query.isLoading && !data) {
    body = (
      <>
        <section className={cn('grid gap-3', kpiGridCols(kpiCount))}>
          {Array.from({ length: kpiCount }, (_, i) => (
            <SkeletonBlock key={i} className="h-[76px] rounded-md" />
          ))}
        </section>
        <SkeletonDetail />
      </>
    );
  } else if (query.isError) {
    body = (
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
    );
  } else if (data == null || isEmpty?.(data)) {
    body = (
      <EmptyState icon="inbox" title={emptyTitle} description={emptyDescription} />
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
        {staleBar}
        {body}
      </DashboardBody>
    </div>
  );
}
