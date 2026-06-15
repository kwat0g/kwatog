import { ErrorBoundary } from '@/components/guards/ErrorBoundary';
import { Button } from './Button';

/**
 * Granular error boundary for dashboard widgets and detail-page tabs.
 * Prevents a single crashing widget from tearing down the entire layout.
 */
export function WidgetErrorBoundary({ children }: { children: React.ReactNode }) {
  return (
    <ErrorBoundary
      fallback={
        <div className="flex flex-col items-center justify-center p-6 border border-default rounded-md bg-surface text-center">
          <h3 className="text-sm font-medium text-primary mb-1">Unable to load widget</h3>
          <p className="text-xs text-muted mb-3">This widget failed to render.</p>
          <Button
            variant="secondary"
            size="sm"
            onClick={() => window.location.reload()}
          >
            Reload page
          </Button>
        </div>
      }
    >
      {children}
    </ErrorBoundary>
  );
}
