import { useLocation, useNavigate } from 'react-router-dom';
import { Button } from './Button';
import { EmptyState } from './EmptyState';
import { usePermission } from '@/hooks/usePermission';
import { emptyStateCopyFor } from '@/lib/emptyStateCopy';

interface Props {
  /**
   * The search/filter term currently narrowing the list, if any. When set, the
   * copy switches to the "nothing matched" variant — a first-run message on a
   * filtered list is actively wrong, since records do exist.
   */
  searchTerm?: string;
  /** Override the route used for the copy lookup. Defaults to the current path. */
  route?: string;
  /** Replaces the registry's call to action entirely. */
  action?: React.ReactNode;
}

/**
 * The registry-backed empty state for a list page.
 *
 * `lib/emptyStateCopy.ts` was written as the single source of truth for
 * "no data yet" copy across 33 routes, complete with a permission to gate the
 * call to action and a test — and it had zero consumers. All 241 files using
 * EmptyState hand-wrote their own title and description instead, so the same
 * situation was phrased differently on every page and the registry silently
 * drifted from what shipped.
 *
 * This closes that gap by making the registry cheaper to use than not: derive
 * the route, look up the copy, gate the action on the caller's own permissions.
 */
export function ListEmptyState({ searchTerm, route, action }: Props) {
  const { pathname } = useLocation();
  const navigate = useNavigate();
  const { can } = usePermission();
  const copy = emptyStateCopyFor(route ?? pathname);

  if (searchTerm) {
    return (
      <EmptyState
        icon="search-x"
        searchTerm={searchTerm}
        title={`No ${copy.itemNoun} match “${searchTerm}”`}
        description="Try a different term, or clear the filters to see everything."
      />
    );
  }

  // An action the user cannot perform is worse than none — it invites a click
  // that ends in a 403.
  const canAct = Boolean(copy.actionLabel && copy.actionRoute && (!copy.permission || can(copy.permission)));

  return (
    <EmptyState
      icon={copy.icon}
      title={copy.title}
      description={copy.description}
      action={
        action ??
        (canAct ? (
          <Button variant="primary" onClick={() => navigate(copy.actionRoute)}>
            {copy.actionLabel}
          </Button>
        ) : undefined)
      }
    />
  );
}
