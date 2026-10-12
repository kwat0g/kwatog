import { ErrorBoundary } from '@/components/guards/ErrorBoundary';
import { Button } from './Button';

/**
 * Granular error boundary for dashboard widgets and detail-page tabs.
 * Prevents a single crashing widget from tearing down the entire layout.
 */
export function WidgetErrorBoundary({
  children,
  onRetry,
  resetKeys,
}: {
  children: React.ReactNode;
  /**
   * Re-fires the owning query (shell body, widget cell). Falls back to a full
   * page reload when the cell has no query to re-fire (e.g. DashboardGridItem).
   */
  onRetry?: () => void;
  /** Clears a tripped boundary when these change — fresh data arrived, try the render again. */
  resetKeys?: unknown[];
}) {
  return (
  <ErrorBoundary
  resetKeys={resetKeys}
  fallback={
  <div className="flex flex-col items-center justify-center p-5 border border-default rounded-md bg-surface text-center">
  <h3 className="text-sm font-medium text-primary mb-1">Section unavailable</h3>
  <p className="text-xs text-muted mb-3">This section could not be displayed. Try again to reload it.</p>
  <Button
  variant="secondary"
  size="sm"
  onClick={onRetry ?? (() => window.location.reload())}
  >
  Try again
  </Button>
  </div>
  }
  >
  {children}
  </ErrorBoundary>
  );
}
