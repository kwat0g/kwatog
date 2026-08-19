import { Button } from './Button';
import { EmptyState } from './EmptyState';

interface Props {
  /** What failed to load, in the user's words: "work orders", "this delivery". */
  subject: string;
  onRetry: () => void;
  /** Compact fits inside a panel or a touch card; full owns the viewport. */
  size?: 'compact' | 'default';
  /** Rendered under the retry button — e.g. a link back to a list that works. */
  action?: React.ReactNode;
}

/**
 * The one shape a failed fetch takes.
 *
 * Two problems this exists for. First, ~190 pages hand-wrote their own
 * `isError` branch, so the same failure read differently depending on where
 * you hit it, and 18 of them offered no way to try again — a dead end in an
 * app whose most common failure is a dropped plant Wi-Fi association.
 *
 * Second, and worse: a set of pages destructured `{ data }` only and rendered
 * `{data && …}`. On the floor terminals that meant a failed fetch of the
 * active work orders looked exactly like a shift with no work scheduled. An
 * operator cannot tell those apart, and one of them means "go home".
 *
 * Deliberately distinguishes nothing about the cause — the axios interceptor
 * already reported whether this was a timeout, a 5xx or a lost connection.
 * This is the recovery affordance, not the diagnosis.
 */
export function QueryErrorState({ subject, onRetry, size = 'default', action }: Props) {
  return (
    <EmptyState
      size={size}
      icon="alert-circle"
      title={`Could not load ${subject}`}
      description="This is a loading problem, not an empty list — nothing has been lost. Try again, or reload if it keeps failing."
      action={
        <div className="flex flex-wrap items-center justify-center gap-2">
          <Button variant="secondary" onClick={onRetry}>
            Try again
          </Button>
          {action}
        </div>
      }
    />
  );
}
