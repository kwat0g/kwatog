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
export function DashboardBody({ children, className }: { children: ReactNode; className?: string }) {
 return <div className={cn('px-5 py-4 space-y-3', className)}>{children}</div>;
}

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
 /** TanStack Query result. Drives the loading / error / data branches. */
 query: QueryLike<T>;
 /** How many KPI skeleton tiles to show while loading. */
 kpiCount?: number;
 refreshingQueryKey?: readonly unknown[];
 children: (data: T) => ReactNode;
}

/**
 * Renders the header once and switches the body between the three states every
 * list/dashboard page owes the user: loading skeleton, retryable error, data.
 */
export function DashboardShell<T>({
 title,
 subtitle,
 actions,
 query,
 kpiCount = 4,
 refreshingQueryKey,
 children,
}: DashboardShellProps<T>) {
 const header = (
 <PageHeader title={title} subtitle={subtitle} actions={actions} refreshingQueryKey={refreshingQueryKey} />
 );

 if (query.isLoading && !query.data) {
 return (
 <div>
 {header}
 <DashboardBody>
 <section className={cn('grid gap-3', kpiGridCols(kpiCount))}>
 {Array.from({ length: kpiCount }, (_, i) => (
 <SkeletonBlock key={i} className="h-[76px] rounded-md" />
 ))}
 </section>
 <SkeletonDetail />
 </DashboardBody>
 </div>
 );
 }

 if (query.isError || !query.data) {
 return (
 <div>
 {header}
 <DashboardBody>
 <EmptyState
 icon="alert-circle"
 title="Can't load this dashboard"
 description="The data request failed. Check your connection and try again."
 action={
 <Button variant="secondary" onClick={() => query.refetch()}>
 Try again
 </Button>
 }
 />
 </DashboardBody>
 </div>
 );
 }

 return (
 <div>
 {header}
 <DashboardBody>{children(query.data)}</DashboardBody>
 </div>
 );
}
